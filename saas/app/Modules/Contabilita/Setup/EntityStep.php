<?php

declare(strict_types=1);

namespace App\Modules\Contabilita\Setup;

use App\Modules\Contabilita\Rules\Fiscal;
use App\Modules\Core\Contracts\Ability;
use App\Modules\Core\Contracts\Operator;
use App\Modules\Core\Contracts\OrgSettings;
use App\Modules\Core\Contracts\SetupStep;

/**
 * Dati dell'ente (dall'EntityData del plugin): forma, codice fiscale, partita IVA, RUNTS, sede e contatti. Servono a
 * ricevute, rendiconto e comunicazioni; si salvano nelle impostazioni dell'associazione («ente.*»).
 */
final readonly class EntityStep implements SetupStep
{
    public const KINDS = [
        'asd' => 'Associazione sportiva dilettantistica (ASD)',
        'aps' => 'Associazione di promozione sociale (APS)',
        'odv' => 'Organizzazione di volontariato (ODV)',
        'ets' => 'Altro ente del Terzo settore',
        'circolo' => 'Circolo o associazione culturale',
        'altro' => 'Altra associazione',
    ];

    /** Campi di testo e lunghezza massima. */
    private const TEXT = ['runts' => 30, 'address' => 190, 'zip' => 5, 'city' => 120, 'province' => 2, 'phone' => 30];

    public function __construct(private OrgSettings $settings) {}

    public function key(): string
    {
        return 'ente';
    }

    public function title(): string
    {
        return 'Dati dell\'ente';
    }

    public function order(): int
    {
        return 10;
    }

    public function visible(Operator $operator): bool
    {
        return $operator->can(Ability::ManageSettings);
    }

    public function props(): array
    {
        $values = [];
        foreach (['kind', 'tax_code', 'vat', 'email', 'pec', ...array_keys(self::TEXT)] as $k) {
            $v = $this->settings->get('ente.'.$k);
            $values[$k] = is_string($v) ? $v : '';
        }

        return ['values' => $values, 'kinds' => array_map(static fn (string $k, string $l): array => ['value' => $k, 'label' => $l], array_keys(self::KINDS), self::KINDS)];
    }

    public function errors(array $input): array
    {
        $t = static fn (string $k): string => is_scalar($input[$k] ?? null) ? trim((string) $input[$k]) : '';
        $errors = [];
        if ($t('kind') !== '' && ! array_key_exists($t('kind'), self::KINDS)) {
            $errors[] = 'Forma dell\'ente non valida.';
        }
        if ($t('tax_code') !== '' && ! Fiscal::isValidEntityTaxCode($t('tax_code'))) {
            $errors[] = 'Il codice fiscale dell\'ente non è valido: controllalo (11 cifre, oppure 16 caratteri se è una persona fisica).';
        }
        if ($t('vat') !== '' && ! Fiscal::isValidVat($t('vat'))) {
            $errors[] = 'La partita IVA non è valida.';
        }
        foreach (['email' => 'L\'email', 'pec' => 'La PEC'] as $k => $label) {
            if ($t($k) !== '' && (filter_var($t($k), FILTER_VALIDATE_EMAIL) === false || mb_strlen($t($k)) > 190)) {
                $errors[] = "{$label} non è un indirizzo valido.";
            }
        }
        if ($t('zip') !== '' && preg_match('/^\d{5}$/', $t('zip')) !== 1) {
            $errors[] = 'Il CAP ha 5 cifre.';
        }
        if ($t('province') !== '' && preg_match('/^[A-Za-z]{2}$/', $t('province')) !== 1) {
            $errors[] = 'La provincia si scrive con la sigla di 2 lettere.';
        }
        foreach (self::TEXT as $k => $max) {
            if (mb_strlen($t($k)) > $max) {
                $errors[] = 'Un campo dei dati dell\'ente è troppo lungo.';
                break;
            }
        }

        return $errors;
    }

    public function save(array $input): void
    {
        $t = static fn (string $k): string => is_scalar($input[$k] ?? null) ? trim((string) $input[$k]) : '';
        $values = [
            'kind' => $t('kind'),
            'tax_code' => strtoupper((string) preg_replace('/\s+/', '', $t('tax_code'))),
            'vat' => $t('vat') === '' ? '' : Fiscal::normalizeVat($t('vat')),
            'email' => mb_strtolower($t('email')),
            'pec' => mb_strtolower($t('pec')),
        ];
        foreach (array_keys(self::TEXT) as $k) {
            $values[$k] = $k === 'province' ? strtoupper($t($k)) : $t($k);
        }
        foreach ($values as $k => $v) {
            $this->settings->set('ente.'.$k, $v === '' ? null : $v);
        }
    }
}
