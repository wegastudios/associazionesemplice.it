<?php

namespace Tests\Feature\Site;

use App\Models\Article;
use App\Models\Topic;
use App\Support\ArticleBody;
use Tests\SiteTestCase;

class ArticleBodyTest extends SiteTestCase
{
    public function test_html_in_the_text_is_shown_as_text(): void
    {
        $html = ArticleBody::html("<script>alert(1)</script> e <b>grassetto</b>\n\n## <img src=x onerror=alert(1)>");

        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringNotContainsString('<img', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
    }

    public function test_headings_lists_and_emphasis(): void
    {
        $html = ArticleBody::html("## Titolo\n\n1. Primo\n2. Secondo con **grassetto**\n\n- punto\n- altro `codice`");

        $this->assertStringContainsString('<h2>Titolo</h2>', $html);
        $this->assertStringContainsString('<ol><li>Primo</li><li>Secondo con <strong>grassetto</strong></li></ol>', $html);
        $this->assertStringContainsString('<ul><li>punto</li><li>altro <code>codice</code></li></ul>', $html);
    }

    public function test_table_with_header_and_separator(): void
    {
        $html = ArticleBody::html("| Funzione | Free |\n|---|---|\n| Soci | Si |");

        $this->assertStringContainsString('<th>Funzione</th><th>Free</th>', $html);
        $this->assertStringContainsString('<td>Soci</td><td>Si</td>', $html);
        $this->assertStringNotContainsString('---', $html);
    }

    public function test_only_http_and_site_links_become_links(): void
    {
        $html = ArticleBody::html('[sito](https://example.it/a?x=1&y=2) [rel](/ticket/nuovo) [brutto](javascript:alert(1))');

        $this->assertStringContainsString('<a href="https://example.it/a?x=1&amp;y=2" target="_blank" rel="noopener">sito</a>', $html);
        $this->assertStringContainsString('<a href="/ticket/nuovo">rel</a>', $html);
        $this->assertStringNotContainsString('href="javascript', $html);
    }

    public function test_quote_and_paragraphs(): void
    {
        $html = ArticleBody::html("> Nota importante\n\nPrima riga\nseconda riga");

        $this->assertStringContainsString('<blockquote><p>Nota importante</p></blockquote>', $html);
        $this->assertStringContainsString('<p>Prima riga seconda riga</p>', $html);
    }

    public function test_article_page_renders_the_formatted_body(): void
    {
        $topic = Topic::create(['name' => 'Guide', 'slug' => 'guide', 'is_public' => true]);
        $section = $topic->sections()->create(['name' => 'Prime', 'slug' => 'prime']);
        $article = $section->articles()->create(['title' => 'Prova', 'slug' => 'prova', 'is_published' => true, 'body' => "## Passi\n\n1. Uno\n2. Due"]);

        $this->get('/articoli/'.$article->id)->assertOk()->assertSee('<ol><li>Uno</li><li>Due</li></ol>', false);
        $this->assertInstanceOf(Article::class, $article);
    }
}
