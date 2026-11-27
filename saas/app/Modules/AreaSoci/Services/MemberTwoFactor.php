<?php

declare(strict_types=1);

namespace App\Modules\AreaSoci\Services;

use App\Modules\Audit\Contracts\AuditLog;
use App\Modules\Core\Contracts\OrgContext;
use BaconQrCode\Renderer\Color\Rgb;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\Fill;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Illuminate\Cache\RateLimiter;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Crypt;
use PragmaRX\Google2FA\Google2FA;
use Throwable;

/**
 * Verifica in due passaggi dei soci (decisioni del titolare del 26-27/11/2026), allineata al plugin (TwoFactor.php):
 * codice dell'app di 6 cifre ogni 30 secondi, un passo prima e uno dopo per gli orologi sfasati, un codice già usato non
 * vale più; 10 codici di riserva di 10 cifre («12345-67890»), ognuno una volta sola; 5 codici sbagliati in 10 minuti
 * bloccano per 10 minuti. Vale per la scheda di chi entra (un minore senza accesso proprio non la ha).
 */
final readonly class MemberTwoFactor
{
    public const WINDOW = 1;

    public const BACKUP_COUNT = 10;

    public const BACKUP_LEN = 10;

    public const MAX_FAILS = 5;

    public const BLOCK_SECONDS = 600;

    public const WRONG_MSG = 'Il codice non è corretto o è già stato usato. Scrivi il codice che vedi adesso nell\'app, oppure un codice di riserva.';

    public const BLOCKED_MSG = 'Troppi codici sbagliati: aspetta 10 minuti e riprova.';

    public function __construct(
        private DatabaseManager $db,
        private OrgContext $context,
        private AuditLog $audit,
        private RateLimiter $limiter,
    ) {}

    /** @return array{on: bool, pending: bool, since: string|null, backups: int} */
    public function status(string $personId): array
    {
        $row = $this->table()->where('person_id', $personId)->first(['confirmed_at', 'recovery']);
        if ($row === null) {
            return ['on' => false, 'pending' => false, 'since' => null, 'backups' => 0];
        }
        $since = is_string($row->confirmed_at ?? null) ? $row->confirmed_at : null;

        return ['on' => $since !== null, 'pending' => $since === null, 'since' => $since, 'backups' => count(self::hashes($row->recovery ?? '[]'))];
    }

    public function isOn(string $personId): bool
    {
        return $this->table()->where('person_id', $personId)->whereNotNull('confirmed_at')->exists();
    }

    /**
     * Prepara l'accensione: nuovo segreto (sostituisce solo uno mai confermato) e codice QR da inquadrare.
     *
     * @return array{secret: string, qr_svg: string}
     */
    public function start(string $personId, string $issuer, string $account): array
    {
        if ($this->isOn($personId)) {
            throw new MemberAccessError('La verifica in due passaggi è già accesa.');
        }
        $secret = (new Google2FA)->generateSecretKey(32);
        $this->table()->upsert(
            [['org_id' => $this->org(), 'person_id' => $personId, 'secret' => Crypt::encryptString($secret), 'confirmed_at' => null, 'recovery' => '[]', 'last_step' => 0, 'updated_at' => now()]],
            ['org_id', 'person_id'],
            ['secret', 'confirmed_at', 'recovery', 'last_step', 'updated_at'],
        );

        return ['secret' => $secret, 'qr_svg' => $this->qr($issuer, $account, $secret)];
    }

    /** Il segreto in attesa di conferma, per ridisegnare il codice QR; null se non c'è. @return array{secret: string, qr_svg: string}|null */
    public function pendingSetup(string $personId, string $issuer, string $account): ?array
    {
        $row = $this->table()->where('person_id', $personId)->whereNull('confirmed_at')->first(['secret']);
        $secret = $row === null ? null : $this->decrypt($row->secret ?? null);

        return $secret === null ? null : ['secret' => $secret, 'qr_svg' => $this->qr($issuer, $account, $secret)];
    }

    /**
     * Primo codice giusto: accende la verifica e crea i codici di riserva (restituiti in chiaro, una volta sola).
     *
     * @return list<string>
     */
    public function confirm(string $personId, string $code): array
    {
        $this->guard($personId);
        $row = $this->table()->where('person_id', $personId)->whereNull('confirmed_at')->first(['secret']);
        $secret = $row === null ? null : $this->decrypt($row->secret ?? null);
        $step = $secret === null ? false : $this->totp($secret, $code, 0);
        if ($step === false) {
            $this->fail($personId);
        }
        $codes = self::newBackups();
        $this->table()->where('person_id', $personId)->whereNull('confirmed_at')->update([
            'confirmed_at' => now(), 'last_step' => $step, 'recovery' => json_encode(array_map(self::hashBackup(...), $codes), JSON_THROW_ON_ERROR), 'updated_at' => now(),
        ]);
        $this->limiter->clear($this->key($personId));
        $this->audit->record('member.tfa_on', 'app.people', $personId);

        return $codes;
    }

    /** Codice dell'app o di riserva all'accesso (o per spegnere / rigenerare). Sbagliato: eccezione con il messaggio per il socio. */
    public function check(string $personId, string $code): void
    {
        $this->guard($personId);
        $row = $this->table()->where('person_id', $personId)->whereNotNull('confirmed_at')->first(['secret', 'recovery', 'last_step']);
        if ($row === null) {
            $this->fail($personId);
        }
        $digits = preg_replace('/\D/', '', $code) ?? '';

        if (strlen($digits) === 6) {
            $secret = $this->decrypt($row->secret ?? null);
            $last = is_numeric($row->last_step ?? null) ? (int) $row->last_step : 0;
            $step = $secret === null ? false : $this->totp($secret, $digits, $last);
            // Il passo si segna con un solo aggiornamento condizionato: due richieste con lo stesso codice non passano entrambe.
            if ($step !== false && $this->table()->where('person_id', $personId)->where('last_step', $last)->update(['last_step' => $step, 'updated_at' => now()]) === 1) {
                $this->limiter->clear($this->key($personId));

                return;
            }
        } elseif (strlen($digits) === self::BACKUP_LEN) {
            $hashes = self::hashes($row->recovery ?? '[]');
            foreach ($hashes as $i => $hash) {
                if (password_verify('tfa-backup:'.$digits, $hash)) {
                    $left = $hashes;
                    unset($left[$i]);
                    $json = json_encode(array_values($left), JSON_THROW_ON_ERROR);
                    // Solo se la lista è ancora quella letta: lo stesso codice di riserva non vale due volte.
                    if ($this->connection()->update('UPDATE app.member_two_factor SET recovery = CAST(? AS jsonb), updated_at = now() WHERE person_id = ? AND recovery = CAST(? AS jsonb)', [$json, $personId, json_encode($hashes, JSON_THROW_ON_ERROR)]) === 1) {
                        $this->limiter->clear($this->key($personId));
                        $this->audit->record('member.tfa_backup_used', 'app.people', $personId, ['left' => count($left)]);

                        return;
                    }
                }
            }
        }
        $this->fail($personId);
    }

    /** Nuovi codici di riserva al posto di quelli di prima. @return list<string> */
    public function regenerate(string $personId): array
    {
        $codes = self::newBackups();
        $this->table()->where('person_id', $personId)->whereNotNull('confirmed_at')->update(['recovery' => json_encode(array_map(self::hashBackup(...), $codes), JSON_THROW_ON_ERROR), 'updated_at' => now()]);
        $this->audit->record('member.tfa_backups_renewed', 'app.people', $personId);

        return $codes;
    }

    /** Spegne la verifica della scheda. $how: code (il socio), email (link di ripristino), staff (la segreteria), cancel. */
    public function disable(string $personId, string $how): bool
    {
        $deleted = $this->table()->where('person_id', $personId)->delete() > 0;
        if ($deleted && $how !== 'cancel') {
            $this->audit->record('member.tfa_off', 'app.people', $personId, ['how' => $how]);
        }

        return $deleted;
    }

    public static function showBackup(string $code): string
    {
        return substr($code, 0, 5).'-'.substr($code, 5);
    }

    private function guard(string $personId): void
    {
        if ($this->limiter->tooManyAttempts($this->key($personId), self::MAX_FAILS)) {
            throw new MemberAccessError(self::BLOCKED_MSG);
        }
    }

    private function fail(string $personId): never
    {
        $this->limiter->hit($this->key($personId), self::BLOCK_SECONDS);
        $this->audit->record('member.tfa_failed', 'app.people', $personId);
        if ($this->limiter->tooManyAttempts($this->key($personId), self::MAX_FAILS)) {
            throw new MemberAccessError(self::BLOCKED_MSG);
        }

        throw new MemberAccessError(self::WRONG_MSG);
    }

    /** Passo (timestamp/30) del codice se vale ed è più nuovo di $last, altrimenti false. */
    private function totp(string $secret, string $code, int $last): int|false
    {
        $code = preg_replace('/\D/', '', $code) ?? '';
        if (strlen($code) !== 6) {
            return false;
        }
        try {
            $step = (new Google2FA)->verifyKeyNewer($secret, $code, $last, self::WINDOW);
        } catch (Throwable) {
            return false;
        }

        return is_int($step) && $step > $last ? $step : false;
    }

    private function qr(string $issuer, string $account, string $secret): string
    {
        $issuer = trim($issuer) !== '' ? trim($issuer) : 'Area soci';
        $style = new RendererStyle(192, 0, null, null, Fill::uniformColor(new Rgb(255, 255, 255), new Rgb(45, 55, 72)));
        $svg = (new Writer(new ImageRenderer($style, new SvgImageBackEnd)))->writeString((new Google2FA)->getQRCodeUrl($issuer, $account, $secret));

        return trim(substr($svg, (int) strpos($svg, "\n") + 1));
    }

    private function decrypt(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        try {
            return Crypt::decryptString($value);
        } catch (Throwable) {
            return null;
        }
    }

    /** @return list<string> */
    private static function newBackups(): array
    {
        $codes = [];
        while (count($codes) < self::BACKUP_COUNT) {
            $code = '';
            for ($i = 0; $i < self::BACKUP_LEN; $i++) {
                $code .= (string) random_int(0, 9);
            }
            $codes[$code] = $code;
        }

        return array_values($codes);
    }

    private static function hashBackup(string $code): string
    {
        return password_hash('tfa-backup:'.$code, PASSWORD_DEFAULT);
    }

    /** @return list<string> */
    private static function hashes(mixed $json): array
    {
        $list = is_string($json) ? json_decode($json, true) : null;

        return is_array($list) ? array_values(array_filter($list, is_string(...))) : [];
    }

    private function key(string $personId): string
    {
        return 'member-tfa:'.$this->org().':'.$personId;
    }

    private function org(): string
    {
        return $this->context->require()->id;
    }

    private function table(): Builder
    {
        return $this->connection()->table('app.member_two_factor');
    }

    private function connection(): ConnectionInterface
    {
        return $this->db->connection($this->context->require()->connection);
    }
}
