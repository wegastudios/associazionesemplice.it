<?php

namespace Database\Seeders;

use App\Models\Article;
use App\Models\Section;
use App\Models\Topic;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Guide e domande frequenti del centro assistenza. I testi stanno in database/guides/part*.php.
 * Si puo rilanciare quando i testi cambiano: argomenti, sezioni e articoli si riconoscono dal nome breve (slug) e si aggiornano.
 */
class GuideContentSeeder extends Seeder
{
    /** @var array<string, array{0: string, 1: string}> slug => [nome, descrizione] in ordine di visualizzazione */
    public const TOPICS = [
        'installazione' => ['Installazione', 'Installare il plugin, la configurazione guidata, le pagine del sito e le copie di sicurezza.'],
        'licenza' => ['Licenza', 'Chiave gratuita, piani Pro, rinnovi e cambio di dominio.'],
        'soci-e-tessere' => ['Soci e tessere', 'Importare i soci, tessere e QR, ospiti, ruoli e area riservata.'],
        'corsi-ed-eventi' => ['Corsi ed eventi', 'Creare corsi ed eventi, ingressi, avvisi e comunicazioni.'],
        'pagamenti' => ['Pagamenti', 'Incassi, spese, prima nota, pagamenti online e ricevute.'],
        'plugin-pro' => ['Plugin Pro', 'Conti e fondi, fiscalità, registri, app, wallet e personalizzazione.'],
        'domande-frequenti' => ['Domande frequenti', 'Le risposte rapide, con il confronto tra Free, {piano_contabile} e {piano_fiscale}.'],
        'altro' => ['Altro', 'Privacy e dati personali.'],
        'gestionale' => ['Gestionale online', 'Guide del gestionale online: soci, soldi, attività, pagamenti online e operatori.'],
    ];

    /** Argomenti del gestionale online (prodotto saas): tutti gli altri sono del plugin. */
    public const SAAS_TOPICS = ['gestionale'];

    /** @var array<string, string> articoli rinominati: lo slug vecchio si elimina, cosi non restano doppioni */
    public const RENAMED = ['anni-solari-e-rendiconto' => 'rendiconto-per-cassa-e-report'];

    public function run(): void
    {
        Article::query()->whereIn('slug', array_keys(self::RENAMED))->delete();

        $sort = 0;
        $topics = [];
        foreach (self::TOPICS as $slug => [$name, $description]) {
            $topics[$slug] = Topic::query()->updateOrCreate(
                ['slug' => $slug],
                ['name' => $name, 'description' => $this->planNames($description), 'sort' => $sort++, 'is_public' => true, 'product' => in_array($slug, self::SAAS_TOPICS, true) ? 'saas' : 'plugin'],
            );
        }

        $sectionSort = [];
        foreach ($this->guides() as $guide) {
            $topic = $topics[$guide['topic']];
            $sectionSlug = Str::slug($guide['section']);

            $sectionSort[$topic->id] ??= 0;
            $section = Section::query()->firstOrNew(['topic_id' => $topic->id, 'slug' => $sectionSlug]);
            if (! $section->exists) {
                $section->forceFill(['name' => $guide['section'], 'sort' => $sectionSort[$topic->id]++])->save();
            }

            // Se una guida cambia sezione non resta il doppione nella vecchia
            Article::query()->where('slug', $guide['slug'])->where('section_id', '!=', $section->id)->delete();

            Article::query()->updateOrCreate(
                ['section_id' => $section->id, 'slug' => $guide['slug']],
                ['title' => $this->planNames($guide['title']), 'body' => $this->planNames($this->pluginNotice($guide['topic'], trim($guide['body']))), 'is_published' => true],
            );
        }

        // Sezioni rimaste vuote dopo uno spostamento
        Section::query()->doesntHave('articles')->delete();
    }

    /** In cima alle guide del plugin: chi usa il gestionale online trova la strada per le sue guide. */
    private function pluginNotice(string $topic, string $body): string
    {
        if (in_array($topic, self::SAAS_TOPICS, true) || $topic === 'domande-frequenti') {
            return $body;
        }

        return "> Questa guida vale per il **plugin WordPress**. Usi il gestionale online? Vai alle [guide del gestionale](/argomenti/gestionale).\n\n".$body;
    }

    /** Sostituisce {piano_contabile} e {piano_fiscale} con i nomi in config('support.plan_names'). */
    private function planNames(string $text): string
    {
        return strtr($text, [
            '{piano_contabile}' => (string) config('support.plan_names.contabile'),
            '{piano_fiscale}' => (string) config('support.plan_names.fiscale'),
            '{piano_piccola}' => (string) config('support.plan_names.piccola'),
            '{piano_media}' => (string) config('support.plan_names.media'),
            '{piano_grande}' => (string) config('support.plan_names.grande'),
        ]);
    }

    /** @return array<int, array{topic: string, section: string, slug: string, title: string, body: string}> */
    public function guides(): array
    {
        $all = [];
        foreach (glob(database_path('guides/part*.php')) ?: [] as $file) {
            $all = array_merge($all, require $file);
        }

        return $all;
    }
}
