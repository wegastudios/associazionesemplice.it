<?php

declare(strict_types=1);

namespace App\Modules\Core\Contracts;

/**
 * Un passo della configurazione guidata, fornito dal modulo a cui appartiene (dati dell'ente, tesseramento, conti…).
 * I moduli lo registrano con il tag SetupStep::TAG; la configurazione li mostra in ordine nella stessa pagina e li
 * salva insieme, nell'associazione corrente.
 */
interface SetupStep
{
    public const TAG = 'saas.setup-steps';

    /** Chiave del passo, come nei dati inviati: steps[<key>][campo]. */
    public function key(): string;

    public function title(): string;

    /** Posizione nella pagina (crescente). */
    public function order(): int;

    /** Il passo serve a questo operatore? (permessi) */
    public function visible(Operator $operator): bool;

    /** @return array<string, mixed> valori attuali e scelte per la pagina */
    public function props(): array;

    /**
     * Controlla i dati senza salvare nulla.
     *
     * @param  array<mixed>  $input
     * @return list<string> errori da mostrare
     */
    public function errors(array $input): array;

    /** @param  array<mixed>  $input */
    public function save(array $input): void;
}
