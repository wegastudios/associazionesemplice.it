<?php

namespace App\Http\Controllers\Admin\Site;

use App\Http\Controllers\Controller;
use App\Models\Article;
use App\Models\Section;
use App\Support\Slug;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ArticleController extends Controller
{
    public function index(): View
    {
        return view('admin.site.articles.index', ['articles' => Article::query()->with('section.topic')->orderByDesc('updated_at')->paginate(30)]);
    }

    public function create(): View
    {
        return view('admin.site.articles.form', ['article' => new Article(['is_published' => false]), 'sections' => $this->sections()]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);
        $article = Article::create($data + ['slug' => Slug::unique(Article::query()->where('section_id', $data['section_id']), $data['title'])]);

        return redirect()->route('admin.articles.edit', $article)->with('ok', 'Articolo creato.');
    }

    public function edit(Article $article): View
    {
        return view('admin.site.articles.form', ['article' => $article, 'sections' => $this->sections()]);
    }

    public function update(Request $request, Article $article): RedirectResponse
    {
        $data = $this->validated($request);
        $article->update($data + ['slug' => Slug::unique(Article::query()->where('section_id', $data['section_id']), $data['title'], $article->id)]);

        return back()->with('ok', 'Articolo salvato.');
    }

    public function destroy(Article $article): RedirectResponse
    {
        $article->delete();

        return redirect()->route('admin.articles.index')->with('ok', 'Articolo eliminato.');
    }

    private function sections()
    {
        return Section::query()->with('topic')->get()->sortBy(fn (Section $s) => $s->topic->name.' '.$s->name);
    }

    /** @return array{section_id: int, title: string, body: string, is_published: bool} */
    private function validated(Request $request): array
    {
        $data = $request->validate([
            'section_id' => 'required|integer|exists:sections,id',
            'title' => 'required|string|max:200',
            'body' => 'required|string|max:50000',
        ]);

        return $data + ['is_published' => $request->boolean('is_published')];
    }
}
