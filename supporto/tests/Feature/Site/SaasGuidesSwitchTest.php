<?php

namespace Tests\Feature\Site;

use App\Models\Article;
use App\Models\Topic;
use App\Support\SaasGuides;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
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

        Auth::logout();
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

    public function test_plugin_guides_switch_is_on_by_default_and_can_be_hidden(): void
    {
        [$plugin] = $this->guides();

        $this->get('/articoli/'.$plugin->id)->assertOk();
        $this->actingAs($this->admin())->post('/admin/argomenti/guide-gestionale', ['product' => 'plugin', 'on' => '0'])->assertSessionHas('ok');
        $this->assertFalse(SaasGuides::enabled('plugin'));
        $this->assertFalse(SaasGuides::enabled('saas'));
        $this->get('/articoli/'.$plugin->id)->assertOk();

        Auth::logout();
        $this->get('/articoli/'.$plugin->id)->assertRedirect('/');
        $this->get('/')->assertOk()->assertDontSee('Soci');
    }

    public function test_saas_guides_follow_the_portal_when_it_is_configured(): void
    {
        [, $saas] = $this->guides();
        SaasGuides::set(true);
        config(['support.portal_status_url' => 'https://portale.test/stato']);

        Http::fake(['portale.test/*' => Http::sequence()->push(['saas_sale' => false])->push(['saas_sale' => true])->push('', 500)]);
        Cache::forget('portal-saas-sale');
        $this->get('/articoli/'.$saas->id)->assertRedirect('/');

        Cache::forget('portal-saas-sale');
        $this->get('/articoli/'.$saas->id)->assertOk();

        // Portale non raggiungibile: vale l'interruttore locale
        Cache::forget('portal-saas-sale');
        Http::fake(['portale.test/*' => Http::response('', 500)]);
        SaasGuides::set(false);
        $this->get('/articoli/'.$saas->id)->assertRedirect('/');
    }
}
