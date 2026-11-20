<?php

declare(strict_types=1);

namespace App\Modules\Contabilita\Contracts;

/**
 * Incassi senza ricevuta, da emetterla dopo: dentro run() ogni incasso si registra subito in prima nota ma senza
 * ricevuta, e la prima nota ricorda i movimenti nati così. Chi chiama poi decide se emettere la ricevuta
 * (Ledger::issueReceipt) o aspettare la segreteria.
 */
final class ReceiptHold
{
    /** @var list<string>|null */
    private static ?array $held = null;

    /** Si sta registrando senza ricevuta? */
    public static function active(): bool
    {
        return self::$held !== null;
    }

    /** Lo chiama la prima nota: questi movimenti sono nati senza ricevuta. */
    public static function note(string $transactionId): void
    {
        if (self::$held !== null) {
            self::$held[] = $transactionId;
        }
    }

    /**
     * Esegue $work senza emettere ricevute. Restituisce gli id dei movimenti registrati (vuoti se $work fallisce: in quel
     * caso l'eccezione risale e chi chiama annulla la transazione).
     *
     * @param  callable(): mixed  $work
     * @return list<string>
     */
    public static function run(callable $work): array
    {
        $previous = self::$held;
        self::$held = [];
        try {
            $work();

            return self::$held;
        } finally {
            self::$held = $previous;
        }
    }
}
