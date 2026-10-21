<?php

namespace App\Http\Controllers\Admin\Site;

use App\Http\Controllers\Controller;
use App\Models\Section;
use App\Models\Topic;
use App\Support\Slug;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class SectionController extends Controller
{
    public function store(Request $request, Topic $topic): RedirectResponse
    {
        $data = $this->validated($request);
        $topic->sections()->create($data + ['slug' => Slug::unique($topic->sections()->getQuery(), $data['name'])]);

        return back()->with('ok', 'Sezione creata.');
    }

    public function update(Request $request, Section $section): RedirectResponse
    {
        $data = $this->validated($request);
        $section->update($data + ['slug' => Slug::unique(Section::query()->where('topic_id', $section->topic_id), $data['name'], $section->id)]);

        return back()->with('ok', 'Sezione salvata.');
    }

    public function destroy(Section $section): RedirectResponse
    {
        $section->delete();

        return back()->with('ok', 'Sezione eliminata con i suoi articoli.');
    }

    /** @return array{name: string, sort: int} */
    private function validated(Request $request): array
    {
        $data = $request->validate(['name' => 'required|string|max:120', 'sort' => 'nullable|integer|min:0|max:65000']);

        return ['name' => $data['name'], 'sort' => (int) ($data['sort'] ?? 0)];
    }
}
