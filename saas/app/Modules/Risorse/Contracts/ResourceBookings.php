<?php

declare(strict_types=1);

namespace App\Modules\Risorse\Contracts;

/** Le risorse dell'associazione corrente e le loro prenotazioni, per chi ci collega le attività. */
interface ResourceBookings
{
    /** @return list<array{id: string, name: string}> risorse non archiviate, per nome */
    public function options(): array;

    /**
     * @param  list<string>  $ids
     * @return array<string, string> id => nome
     */
    public function names(array $ids): array;

    /**
     * Prenotazioni e blocchi attivi della risorsa che si sovrappongono all'intervallo [$from, $to) (testo «Y-m-d H:i:s»).
     *
     * @return list<array{id: string, kind: string, from: string, to: string|null, label: string}>
     */
    public function busy(string $resourceId, string $from, string $to): array;

    /**
     * Sposta prenotazioni e blocchi su un'altra risorsa, tutti o nessuno.
     *
     * @param  list<string>  $ids
     *
     * @throws ResourceError se sulla risorsa di arrivo uno di loro si sovrappone a qualcosa
     */
    public function move(array $ids, string $toResourceId): void;

    /** Risorse non archiviate con l'IVA impostata (anche «IVA esente»). */
    public function withVatCount(): int;

    /**
     * Aliquote maggiori di zero impostate sulle risorse (anche archiviate).
     *
     * @return list<int>
     */
    public function vatRatesInUse(): array;

    /**
     * Aliquote maggiori di zero delle prenotazioni non annullate e non ancora finite: all'incasso userebbero quelle.
     *
     * @return list<int>
     */
    public function vatRatesInBookings(): array;
}
