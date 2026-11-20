<?php

declare(strict_types=1);

namespace App\Modules\Soci\Services;

use App\Modules\Audit\Contracts\AuditLog;
use App\Modules\Core\Contracts\OrgContacts;
use App\Modules\Core\Contracts\OrgContext;
use App\Modules\Core\Contracts\OrgDirectory;
use App\Modules\Core\Contracts\OrgSettings;
use App\Modules\Soci\Contracts\ConsentChannel;
use App\Modules\Soci\Contracts\ConsentSource;
use App\Modules\Soci\Contracts\MemberType;
use App\Modules\Soci\Contracts\PeopleError;
use App\Modules\Soci\Jobs\SendApplicationEmail;
use App\Modules\Soci\Models\Application;
use App\Modules\Soci\Models\Person;
use App\Modules\Soci\Rules\ApplicationRules;
use Illuminate\Cache\RateLimiter;
use Illuminate\Contracts\Auth\Factory as Auth;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Throwable;

/**
 * Domande di adesione online (dall'Applications del plugin). Chi compila il modulo riceve un'email con un link: finché
 * non lo apre la domanda è «da confermare», non compare in segreteria e non produce altre email (a un indirizzo scritto
 * da chiunque va una sola email l'ora). Confermata, resta in attesa finché la segreteria non la accoglie (nasce la scheda,
 * con il genitore se è un minore, e i consensi dati nel modulo) o la respinge con un motivo. Le domande mai confermate
 * si cancellano dopo 30 giorni, le respinte dopo 90 (`saas:applications:prune`).
 */
final readonly class ApplicationService
{
    public const ENABLED = 'soci.applications_enabled';

    public const NEEDS_RULES = 'soci.applications_rules';

    /** Tipi di socio che si possono chiedere online. */
    public const TYPES = ['ordinary', 'volunteer'];

    /** Ore di validità del link di conferma. */
    public const CONFIRM_HOURS = 48;

    /** Giorni dopo i quali una domanda mai confermata si cancella. */
    public const UNCONFIRMED_DAYS = 30;

    /** Giorni dopo i quali una domanda respinta si cancella. */
    public const REJECTED_DAYS = 90;

    public function __construct(
        private DatabaseManager $db,
        private OrgContext $context,
        private OrgDirectory $directory,
        private RateLimiter $limiter,
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
     * Domanda dal modulo pubblico. Restituisce gli errori di compilazione; se non ce ne sono la domanda è salvata «da
     * confermare» e all'indirizzo indicato va un'email con il link di conferma. A uno stesso indirizzo si scrive una sola
     * volta l'ora: se è già successo la domanda non si salva e la risposta a chi scrive è la stessa.
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

        $to = mb_strtolower($this->contactEmail($data, $check['minor']));
        if (! $this->limiter->attempt('application-confirm|'.hash('sha256', $to), 1, static fn (): bool => true, 3600)) {
            return [];
        }

        $application = new Application([
            'first_name' => $data['first_name'],
            'last_name' => $data['last_name'],
            'minor' => $check['minor'],
            'unconfirmed' => true,
            'data' => $data,
        ]);
        $application->save();
        $this->audit->record('application.submitted', 'app.applications', $application->id, ['minor' => $check['minor']]);

        $this->queueMail($application->id, 'confirm', $orgName);

        return [];
    }

    /**
     * Chi ha ricevuto il link lo apre: da quel momento la domanda è in segreteria e a chi l'ha scritta va la ricevuta.
     * Il link vale una volta sola e per 48 ore. Restituisce false se non vale (scaduto, già usato, inventato).
     */
    public function confirm(string $token, string $orgName): bool
    {
        if (preg_match('/^[A-Za-z0-9_-]{43}$/', $token) !== 1) {
            return false;
        }
        $hash = hash('sha256', $token);
        $table = $this->connection()->table('app.applications');
        $id = $table->where('confirm_hash', $hash)->where('unconfirmed', true)->where('status', 'pending')->where('confirm_expires_at', '>', now())->value('id');
        if (! is_string($id)) {
            return false;
        }
        $updated = $this->connection()->table('app.applications')->where('id', $id)->where('confirm_hash', $hash)->where('unconfirmed', true)
            ->update(['unconfirmed' => false, 'confirmed_at' => now(), 'confirm_hash' => null, 'confirm_expires_at' => null]);
        if ($updated !== 1) {
            return false;
        }
        $this->audit->record('application.confirmed', 'app.applications', $id);
        $this->queueMail($id, 'received', $orgName);

        return true;
    }

    /**
     * Cancella le domande mai confermate da 30 giorni e quelle respinte da 90 (le accolte non si toccano: hanno dato
     * vita a una scheda). Restituisce quante ne ha cancellate.
     *
     * @return array{unconfirmed: int, rejected: int}
     */
    public function prune(): array
    {
        $unconfirmed = $this->connection()->table('app.applications')->where('unconfirmed', true)->where('status', 'pending')
            ->where('submitted_at', '<', now()->subDays(self::UNCONFIRMED_DAYS))->delete();
        $rejected = $this->connection()->table('app.applications')->where('status', 'rejected')
            ->where('decided_at', '<', now()->subDays(self::REJECTED_DAYS))->delete();

        return ['unconfirmed' => $unconfirmed, 'rejected' => $rejected];
    }

    /** @return list<array{id: string, name: string, minor: bool, submitted: string}> */
    public function pending(): array
    {
        return array_values(Application::query()->where('status', 'pending')->where('unconfirmed', false)->orderBy('submitted_at')
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
        return Application::query()->where('status', 'pending')->where('unconfirmed', false)->count();
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

        $this->queueMail($application->id, 'accepted', $orgName);

        return $personId;
    }

    public function reject(string $id, string $reason, string $orgName = ''): void
    {
        if (trim($reason) === '') {
            throw new PeopleError(['Indica il motivo.']);
        }
        $application = $this->find($id, pending: true);
        $this->decide($application, 'rejected', mb_substr(trim($reason), 0, 200), null);

        // Il motivo resta nel gestionale; a chi ha fatto domanda va una risposta, senza dettagli interni.
        $this->queueMail($application->id, 'rejected', $orgName);
    }

    /**
     * A chi scrivere: al socio, o per un minore al genitore del modulo.
     *
     * @param  array<mixed>  $data
     */
    private function contactEmail(array $data, bool $minor): string
    {
        $g = $data['guardian'] ?? null;

        return $minor && is_array($g) ? $this->text($g, 'email') : $this->text($data, 'email');
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
            throw new PeopleError(['La domanda ha già avuto risposta.']);
        }
        $this->audit->record('application.'.$status, 'app.applications', $application->id, $personId === null ? [] : ['person_id' => $personId]);
    }

    private function find(string $id, bool $pending = false): Application
    {
        // Una domanda ancora da confermare non esiste per la segreteria: non si apre, non si accoglie, non si respinge.
        $q = Application::query()->where('unconfirmed', false);
        if ($pending) {
            $q->where('status', 'pending');
        }
        $application = Str::isUuid($id) ? $q->find($id) : null;

        return $application ?? throw new PeopleError([$pending ? 'Domanda non trovata: forse ha già avuto risposta.' : 'Domanda inesistente.']);
    }

    /** @param  array<mixed>  $data */
    private function text(array $data, string $key): string
    {
        return is_scalar($data[$key] ?? null) ? trim((string) $data[$key]) : '';
    }

    /** Accoda l'email a chi ha fatto domanda (solo identificativi nel lavoro). La domanda resta salvata anche se l'accodamento fallisce. */
    private function queueMail(string $applicationId, string $kind, string $orgName): void
    {
        try {
            SendApplicationEmail::dispatch($this->context->require()->id, $applicationId, $kind, $orgName);
        } catch (Throwable $e) {
            report($e);
        }
    }

    /**
     * Manda l'email di una domanda (dal lavoro in coda): $kind è confirm (il link di conferma), received, accepted o
     * rejected. Indirizzo e testo si ricavano adesso dalla domanda; un errore di posta si rilancia, così il lavoro riprova.
     * Nelle email non entra testo scritto da chi compila il modulo: saluto e frasi sono sempre gli stessi. Tutto tranne la
     * conferma parte solo a domanda confermata.
     */
    public function sendMail(string $applicationId, string $kind, string $orgName): void
    {
        $application = Str::isUuid($applicationId) ? Application::query()->find($applicationId) : null;
        if ($application === null || $application->unconfirmed !== ($kind === 'confirm')) {
            return;
        }
        $email = $this->contactEmail($application->data, $application->minor);
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return;
        }
        $of = $application->minor ? ' del minore indicato nel modulo' : '';
        $office = (new OrgContacts($this->settings))->all();
        [$subject, $body] = match ($kind) {
            'confirm' => ['Conferma la domanda di adesione', 'è arrivata una domanda di adesione che indica questo indirizzo email. Per mandarla alla segreteria devi confermarla: il link vale '.self::CONFIRM_HOURS.' ore. Se la domanda non l\'hai fatta tu, ignora questo messaggio: non succede nulla.'],
            'received' => ['Domanda di adesione ricevuta', 'la domanda di adesione'.($application->minor ? ' per il minore indicato nel modulo' : '').' è stata ricevuta. La segreteria la esamina e ti risponde al più presto.'],
            'accepted' => ['Domanda di adesione accolta', "la domanda di adesione{$of} è stata accolta. Per la tessera e la quota rivolgiti alla segreteria."],
            'rejected' => ['Domanda di adesione non accolta', "la domanda di adesione{$of} non è stata accolta. Per chiarimenti rivolgiti alla segreteria".($office === null ? '.' : ': '.OrgContacts::line($office['email'], $office['phone']).'.')],
            default => [null, null],
        };
        if ($subject === null || $body === null) {
            return;
        }
        $link = null;
        if ($kind === 'confirm') {
            $link = $this->confirmLink($application->id);
            if ($link === null) {
                return;
            }
        }

        // Siamo già dentro un lavoro in coda: la posta parte subito, senza accodarla una seconda volta.
        Notification::route('mail', $email)->notifyNow(new MemberNotification($subject, 'Ciao,', $body, $orgName, $link === null ? null : 'Conferma la domanda', $link));
    }

    /**
     * Prepara un link di conferma nuovo (valido una volta, per 48 ore; nel database resta solo l'impronta). Il lavoro in
     * coda non porta il link: nasce qui, al momento dell'invio. L'indirizzo parte da quello configurato dell'app.
     */
    private function confirmLink(string $applicationId): ?string
    {
        $slug = $this->directory->slug($this->context->require()->id);
        if ($slug === null) {
            return null;
        }
        $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $this->connection()->table('app.applications')->where('id', $applicationId)->where('unconfirmed', true)
            ->update(['confirm_hash' => hash('sha256', $token), 'confirm_expires_at' => now()->addHours(self::CONFIRM_HOURS)]);
        $base = config('app.url');

        return rtrim(is_string($base) ? $base : '', '/').route('application.confirm', ['slug' => $slug, 'token' => $token], false);
    }

    private function connection(): ConnectionInterface
    {
        return $this->db->connection($this->context->require()->connection);
    }
}
