<?php

namespace App\Http\Controllers\Admin\Site;

use App\Http\Controllers\Controller;
use App\Models\Plan;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** Piani del portale: gestiti qui, in totale autonomia (l'hub riceve solo il nome sul ticket). */
class PlanController extends Controller
{
    public function index(): View
    {
        return view('admin.site.plans', ['plans' => Plan::query()->orderBy('sort')->orderBy('name')->get()]);
    }

    public function store(Request $request): RedirectResponse
    {
        Plan::create($this->validated($request));

        return back()->with('ok', 'Piano creato.');
    }

    public function update(Request $request, Plan $plan): RedirectResponse
    {
        $plan->update($this->validated($request));

        return back()->with('ok', 'Piano salvato.');
    }

    public function destroy(Plan $plan): RedirectResponse
    {
        $plan->delete();

        return back()->with('ok', 'Piano eliminato: i ticket esistenti restano senza piano.');
    }

    /** @return array{name: string, sort: int, is_active: bool} */
    private function validated(Request $request): array
    {
        $data = $request->validate(['name' => 'required|string|max:120', 'sort' => 'nullable|integer|min:0|max:65000']);

        return ['name' => $data['name'], 'sort' => (int) ($data['sort'] ?? 0), 'is_active' => $request->boolean('is_active')];
    }
}
