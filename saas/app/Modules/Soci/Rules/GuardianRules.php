<?php

declare(strict_types=1);

namespace App\Modules\Soci\Rules;

use App\Modules\Soci\Contracts\MemberType;

/**
 * Collegamento tra un minore e i suoi tutori (dal Guardians::validate del plugin). Al massimo due tutori; quello
 * «operativo» segue il minore nell'area soci e deve poter entrare, l'altro basta che sia censito.
 */
final class GuardianRules
{
    public const MAX = 2;

    /**
     * @param  array{id: string, birth_date: string|null}  $minor
     * @param  array{id: string, type: MemberType, email: string|null, birth_date: string|null}  $guardian
     * @return list<string>
     */
    public static function validate(array $minor, array $guardian, int $alreadyLinked, string $today, bool $operative = true): array
    {
        $errors = [];
        if ($minor['id'] === $guardian['id']) {
            $errors[] = 'Una persona non può essere tutore di se stessa.';
        }
        if ($minor['birth_date'] === null || $minor['birth_date'] === '') {
            $errors[] = 'Per collegare un tutore serve la data di nascita del minore.';
        } elseif (! Age::isMinor($minor['birth_date'], $today)) {
            $errors[] = 'Si può indicare un tutore solo per una persona minorenne.';
        }
        if ($guardian['type'] === MemberType::Guest) {
            $errors[] = 'Un ospite non può fare da tutore: cambia il tipo della sua scheda in «Genitore o tutore (non socio)».';
        } elseif ($operative && ! $guardian['type']->canLogin()) {
            $errors[] = 'Il tutore deve essere un socio oppure una persona registrata come «Genitore o tutore (non socio)».';
        }
        $email = trim((string) $guardian['email']);
        if ($operative && ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false)) {
            $errors[] = "Il tutore deve avere un'email.";
        }
        if (Age::isMinor($guardian['birth_date'], $today)) {
            $errors[] = 'Il tutore deve essere maggiorenne.';
        }
        if ($alreadyLinked >= self::MAX) {
            $errors[] = 'Un minore può avere al massimo '.self::MAX.' tutori (due genitori, oppure un tutore legale).';
        }

        return $errors;
    }
}
