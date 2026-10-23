<?php

namespace Tests\Feature\Site;

use App\Models\Article;
use App\Models\Topic;
use Database\Seeders\GuideContentSeeder;
use Tests\SiteTestCase;

class GuideContentTest extends SiteTestCase
{
    private function seedGuides(): void
    {
        $this->seed(GuideContentSeeder::class);
    }

    public function test_guides_are_loaded_with_unique_slugs_and_known_topics(): void
    {
        $guides = (new GuideContentSeeder)->guides();
        $slugs = array_column($guides, 'slug');

        $this->assertSame($slugs, array_values(array_unique($slugs)), 'gli slug delle guide devono essere unici');
        foreach ($guides as $guide) {
            $this->assertArrayHasKey($guide['topic'], GuideContentSeeder::TOPICS, $guide['slug']);
            $this->assertNotSame('', trim($guide['title']));
            $this->assertGreaterThan(200, mb_strlen($guide['body']), $guide['slug'].' e troppo corto');
        }
    }

    public function test_there_are_exactly_25_faq_with_the_comparison_table(): void
    {
        $this->seedGuides();

        $faq = Article::query()->whereHas('section.topic', fn ($t) => $t->where('slug', 'domande-frequenti'))->get();
        $this->assertCount(25, $faq);

        $table = $faq->firstWhere('slug', 'free-pro-contabile-e-pro-fiscale-a-confronto');
        $this->assertNotNull($table);
        $this->assertStringContainsString('| Funzione | Free | Pro Contabile | Pro Fiscale |', $table->body);
    }

    public function test_every_guide_reference_points_to_an_existing_guide(): void
    {
        $this->seedGuides();
        $slugs = Article::query()->pluck('slug')->all();

        foreach (Article::all() as $article) {
            preg_match_all('/\(guida:([a-z0-9-]+)\)/', $article->body, $m);
            foreach ($m[1] as $ref) {
                $this->assertContains($ref, $slugs, "{$article->slug} rimanda a una guida che non esiste: {$ref}");
            }
        }
    }

    public function test_seeding_twice_does_not_duplicate_anything(): void
    {
        $this->seedGuides();
        $count = Article::count();
        $topics = Topic::count();

        $this->seedGuides();

        $this->assertSame($count, Article::count());
        $this->assertSame($topics, Topic::count());
    }

    public function test_every_article_page_renders(): void
    {
        $this->seedGuides();

        foreach (Article::all() as $article) {
            $this->get('/articoli/'.$article->id)->assertOk();
        }
    }

    public function test_comparison_table_is_rendered_as_a_table_and_links_resolve(): void
    {
        $this->seedGuides();
        $table = Article::where('slug', 'free-pro-contabile-e-pro-fiscale-a-confronto')->firstOrFail();
        $target = Article::where('slug', 'attivare-stripe-o-paypal')->firstOrFail();

        $this->get('/articoli/'.$table->id)
            ->assertOk()
            ->assertSee('<th>Pro Contabile</th>', false)
            ->assertSee('<a href="'.route('help.article', $target->id).'">Attivare i pagamenti online</a>', false);
    }

    public function test_search_finds_guides(): void
    {
        $this->seedGuides();

        $this->get('/cerca?q=Stripe')->assertOk()->assertSee('Attivare i pagamenti online con Stripe o PayPal');
    }
}
