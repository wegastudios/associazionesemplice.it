<?php

declare(strict_types=1);

namespace App\Modules\Soci\Rules;

use App\Modules\Soci\Contracts\MemberType;

/** Validazione della scheda (dal Rules::validate_person del plugin). Restituisce i messaggi; vuoto = valida. */
final class PersonRules
{
    private const LIMITS = [
        'first_name' => ['Il nome', 120], 'last_name' => ['Il cognome', 120], 'email' => ["L'email", 190],
        'phone' => ['Il cellulare', 60], 'tax_code' => ['Il codice fiscale', 32], 'birth_place' => ['Il luogo di nascita', 120],
        'card_number' => ['Il numero di tessera', 40],
    ];

    /**
     * @param  array<string, mixed>  $d  type, first_name, last_name, email, phone, tax_code, birth_date, joined_on,
     *                                   card_number, host_person_id, iban
     * @param  MemberType|null  $hostType  tipo del socio ospitante, se indicato
     * @return list<string>
     */
    public static function validate(array $d, ?MemberType $hostType = null, string $today = ''): array
    {
        $text = static fn (string $key): string => is_scalar($d[$key] ?? null) ? trim((string) $d[$key]) : '';
        $type = MemberType::tryFrom($text('type'));
        if ($type === null) {
            return ['Tipo di persona non valido.'];
        }
        $today = $today !== '' ? $today : date('Y-m-d');
        $errors = [];

        $entity = filter_var($d['is_entity'] ?? false, FILTER_VALIDATE_BOOLEAN);
        if ($entity) {
            if ($text('last_name') === '') {
                $errors[] = "La denominazione dell'ente è obbligatoria.";
            }
        } elseif ($text('first_name') === '' || $text('last_name') === '') {
            $errors[] = 'Nome e cognome sono obbligatori.';
        }
        if ($text('email') !== '' && filter_var($text('email'), FILTER_VALIDATE_EMAIL) === false) {
            $errors[] = "L'email non è valida.";
        }
        if ($text('phone') !== '' && ! Phone::isValid($text('phone'))) {
            $errors[] = 'Il numero di cellulare non è valido.';
        }
        $entityCode = $entity && preg_match('/^\d{11}$/', TaxCode::normalize($text('tax_code'))) === 1;
        if ($text('tax_code') !== '' && ! $entityCode && ! TaxCode::isValid($text('tax_code'))) {
            $errors[] = 'Il codice fiscale non è valido.';
        }
        if ($text('iban') !== '' && ! Iban::isValid($text('iban'))) {
            $errors[] = "L'IBAN non è valido.";
        }
        if ($text('joined_on') !== '' && ! Age::isValidDate($text('joined_on'))) {
            $errors[] = 'La data di ingresso non è valida.';
        }
        $birth = $text('birth_date');
        if ($birth !== '') {
            if (! Age::isValidDate($birth)) {
                $errors[] = 'La data di nascita non è valida.';
            } elseif ($birth > $today) {
                $errors[] = 'La data di nascita è nel futuro.';
            }
        }
        foreach (self::LIMITS as $field => [$label, $max]) {
            if (mb_strlen($text($field)) > $max) {
                $errors[] = "{$label} è troppo lungo (al massimo {$max} caratteri).";
            }
        }

        $hasHost = $text('host_person_id') !== '';
        if ($type === MemberType::Guest) {
            if ($text('card_number') !== '') {
                $errors[] = 'Gli ospiti non hanno il numero di tessera.';
            }
            if (! Phone::isValid($text('phone'))) {
                $errors[] = 'Il numero di cellulare è obbligatorio per gli ospiti (serve a riconoscerli ed evitare doppioni).';
            }
            if (! $hasHost) {
                $errors[] = 'Un ospite deve essere collegato al socio che lo ospita.';
            } elseif ($hostType === null || ! $hostType->isMember()) {
                $errors[] = 'Il socio ospitante non è valido (deve essere un socio, non un altro ospite).';
            }
        } elseif ($hasHost) {
            $errors[] = 'Solo gli ospiti hanno un socio ospitante.';
        }

        return $errors;
    }
}
