<?php

namespace Tests\Feature\Site;

use App\Models\Article;
use App\Models\Topic;
use Tests\SiteTestCase;

class HelpCenterTest extends SiteTestCase
{
    private function content(): array
    {
        $public = Topic::create(['name' => 'Accesso', 'slug' => 'accesso', 'is_public' => true]);
        $private = Topic::create(['name' => 'Interno', 'slug' => 'interno', 'is_public' => false]);
        $section = $public->sections()->create(['name' => 'Password', 'slug' => 'password']);
        $hiddenSection = $private->sections()->create(['name' => 'Segreta', 'slug' => 'segreta']);

        return [
            $public,
            $section->articles()->create(['title' => 'Reimposta la password', 'slug' => 'reimposta', 'body' => 'Clicca su password dimenticata.', 'is_published' => true]),
            $section->articles()->create(['title' => 'Bozza', 'slug' => 'bozza', 'body' => 'non pubblicata', 'is_published' => false]),
            $hiddenSection->articles()->create(['title' => 'Guida interna', 'slug' => 'interna', 'body' => 'password admin', 'is_published' => true]),
        ];
    }

    public function test_home_lists_only_public_topics(): void
    {
        $this->content();

        $this->get('/')->assertOk()->assertSee('Accesso')->assertDontSee('Interno');
    }

    public function test_topic_page_lists_published_articles_only(): void
    {
        [$topic] = $this->content();

        $this->get('/argomenti/'.$topic->slug)->assertOk()->assertSee('Reimposta la password')->assertDontSee('Bozza');
    }

    public function test_private_topic_is_not_reachable(): void
    {
        $this->content();

        $this->get('/argomenti/interno')->assertNotFound();
    }

    public function test_draft_and_private_articles_are_not_reachable(): void
    {
        [, $published, $draft, $internal] = $this->content();

        $this->get('/articoli/'.$published->id)->assertOk()->assertSee('Clicca su password dimenticata.');
        $this->get('/articoli/'.$draft->id)->assertNotFound();
        $this->get('/articoli/'.$internal->id)->assertNotFound();
    }

    public function test_search_finds_published_public_articles_only(): void
    {
        $this->content();

        $this->get('/cerca?q=password')->assertOk()->assertSee('Reimposta la password')->assertDontSee('Guida interna')->assertDontSee('Bozza');
        $this->assertSame(3, Article::count());
    }

    public function test_search_ignores_wildcards_typed_by_the_visitor(): void
    {
        $this->content();

        $this->get('/cerca?q=%25%25')->assertOk()->assertDontSee('Reimposta la password');
    }

    public function test_ticket_form_loads_without_hub(): void
    {
        $this->get('/ticket/nuovo')->assertOk()->assertSee('Apri una richiesta');
    }
}
