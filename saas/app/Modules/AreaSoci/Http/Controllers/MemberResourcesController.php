<?php

declare(strict_types=1);

namespace App\Modules\AreaSoci\Http\Controllers;

use App\Modules\AreaSoci\Services\CurrentMember;
use App\Modules\AreaSoci\Services\MemberPage;
use App\Modules\Contabilita\Contracts\Money;
use App\Modules\Core\Contracts\Input;
use App\Modules\Risorse\Contracts\MemberResources;
use App\Modules\Risorse\Contracts\ResourceError;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/** Area soci: le risorse prenotabili online (campi, sale), le fasce libere, le prenotazioni del socio. */
final readonly class MemberResourcesController
{
    public function __construct(
        private MemberResources $resources,
        private CurrentMember $current,
        private MemberPage $memberPage,
    ) {}

    public function index(Request $request): Response
    {
        $list = $this->resources->available();
        if ($list === []) {
            abort(404);
        }
        $wanted = $request->string('risorsa')->toString();
        $chosen = null;
        foreach ($list as $r) {
            if ($r['id'] === $wanted) {
                $chosen = $r;
            }
        }
        $chosen ??= $list[0];
        $today = now('Europe/Rome')->toDateString();
        $date = $request->string('giorno')->toString();
        $date = preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) === 1 && strtotime($date) !== false && $date >= $today ? $date : $today;

        return Inertia::render('AreaSoci/Risorse', [
            'member' => $this->memberPage->props(),
            'resources' => array_map(static fn (array $r): array => $r + ['fee_label' => Money::format($r['fee'])], $list),
            'chosen' => $chosen['id'],
            'date' => $date,
            'today' => $today,
            'slots' => array_map(static fn (array $s): array => $s + ['price_label' => Money::format($s['price'])], $this->resources->slots($chosen['id'], $date)),
            'dayFree' => $chosen['typology'] === 'daily' ? $this->resources->dayFree($chosen['id'], $date) : null,
            'mine' => array_map(static fn (array $x): array => $x + [
                'when' => $x['ends_at'] !== null && substr($x['starts_at'], 0, 10) === substr($x['ends_at'], 0, 10)
                    ? date('d/m/Y H:i', (int) strtotime($x['starts_at'])).'–'.date('H:i', (int) strtotime($x['ends_at']))
                    : 'dal '.date('d/m/Y', (int) strtotime($x['starts_at'])),
                'fee_label' => Money::format($x['fee']), 'due' => max(0, $x['fee'] - $x['paid']),
            ], $this->resources->mine([$this->current->active()])),
        ]);
    }

    public function book(Request $request, string $resource): RedirectResponse
    {
        $data = Input::of($request->validate([
            'date' => ['required', 'date_format:Y-m-d'],
            'start' => ['nullable', 'date_format:H:i'],
            'units' => ['nullable', 'integer', 'between:1,48'],
            'guests' => ['nullable', 'string', 'max:500'],
        ]));
        $guests = array_values(array_filter(array_map('trim', preg_split('/[\n,]/u', Input::optionalString($data, 'guests') ?? '') ?: []), static fn (string $g): bool => $g !== ''));
        try {
            $this->resources->book($resource, $this->current->active(), [
                'date' => Input::string($data, 'date'), 'start' => Input::optionalString($data, 'start') ?? '',
                'units' => is_numeric($data['units'] ?? null) ? (int) $data['units'] : 1, 'guests' => $guests,
            ]);
        } catch (ResourceError $e) {
            throw ValidationException::withMessages(['ledger' => $e->getMessage()]);
        }

        return back()->with('status', 'Prenotazione registrata: puoi pagarla da «Paga» o in segreteria.');
    }

    public function cancel(string $reservation): RedirectResponse
    {
        try {
            $this->resources->cancel($reservation, $this->current->active());
        } catch (ResourceError $e) {
            throw ValidationException::withMessages(['ledger' => $e->getMessage()]);
        }

        return back()->with('status', 'Prenotazione annullata.');
    }
}
