<?php

declare(strict_types=1);

namespace App\Modules\Cifratura\Contracts;

use SensitiveParameter;

/**
 * Cifratura dei singoli campi con la chiave dell'associazione (XChaCha20-Poly1305). Il testo cifrato è legato
 * all'associazione e al campo: copiato in un'altra associazione o in un altro campo non si apre.
 */
interface FieldCipher
{
    public function encrypt(string $orgId, string $field, #[SensitiveParameter] string $plain): string;

    public function decrypt(string $orgId, string $field, string $cipher): string;

    /** Impronta per la ricerca esatta e l'unicità (blind index): stesso valore normalizzato, stessa impronta. */
    public function blindIndex(string $orgId, string $field, #[SensitiveParameter] string $normalized): string;
}
