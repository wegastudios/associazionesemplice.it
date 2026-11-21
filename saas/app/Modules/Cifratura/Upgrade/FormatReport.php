<?php

declare(strict_types=1);

namespace App\Modules\Cifratura\Upgrade;

/** Esito di un passaggio al formato legato alla riga per una associazione. */
final class FormatReport
{
    /** @var array<string, TableCount> */
    public array $tables = [];

    public function convertRows(): int
    {
        return array_sum(array_map(static fn (TableCount $t): int => $t->convertRows, $this->tables));
    }

    public function convertFields(): int
    {
        return array_sum(array_map(static fn (TableCount $t): int => $t->convertFields, $this->tables));
    }

    public function alreadyRows(): int
    {
        return array_sum(array_map(static fn (TableCount $t): int => $t->alreadyRows, $this->tables));
    }

    public function unreadableRows(): int
    {
        return array_sum(array_map(static fn (TableCount $t): int => $t->unreadableRows, $this->tables));
    }

    /** @return array<string, int> per il registro delle azioni: solo numeri */
    public function details(): array
    {
        return [
            'rows_converted' => $this->convertRows(),
            'fields_converted' => $this->convertFields(),
            'rows_already' => $this->alreadyRows(),
            'rows_unreadable' => $this->unreadableRows(),
        ];
    }
}
