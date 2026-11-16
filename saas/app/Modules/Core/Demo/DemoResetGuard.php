<?php

declare(strict_types=1);

namespace App\Modules\Core\Demo;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

/**
 * Controlli prima di azzerare la demo: il comando cancella tutto il database e tutti i file, quindi si ferma
 * se non è davvero nell'ambiente della demo (database atteso, nessuna associazione estranea, cartella «saas-demo»).
 */
final class DemoResetGuard
{
    /**
     * Motivo del rifiuto, o null se l'azzeramento è sicuro.
     *
     * @param  list<string>  $slugs  associazioni presenti nel database
     */
    public static function refusal(string $database, string $expectedDatabase, array $slugs, string $demoSlug, string $path, string $marker): ?string
    {
        if ($expectedDatabase === '') {
            return 'Manca SAAS_DEMO_DATABASE: scrivi nel .env della demo il nome del suo database, così l\'azzeramento sa dove può lavorare.';
        }
        if ($database !== $expectedDatabase) {
            return "Il database collegato non è quello della demo (atteso «{$expectedDatabase}», collegato «{$database}»): nessun azzeramento.";
        }
        $extra = array_values(array_filter($slugs, static fn (string $slug): bool => $slug !== $demoSlug));
        if ($extra !== []) {
            return 'Nel database ci sono associazioni che non sono la demo ('.implode(', ', array_slice($extra, 0, 3)).'): nessun azzeramento.';
        }
        if (! self::under($path, $marker)) {
            return "L'applicazione non sta in una cartella «{$marker}»: nessun azzeramento.";
        }

        return null;
    }

    /** Controlli con i valori dell'ambiente in cui gira il comando. */
    public static function check(): ?string
    {
        $slugs = [];
        // Schema non ancora creato (primo avvio): non c'è niente da proteggere.
        if (DB::scalar("SELECT to_regclass('core.orgs')") !== null) {
            $slugs = array_values(array_map('strval', DB::table('core.orgs')->pluck('slug')->all()));
        }
        $expected = config('saas.demo.database');
        $slug = config('saas.demo.org_slug');
        $marker = config('saas.demo.path_marker');

        return self::refusal(DB::connection()->getDatabaseName(), is_string($expected) ? $expected : '', $slugs, is_string($slug) ? $slug : 'polisportiva-demo', base_path(), is_string($marker) ? $marker : 'saas-demo');
    }

    /**
     * Svuota la cartella dei file delle associazioni (la radice resta): ogni azzeramento crea un'associazione nuova
     * e la cartella della precedente non servirebbe più a nessuno. Solo se la radice sta sotto la cartella della demo.
     *
     * @return int|null file e cartelle tolti, null se la radice non sta nella cartella della demo (non si tocca)
     */
    public static function clearFiles(string $root, string $marker): ?int
    {
        if ($root === '' || ! self::under($root, $marker) || ! is_dir($root)) {
            return null;
        }
        $removed = 0;
        foreach (File::directories($root) as $dir) {
            File::deleteDirectory($dir);
            $removed++;
        }
        foreach (File::files($root, true) as $file) {
            File::delete($file->getPathname());
            $removed++;
        }

        return $removed;
    }

    private static function under(string $path, string $marker): bool
    {
        $segments = explode('/', str_replace('\\', '/', $path));

        return $marker !== '' && in_array($marker, $segments, true);
    }
}
