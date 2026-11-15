<?php

declare(strict_types=1);

namespace App\Modules\Contabilita\Http\Controllers;

use App\Modules\Core\Contracts\Input;
use App\Modules\Core\Contracts\OrgFiscal;
use App\Modules\Core\Contracts\OrgSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/** Aspetto fiscale: le aliquote IVA, se i prezzi sono finali o IVA esclusa, l'aliquota della quota associativa. Solo con la partita IVA. */
final readonly class FiscalSettingsController
{
    public function __construct(
        private OrgFiscal $fiscal,
        private OrgSettings $settings,
    ) {}

    public function show(): Response
    {
        return Inertia::render('Contabilita/AspettoFiscale', [
            'hasVat' => $this->fiscal->hasVat(),
            'rates' => implode(', ', $this->fiscal->rates()),
            'includesVat' => $this->fiscal->includesVat(),
            'membershipRate' => $this->fiscal->membershipRate() ?? 0,
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        if (! $this->fiscal->hasVat()) {
            throw ValidationException::withMessages(['rates' => 'Senza partita IVA l\'IVA non si applica: non c\'è nulla da impostare.']);
        }
        $data = Input::of($request->validate([
            'rates' => ['nullable', 'string', 'max:60'],
            'includes_vat' => ['boolean'],
            'membership_rate' => ['nullable', 'integer', 'min:0', 'max:30'],
        ]));
        $raw = Input::optionalString($data, 'rates') ?? '';
        $rates = OrgFiscal::parse($raw);
        if (preg_replace('/[\s,;]+/', '', $raw) !== '' && $rates === []) {
            throw ValidationException::withMessages(['rates' => 'Scrivi le aliquote come numeri da 1 a 30, separati da virgole (per esempio 22, 10, 5, 4).']);
        }
        $membership = is_numeric($data['membership_rate'] ?? null) ? (int) $data['membership_rate'] : 0;
        if ($membership !== 0 && ! in_array($membership, $rates, true)) {
            throw ValidationException::withMessages(['membership_rate' => 'L\'aliquota della quota associativa deve essere «IVA esente» o una di quelle dell\'elenco.']);
        }
        $this->settings->set(OrgFiscal::RATES, implode(',', $rates));
        $this->settings->set(OrgFiscal::INCLUDES_VAT, Input::bool($data, 'includes_vat', true));
        $this->settings->set(OrgFiscal::MEMBERSHIP_RATE, $membership);

        return back()->with('status', 'Aspetto fiscale salvato.');
    }
}
