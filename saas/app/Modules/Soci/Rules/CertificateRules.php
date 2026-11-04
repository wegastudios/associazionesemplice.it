<?php

declare(strict_types=1);

namespace App\Modules\Soci\Rules;

use App\Modules\Soci\Contracts\CertificateKind;
use App\Modules\Soci\Contracts\PeopleError;

/** Certificato medico sportivo: controllo dei dati e certificato che conta oggi. Dal plugin. */
final class CertificateRules
{
    /**
     * @param  array<mixed>  $in  kind, issued_on, expires_on, doctor
     * @return array{kind: CertificateKind, issued_on: string, expires_on: string, doctor: string}
     */
    public static function clean(array $in): array
    {
        $text = static fn (string $key): string => is_string($in[$key] ?? null) ? trim($in[$key]) : '';
        $kind = CertificateKind::tryFrom($text('kind')) ?? throw new PeopleError(['Scegli il tipo di certificato.']);
        $issued = $text('issued_on');
        $expires = $text('expires_on');
        if (! Age::isValidDate($issued)) {
            throw new PeopleError(['Indica la data di rilascio del certificato.']);
        }
        if (! Age::isValidDate($expires)) {
            throw new PeopleError(['Indica la data di scadenza del certificato.']);
        }
        if ($expires < $issued) {
            throw new PeopleError(['Il certificato non può scadere prima di essere stato rilasciato.']);
        }

        return ['kind' => $kind, 'issued_on' => $issued, 'expires_on' => $expires, 'doctor' => mb_substr(strip_tags($text('doctor')), 0, 190)];
    }

    /**
     * Il certificato che conta: tra quelli già rilasciati, quello che scade più tardi (a parità, l'ultimo inserito).
     *
     * @template T of array{id: string, issued_on: string, expires_on: string}
     *
     * @param  list<T>  $certs  dal più vecchio al più recente inserito
     * @return T|null
     */
    public static function current(array $certs, string $today): ?array
    {
        $best = null;
        foreach ($certs as $c) {
            if ($c['issued_on'] > $today) {
                continue;
            }
            if ($best === null || $c['expires_on'] >= $best['expires_on']) {
                $best = $c;
            }
        }

        return $best;
    }

    /** @param  list<array{id: string, issued_on: string, expires_on: string}>  $certs */
    public static function status(array $certs, string $today, int $warn): string
    {
        return ExpiryRules::status(self::current($certs, $today)['expires_on'] ?? null, $today, $warn);
    }
}
