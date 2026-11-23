<?php

declare(strict_types=1);

namespace App\Modules\Core\Export;

use App\Modules\Core\Contracts\DataExport;
use App\Modules\Core\Contracts\Operator;
use App\Modules\Core\Contracts\ReadableExport;
use Closure;
use Illuminate\Contracts\Container\Container;
use RuntimeException;
use Throwable;
use ZipArchive;

/**
 * Copia di sicurezza: un archivio ZIP con due parti. «Dati per il programma» (un file CSV per tabella, per riportare i
 * dati nel plugin o nel gestionale) e «Documenti leggibili» (PDF e tabelle per anno fiscale, da consegnare). Contiene
 * quello che l'operatore può vedere. I certificati medici in chiaro solo se chi scarica lo chiede e può vedere i dati
 * sanitari. Si costruisce su disco un file alla volta: ogni FLUSH_EVERY file l'archivio si chiude e si riapre, così i
 * file di lavoro si tolgono subito e in memoria non resta niente. La prepara il lavoro in coda (FullExports).
 */
final readonly class FullExport
{
    public const DATA = 'Dati per il programma';

    public const READABLE = 'Documenti leggibili';

    public const CERTIFICATES = 'Certificati medici';

    private const FLUSH_EVERY = 200;

    public function __construct(private Container $container) {}

    /**
     * Con $year la copia ha i documenti leggibili di quell'anno fiscale soltanto: il limite vale per tutti gli anni insieme.
     *
     * @throws ExportTooLarge
     */
    public function assertFits(Operator $operator, bool $withCertificates = false, ?int $year = null): void
    {
        if ($year !== null) {
            return;
        }
        $heavy = 0;
        foreach ($this->container->tagged(ReadableExport::TAG) as $part) {
            $heavy += $part instanceof ReadableExport ? $part->weight($operator, $withCertificates, null) : 0;
        }
        if ($heavy > ReadableExport::MAX_HEAVY_FILES) {
            throw new ExportTooLarge('Questa associazione ha molti documenti: una copia unica rischia di non finire. Scegli un anno fiscale e scarica una copia per anno.');
        }
    }

    /**
     * Scrive la copia in $path (che non deve esistere). $progress riceve i passi fatti e il totale.
     *
     * @param  (Closure(int, int): void)|null  $progress
     * @return array{files: int, certificates: int}
     */
    public function build(Operator $operator, string $orgName, string $path, bool $withCertificates = false, ?int $year = null, ?Closure $progress = null): array
    {
        $data = [];
        foreach ($this->container->tagged(DataExport::TAG) as $part) {
            if ($part instanceof DataExport) {
                $data += $part->files($operator);
            }
        }
        ksort($data);

        // Un'unica lista: nome nell'archivio => contenuto o chiusura che lo produce. Le chiusure si valutano in ordine.
        $entries = [];
        foreach ($data as $name => $content) {
            $entries[self::DATA.'/'.$name] = $content;
        }
        unset($data);
        foreach ($this->container->tagged(ReadableExport::TAG) as $part) {
            if ($part instanceof ReadableExport) {
                foreach ($part->files($operator, $withCertificates, $year) as $name => $producer) {
                    $entries[self::READABLE.'/'.$name] = $producer;
                }
            }
        }

        $work = $path.'.parti';
        if (! mkdir($work, 0700, true)) {
            throw new RuntimeException('Cartella di lavoro non disponibile.');
        }
        $zip = new ZipArchive;
        $open = false;
        $total = count($entries) + 1;
        $done = 0;
        $count = 0;
        $certificates = 0;
        $pending = [];
        try {
            if ($zip->open($path, ZipArchive::CREATE | ZipArchive::EXCL) !== true) {
                throw new RuntimeException('Archivio non disponibile.');
            }
            $open = true;
            foreach ($entries as $name => $content) {
                $body = is_string($content) ? $content : $content();
                $done++;
                if ($body !== null) {
                    $file = $work.'/'.$done;
                    if (file_put_contents($file, $body) === false) {
                        throw new RuntimeException('File di lavoro non scritto.');
                    }
                    unset($body);
                    $zip->addFile($file, $name);
                    // Foto, scansioni e archivi sono già compressi: ricomprimerli costa tempo e non fa guadagnare spazio.
                    if (preg_match('/\.(jpe?g|png|webp|gif|heic|zip)$/i', $name) === 1) {
                        $zip->setCompressionName($name, ZipArchive::CM_STORE);
                    }
                    $pending[] = $file;
                    $count++;
                    $certificates += str_starts_with($name, self::READABLE.'/'.self::CERTIFICATES.'/') ? 1 : 0;
                }
                if (count($pending) >= self::FLUSH_EVERY) {
                    $open = false;
                    self::flush($zip, $path, $pending);
                    $open = true;
                }
                if ($progress !== null) {
                    $progress($done, $total);
                }
            }
            $zip->addFromString('LEGGIMI.txt', self::readme($orgName, $withCertificates));
            $open = false;
            if (! $zip->close()) {
                throw new RuntimeException('Archivio non scritto.');
            }
        } catch (Throwable $e) {
            if ($open) {
                @$zip->close();
            }
            @unlink($path);
            throw $e;
        } finally {
            foreach (glob($work.'/*') ?: [] as $f) {
                @unlink($f);
            }
            @rmdir($work);
        }
        chmod($path, 0600);
        if ($progress !== null) {
            $progress($total, $total);
        }

        return ['files' => $count + 1, 'certificates' => $certificates];
    }

    /**
     * Chiude l'archivio (i file aggiunti finiscono dentro), toglie i file di lavoro e lo riapre per i prossimi.
     *
     * @param  list<string>  $pending
     */
    private static function flush(ZipArchive $zip, string $path, array &$pending): void
    {
        if (! $zip->close()) {
            throw new RuntimeException('Archivio non scritto.');
        }
        foreach ($pending as $file) {
            @unlink($file);
        }
        $pending = [];
        if ($zip->open($path) !== true) {
            throw new RuntimeException('Archivio non disponibile.');
        }
    }

    private static function readme(string $orgName, bool $withCertificates): string
    {
        return implode("\r\n", [
            'Copia di sicurezza di '.$orgName,
            'Creata il '.now()->format('d/m/Y').' alle '.now()->format('H:i').'.',
            '',
            'Contiene dati personali: conservala con cura ed eliminala quando non serve più.',
            '',
            'Come si aprono i file: i PDF con qualsiasi lettore PDF; le tabelle .csv con Excel, LibreOffice o simili (codifica UTF-8, separatore punto e virgola).',
            '',
            'Cartelle:',
            '',
            '«'.self::DATA.'»',
            'Tutti i dati dell\'associazione, un file CSV per ogni tabella (soci, tessere, prima nota, conti, attività, operatori, impostazioni, registro delle azioni).',
            'Servono per riportare i dati nel plugin o nel gestionale Associazione Semplice: la prima nota e l\'anagrafica si ricaricano dalle pagine «Importa».',
            '',
            '«'.self::READABLE.'»',
            'Una cartella per ogni anno fiscale con il rendiconto, la prima nota, il libro soci e le ricevute di quell\'anno. Gli anni chiusi sono com\'erano alla chiusura.',
            'Servono per leggere, stampare e consegnare i documenti senza alcun programma (a un revisore, al commercialista, ai soci).',
            '',
            $withCertificates
                ? 'In «'.self::CERTIFICATES.'» ci sono i certificati in chiaro: sono dati sulla salute, da conservare con cura e consegnare solo a chi ne ha titolo.'
                : 'I certificati medici non sono in questa copia.',
            '',
        ]);
    }
}
