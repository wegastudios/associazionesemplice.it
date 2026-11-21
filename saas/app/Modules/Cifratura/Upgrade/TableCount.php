<?php

declare(strict_types=1);

namespace App\Modules\Cifratura\Upgrade;

/** Conteggi di una tabella per un'associazione: solo numeri e identificativi, mai valori. */
final class TableCount
{
    /** Quanti identificativi di campi illeggibili si ricordano per tabella (il conteggio è completo). */
    public const REMEMBERED = 20;

    public int $rows = 0;

    /** Righe con almeno un campo ancora 0x01 (da convertire). */
    public int $convertRows = 0;

    /** Campi 0x01: tante copie d'appoggio quanti sono. */
    public int $convertFields = 0;

    /** Righe con campi cifrati tutti già 0x02. */
    public int $alreadyRows = 0;

    /** Righe con almeno un campo che non si decifra (o con un formato sconosciuto). */
    public int $unreadableRows = 0;

    /** @var list<string> tabella, riga e colonna dei primi campi illeggibili */
    public array $unreadable = [];

    public function __construct(public readonly string $table) {}
}
