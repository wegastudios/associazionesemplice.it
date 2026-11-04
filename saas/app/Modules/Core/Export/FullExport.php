<?php

declare(strict_types=1);

namespace App\Modules\Core\Export;

use App\Modules\Audit\Contracts\AuditLog;
use App\Modules\Core\Contracts\DataExport;
use App\Modules\Core\Contracts\Operator;
use Illuminate\Contracts\Container\Container;
use RuntimeException;
use ZipArchive;

/**
 * Esportazione completa: i dati dell'associazione in un archivio ZIP di file CSV (uno per tabella, apribili in Excel),
 * con quello che l'operatore può vedere. La prima nota e l'anagrafica si ricaricano con gli import. Il file si crea in
 * una cartella privata e si cancella dopo lo scarico.
 */
final readonly class FullExport
{
    public function __construct(
        private Container $container,
        private AuditLog $audit,
    ) {}

    /** @return array{path: string, files: int} */
    public function build(Operator $operator, string $orgName): array
    {
        $files = [];
        foreach ($this->container->tagged(DataExport::TAG) as $part) {
            if ($part instanceof DataExport) {
                $files += $part->files($operator);
            }
        }
        ksort($files);
        $files = ['LEGGIMI.txt' => self::readme($orgName, array_keys($files))] + $files;

        $dir = storage_path('app/tmp');
        if (! is_dir($dir) && ! mkdir($dir, 0700, true) && ! is_dir($dir)) {
            throw new RuntimeException('Cartella temporanea non disponibile.');
        }
        $path = $dir.'/export-'.bin2hex(random_bytes(16)).'.zip';
        $zip = new ZipArchive;
        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::EXCL) !== true) {
            throw new RuntimeException('Archivio non disponibile.');
        }
        foreach ($files as $name => $content) {
            $zip->addFromString($name, $content);
        }
        $zip->close();
        chmod($path, 0600);
        $this->audit->record('export.full', null, null, ['files' => count($files)]);

        return ['path' => $path, 'files' => count($files)];
    }

    /** @param  list<string>  $names */
    private static function readme(string $orgName, array $names): string
    {
        return implode("\r\n", [
            'Esportazione completa dei dati di '.$orgName,
            'Creata il '.now()->format('d/m/Y').' alle '.now()->format('H:i').'.',
            '',
            'Ogni file è una tabella in formato CSV (UTF-8, separatore punto e virgola): si apre con Excel o LibreOffice.',
            'contabilita/prima-nota.csv e soci/anagrafica.csv si possono ricaricare con l\'import della prima nota e dei soci.',
            'Contiene dati personali e, se presenti, sanitari: conservalo con cura e cancellalo quando non serve più.',
            'I file allegati ai certificati medici non sono compresi: si scaricano dalla scheda della persona.',
            '',
            'File:',
            ...array_map(static fn (string $n): string => '- '.$n, $names),
            '',
        ]);
    }
}
