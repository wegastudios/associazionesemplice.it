<?php

declare(strict_types=1);

namespace App\Modules\Soci\Services;

use App\Modules\Audit\Contracts\AuditLog;
use App\Modules\Core\Contracts\OrgContext;
use App\Modules\Core\Contracts\OrgSettings;
use App\Modules\Soci\Contracts\ConsentChannel;
use App\Modules\Soci\Contracts\ConsentSource;
use App\Modules\Soci\Contracts\MemberType;
use App\Modules\Soci\Contracts\PeopleError;
use App\Modules\Soci\Models\Application;
use App\Modules\Soci\Models\Person;
use App\Modules\Soci\Rules\ApplicationRules;
use Illuminate\Contracts\Auth\Factory as Auth;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Throwable;

/**
 * Domande di adesione online (dall'Applications del plugin). La domanda resta in attesa finché la segreteria non la
 * accoglie (nasce la scheda, con il genitore se è un minore, e i consensi dati nel modulo) o la respinge con un motivo.
 */
final readonly class ApplicationService
{
    public const ENABLED = 'soci.applications_enabled';

    public const NEEDS_RULES = 'soci.applications_rules';

    /** Tipi di socio che si possono chiedere online. */
    public const TYPES = ['ordinary', 'volunteer'];

    public function __construct(
        private DatabaseManager $db,
        private OrgContext $context,
        private OrgSettings $settings,
        private PeopleService $people,
        private GuardianService $guardians,
        private ConsentService $consents,
        private AuditLog $audit,
        private Auth $auth,
    ) {}

    public function enabled(): bool
    {
        return $this->settings->get(self::ENABLED, false) === true;
    }

    public function needsRules(): bool
    {
        return $this->settings->get(self::NEEDS_RULES, false) === true;
    }

    /**
     * Domanda dal modulo pubblico. Restituisce gli errori di compilazione; se non ce ne sono la domanda è salvata e
     * chi l'ha mandata riceve una conferma per email.
     *
     * @param  array<mixed>  $input
     * @return list<string>
     */
    public function submit(array $input, string $orgName): array
    {
        $check = ApplicationRules::check($input, now()->toDateString(), $this->needsRules(), self::TYPES);
        if ($check['errors'] !== []) {
            return $check['errors'];
        }
        $data = $check['data'] + [
            'privacy_ok' => true,
            'rules_ok' => ! empty($input['rules_ok']),
        ];

        $application = new Application([
            'first_name' => $data['first_name'],
            'last_name' => $data['last_name'],
            'minor' => $check['minor'],
            'data' => $data,
        ]);
        $application->save();
        $this->audit->record('application.submitted', 'app.applications', $application->id, ['minor' => $check['minor']]);

        $c = $this->contact($data, $check['minor']);
        $about = $c['about'] !== '' ? " per {$c['about']}" : '';
        $this->mail($c['email'], 'Abbiamo ricevuto la domanda di adesione', "Ciao {$c['name']},", "abbiamo ricevuto la domanda di adesione{$about}. La segreteria la esamina e ti risponde al più presto.", $orgName);

        return [];
    }

    /** @return list<array{id: string, name: string, minor: bool, submitted: string}> */
    public function pending(): array
    {
        return array_values(Application::query()->where('status', 'pending')->orderBy('submitted_at')
            ->get(['id', 'first_name', 'last_name', 'minor', 'submitted_at'])
            ->map(static fn (Application $a): array => [
                'id' => $a->id,
                'name' => $a->last_name.' '.$a->first_name,
                'minor' => $a->minor,
                'submitted' => $a->submitted_at->format('d/m/Y H:i'),
            ])->all());
    }

    public function pendingCount(): int
    {
        return Application::query()->where('status', 'pending')->count();
    }

    /** @return list<array{id: string, name: string, status: string, decided: string, reason: string|null, person_id: string|null}> */
    public function decided(int $limit = 30): array
    {
        return array_values(Application::query()->where('status', '!=', 'pending')->orderByDesc('decided_at')->limit($limit)
            ->get(['id', 'first_name', 'last_name', 'status', 'decided_at', 'reason', 'person_id'])
            ->map(static fn (Application $a): array => [
                'id' => $a->id,
                'name' => $a->last_name.' '.$a->first_name,
                'status' => $a->status,
                'decided' => $a->decided_at?->format('d/m/Y') ?? '',
                'reason' => $a->reason,
                'person_id' => $a->person_id,
            ])->all());
    }

    /** I dati del modulo, per la segreteria che decide. */
    public function details(string $id): Application
    {
        return $this->find($id);
    }

    /** Accoglie la domanda: crea la scheda (e quella del genitore), collega il tutore, registra i consensi del modulo. */
    public function approve(string $id, ?string $cardNumber, string $orgName): string
    {
        $application = $this->find($id, pending: true);
        $data = $application->data;
        $today = now()->toDateString();

        $personId = $this->connection()->transaction(function () use ($application, $data, $cardNumber, $today): string {
            $person = $this->people->create([
                'type' => $this->text($data, 'type'),
                'first_name' => $this->text($data, 'first_name'),
                'last_name' => $this->text($data, 'last_name'),
                'email' => $this->text($data, 'email'),
                'phone' => $this->text($data, 'phone'),
                'tax_code' => $this->text($data, 'tax_code'),
                'birth_date' => $this->text($data, 'birth_date'),
                'birth_place' => $this->text($data, 'birth_place'),
                'address' => $this->text($data, 'address') === '' ? null : ['text' => $this->text($data, 'address')],
                'card_number' => trim((string) $cardNumber) !== '' ? trim((string) $cardNumber) : $this->people->firstFreeCard(),
                'joined_on' => $today,
            ]);

            $givenBy = null;
            if ($application->minor && is_array($data['guardian'] ?? null)) {
                $guardian = $this->guardianFor($data['guardian']);
                $this->guardians->link($person->id, $guardian->id);
                $givenBy = $guardian->id;
            }

            $this->consents->set($person->id, ConsentChannel::Privacy, true, ConsentSource::Web, $givenBy);
            if (($data['rules_ok'] ?? false) === true) {
                $this->consents->set($person->id, ConsentChannel::Rules, true, ConsentSource::Web, $givenBy);
            }
            if (($data['images_ok'] ?? false) === true) {
                $this->consents->set($person->id, ConsentChannel::Images, true, ConsentSource::Web, $givenBy);
            }

            $this->decide($application, 'approved', null, $person->id);

            return $person->id;
        });

        $c = $this->contact($data, $application->minor);
        $about = $c['about'] !== '' ? " di {$c['about']}" : '';
        $this->mail($c['email'], 'Domanda di adesione accolta', "Ciao {$c['name']},", "la domanda di adesione{$about} è stata accolta: benvenuto! Per la tessera e la quota rivolgiti alla segreteria.", $orgName);

        return $personId;
    }

    public function reject(string $id, string $reason): void
    {
        if (trim($reason) === '') {
            throw new PeopleError(['Indica il motivo.']);
        }
        $this->decide($this->find($id, pending: true), 'rejected', mb_substr(trim($reason), 0, 200), null);
    }

    /**
     * A chi scrivere: al socio, o per un minore al genitore del modulo.
     *
     * @param  array<mixed>  $data
     * @return array{email: string, name: string, about: string}
     */
    private function contact(array $data, bool $minor): array
    {
        $g = $data['guardian'] ?? null;
        if ($minor && is_array($g)) {
            return ['email' => $this->text($g, 'email'), 'name' => $this->text($g, 'first_name'), 'about' => $this->text($data, 'first_name')];
        }

        return ['email' => $this->text($data, 'email'), 'name' => $this->text($data, 'first_name'), 'about' => ''];
    }

    /**
     * Il genitore del modulo: la scheda che c'è già (stesso codice fiscale o stessa email), altrimenti una nuova.
     *
     * @param  array<mixed>  $g
     */
    private function guardianFor(array $g): Person
    {
        $taxCode = $this->text($g, 'tax_code');
        $email = $this->text($g, 'email');
        $existing = $taxCode !== '' ? Person::query()->whereEncrypted('tax_code', $taxCode)->whereNull('left_on')->first() : null;
        $existing ??= $email !== '' ? Person::query()->where('email', $email)->whereNull('left_on')->whereNull('anonymized_at')->orderBy('id')->first() : null;
        if ($existing !== null) {
            return $existing;
        }

        return $this->people->create([
            'type' => MemberType::Guardian->value,
            'first_name' => $this->text($g, 'first_name'),
            'last_name' => $this->text($g, 'last_name'),
            'email' => $email,
            'phone' => $this->text($g, 'phone'),
            'tax_code' => $taxCode,
        ]);
    }

    private function decide(Application $application, string $status, ?string $reason, ?string $personId): void
    {
        $userId = $this->auth->guard()->id();
        $updated = $this->connection()->table('app.applications')->where('id', $application->id)->where('status', 'pending')->update([
            'status' => $status,
            'decided_at' => now(),
            'decided_by' => is_string($userId) ? $userId : null,
            'reason' => $reason,
            'person_id' => $personId,
        ]);
        if ($updated === 0) {
            throw new PeopleError(['La domanda è già stata decisa.']);
        }
        $this->audit->record('application.'.$status, 'app.applications', $application->id, $personId === null ? [] : ['person_id' => $personId]);
    }

    private function find(string $id, bool $pending = false): Application
    {
        $q = Application::query();
        if ($pending) {
            $q->where('status', 'pending');
        }
        $application = Str::isUuid($id) ? $q->find($id) : null;

        return $application ?? throw new PeopleError([$pending ? 'Domanda inesistente o già decisa.' : 'Domanda inesistente.']);
    }

    /** @param  array<mixed>  $data */
    private function text(array $data, string $key): string
    {
        return is_scalar($data[$key] ?? null) ? trim((string) $data[$key]) : '';
    }

    private function mail(string $to, string $subject, string $greeting, string $body, string $orgName): void
    {
        if (filter_var($to, FILTER_VALIDATE_EMAIL) === false) {
            return;
        }
        try {
            Notification::route('mail', $to)->notify(new MemberNotification($subject, $greeting, $body, $orgName));
        } catch (Throwable $e) {
            report($e); // la domanda resta salvata anche se l'email non parte
        }
    }

    private function connection(): ConnectionInterface
    {
        return $this->db->connection($this->context->require()->connection);
    }
}
