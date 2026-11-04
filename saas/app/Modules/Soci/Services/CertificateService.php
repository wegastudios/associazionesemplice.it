<?php

declare(strict_types=1);

namespace App\Modules\Soci\Services;

use App\Modules\Audit\Contracts\AuditLog;
use App\Modules\Cifratura\Contracts\EncryptedFiles;
use App\Modules\Cifratura\Contracts\StoredFile;
use App\Modules\Core\Contracts\OrgContext;
use App\Modules\Core\Contracts\OrgSettings;
use App\Modules\Soci\Contracts\PeopleError;
use App\Modules\Soci\Models\MedicalCertificate;
use App\Modules\Soci\Models\Person;
use App\Modules\Soci\Rules\CertificateRules;
use App\Modules\Soci\Rules\ExpiryRules;
use Illuminate\Contracts\Auth\Factory as Auth;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Certificati medici (dal Certificates del plugin): dati e file cifrati, letture nel registro delle azioni. Lo stato
 * di una persona (valido, in scadenza, scaduto, mancante) viene dal certificato che conta oggi.
 */
final readonly class CertificateService
{
    public const WARN_DAYS = 'soci.cert_warn_days';

    /** Tipi di file ammessi: PDF e foto. */
    public const MIMES = ['application/pdf', 'image/jpeg', 'image/png'];

    public function __construct(
        private OrgContext $context,
        private OrgSettings $settings,
        private EncryptedFiles $files,
        private AuditLog $audit,
        private Auth $auth,
    ) {}

    public function warnDays(): int
    {
        return ExpiryRules::warnDays($this->settings->get(self::WARN_DAYS, ExpiryRules::DEFAULT_WARN));
    }

    /** @param  array<mixed>  $data  kind, issued_on, expires_on, doctor */
    public function add(string $personId, array $data, ?UploadedFile $file = null): MedicalCertificate
    {
        $person = Str::isUuid($personId) ? Person::query()->find($personId) : null;
        if ($person === null) {
            throw new PeopleError(['Persona non trovata.']);
        }
        $clean = CertificateRules::clean($data);

        $stored = null;
        if ($file !== null) {
            $mime = (string) $file->getMimeType();
            if (! in_array($mime, self::MIMES, true)) {
                throw new PeopleError(['Il file deve essere un PDF o una foto (JPG, PNG).']);
            }
            $stored = $this->files->store($this->context->require()->id, (string) $file->getRealPath(), $file->getClientOriginalName(), $mime);
        }

        $userId = $this->auth->guard()->id();
        $cert = new MedicalCertificate([
            'person_id' => $person->id,
            'kind' => $clean['kind']->value,
            'doctor' => $clean['doctor'] === '' ? null : $clean['doctor'],
            'issued_on' => $clean['issued_on'],
            'expires_on' => $clean['expires_on'],
            'file_path' => $stored?->path,
            'file_name' => $stored?->originalName,
            'file_mime' => $stored?->mime,
            'file_size' => $stored?->size,
            'file_sha256' => $stored?->sha256,
            'created_by' => is_string($userId) ? $userId : null,
        ]);
        $cert->save();

        return $cert;
    }

    public function delete(string $id): void
    {
        $cert = MedicalCertificate::query()->findOrFail($id);
        $cert->delete();
    }

    public function download(string $id): StreamedResponse
    {
        $cert = MedicalCertificate::query()->findOrFail($id);
        if ($cert->file_path === null || $cert->file_sha256 === null) {
            throw new PeopleError(['Questo certificato non ha un file.']);
        }
        $this->audit->record('health.read', 'app.medical_certs', $cert->id, ['fields' => ['file']]);

        return $this->files->download($this->context->require()->id, new StoredFile(
            $cert->file_path,
            (string) ($cert->file_name ?? 'certificato'),
            (string) $cert->file_mime,
            (int) $cert->file_size,
            $cert->file_sha256,
        ));
    }

    /**
     * Certificati di una persona, il più recente prima, con lo stato complessivo.
     *
     * @return array{status: string, label: string, items: list<array{id: string, kind: string, doctor: string|null, issued_on: string, expires_on: string, has_file: bool, current: bool}>}
     */
    public function forPerson(string $personId): array
    {
        $certs = MedicalCertificate::query()->where('person_id', $personId)->orderBy('created_at')->get();
        $today = now()->toDateString();
        $rows = array_values($certs->map(static fn (MedicalCertificate $c): array => [
            'id' => $c->id, 'issued_on' => $c->issued_on->toDateString(), 'expires_on' => $c->expires_on->toDateString(),
        ])->all());
        $current = CertificateRules::current($rows, $today);
        $status = CertificateRules::status($rows, $today, $this->warnDays());

        return [
            'status' => $status,
            'label' => ExpiryRules::label($status),
            'items' => array_values($certs->reverse()->map(static fn (MedicalCertificate $c): array => [
                'id' => $c->id,
                'kind' => $c->kindLabel(),
                'doctor' => $c->doctor,
                'issued_on' => $c->issued_on->format('d/m/Y'),
                'expires_on' => $c->expires_on->format('d/m/Y'),
                'has_file' => $c->file_path !== null,
                'current' => $current !== null && $current['id'] === $c->id,
            ])->all()),
        ];
    }

    /**
     * Soci con il certificato in scadenza, scaduto o mancante: solo date e nomi, senza aprire i dati sanitari.
     *
     * @return list<array{id: string, name: string, card: string|null, expires_on: string|null, status: string, label: string}>
     */
    public function overview(string $filter = ''): array
    {
        $today = now()->toDateString();
        $warn = $this->warnDays();
        $latest = MedicalCertificate::query()->where('issued_on', '<=', $today)
            ->selectRaw('person_id, max(expires_on) AS expires_on')->groupBy('person_id')
            ->toBase()->pluck('expires_on', 'person_id');

        $out = [];
        foreach (Person::query()->whereNull('left_on')->whereNull('anonymized_at')->whereIn('type', ['founder', 'ordinary', 'volunteer'])
            ->orderBy('last_name')->orderBy('first_name')->get(['id', 'first_name', 'last_name', 'card_number']) as $p) {
            $expires = $latest->get($p->id);
            $expires = is_string($expires) ? substr($expires, 0, 10) : null;
            $status = ExpiryRules::status($expires, $today, $warn);
            if ($filter !== '' && $status !== $filter) {
                continue;
            }
            $out[] = [
                'id' => $p->id,
                'name' => $p->last_name.' '.$p->first_name,
                'card' => $p->card_number,
                'expires_on' => $expires === null ? null : date('d/m/Y', (int) strtotime($expires)),
                'status' => $status,
                'label' => ExpiryRules::label($status),
            ];
        }

        return $out;
    }
}
