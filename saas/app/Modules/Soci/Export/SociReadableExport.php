<?php

declare(strict_types=1);

namespace App\Modules\Soci\Export;

use App\Modules\Audit\Contracts\AuditLog;
use App\Modules\Cifratura\Contracts\DecryptionFailed;
use App\Modules\Cifratura\Contracts\EncryptedFiles;
use App\Modules\Cifratura\Contracts\StoredFile;
use App\Modules\Core\Contracts\Ability;
use App\Modules\Core\Contracts\Operator;
use App\Modules\Core\Contracts\OrgContext;
use App\Modules\Core\Contracts\ReadableExport;
use App\Modules\Core\Contracts\ZipName;
use App\Modules\Soci\Models\MedicalCertificate;
use App\Modules\Soci\Models\Person;
use App\Modules\Soci\Services\MemberBookService;
use App\Modules\Soci\Services\MemberBookSnapshots;
use Illuminate\Database\DatabaseManager;
use League\Flysystem\UnableToReadFile;

/**
 * Per ogni anno fiscale il libro soci in PDF e in CSV (per gli anni chiusi, dalla fotografia alla chiusura) e, solo se
 * chi scarica lo chiede e può vedere i dati sanitari, i file dei certificati medici in chiaro.
 */
final readonly class SociReadableExport implements ReadableExport
{
    public function __construct(
        private DatabaseManager $db,
        private OrgContext $context,
        private MemberBookService $book,
        private MemberBookSnapshots $snapshots,
        private EncryptedFiles $files,
        private AuditLog $audit,
    ) {}

    public function weight(Operator $operator, bool $withCertificates, ?int $only = null): int
    {
        if (! $withCertificates || ! $operator->can(Ability::ViewHealth)) {
            return 0;
        }

        return MedicalCertificate::query()->whereNotNull('file_path')->count();
    }

    public function files(Operator $operator, bool $withCertificates, ?int $only = null): array
    {
        if (! $operator->can(Ability::ViewPeople)) {
            return [];
        }
        $out = [];
        $years = $this->db->connection($this->context->require()->connection)->table('app.fiscal_years')->orderBy('year')->pluck('year');
        foreach ($years as $y) {
            $year = is_numeric($y) ? (int) $y : 0;
            if ($only !== null && $year !== $only) {
                continue;
            }
            $dir = 'Anno fiscale '.$year.'/';
            $out[$dir.'Libro soci.pdf'] = fn (): string => $this->book->pdf($this->snapshots->forYear($year)['rows'], $operator->orgName, '');
            $out[$dir.'Libro soci.csv'] = fn (): string => $this->book->csv($this->snapshots->forYear($year)['rows']);
        }
        if ($withCertificates && $operator->can(Ability::ViewHealth)) {
            $names = [];
            $certificates = MedicalCertificate::query()->whereNotNull('file_path')->whereNotNull('file_sha256')->orderBy('expires_on')->get();
            // Nomi delle persone in una sola query, non una per certificato.
            $people = Person::query()->whereIn('id', $certificates->pluck('person_id')->unique()->values()->all())->get(['id', 'first_name', 'last_name'])->keyBy('id');
            foreach ($certificates as $c) {
                $person = $people->get($c->person_id);
                $who = $person === null ? 'Persona' : trim($person->last_name.' '.$person->first_name);
                $ext = strtolower(pathinfo((string) $c->file_name, PATHINFO_EXTENSION));
                $ext = in_array($ext, ['pdf', 'jpg', 'jpeg', 'png'], true) ? $ext : match ($c->file_mime) {
                    'image/jpeg' => 'jpg', 'image/png' => 'png', default => 'pdf',
                };
                $base = ZipName::clean($who.' - '.$c->kindLabel().' - scade '.$c->expires_on->toDateString());
                $name = $base;
                for ($n = 2; isset($names[strtolower($name.'.'.$ext)]); $n++) {
                    $name = $base.' ('.$n.')';
                }
                $names[strtolower($name.'.'.$ext)] = true;
                $stored = new StoredFile((string) $c->file_path, (string) ($c->file_name ?? 'certificato'), (string) $c->file_mime, (int) $c->file_size, (string) $c->file_sha256);
                $id = $c->id;
                $out['Certificati medici/'.$name.'.'.$ext] = function () use ($stored, $id): ?string {
                    try {
                        $plain = $this->files->read($this->context->require()->id, $stored);
                    } catch (UnableToReadFile|DecryptionFailed) {
                        return null;
                    }
                    $this->audit->record('health.read', 'app.medical_certs', $id, ['fields' => ['file'], 'via' => 'copia di sicurezza']);

                    return $plain;
                };
            }
        }

        return $out;
    }
}
