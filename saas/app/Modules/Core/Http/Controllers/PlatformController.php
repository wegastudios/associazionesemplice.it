<?php

declare(strict_types=1);

namespace App\Modules\Core\Http\Controllers;

use App\Modules\Audit\Contracts\SecurityLog;
use App\Modules\Cifratura\Contracts\KeyVault;
use App\Modules\Core\Platform\PlatformSettings;
use Illuminate\Contracts\Cache\Factory as Cache;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Mail\Message;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/** Amministrazione della piattaforma: posta, spazio dei backup, servizio chiavi e cache, con la prova di ciascuno. */
final readonly class PlatformController
{
    private const SECTIONS = ['mail', 'backup', 'keys', 'cache'];

    public function __construct(
        private PlatformSettings $settings,
        private SecurityLog $log,
    ) {}

    public function show(): Response
    {
        $warnings = config('saas.platform_warnings');

        return Inertia::render('Admin/Piattaforma', [
            'settings' => $this->settings->forPage(),
            'warnings' => is_array($warnings) ? array_values(array_filter($warnings, is_string(...))) : [],
            'effective' => [
                'mail' => config('mail.default') === 'smtp' ? 'SMTP '.self::str(config('mail.mailers.smtp.host')) : 'nel log (non partono)',
                'backup' => config('filesystems.disks.backups.driver') === 'sftp' ? 'SFTP '.self::str(config('filesystems.disks.backups.host')) : 'sul server',
                'keys' => config('saas.crypto.vault') === 'remote' ? 'servizio chiavi '.self::str(config('saas.crypto.service_url')) : (config('saas.crypto.trial') === true ? 'chiave locale (solo prova)' : 'chiave locale'),
                'cache' => self::str(config('cache.default')),
            ],
        ]);
    }

    public function save(Request $request, string $section): RedirectResponse
    {
        abort_unless(in_array($section, self::SECTIONS, true), 404);
        $keys = array_values(array_filter(array_keys(PlatformSettings::KEYS), static fn (string $k): bool => str_starts_with($k, $section.'.')));
        $values = [];
        foreach ($keys as $k) {
            $v = $request->input(str_replace('.', '__', $k));
            $values[$k] = is_scalar($v) ? (string) $v : null;
        }
        $clear = array_values(array_filter(array_map(static fn (mixed $k): string => is_string($k) ? str_replace('__', '.', $k) : '', (array) $request->input('clear', [])), static fn (string $k): bool => in_array($k, $keys, true)));

        try {
            $changed = $this->settings->save($values, $clear, self::str($request->user()?->getAuthIdentifier()));
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['ledger' => $e->getMessage()]);
        }
        if ($changed !== []) {
            // Nel registro solo i nomi delle impostazioni, mai i valori.
            $this->log->record('platform.settings_changed', self::str($request->user()?->getAuthIdentifier()), ['keys' => implode(',', $changed)]);
        }

        return back()->with('status', $changed === [] ? 'Nessuna modifica.' : 'Impostazioni salvate: valgono dalla prossima richiesta.');
    }

    /** Prova la sezione con le impostazioni in vigore. */
    public function test(Request $request, string $section, KeyVault $vault, Cache $cache): RedirectResponse
    {
        abort_unless(in_array($section, self::SECTIONS, true), 404);
        try {
            match ($section) {
                'mail' => Mail::raw('Prova della posta di Associazione Semplice: se la leggi, la configurazione funziona.', static function (Message $m) use ($request): void {
                    $m->to(self::str($request->user()?->email))->subject('Prova della posta');
                }),
                'backup' => self::roundTrip(),
                'keys' => (static function () use ($vault): void {
                    $key = random_bytes(32);
                    $org = '00000000-0000-7000-8000-000000000000';
                    if ($vault->unwrap($org, 1, $vault->wrap($org, 1, $key)) !== $key) {
                        throw new RuntimeException('risposta diversa');
                    }
                })(),
                default => (static function () use ($cache): void {
                    $store = $cache->store();
                    $store->put('platform-test', 'ok', 10);
                    if ($store->pull('platform-test') !== 'ok') {
                        throw new RuntimeException('lettura diversa');
                    }
                })(),
            };
        } catch (Throwable $e) {
            report($e);
            throw ValidationException::withMessages(['ledger' => 'La prova non è riuscita: '.mb_substr(preg_replace('/\s+/', ' ', $e->getMessage()) ?? '', 0, 200)]);
        }

        return back()->with('status', $section === 'mail' ? 'Email di prova mandata a '.self::str($request->user()?->email).'.' : 'Prova riuscita.');
    }

    private static function roundTrip(): void
    {
        $path = '.prova/'.bin2hex(random_bytes(8));
        $disk = Storage::disk('backups');
        $disk->put($path, 'ok');
        $ok = $disk->get($path) === 'ok';
        $disk->delete($path);
        if (! $ok) {
            throw new RuntimeException('lettura diversa dalla scrittura');
        }
    }

    private static function str(mixed $v): string
    {
        return is_scalar($v) ? (string) $v : '';
    }
}
