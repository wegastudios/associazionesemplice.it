<?php

declare(strict_types=1);

namespace App\Modules\Core\Contracts;

/**
 * Voci che un socio può pagare online dall'area soci (da PaymentItems del plugin): quota associativa, mensilità dei
 * corsi, prenotazioni di eventi e risorse. Ogni modulo registra la sua parte con il tag PayableDues::TAG.
 *
 * Il browser manda solo le chiavi scelte: importi e descrizioni si ricalcolano sempre qui. All'incasso settle()
 * registra il pagamento in prima nota con la ricevuta, come se lo facesse la segreteria.
 *
 * @phpstan-type Due array{key: string, person_id: string, group: string, label: string, amount_cents: int, donation?: bool, public?: bool}
 */
interface PayableDues
{
    public const TAG = 'saas.payable-dues';

    /**
     * Le voci dovute ora da queste persone.
     *
     * @param  list<string>  $personIds
     * @return list<Due>
     */
    public function dues(array $personIds): array;

    /** Questo modulo riconosce la chiave? */
    public function owns(string $key): bool;

    /**
     * Registra l'incasso di una voce (già pagata online) in prima nota. Con $anyway i soldi sono già arrivati sul conto del gestore: la voce si registra comunque, anche se nel frattempo è stata pagata o annullata (la verifica è di chi chiama).
     *
     * @param  string  $method  valore di Contabilita\Contracts\PaymentMethod (stripe, paypal)
     * @return string|null il numero di ricevuta
     */
    public function settle(string $key, int $cents, string $accountId, string $date, string $method, bool $anyway = false): ?string;
}
