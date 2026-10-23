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
        'domande-frequenti' => ['Domande frequenti', 'Le risposte rapide, con il confronto tra Free, Pro Contabile e Pro Fiscale.'],
        'altro' => ['Altro', 'Privacy e dati personali.'],
    ];

    public function run(): void
    {
        $sort = 0;
        $topics = [];
        foreach (self::TOPICS as $slug => [$name, $description]) {
            $topics[$slug] = Topic::query()->updateOrCreate(
                ['slug' => $slug],
                ['name' => $name, 'description' => $description, 'sort' => $sort++, 'is_public' => true],
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

            Article::query()->updateOrCreate(
                ['section_id' => $section->id, 'slug' => $guide['slug']],
                ['title' => $guide['title'], 'body' => trim($guide['body']), 'is_published' => true],
            );
        }
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
