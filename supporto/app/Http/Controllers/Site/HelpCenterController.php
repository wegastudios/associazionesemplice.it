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
            ->with('section.topic')->limit(60)->get()
            ->map(function (Article $a) use ($q) {
                $plain = trim((string) preg_replace('/\s+/', ' ', strip_tags(str_replace(['**', '__', '#', '`', '|'], ' ', (string) $a->body))));
                $pos = mb_stripos($plain, $q);
                $a->search_score = (mb_stripos($a->title, $q) !== false ? 100 : 0) + min(20, mb_substr_count(mb_strtolower($plain), mb_strtolower($q)));
                $a->search_snippet = $pos === false ? '' : (($pos > 60 ? '…' : '').mb_substr($plain, max(0, $pos - 60), 170).'…');

                return $a;
            })
            ->sort(fn ($a, $b) => [$b->search_score, $a->title] <=> [$a->search_score, $b->title])
            ->take(30)->values();

        return view('site.search', ['q' => $q, 'results' => $results]);
    }
}
