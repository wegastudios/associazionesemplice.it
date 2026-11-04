<?php

declare(strict_types=1);

namespace App\Modules\Contabilita\Rules;

use DateTimeImmutable;

/**
 * Intestazione della ricevuta di un minore (dal Receipts::minor_block del plugin): va a un genitore (chi ha pagato, se è
 * un genitore, altrimenti l'operativo), con i codici fiscali dell'altro genitore e del minore e, dai 5 ai 18 anni, se il
 * pagamento è tracciato (detrazione delle spese sportive).
 *
 * @phpstan-type Person array{id: string, first_name: string, last_name: string, tax_code?: string|null, birth_date?: string|null, operative?: bool}
 */
final class ReceiptRules
{
    /** Metodi tracciabili, che valgono per la detrazione; contanti e «altro» no. */
    public const TRACKED_METHODS = ['bank_transfer', 'pos', 'check', 'stripe', 'paypal', 'sumup', 'satispay'];

    /**
     * @param  Person|null  $payer  chi ha pagato
     * @param  Person|null  $ward  per chi
     * @param  list<Person>  $guardians
     * @param  list<string>  $kinds  natura delle voci (categorie)
     * @return array{holder: Person|null, lines: list<string>, note: string}
     */
    public static function minorBlock(?array $payer, ?array $ward, array $guardians, string $date, string $method, string $methodLabel, array $kinds): array
    {
        $none = ['holder' => $payer, 'lines' => [], 'note' => ''];
        $years = $ward === null ? null : self::years($ward['birth_date'] ?? null, $date);
        if ($ward === null || $guardians === [] || $years === null || $years >= 18) {
            return $none;
        }
        $holder = $guardians[0];
        foreach ($guardians as $g) {
            if (($g['operative'] ?? false) === true) {
                $holder = $g;
                break;
            }
        }
        foreach ($guardians as $g) {
            if ($payer !== null && $g['id'] === $payer['id']) {
                $holder = $g; // ha pagato l'altro genitore: la ricevuta è sua
            }
        }
        $cf = static fn (array $p): string => is_string($p['tax_code'] ?? null) && $p['tax_code'] !== '' ? $p['tax_code'] : 'non indicato';
        $lines = [];
        foreach ($guardians as $g) {
            if ($g['id'] !== $holder['id']) {
                $lines[] = 'Altro genitore o tutore: '.trim($g['first_name'].' '.$g['last_name']).' — C.F. '.$cf($g);
            }
        }
        $birth = (string) ($ward['birth_date'] ?? '');
        $lines[] = 'Per conto di '.trim($ward['first_name'].' '.$ward['last_name']).' — C.F. '.$cf($ward).', nato/a il '.(new DateTimeImmutable($birth))->format('d/m/Y');
        $note = '';
        if ($years >= 5 && array_intersect($kinds, ['activity_fee', 'membership']) !== []) {
            $note = in_array($method, self::TRACKED_METHODS, true)
                ? 'Pagamento tracciato ('.$methodLabel.'): idoneo alla detrazione delle spese sportive per ragazzi dai 5 ai 18 anni (art. 15, c. 1, lett. i-quinquies, TUIR).'
                : 'Pagamento non tracciato ('.$methodLabel.'): non consente la detrazione delle spese sportive per ragazzi dai 5 ai 18 anni.';
        }

        return ['holder' => $holder, 'lines' => $lines, 'note' => $note];
    }

    /** Anni compiuti a quella data; null se la data di nascita manca o non è valida. */
    public static function years(?string $birth, string $date): ?int
    {
        if ($birth === null || preg_match('/^\d{4}-\d{2}-\d{2}$/', $birth) !== 1 || $birth > $date) {
            return null;
        }

        return (new DateTimeImmutable($birth))->diff(new DateTimeImmutable($date))->y;
    }

    /**
     * Imponibile e IVA per aliquota (solo le voci con IVA).
     *
     * @param  list<array{amount: int, vat_rate: int|null, vat: int}>  $rows
     * @return array<int, array{net: int, vat: int}>
     */
    public static function vatBreakdown(array $rows): array
    {
        $out = [];
        foreach ($rows as $r) {
            if ($r['vat_rate'] === null || $r['vat'] <= 0) {
                continue;
            }
            $out[$r['vat_rate']] ??= ['net' => 0, 'vat' => 0];
            $out[$r['vat_rate']]['vat'] += $r['vat'];
            $out[$r['vat_rate']]['net'] += $r['amount'] - $r['vat'];
        }
        ksort($out);

        return $out;
    }
}
