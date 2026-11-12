<?php

declare(strict_types=1);

namespace App\Modules\Core\Contracts;

use InvalidArgumentException;

/** Le impostazioni della piattaforma modificabili dall'amministrazione (cifrate nel database centrale). */
interface PlatformSettingsStore
{
    /**
     * Valori per la pagina: i segreti solo come «impostato».
     *
     * @return array<string, array{label: string, secret: bool, set: bool, value: string}>
     */
    public function forPage(): array;

    /**
     * Salva le impostazioni indicate. Un valore normale vuoto toglie l'impostazione (vale di nuovo il file d'ambiente).
     *
     * @param  array<string, string|null>  $values
     * @param  list<string>  $clear  segreti da togliere
     * @return list<string> chiavi cambiate
     *
     * @throws InvalidArgumentException
     */
    public function save(array $values, array $clear, ?string $userId): array;
}
