<?php

namespace App\Services;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

/**
 * Impostazioni della posta in uscita. Valori predefiniti: mittente noreply@dominio e server mail.dominio;
 * si possono modificare dal pannello (sezione Email) e vengono salvati nel database.
 */
class MailSettings
{
    public const KEYS = ['host', 'port', 'encryption', 'username', 'password', 'from_address', 'from_name'];

    public static function domain(): string
    {
        return (string) env('MAIL_DOMAIN', 'wegastudios.com');
    }

    /** @return array<string, ?string> */
    public function defaults(): array
    {
        return [
            'host' => 'mail.'.self::domain(),
            'port' => '587',
            'encryption' => 'starttls',
            'username' => null,
            'password' => null,
            'from_address' => 'noreply@'.self::domain(),
            'from_name' => 'Associazione Semplice',
        ];
    }

    /** Valori in uso: predefiniti, sovrascritti da quanto salvato. La password e restituita in chiaro solo da decryptedPassword(). */
    public function current(): array
    {
        $values = $this->defaults();
        foreach (DB::table('settings')->where('key', 'like', 'mail.%')->get() as $row) {
            $key = substr($row->key, 5);
            if (in_array($key, self::KEYS, true) && $row->value !== null && $row->value !== '') {
                $values[$key] = $row->value;
            }
        }

        return $values;
    }

    public function hasPassword(): bool
    {
        return DB::table('settings')->where('key', 'mail.password')->whereNotNull('value')->exists();
    }

    /** @param  array<string, ?string>  $data  password vuota = invariata */
    public function save(array $data): void
    {
        foreach (self::KEYS as $key) {
            if (! array_key_exists($key, $data)) {
                continue;
            }
            $value = $data[$key];
            if ($key === 'password') {
                if ($value === null || $value === '') {
                    continue;
                }
                $value = Crypt::encryptString($value);
            }
            DB::table('settings')->updateOrInsert(['key' => 'mail.'.$key], ['value' => $value, 'updated_at' => now(), 'created_at' => now()]);
        }
        $this->apply();
    }

    /** Applica le impostazioni solo se salvate dal pannello; altrimenti resta la configurazione dell'ambiente. */
    public function applyIfConfigured(): void
    {
        if (DB::table('settings')->where('key', 'mail.host')->exists()) {
            $this->apply();
        }
    }

    /** Applica le impostazioni alla configurazione della posta. */
    public function apply(): void
    {
        $v = $this->current();
        $password = null;
        $stored = DB::table('settings')->where('key', 'mail.password')->value('value');
        if ($stored) {
            try {
                $password = Crypt::decryptString($stored);
            } catch (\Throwable) {
                $password = null;
            }
        }

        Config::set('mail.default', 'smtp');
        Config::set('mail.mailers.smtp', [
            'transport' => 'smtp',
            'scheme' => $v['encryption'] === 'ssl' ? 'smtps' : 'smtp',
            'host' => $v['host'],
            'port' => (int) $v['port'],
            'username' => $v['username'],
            'password' => $password,
            'timeout' => 15,
        ]);
        Config::set('mail.from', ['address' => $v['from_address'], 'name' => $v['from_name']]);
        app()->forgetInstance('mail.manager');
        app()->forgetInstance('mailer');
    }
}
