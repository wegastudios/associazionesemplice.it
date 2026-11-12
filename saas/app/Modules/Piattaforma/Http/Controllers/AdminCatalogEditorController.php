<?php

declare(strict_types=1);

namespace App\Modules\Piattaforma\Http\Controllers;

use App\Modules\Piattaforma\Catalogo\CatalogDraftEditor;
use App\Modules\Piattaforma\Catalogo\CatalogForm;
use App\Modules\Piattaforma\Catalogo\CatalogPublisher;
use App\Modules\Piattaforma\Catalogo\PriceCalculator;
use App\Modules\Piattaforma\Contracts\Catalog;
use App\Modules\Piattaforma\Models\PlatformStaff;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use InvalidArgumentException;

/**
 * Editor a moduli di piani, pacchetti (addon) e promozioni: lavora sulla bozza del catalogo, la stessa del JSON, e ogni
 * salvataggio passa dal validatore. Le letture le vede anche il «solo lettura»; salvare solo chi può scrivere.
 */
final readonly class AdminCatalogEditorController
{
    /** Soci per cui l'anteprima dei prezzi calcola il totale. */
    private const PREVIEW_MEMBERS = [30, 50, 150, 400, 800];

    public function __construct(private CatalogDraftEditor $editor, private CatalogPublisher $publisher, private Catalog $catalog) {}

    // ───────────────────────────── Piani ─────────────────────────────

    public function plans(): View
    {
        $document = $this->editor->document();
        $published = $this->catalog->published();

        $plans = [];
        foreach ($this->items($document, 'plans') as $plan) {
            $code = is_string($plan['code'] ?? null) ? $plan['code'] : '';
            $version = is_int($plan['plan_version'] ?? null) ? $plan['plan_version'] : 0;
            $plans[$code][] = $plan + ['in_vigore' => $published->plan($code, $version) !== null];
        }

        return view('admin.plans', ['plans' => $plans, 'hasDraft' => $this->hasDraft()]);
    }

    public function planNew(): View
    {
        return view('admin.plan-form', $this->planView(null, null, true));
    }

    public function planCreate(Request $request): RedirectResponse|View
    {
        $input = $this->input($request);
        $code = $request->string('code')->toString();
        try {
            $this->editor->savePlan($code, $input, $this->staffId(), true);
        } catch (InvalidArgumentException $e) {
            return view('admin.plan-form', $this->planView($code, $input, true) + ['problem' => $e->getMessage()]);
        }

        return redirect(AdminLoginController::url('catalogo/piani/'.$code))->with('status', 'Piano creato nella bozza.');
    }

    public function planEdit(string $code): View
    {
        abort_if($this->editor->latestPlan($code) === null, 404);

        return view('admin.plan-form', $this->planView($code, null, false));
    }

    public function planSave(Request $request, string $code): RedirectResponse|View
    {
        abort_if($this->editor->latestPlan($code) === null, 404);
        $input = $this->input($request);
        try {
            $version = $this->editor->savePlan($code, $input, $this->staffId());
        } catch (InvalidArgumentException $e) {
            return view('admin.plan-form', $this->planView($code, $input, false) + ['problem' => $e->getMessage()]);
        }

        return redirect(AdminLoginController::url('catalogo/piani/'.$code))
            ->with('status', "Piano salvato nella bozza (versione {$version}). Per renderlo visibile al portale vai al catalogo e premi «Pubblica».");
    }

    // ───────────────────────── Pacchetti e servizi ─────────────────────────

    public function addons(): View
    {
        $addons = $this->items($this->editor->document(), 'addons');
        $published = $this->catalog->published();
        foreach ($addons as $i => $addon) {
            $code = is_string($addon['code'] ?? null) ? $addon['code'] : '';
            $addons[$i]['in_vigore'] = $published->addon($code) !== null;
            $addons[$i]['riepilogo'] = $this->priceSummary($addon['prices'] ?? null);
        }

        return view('admin.addons', ['addons' => $addons, 'hasDraft' => $this->hasDraft()]);
    }

    public function addonNew(): View
    {
        return view('admin.addon-form', $this->addonView(null, null, true));
    }

    public function addonCreate(Request $request): RedirectResponse|View
    {
        $input = $this->input($request);
        $code = $request->string('code')->toString();
        try {
            $this->editor->saveAddon($code, $input, $this->staffId(), true);
        } catch (InvalidArgumentException $e) {
            return view('admin.addon-form', $this->addonView($code, $input, true) + ['problem' => $e->getMessage()]);
        }

        return redirect(AdminLoginController::url('catalogo/pacchetti'))->with('status', 'Pacchetto creato nella bozza.');
    }

    public function addonEdit(string $code): View
    {
        abort_if($this->addon($code) === null, 404);

        return view('admin.addon-form', $this->addonView($code, null, false));
    }

    public function addonSave(Request $request, string $code): RedirectResponse|View
    {
        abort_if($this->addon($code) === null, 404);
        $input = $this->input($request);
        try {
            $this->editor->saveAddon($code, $input, $this->staffId());
        } catch (InvalidArgumentException $e) {
            return view('admin.addon-form', $this->addonView($code, $input, false) + ['problem' => $e->getMessage()]);
        }

        return redirect(AdminLoginController::url('catalogo/pacchetti'))->with('status', 'Pacchetto salvato nella bozza.');
    }

    // ───────────────────────────── Promozioni ─────────────────────────────

    public function promos(): View
    {
        return view('admin.promos', $this->promosView($this->promoRows($this->items($this->editor->document(), 'promos'))));
    }

    public function promosSave(Request $request): RedirectResponse|View
    {
        $rows = $request->input('promos');
        $rows = is_array($rows) ? $rows : [];
        try {
            $this->editor->savePromos($rows, $this->staffId());
        } catch (InvalidArgumentException $e) {
            return view('admin.promos', $this->promosView($rows) + ['problem' => $e->getMessage()]);
        }

        return redirect(AdminLoginController::url('catalogo/promozioni'))->with('status', 'Promozioni salvate nella bozza.');
    }

    // ───────────────────────────── Viste ─────────────────────────────

    /**
     * @param  array<string, mixed>|null  $input  campi appena inviati (se il salvataggio è fallito)
     * @return array<string, mixed>
     */
    private function planView(?string $code, ?array $input, bool $isNew): array
    {
        $document = $this->editor->document();
        $plan = $code === null ? null : $this->editor->latestPlan($code);
        $limits = is_array($plan['limits'] ?? null) ? $plan['limits'] : [];
        $planFeatures = is_array($plan['features'] ?? null) ? $plan['features'] : [];
        $sentFeatures = is_array($input['features'] ?? null) ? $input['features'] : [];
        $features = [];
        foreach ($this->items($document, 'features') as $f) {
            $id = is_string($f['id'] ?? null) ? $f['id'] : '';
            $state = is_array($planFeatures[$id] ?? null) ? $planFeatures[$id] : ['state' => 'excluded'];
            $features[] = ['id' => $id, 'text' => $f['text'] ?? $id, 'group' => $f['group'] ?? '', 'state' => $state['state'] ?? 'excluded', 'limit_text' => $state['text'] ?? ''];
        }

        $values = [
            'code' => $code ?? '',
            'name' => $plan['name'] ?? '',
            'audience' => $plan['audience'] ?? '',
            'order' => $plan['order'] ?? 1,
            'visible' => ($plan['visible'] ?? true) === true,
            'support_level' => $plan['support_level'] ?? 'paid',
            'trial_days' => $plan['trial_days'] ?? 0,
            'limits' => [
                'members' => $limits['members'] ?? '',
                'operators' => $limits['operators'] ?? 1,
                'storage_mb_per_member' => $limits['storage_mb_per_member'] ?? 5,
                'storage_mb_min' => $limits['storage_mb_min'] ?? 250,
            ],
        ];
        $rows = CatalogForm::priceRows($plan['prices'] ?? []);

        if ($input !== null) {
            $values = [
                'code' => $code ?? '',
                'name' => $input['name'] ?? '',
                'audience' => $input['audience'] ?? '',
                'order' => $input['order'] ?? '',
                'visible' => ($input['visible'] ?? null) === '1',
                'support_level' => $input['support_level'] ?? '',
                'trial_days' => $input['trial_days'] ?? '',
                'limits' => is_array($input['limits'] ?? null) ? $input['limits'] : [],
            ];
            $rows = is_array($input['prices'] ?? null) ? array_values($input['prices']) : [];
            foreach ($features as $i => $f) {
                $sent = is_array($sentFeatures[$f['id']] ?? null) ? $sentFeatures[$f['id']] : [];
                $features[$i]['state'] = $sent['state'] ?? 'excluded';
                $features[$i]['limit_text'] = $sent['text'] ?? '';
            }
        }

        $published = $plan !== null && is_int($plan['plan_version'] ?? null) && $this->catalog->plan((string) $code, $plan['plan_version']) !== null;

        return [
            'isNew' => $isNew,
            'values' => $values,
            'features' => $features,
            'priceRows' => $rows,
            'version' => is_int($plan['plan_version'] ?? null) ? $plan['plan_version'] : 1,
            'willCreateVersion' => $published,
            'preview' => $this->preview($plan['prices'] ?? []),
            'canWrite' => $this->canWrite(),
        ];
    }

    /**
     * @param  array<string, mixed>|null  $input
     * @return array<string, mixed>
     */
    private function addonView(?string $code, ?array $input, bool $isNew): array
    {
        $addon = $code === null ? null : $this->addon($code);
        $grants = is_array($addon['grants'] ?? null) ? $addon['grants'] : [];
        $limits = $grants['limits'] ?? [];

        $values = [
            'code' => $code ?? '',
            'name' => $addon['name'] ?? '',
            'kind' => $addon['kind'] ?? 'package',
            'visible' => ($addon['visible'] ?? true) === true,
            'grant_operators' => $grants['operators'] ?? '',
            'grant_storage_gb' => $grants['storage_gb'] ?? '',
            'grant_package' => $grants['package'] ?? '',
            'grant_limits' => $limits === [] ? '' : json_encode($limits, JSON_UNESCAPED_UNICODE),
        ];
        $rows = CatalogForm::priceRows($addon['prices'] ?? []);
        if ($input !== null) {
            $values = [
                'code' => $code ?? '',
                'name' => $input['name'] ?? '',
                'kind' => $addon['kind'] ?? ($input['kind'] ?? 'package'),
                'visible' => ($input['visible'] ?? null) === '1',
                'grant_operators' => $input['grant_operators'] ?? '',
                'grant_storage_gb' => $input['grant_storage_gb'] ?? '',
                'grant_package' => $input['grant_package'] ?? '',
                'grant_limits' => $input['grant_limits'] ?? '',
            ];
            $rows = is_array($input['prices'] ?? null) ? array_values($input['prices']) : [];
        }

        return [
            'isNew' => $isNew,
            'values' => $values,
            'priceRows' => $rows,
            'preview' => $this->preview($addon['prices'] ?? []),
            'canWrite' => $this->canWrite(),
        ];
    }

    /**
     * @param  array<int|string, mixed>  $rows
     * @return array<string, mixed>
     */
    private function promosView(array $rows): array
    {
        $plans = [];
        foreach ($this->items($this->editor->document(), 'plans') as $plan) {
            if (is_string($plan['code'] ?? null)) {
                $plans[$plan['code']] = is_string($plan['name'] ?? null) ? $plan['name'] : $plan['code'];
            }
        }

        $defaults = ['code' => '', 'name' => '', 'type' => 'percent', 'value' => '', 'plans' => [], 'valid_from' => '', 'valid_to' => '', 'max_uses' => ''];
        $normalized = [];
        foreach ($rows as $row) {
            if (is_array($row)) {
                $merged = $row + $defaults;
                $merged['plans'] = is_array($merged['plans']) ? $merged['plans'] : [];
                $normalized[] = $merged;
            }
        }

        return ['rows' => $normalized, 'plans' => $plans, 'canWrite' => $this->canWrite()];
    }

    /**
     * @param  list<array<string, mixed>>  $promos
     * @return list<array<string, mixed>>
     */
    private function promoRows(array $promos): array
    {
        $rows = [];
        foreach ($promos as $p) {
            $discount = is_array($p['discount'] ?? null) ? $p['discount'] : [];
            $type = $discount['type'] ?? 'percent';
            $rows[] = [
                'code' => $p['code'] ?? '',
                'name' => $p['name'] ?? '',
                'type' => $type,
                'value' => $type === 'amount_cents' ? CatalogForm::euros($discount['value'] ?? null) : ($discount['value'] ?? ''),
                'plans' => is_array($p['plans'] ?? null) ? $p['plans'] : [],
                'valid_from' => $p['valid_from'] ?? '',
                'valid_to' => $p['valid_to'] ?? '',
                'max_uses' => $p['max_uses'] ?? '',
            ];
        }

        return $rows;
    }

    /**
     * Quanto costa per alcuni numeri di soci (mensile e annuale, IVA esclusa): la stessa regola del portale.
     *
     * @return list<array{members: int, month: string, year: string}>
     */
    private function preview(mixed $prices): array
    {
        $month = null;
        $year = null;
        foreach (is_array($prices) ? $prices : [] as $price) {
            if (is_array($price) && ($price['kind'] ?? null) === 'members_tiers') {
                $month = ($price['period'] ?? null) === 'month' ? $price : $month;
                $year = ($price['period'] ?? null) === 'year' ? $price : $year;
            }
        }
        if ($month === null && $year === null) {
            return [];
        }

        $rows = [];
        foreach (self::PREVIEW_MEMBERS as $members) {
            $rows[] = [
                'members' => $members,
                'month' => $month === null ? '—' : CatalogForm::euros(PriceCalculator::members(self::map($month), $members)),
                'year' => $year === null ? '—' : CatalogForm::euros(PriceCalculator::members(self::map($year), $members)),
            ];
        }

        return $rows;
    }

    /**
     * @param  array<mixed>  $value
     * @return array<string, mixed>
     */
    private static function map(array $value): array
    {
        /** @var array<string, mixed> $value */
        return $value;
    }

    private function priceSummary(mixed $prices): string
    {
        $parts = [];
        foreach (is_array($prices) ? $prices : [] as $p) {
            if (! is_array($p)) {
                continue;
            }
            $per = ($p['period'] ?? null) === 'year' ? 'anno' : 'mese';
            $parts[] = match ($p['kind'] ?? null) {
                'flat' => CatalogForm::euros($p['amount_cents'] ?? null)." €/{$per}",
                'members_tiers' => 'da '.CatalogForm::euros($p['base_cents'] ?? null)." €/{$per} + soci",
                default => "a scatti/{$per}",
            };
        }

        return implode(' · ', $parts);
    }

    // ───────────────────────────── Utilità ─────────────────────────────

    /** @return array<string, mixed>|null */
    private function addon(string $code): ?array
    {
        foreach ($this->items($this->editor->document(), 'addons') as $addon) {
            if (($addon['code'] ?? null) === $code) {
                return $addon;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $document
     * @return list<array<string, mixed>>
     */
    private function items(array $document, string $key): array
    {
        $out = [];
        foreach (is_array($document[$key] ?? null) ? $document[$key] : [] as $item) {
            if (is_array($item)) {
                /** @var array<string, mixed> $item */
                $out[] = $item;
            }
        }

        return $out;
    }

    /** @return array<string, mixed> */
    private function input(Request $request): array
    {
        /** @var array<string, mixed> */
        return $request->except(['_token', 'code']);
    }

    private function hasDraft(): bool
    {
        return $this->publisher->hasDraft();
    }

    private function canWrite(): bool
    {
        $staff = Auth::guard('platform')->user();

        return $staff instanceof PlatformStaff && $staff->canWrite();
    }

    private function staffId(): string
    {
        $staff = Auth::guard('platform')->user();

        return $staff instanceof PlatformStaff ? $staff->id : '';
    }
}
