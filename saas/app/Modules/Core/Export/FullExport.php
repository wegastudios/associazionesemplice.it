<?php

declare(strict_types=1);

namespace App\Modules\Core\Export;

use App\Modules\Audit\Contracts\AuditLog;
use App\Modules\Core\Contracts\DataExport;
use App\Modules\Core\Contracts\Operator;
use App\Modules\Core\Contracts\ReadableExport;
use Illuminate\Contracts\Container\Container;
use RuntimeException;
use Throwable;
use ZipArchive;

/**
 * Copia di sicurezza: un archivio ZIP con due parti. «Dati per il programma» (un file CSV per tabella, per riportare i
 * dati nel plugin o nel gestionale) e «Documenti leggibili» (PDF e tabelle per anno fiscale, da consegnare). Contiene
 * quello che l'operatore può vedere. I certificati medici in chiaro solo se chi scarica lo chiede e può vedere i dati
 * sanitari. Si costruisce su disco, un file alla volta, in una cartella privata; l'archivio si cancella dopo lo scarico.
 */
final readonly class FullExport
{
    public const DATA = 'Dati per il programma';

    public const READABLE = 'Documenti leggibili';

    public const CERTIFICATES = 'Certificati medici';

    public function __construct(
        private Container $container,
        private AuditLog $audit,
    ) {}

    /** @return array{path: string, files: int, certificates: int} */
    public function build(Operator $operator, string $orgName, bool $withCertificates = false): array
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
        foreach ($this->container->tagged(ReadableExport::TAG) as $part) {
            if ($part instanceof ReadableExport) {
                foreach ($part->files($operator, $withCertificates) as $name => $producer) {
                    $entries[self::READABLE.'/'.$name] = $producer;
                }
            }
        }

        $dir = storage_path('app/tmp');
        if (! is_dir($dir) && ! mkdir($dir, 0700, true) && ! is_dir($dir)) {
            throw new RuntimeException('Cartella temporanea non disponibile.');
        }
        $work = $dir.'/export-'.bin2hex(random_bytes(16));
        if (! mkdir($work, 0700)) {
            throw new RuntimeException('Cartella temporanea non disponibile.');
        }
        $path = $work.'.zip';
        $zip = new ZipArchive;
        $count = 0;
        $certificates = 0;
        try {
            if ($zip->open($path, ZipArchive::CREATE | ZipArchive::EXCL) !== true) {
                throw new RuntimeException('Archivio non disponibile.');
            }
            // Ogni file passa dal disco: l'archivio li legge alla chiusura, in memoria non resta niente.
            $tmp = [];
            $n = 0;
            foreach ($entries as $name => $content) {
                $body = is_string($content) ? $content : $content();
                if ($body === null) {
                    continue;
                }
                $file = $work.'/'.(++$n);
                file_put_contents($file, $body);
                unset($body);
                $zip->addFile($file, $name);
                $tmp[] = $file;
                $count++;
                $certificates += str_starts_with($name, self::READABLE.'/'.self::CERTIFICATES.'/') ? 1 : 0;
            }
            $readme = self::readme($orgName, $withCertificates);
            $zip->addFromString('LEGGIMI.txt', $readme);
            $zip->close();
            foreach ($tmp as $file) {
                @unlink($file);
            }
        } catch (Throwable $e) {
            @$zip->close();
            @unlink($path);
            throw $e;
        } finally {
            foreach (glob($work.'/*') ?: [] as $f) {
                @unlink($f);
            }
            @rmdir($work);
        }
        chmod($path, 0600);
        $this->audit->record('backup.downloaded', null, null, ['files' => $count + 1, 'certificates' => $certificates]);

        return ['path' => $path, 'files' => $count + 1, 'certificates' => $certificates];
    }

    private static function readme(string $orgName, bool $withCertificates): string
    {
        return implode("\r\n", [
            'Copia di sicurezza di '.$orgName,
            'Creata il '.now()->format('d/m/Y').' alle '.now()->format('H:i').'.',
            '',
            'Contiene dati personali: conservala con cura e cancellala quando non serve più.',
            '',
            'Come si aprono i file: I PDF si aprono con qualsiasi lettore PDF; le tabelle .csv con Excel, LibreOffice o simili (UTF-8, separatore punto e virgola).',
            '',
            'Cartelle:',
            '',
            '«'.self::DATA.'»',
            'Tutti i dati dell\'associazione, un file CSV per ogni tabella (soci, tessere, prima nota, conti, attività, operatori, impostazioni, registro delle azioni).',
            'Servono per riportare i dati nel plugin o nel gestionale Associazione Semplice: la prima nota e l\'anagrafica si ricaricano con gli import.',
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
