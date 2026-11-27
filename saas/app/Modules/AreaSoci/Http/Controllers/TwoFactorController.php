<?php

declare(strict_types=1);

namespace App\Modules\AreaSoci\Http\Controllers;

use App\Modules\AreaSoci\Mail\TwoFactorOffNotice;
use App\Modules\AreaSoci\Mail\TwoFactorResetLink;
use App\Modules\AreaSoci\Services\CurrentMember;
use App\Modules\AreaSoci\Services\MemberAccess;
use App\Modules\AreaSoci\Services\MemberAccessError;
use App\Modules\AreaSoci\Services\MemberTwoFactor;
use App\Modules\Core\Access\OrgTwoFactor;
use App\Modules\Core\Contracts\Input;
use App\Modules\Core\Contracts\MailQueue;
use App\Modules\Core\Contracts\OrgContacts;
use App\Modules\Core\Contracts\OrgContext;
use App\Modules\Core\Contracts\QueuedMail;
use App\Modules\Soci\Contracts\MemberProfiles;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Verifica in due passaggi dell'area soci: il codice dopo il primo passaggio (link o codice via email, link della
 * segreteria), il ripristino via email (decisione del titolare del 27/11/2026: «Recupero anche con email»), e dal profilo
 * accensione, codici di riserva e spegnimento. Spegnerla non si può dove l'associazione la chiede ai soci.
 */
final readonly class TwoFactorController
{
    /** Primo passaggio fatto, codice dell'app ancora da scrivere: {person, email, at} nella sessione del cookie. */
    public const PENDING = 'member_tfa_pending';

    /** Codici di riserva appena creati: si vedono una volta sola, entro 10 minuti. */
    public const SHOW = 'member_tfa_show';

    private const PENDING_TTL = 600;

    private const SHOW_TTL = 600;

    private const RESET_MINUTES = 15;

    public function __construct(
        private MemberTwoFactor $twoFactor,
        private OrgTwoFactor $orgTwoFactor,
        private MemberAccess $access,
        private AccessController $entry,
        private CurrentMember $current,
        private MemberProfiles $profiles,
        private OrgContacts $contacts,
        private OrgContext $context,
    ) {}

    public function codePage(Request $request): Response|RedirectResponse
    {
        if ($this->pending($request) === null) {
            return redirect('/area/accesso')->with('member_login_message', 'Il tempo per scrivere il codice è finito: entra di nuovo.');
        }

        return Inertia::render('AreaSoci/CodiceVerifica', [
            'orgName' => AccessController::org($request)['name'],
            'message' => $request->session()->get('member_login_message'),
        ]);
    }

    public function code(Request $request): RedirectResponse
    {
        $pending = $this->pending($request);
        if ($pending === null) {
            return redirect('/area/accesso')->with('member_login_message', 'Il tempo per scrivere il codice è finito: entra di nuovo.');
        }
        $data = Input::of($request->validate(['code' => ['required', 'string', 'max:20']]));
        try {
            $this->twoFactor->check($pending['person'], Input::string($data, 'code'));
        } catch (MemberAccessError $e) {
            throw ValidationException::withMessages(['code' => $e->getMessage()]);
        }

        return $this->entry->open($request, $pending['person'], $pending['email']);
    }

    /** Telefono e codici di riserva persi: link via email all'indirizzo della scheda, 15 minuti, una volta sola. */
    public function requestReset(Request $request): RedirectResponse
    {
        $pending = $this->pending($request);
        if ($pending === null) {
            return redirect('/area/accesso')->with('member_login_message', 'Il tempo per scrivere il codice è finito: entra di nuovo.');
        }
        $email = $this->profiles->details($pending['person'])['email'] ?? null;
        $sent = 'Se la scheda ha un indirizzo email, è partito un link per spegnere la verifica: vale '.self::RESET_MINUTES.' minuti. Hai perso anche l\'accesso alla posta? Chiedi alla segreteria di spegnere la verifica.';
        $once = 'member-tfa-reset-sent:'.$this->context->require()->id.':'.$pending['person'];
        if (is_string($email) && $email !== '' && Cache::add($once, true, 60)) {
            $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
            Cache::put(self::resetKey($token), ['org' => $this->context->require()->id, 'person' => $pending['person']], now()->addMinutes(self::RESET_MINUTES));
            $org = AccessController::org($request)['name'];
            $contact = $this->contacts->all()['email'] ?? null;
            MailQueue::sendNowOrQueue(QueuedMail::mailable($email, new TwoFactorResetLink($org, rtrim($request->getSchemeAndHttpHost(), '/').'/area/verifica/ripristino/'.$token, self::RESET_MINUTES, $contact), MailQueue::URGENT, now()->addMinutes(self::RESET_MINUTES)));
        }

        return redirect('/area/accesso/verifica')->with('member_login_message', $sent);
    }

    /** Il link dell'email si apre senza consumarlo (i controlli della posta lo aprono per verificarlo): si conferma col pulsante. */
    public function resetPage(Request $request, string $token): Response
    {
        return Inertia::render('AreaSoci/RipristinoVerifica', ['orgName' => AccessController::org($request)['name'], 'token' => $token]);
    }

    /** Conferma del link dell'email: spegne la verifica, chiude le altre sessioni, avvisa socio e segreteria, poi fa entrare. */
    public function reset(Request $request, string $token): RedirectResponse
    {
        $claim = Cache::pull(self::resetKey($token));
        $org = $this->context->require()->id;
        if (! is_array($claim) || ($claim['org'] ?? null) !== $org || ! is_string($claim['person'] ?? null) || ! $this->profiles->canLogin($claim['person'])) {
            return redirect('/area/accesso')->with('member_login_message', 'Il link non vale più (è scaduto o è già stato usato): entra di nuovo e chiedine uno nuovo.');
        }
        $person = $claim['person'];
        $this->twoFactor->disable($person, 'email');
        $this->access->closeAll($person);
        $this->notifyOff($request, $person, 'email');

        return $this->entry->open($request, $person, $this->profiles->details($person)['email'] ?? null)
            ->with('status', 'Verifica in due passaggi spenta. Ti consigliamo di riaccenderla subito, qui sotto.');
    }

    public function start(Request $request): RedirectResponse
    {
        $person = $this->current->require()['person'];
        try {
            $this->twoFactor->start($person, AccessController::org($request)['name'], $this->account($person));
        } catch (MemberAccessError $e) {
            throw ValidationException::withMessages(['tfa' => $e->getMessage()]);
        }

        return redirect('/area/profilo#verifica');
    }

    public function confirm(Request $request): RedirectResponse
    {
        $person = $this->current->require()['person'];
        $data = Input::of($request->validate(['code' => ['required', 'string', 'max:20']]));
        try {
            $codes = $this->twoFactor->confirm($person, Input::string($data, 'code'));
        } catch (MemberAccessError $e) {
            throw ValidationException::withMessages(['tfa' => $e->getMessage()]);
        }
        $request->session()->put(self::SHOW, ['codes' => $codes, 'until' => time() + self::SHOW_TTL]);

        return redirect('/area/profilo#verifica')->with('status', 'Verifica in due passaggi accesa.');
    }

    public function cancel(): RedirectResponse
    {
        $person = $this->current->require()['person'];
        if (! $this->twoFactor->isOn($person)) {
            $this->twoFactor->disable($person, 'cancel');
        }

        return redirect('/area/profilo#verifica');
    }

    public function regenerate(Request $request): RedirectResponse
    {
        $person = $this->current->require()['person'];
        $this->checkCode($request, $person);
        $request->session()->put(self::SHOW, ['codes' => $this->twoFactor->regenerate($person), 'until' => time() + self::SHOW_TTL]);

        return redirect('/area/profilo#verifica')->with('status', 'Nuovi codici di riserva creati: quelli di prima non valgono più.');
    }

    public function disable(Request $request): RedirectResponse
    {
        $person = $this->current->require()['person'];
        if ($this->orgTwoFactor->membersRequiredHere()) {
            throw ValidationException::withMessages(['tfa' => 'Non puoi spegnerla: l\'associazione chiede la verifica in due passaggi ai soci.']);
        }
        $this->checkCode($request, $person);
        $this->twoFactor->disable($person, 'code');

        return redirect('/area/profilo#verifica')->with('status', 'Verifica in due passaggi spenta.');
    }

    /**
     * Dati per la sezione del profilo (MemberAreaController::profile). I codici appena creati si consegnano una volta sola.
     *
     * @return array{on: bool, pending: bool, since: string|null, backups: int, required: bool, setup: array{secret: string, qr_svg: string}|null, codes: list<string>|null}
     */
    public function profileData(Request $request, string $orgName): array
    {
        $person = $this->current->require()['person'];
        $status = $this->twoFactor->status($person);
        $show = $request->session()->pull(self::SHOW);
        $codes = is_array($show) && is_int($show['until'] ?? null) && $show['until'] >= time() && is_array($show['codes'] ?? null)
            ? array_values(array_map(MemberTwoFactor::showBackup(...), array_filter($show['codes'], is_string(...)))) : null;

        return $status + [
            'required' => $this->orgTwoFactor->membersRequiredHere(),
            'setup' => $status['pending'] ? $this->twoFactor->pendingSetup($person, $orgName, $this->account($person)) : null,
            'codes' => $codes,
        ];
    }

    private function checkCode(Request $request, string $person): void
    {
        $data = Input::of($request->validate(['code' => ['required', 'string', 'max:20']]));
        try {
            $this->twoFactor->check($person, Input::string($data, 'code'));
        } catch (MemberAccessError $e) {
            throw ValidationException::withMessages(['tfa' => $e->getMessage()]);
        }
    }

    private function notifyOff(Request $request, string $person, string $how): void
    {
        $org = AccessController::org($request)['name'];
        $contact = $this->contacts->all()['email'] ?? null;
        $email = $this->profiles->details($person)['email'] ?? null;
        $card = $this->profiles->card($person);
        $name = $card === null ? '' : trim($card['first_name'].' '.$card['last_name']);
        if (is_string($email) && $email !== '') {
            MailQueue::sendNowOrQueue(QueuedMail::mailable($email, new TwoFactorOffNotice($org, $how === 'email' ? 'email' : 'staff', false, '', $contact), MailQueue::URGENT, now()->addDay()));
        }
        if (is_string($contact) && $contact !== '') {
            MailQueue::sendNowOrQueue(QueuedMail::mailable($contact, new TwoFactorOffNotice($org, $how === 'email' ? 'email' : 'staff', true, $name), MailQueue::URGENT, now()->addDay()));
        }
    }

    /** @return array{person: string, email: string|null}|null */
    private function pending(Request $request): ?array
    {
        $p = $request->session()->get(self::PENDING);
        if (! is_array($p) || ! is_string($p['person'] ?? null) || ! is_int($p['at'] ?? null) || time() - $p['at'] > self::PENDING_TTL) {
            return null;
        }

        return ['person' => $p['person'], 'email' => is_string($p['email'] ?? null) ? $p['email'] : null];
    }

    /** Etichetta dell'account nell'app: l'email della scheda, altrimenti il nome. */
    private function account(string $person): string
    {
        $email = $this->profiles->details($person)['email'] ?? null;
        if (is_string($email) && $email !== '') {
            return $email;
        }
        $card = $this->profiles->card($person);

        return $card === null ? 'Socio' : trim($card['first_name'].' '.$card['last_name']);
    }

    private static function resetKey(string $token): string
    {
        return 'member-tfa-reset:'.hash('sha256', $token);
    }
}
