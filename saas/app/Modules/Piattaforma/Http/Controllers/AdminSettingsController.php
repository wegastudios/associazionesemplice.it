<?php

declare(strict_types=1);

namespace App\Modules\Piattaforma\Http\Controllers;

use App\Modules\Core\Contracts\PlatformSettingsStore;
use App\Modules\Piattaforma\Admin\PlatformAudit;
use App\Modules\Piattaforma\Models\PlatformStaff;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use InvalidArgumentException;

/**
 * Impostazioni della vendita che l'amministratore può cambiare senza toccare il server: oggi i nameserver dei domini
 * personalizzati. Le altre impostazioni della piattaforma (posta, backup, chiavi, cache) restano nel file d'ambiente.
 * Si vedono nel registro solo le chiavi cambiate, mai i valori.
 */
final readonly class AdminSettingsController
{
    private const PREFIX = 'domains.';

    public function __construct(private PlatformSettingsStore $settings, private PlatformAudit $audit) {}

    public function show(): View
    {
        return $this->page();
    }

    public function save(Request $request): RedirectResponse|View
    {
        $staff = Auth::guard('platform')->user();
        abort_unless($staff instanceof PlatformStaff && $staff->canManageUsers(), 403, 'Solo l\'amministratore cambia le impostazioni.');

        $values = [];
        foreach (array_keys($this->fields()) as $key) {
            $value = $request->input('settings.'.str_replace('.', '_', $key));
            $values[$key] = is_string($value) ? $value : '';
        }

        try {
            $changed = $this->settings->save($values, [], null);
        } catch (InvalidArgumentException $e) {
            return $this->page($e->getMessage(), $values);
        }

        if ($changed !== []) {
            $this->audit->record('settings.updated', $staff->id, null, ['keys' => $changed], $request);
        }

        return redirect()->route('platform.settings')->with('status', $changed === [] ? 'Nessuna modifica.' : 'Impostazioni salvate.');
    }

    /**
     * @param  array<string, string>|null  $sent  valori appena inviati (se il salvataggio è fallito)
     */
    private function page(?string $problem = null, ?array $sent = null): View
    {
        $fields = $this->fields();
        if ($sent !== null) {
            foreach ($fields as $key => $field) {
                $fields[$key]['value'] = $sent[$key] ?? '';
            }
        }

        $staff = Auth::guard('platform')->user();

        return view('admin.settings', [
            'fields' => $fields,
            'problem' => $problem,
            'canEdit' => $staff instanceof PlatformStaff && $staff->canManageUsers(),
        ]);
    }

    /** @return array<string, array{label: string, secret: bool, set: bool, value: string}> */
    private function fields(): array
    {
        return array_filter($this->settings->forPage(), static fn (string $key): bool => str_starts_with($key, self::PREFIX), ARRAY_FILTER_USE_KEY);
    }
}
