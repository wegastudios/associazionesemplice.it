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
    private const TEXT = ['runts' => 30, 'address' => 190, 'zip' => 5, 'city' => 120, 'province' => 2, 'phone' => 30, 'legal_rep' => 120, 'sdi' => 7, 'tax_regime' => 60];

    /** Dati «solo se esistono»: la casella «non ce l'ha» al posto del valore. */
    public const NONE = ['vat', 'runts', 'pec'];

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
        foreach (self::NONE as $k) {
            $values[$k.'_none'] = $this->settings->get('ente.'.$k.'_none') === true;
        }

        return ['values' => $values, 'kinds' => array_map(static fn (string $k, string $l): array => ['value' => $k, 'label' => $l], array_keys(self::KINDS), self::KINDS)];
    }

    public function errors(array $input): array
    {
        $t = static fn (string $k): string => is_scalar($input[$k] ?? null) ? trim((string) $input[$k]) : '';
        $none = static fn (string $k): bool => filter_var($input[$k.'_none'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $errors = [];
        if ($t('kind') === '') {
            $errors[] = 'Indica la forma dell\'ente.';
        } elseif (! array_key_exists($t('kind'), self::KINDS)) {
            $errors[] = 'Forma dell\'ente non valida.';
        }
        if ($t('tax_code') === '') {
            $errors[] = 'Indica il codice fiscale dell\'ente.';
        } elseif (! Fiscal::isValidEntityTaxCode($t('tax_code'))) {
            $errors[] = 'Il codice fiscale dell\'ente non è valido: controllalo (11 cifre, oppure 16 caratteri se è una persona fisica).';
        }
        if ($t('vat') !== '' && ! Fiscal::isValidVat($t('vat'))) {
            $errors[] = 'La partita IVA non è valida.';
        }
        // Partita IVA, numero RUNTS e PEC: o il dato, o la casella «non ce l'ha».
        foreach (['vat' => 'Indica la partita IVA oppure spunta «L\'associazione non ha la partita IVA».', 'runts' => 'Indica il numero RUNTS (con la sezione) oppure spunta «L\'associazione non è iscritta al RUNTS».', 'pec' => 'Indica la PEC oppure spunta «L\'associazione non ha una PEC».'] as $k => $msg) {
            if ($t($k) === '' && ! $none($k)) {
                $errors[] = $msg;
            }
        }
        if ($t('address') === '') {
            $errors[] = 'Indica l\'indirizzo della sede.';
        }
        if ($t('zip') === '') {
            $errors[] = 'Indica il CAP della sede.';
        }
        if ($t('city') === '') {
            $errors[] = 'Indica il comune della sede.';
        }
        if ($t('province') === '') {
            $errors[] = 'Indica la provincia della sede (sigla di 2 lettere).';
        }
        if ($t('legal_rep') === '') {
            $errors[] = 'Indica nome e cognome del legale rappresentante.';
        }
        if ($t('sdi') !== '' && preg_match('/^[A-Za-z0-9]{7}$/', $t('sdi')) !== 1) {
            $errors[] = 'Il codice destinatario ha 7 caratteri (lettere e cifre).';
        }
        if ($t('email') === '') {
            $errors[] = 'Indica l\'email della segreteria.';
        }
        if ($t('phone') === '') {
            $errors[] = 'Indica il telefono della segreteria.';
        } elseif (preg_match('/^[0-9+\-.()\/ ]+$/', $t('phone')) !== 1 || preg_match_all('/\d/', $t('phone')) < 6) {
            $errors[] = 'Indica un telefono valido per la segreteria (almeno 6 cifre; ammessi + - . ( ) e spazi).';
        }
        foreach (['email' => 'L\'email della segreteria', 'pec' => 'La PEC'] as $k => $label) {
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
            $values[$k] = in_array($k, ['province', 'sdi'], true) ? strtoupper($t($k)) : $t($k);
        }
        // «Non ce l'ha» vale solo se il dato è vuoto.
        foreach (self::NONE as $k) {
            $has = $k === 'vat' ? $values['vat'] !== '' : ($k === 'pec' ? $values['pec'] !== '' : $values['runts'] !== '');
            $values[$k.'_none'] = ! $has && filter_var($input[$k.'_none'] ?? false, FILTER_VALIDATE_BOOLEAN);
        }
        foreach ($values as $k => $v) {
            $this->settings->set('ente.'.$k, $v === '' ? null : $v);
        }
    }
}
