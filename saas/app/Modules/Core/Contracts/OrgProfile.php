<?php

declare(strict_types=1);

namespace App\Modules\Core\Contracts;

/**
 * Dati obbligatori dell'associazione (chiavi «ente.*» dei Dati dell'ente): cosa manca, per il promemoria fisso.
 * Partita IVA, numero RUNTS e PEC sono obbligatori solo se esistono: vale anche la casella «non ce l'ha».
 * Il blocco delle email manuali dipende solo dai contatti (OrgContacts), non da questi dati.
 */
final readonly class OrgProfile
{
    public function __construct(private OrgSettings $settings) {}

    /** @return list<string> i dati che mancano, nell'ordine in cui si compilano */
    public function missing(): array
    {
        $has = fn (string $k): bool => is_string($v = $this->settings->get('ente.'.$k)) && trim($v) !== '';
        $none = fn (string $k): bool => $this->settings->get('ente.'.$k.'_none') === true;

        $missing = [];
        foreach (['kind' => 'forma dell\'ente', 'tax_code' => 'codice fiscale'] as $k => $label) {
            if (! $has($k)) {
                $missing[] = $label;
            }
        }
        if (! ($has('address') && $has('zip') && $has('city') && $has('province'))) {
            $missing[] = 'sede legale';
        }
        if (! $has('legal_rep')) {
            $missing[] = 'legale rappresentante';
        }
        foreach (['email' => 'email della segreteria', 'phone' => 'telefono della segreteria'] as $k => $label) {
            if (! $has($k)) {
                $missing[] = $label;
            }
        }
        foreach (['vat' => 'partita IVA', 'runts' => 'numero RUNTS', 'pec' => 'PEC'] as $k => $label) {
            if (! $has($k) && ! $none($k)) {
                $missing[] = $label;
            }
        }

        return $missing;
    }
}
