<?php

declare(strict_types=1);

namespace App\Modules\Cifratura\Console;

use App\Modules\Cifratura\Keys\RecoveryFile;
use RuntimeException;
use Throwable;

/**
 * Frase segreta della copia di recupero: solo da un prompt nascosto, mai da un argomento della riga di comando (finirebbe
 * nella cronologia della shell e nell'elenco dei processi), mai stampata. Senza terminale interattivo si rifiuta.
 */
trait AsksPassphrase
{
    private function askPassphrase(bool $confirm): string
    {
        $first = $this->hidden('Frase segreta');
        if ($confirm) {
            RecoveryFile::checkPassphrase($first);
            if (! hash_equals($first, $this->hidden('Ripeti la frase segreta'))) {
                throw new RuntimeException('Le due frasi non coincidono.');
            }
        }

        return $first;
    }

    private function hidden(string $question): string
    {
        try {
            // Niente ripiego visibile: se il terminale non sa nascondere l'input, meglio fermarsi.
            $value = $this->secret($question, false);
        } catch (Throwable) {
            $value = null;
        }
        if (! is_string($value) || $value === '') {
            throw new RuntimeException('Serve un terminale interattivo per scrivere la frase segreta (prompt nascosto).');
        }

        return $value;
    }
}
