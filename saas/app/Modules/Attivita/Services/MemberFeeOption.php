<?php

declare(strict_types=1);

namespace App\Modules\Attivita\Services;

use App\Modules\Attivita\Contracts\ActivityKind;
use App\Modules\Contabilita\Contracts\CategoryKind;
use App\Modules\Contabilita\Contracts\Ledger;
use App\Modules\Core\Contracts\OrgFiscal;
use App\Modules\Soci\Contracts\MemberType;

/**
 * Opzione fiscale, spenta di default: i contributi di eventi e corsi pagati dai soci si incassano come erogazione liberale.
 * I pagamenti dei non soci restano contributi e le quote associative non passano da qui. I movimenti già registrati non
 * cambiano. Dal LedgerService del plugin (member_events_donation).
 */
final readonly class MemberFeeOption
{
    public function __construct(private OrgFiscal $fiscal, private Ledger $ledger) {}

    /** Questa persona paga come socio e l'opzione è accesa? */
    public function applies(string $personType): bool
    {
        return $this->fiscal->memberEventsDonation() && MemberType::tryFrom($personType)?->isMember() === true;
    }

    /**
     * La voce con cui registrare il contributo: quella dell'attività oppure, con l'opzione accesa e il socio che paga,
     * l'«Erogazione liberale» (se la voce manca, resta il contributo).
     *
     * @return array{category: string, donation: bool}
     */
    public function category(string $feeCategory, string $personType): array
    {
        if ($this->applies($personType)) {
            $donation = $this->ledger->categoryFor(CategoryKind::Donation);
            if ($donation !== null) {
                return ['category' => $donation, 'donation' => true];
            }
        }

        return ['category' => $feeCategory, 'donation' => false];
    }

    /** «Contributo X» oppure, come erogazione liberale, «Erogazione liberale per il corso X» / «per l'evento X». */
    public static function description(bool $donation, ActivityKind $kind, string $name, string $suffix = ''): string
    {
        $what = $donation
            ? 'Erogazione liberale per '.($kind === ActivityKind::Course ? 'il corso ' : 'l\'evento ').$name
            : 'Contributo '.$name;

        return $suffix === '' ? $what : $what.' '.$suffix;
    }
}
