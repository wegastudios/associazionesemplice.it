<?php

declare(strict_types=1);

namespace App\Modules\Core\Platform;

use App\Modules\Core\Contracts\PlatformSettingsStore;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Contracts\Encryption\Encrypter;
use Illuminate\Contracts\Encryption\StringEncrypter;
use Illuminate\Database\DatabaseManager;
use InvalidArgumentException;
use RuntimeException;

/**
 * Impostazioni della piattaforma nel database centrale, cifrate con la chiave dell'applicazione. Ognuna, se c'è, prende
 * il posto del valore del file d'ambiente (PlatformConfig). I segreti non tornano mai in chiaro alla pagina.
 */
final readonly class PlatformSettings implements PlatformSettingsStore
{
    /**
     * Le impostazioni: chiave => [etichetta, segreto, controllo].
     *
     * @var array<string, array{0: string, 1: bool, 2: string}>
     */
    public const KEYS = [
        'mail.host' => ['Server SMTP', false, 'host'],
        'mail.port' => ['Porta', false, 'port'],
        'mail.encryption' => ['Cifratura (tls, ssl o nessuna)', false, 'encryption'],
        'mail.username' => ['Utente', false, 'text'],
        'mail.password' => ['Password', true, 'text'],
        'mail.from_address' => ['Indirizzo del mittente', false, 'email'],
        'mail.from_name' => ['Nome del mittente', false, 'text'],
        'backup.sftp_host' => ['Server SFTP', false, 'host'],
        'backup.sftp_port' => ['Porta', false, 'port'],
        'backup.sftp_username' => ['Utente', false, 'text'],
        'backup.sftp_private_key' => ['Chiave privata (testo della chiave)', true, 'key'],
        'backup.sftp_passphrase' => ['Frase della chiave (se c\'è)', true, 'text'],
        'backup.sftp_password' => ['Password (se non si usa la chiave)', true, 'text'],
        'backup.sftp_fingerprint' => ['Impronta del server SFTP', false, 'text'],
        'backup.sftp_root' => ['Cartella', false, 'path'],
        'keys.service_url' => ['Indirizzo del servizio chiavi', false, 'https'],
        'keys.signing_key' => ['Chiave di firma verso il servizio', true, 'base64'],
        'cache.store' => ['Cache (auto, redis, database o file)', false, 'store'],
        'cache.redis_host' => ['Redis: host o socket', false, 'text'],
        'cache.redis_port' => ['Redis: porta (0 con il socket)', false, 'port0'],
        'cache.redis_password' => ['Redis: password', true, 'text'],
        'domains.nameservers' => ['Nameserver a cui il cliente delega il dominio (separati da virgola)', false, 'nameservers'],
        'domains.delegation_hours' => ['Ore di attesa della delega prima di segnalare un errore', false, 'hours'],
    ];

    public function __construct(
        private DatabaseManager $db,
        private Container $container,
    ) {}

    /** Risolto solo quando serve: senza APP_KEY (composer install, build) l'avvio dell'applicazione non deve fallire. */
    private function encrypter(): StringEncrypter
    {
        $encrypter = $this->container->make(Encrypter::class);

        return $encrypter instanceof StringEncrypter ? $encrypter : throw new RuntimeException('Cifratura non disponibile.');
    }

    /** @return array<string, string> tutte le impostazioni presenti, in chiaro (solo per applicarle) */
    public function all(): array
    {
        $out = [];
        foreach ($this->db->connection('pgsql')->table('core.platform_settings')->get(['key', 'value_enc']) as $row) {
            if (! is_string($row->key ?? null) || ! is_string($row->value_enc ?? null) || ! isset(self::KEYS[$row->key])) {
                continue;
            }
            try {
                $value = $this->encrypter()->decryptString($row->value_enc);
            } catch (DecryptException) {
                continue; // chiave dell'applicazione cambiata: il valore non vale più
            }
            $out[$row->key] = $value;
        }

        return $out;
    }

    /**
     * Valori per la pagina: i segreti solo come «impostato».
     *
     * @return array<string, array{label: string, secret: bool, set: bool, value: string}>
     */
    public function forPage(): array
    {
        $all = $this->all();
        $out = [];
        foreach (self::KEYS as $key => [$label, $secret]) {
            $out[$key] = ['label' => $label, 'secret' => $secret, 'set' => isset($all[$key]), 'value' => $secret ? '' : ($all[$key] ?? '')];
        }

        return $out;
    }

    /**
     * Salva le impostazioni di una sezione. Un segreto vuoto resta com'è; per toglierlo si passa in $clear.
     *
     * @param  array<string, string|null>  $values
     * @param  list<string>  $clear
     * @return list<string> chiavi cambiate
     */
    public function save(array $values, array $clear, ?string $userId): array
    {
        $errors = [];
        foreach ($values as $key => $value) {
            if (! isset(self::KEYS[$key])) {
                throw new InvalidArgumentException('Impostazione sconosciuta.');
            }
            $value = trim((string) $value);
            if ($value !== '' && ($e = self::check(self::KEYS[$key][2], $value)) !== null) {
                $errors[] = self::KEYS[$key][0].': '.$e;
            }
        }
        if ($errors !== []) {
            throw new InvalidArgumentException(implode(' ', $errors));
        }

        $table = $this->db->connection('pgsql')->table('core.platform_settings');
        $changed = [];
        foreach ($values as $key => $value) {
            $value = trim((string) $value);
            $secret = self::KEYS[$key][1];
            if ($value === '') {
                if (! $secret || in_array($key, $clear, true)) {
                    if ((clone $table)->where('key', $key)->delete() > 0) {
                        $changed[] = $key;
                    }
                }

                continue;
            }
            (clone $table)->upsert([['key' => $key, 'value_enc' => $this->encrypter()->encryptString($value), 'updated_by' => $userId, 'updated_at' => now()]], ['key'], ['value_enc', 'updated_by', 'updated_at']);
            $changed[] = $key;
        }

        return $changed;
    }

    private static function check(string $kind, string $v): ?string
    {
        return match ($kind) {
            'host' => preg_match('/^[A-Za-z0-9.\-]{1,253}$/', $v) === 1 ? null : 'non è un nome di server valido.',
            'port' => ctype_digit($v) && (int) $v >= 1 && (int) $v <= 65535 ? null : 'porta non valida.',
            'port0' => ctype_digit($v) && (int) $v <= 65535 ? null : 'porta non valida.',
            'encryption' => in_array($v, ['tls', 'ssl', 'nessuna'], true) ? null : 'scrivi tls, ssl o nessuna.',
            'email' => filter_var($v, FILTER_VALIDATE_EMAIL) !== false ? null : 'email non valida.',
            'https' => str_starts_with($v, 'https://') && filter_var($v, FILTER_VALIDATE_URL) !== false ? null : 'serve un indirizzo https://.',
            'base64' => base64_decode($v, true) !== false ? null : 'non è in base64.',
            'nameservers' => self::checkNameservers($v),
            'hours' => ctype_digit($v) && (int) $v >= 1 && (int) $v <= 720 ? null : 'scrivi un numero di ore da 1 a 720.',
            'store' => in_array($v, ['auto', 'redis', 'database', 'file'], true) ? null : 'scegli auto, redis, database o file.',
            'path' => str_starts_with($v, '/') && mb_strlen($v) <= 200 ? null : 'scrivi un percorso che inizia con /.',
            'key' => str_contains($v, 'PRIVATE KEY') ? null : 'incolla il testo della chiave privata (-----BEGIN … PRIVATE KEY-----).',
            default => mb_strlen($v) <= 300 ? null : 'troppo lungo.',
        };
    }

    private static function checkNameservers(string $v): ?string
    {
        $list = preg_split('/[\s,]+/', $v, -1, PREG_SPLIT_NO_EMPTY);
        if ($list === false || $list === [] || count($list) > 8) {
            return 'scrivi da 1 a 8 nameserver, separati da virgola.';
        }
        foreach ($list as $ns) {
            if (preg_match('/^(?=.{4,253}$)([A-Za-z0-9]([A-Za-z0-9-]{0,61}[A-Za-z0-9])?\.)+[A-Za-z]{2,63}\.?$/', $ns) !== 1) {
                return "«{$ns}» non è un nome di server valido.";
            }
        }

        return null;
    }
}
