<?php

namespace App\Http\Controllers\Site;

use App\Http\Controllers\Controller;
use App\Models\Article;
use App\Models\Topic;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** Centro assistenza pubblico: argomenti, sezioni, articoli, ricerca. */
class HelpCenterController extends Controller
{
    public function index(): View
    {
        $topics = Topic::query()->where('is_public', true)->orderBy('sort')->orderBy('name')
            ->with(['sections' => fn ($q) => $q->withCount(['articles' => fn ($a) => $a->where('is_published', true)])])
            ->get();

        return view('site.index', ['topics' => $topics]);
    }

    public function topic(Topic $topic): View
    {
        abort_unless($topic->is_public, 404);

        $topic->load(['sections.articles' => fn ($q) => $q->where('is_published', true)]);

        return view('site.topic', ['topic' => $topic]);
    }

    public function article(Article $article): View
    {
        abort_unless($article->is_published && $article->section->topic->is_public, 404);

        return view('site.article', ['article' => $article->load('section.topic')]);
    }

    public function search(Request $request): View
    {
        $q = trim((string) $request->query('q', ''));
        $escaped = addcslashes($q, '%_\\');

        $results = mb_strlen($q) < 2 ? collect() : Article::query()
            ->where('is_published', true)
            ->whereHas('section.topic', fn ($t) => $t->where('is_public', true))
            ->where(fn ($w) => $w->where('title', 'like', "%{$escaped}%")->orWhere('body', 'like', "%{$escaped}%"))
            ->with('section.topic')->orderBy('title')->limit(30)->get();

        return view('site.search', ['q' => $q, 'results' => $results]);
    }
}
