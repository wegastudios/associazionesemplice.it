<?php

declare(strict_types=1);

namespace App\Modules\Soci\Rules;

/**
 * Domanda di adesione online (dall'ApplicationRules del plugin): controllo dei dati del modulo. Gli errori dicono solo
 * se i dati sono scritti bene, mai se la persona o l'email esistono già.
 */
final class ApplicationRules
{
    public const MAX_AGE = 110;

    /**
     * @param  array<mixed>  $in
     * @param  list<string>  $types  tipi di socio richiedibili
     * @return array{errors: list<string>, minor: bool, data: array<string, mixed>}
     */
    public static function check(array $in, string $today, bool $needsRules, array $types): array
    {
        $t = static fn (string $k): string => is_scalar($in[$k] ?? null) ? trim((string) $in[$k]) : '';
        $ok = static fn (string $k): bool => ! empty($in[$k]);
        $errors = [];
        $data = [
            'first_name' => $t('first_name'),
            'last_name' => $t('last_name'),
            'email' => mb_strtolower($t('email')),
            'phone' => $t('phone'),
            'tax_code' => TaxCode::normalize($t('tax_code')),
            'type' => $t('type'),
            'birth_date' => null,
            'birth_place' => mb_substr($t('birth_place'), 0, 120),
            'address' => mb_substr($t('address'), 0, 190),
            'images_ok' => $ok('images_ok'),
            'guardian' => [],
        ];

        if ($data['first_name'] === '' || $data['last_name'] === '') {
            $errors[] = 'Scrivi nome e cognome.';
        }
        foreach (['first_name' => 'Il nome', 'last_name' => 'Il cognome'] as $k => $label) {
            if (mb_strlen($data[$k]) > 120) {
                $errors[] = "{$label} è troppo lungo.";
            }
        }

        $birth = Age::parse($t('birth_date'));
        $minor = false;
        if ($birth === null) {
            $errors[] = 'Scrivi la data di nascita (giorno, mese e anno).';
        } elseif ($birth > $today) {
            $errors[] = 'La data di nascita è nel futuro.';
        } elseif ((int) Age::years($birth, $today) > self::MAX_AGE) {
            $errors[] = 'Controlla la data di nascita.';
        } else {
            $data['birth_date'] = $birth;
            $minor = Age::isMinor($birth, $today);
        }

        if (! TaxCode::isValid($data['tax_code'])) {
            $errors[] = 'Il codice fiscale non è valido: controllalo (16 caratteri).';
        }
        if (! in_array($data['type'], $types, true)) {
            $errors[] = 'Scegli che tipo di socio vuoi essere.';
        }
        // Un maggiorenne ha bisogno di email e cellulare; per un minore contano quelli del genitore.
        if ((! $minor || $data['email'] !== '') && filter_var($data['email'], FILTER_VALIDATE_EMAIL) === false) {
            $errors[] = $minor ? "L'email non è valida." : "Scrivi un'email valida: ci arriva il link per entrare.";
        }
        if ((! $minor || $data['phone'] !== '') && ! Phone::isValid($data['phone'])) {
            $errors[] = $minor ? 'Il cellulare non è valido.' : 'Scrivi il numero di cellulare.';
        }

        if ($minor) {
            $g = [
                'first_name' => $t('guardian_first_name'),
                'last_name' => $t('guardian_last_name'),
                'email' => mb_strtolower($t('guardian_email')),
                'phone' => $t('guardian_phone'),
                'tax_code' => TaxCode::normalize($t('guardian_tax_code')),
            ];
            if ($data['email'] !== '' && $data['email'] === $g['email']) {
                $data['email'] = ''; // l'email del genitore resta solo al genitore
            }
            if ($g['first_name'] === '' || $g['last_name'] === '') {
                $errors[] = 'Chi ha meno di 18 anni si iscrive con un genitore o tutore: scrivi il suo nome e cognome.';
            }
            if (filter_var($g['email'], FILTER_VALIDATE_EMAIL) === false) {
                $errors[] = "Scrivi l'email del genitore o tutore.";
            }
            if (! Phone::isValid($g['phone'])) {
                $errors[] = 'Scrivi il cellulare del genitore o tutore.';
            }
            if ($g['tax_code'] !== '' && ! TaxCode::isValid($g['tax_code'])) {
                $errors[] = 'Il codice fiscale del genitore non è valido.';
            }
            if (! $ok('guardian_ok')) {
                $errors[] = 'Spunta la casella con cui dichiari di essere il genitore o il tutore.';
            }
            $data['guardian'] = $g;
        }

        if ($needsRules && ! $ok('rules_ok')) {
            $errors[] = 'Per iscriverti devi accettare il regolamento.';
        }
        if (! $ok('privacy_ok')) {
            $errors[] = "Per iscriverti devi accettare l'informativa sulla privacy.";
        }

        return ['errors' => array_values(array_unique($errors)), 'minor' => $minor, 'data' => $data];
    }
}
