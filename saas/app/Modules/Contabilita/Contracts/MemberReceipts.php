<?php

declare(strict_types=1);

namespace App\Modules\Contabilita\Contracts;

/** Le ricevute di una persona per l'area soci: elenco e PDF, solo se la ricevuta è sua (pagata da lei o per lei). */
interface MemberReceipts
{
    /**
     * @param  list<string>  $personIds
     * @return list<array{id: string, number: string, date: string, total: int, description: string}>
     */
    public function forPeople(array $personIds): array;

    /**
     * @param  list<string>  $personIds
     * @return array{pdf: string, filename: string}|null null se la ricevuta non è di queste persone o è annullata
     */
    public function pdf(string $receiptId, array $personIds): ?array;
}
