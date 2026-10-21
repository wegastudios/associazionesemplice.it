<?php

namespace App\Http\Controllers\Admin\Site;

use App\Http\Controllers\Controller;
use App\Models\Topic;
use App\Support\Slug;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** Argomenti del centro assistenza (con le loro sezioni). */
class TopicController extends Controller
{
    public function index(): View
    {
        return view('admin.site.topics', ['topics' => Topic::query()->orderBy('sort')->orderBy('name')->with('sections')->get()]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);
        Topic::create($data + ['slug' => Slug::unique(Topic::query(), $data['name'])]);

        return back()->with('ok', 'Argomento creato.');
    }

    public function update(Request $request, Topic $topic): RedirectResponse
    {
        $data = $this->validated($request);
        $topic->update($data + ['slug' => Slug::unique(Topic::query(), $data['name'], $topic->id)]);

        return back()->with('ok', 'Argomento salvato.');
    }

    public function destroy(Topic $topic): RedirectResponse
    {
        // Elimina anche sezioni e articoli dell'argomento (cascata): i ticket restano, senza argomento
        $topic->delete();

        return back()->with('ok', 'Argomento eliminato.');
    }

    /** @return array{name: string, description: ?string, sort: int, is_public: bool} */
    private function validated(Request $request): array
    {
        $data = $request->validate([
            'name' => 'required|string|max:120',
            'description' => 'nullable|string|max:1000',
            'sort' => 'nullable|integer|min:0|max:65000',
        ]);

        return [
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'sort' => (int) ($data['sort'] ?? 0),
            'is_public' => $request->boolean('is_public'),
        ];
    }
}
