<?php

namespace Tests\Feature\Site;

use App\Models\Article;
use App\Models\Topic;
use App\Support\SaasGuides;
use Tests\SiteTestCase;

class SaasGuidesSwitchTest extends SiteTestCase
{
    /** @return array{0: Article, 1: Article} guida del plugin (con rimando al gestionale) e guida del gestionale */
    private function guides(): array
    {
        $plugin = Topic::create(['name' => 'Soci', 'slug' => 'soci', 'is_public' => true, 'product' => 'plugin']);
        $saas = Topic::create(['name' => 'Gestionale', 'slug' => 'gestionale', 'is_public' => true, 'product' => 'saas']);
        $a = $plugin->sections()->create(['name' => 'Elenco', 'slug' => 'elenco'])->articles()->create([
            'title' => 'Elenco dei soci', 'slug' => 'elenco-soci', 'is_published' => true,
            'body' => "> Questa guida vale per il **plugin WordPress**. Usi il gestionale online? Vai alle [guide del gestionale](/argomenti/gestionale).\n\nTesto con [la guida](guida:libro-soci) e [altro](guida:elenco-soci).",
        ]);
        $b = $saas->sections()->create(['name' => 'Gestione', 'slug' => 'gestione'])->articles()->create([
            'title' => 'Libro soci online', 'slug' => 'libro-soci', 'is_published' => true, 'body' => 'Il libro soci del gestionale.',
        ]);

        return [$a, $b];
    }

    public function test_hidden_by_default_for_the_public(): void
    {
        [$plugin, $saas] = $this->guides();

        $this->get('/')->assertOk()->assertSee('Soci')->assertDontSee('Gestionale');
        $this->get('/argomenti/gestionale')->assertRedirect('/');
        $this->get('/articoli/'.$saas->id)->assertRedirect('/');
        $this->get('/cerca?q=libro')->assertOk()->assertDontSee('Libro soci online');
        $this->get('/articoli/'.$plugin->id)->assertOk()
            ->assertDontSee('guide del gestionale')->assertDontSee('/argomenti/gestionale')->assertDontSee('la guida</a>')->assertSee('la guida')->assertSee('altro</a>', false);
    }

    public function test_visible_when_switched_on_and_staff_always_sees_them(): void
    {
        [$plugin, $saas] = $this->guides();

        $this->actingAs($this->admin())->get('/argomenti/gestionale')->assertOk();
        $this->actingAs($this->admin())->get('/articoli/'.$saas->id)->assertOk();

        $this->post('/esci');
        SaasGuides::set(true);
        $this->get('/')->assertOk()->assertSee('Gestionale');
        $this->get('/articoli/'.$saas->id)->assertOk();
        $this->get('/cerca?q=libro')->assertSee('Libro soci online');
        $this->get('/articoli/'.$plugin->id)->assertSee('guide del gestionale')->assertSee('la guida</a>', false);
    }

    public function test_admin_switches_with_one_click_and_it_is_recorded(): void
    {
        [, $saas] = $this->guides();
        $admin = $this->admin();

        $this->actingAs($admin)->get('/admin/argomenti')->assertSee('nascoste al pubblico');
        $this->actingAs($admin)->post('/admin/argomenti/guide-gestionale', ['on' => '1'])->assertSessionHas('ok');
        $this->assertTrue(SaasGuides::enabled());
        $this->actingAs($admin)->post('/admin/argomenti/guide-gestionale', ['on' => '0']);
        $this->assertFalse(SaasGuides::enabled());
        $this->assertDatabaseHas('audit_logs', ['action' => 'admin.saas_guides_toggled']);
    }
}
