<?php

declare(strict_types=1);

namespace App\Modules\Attivita\Rules;

use App\Modules\Attivita\Contracts\ActivityKind;
use App\Modules\Attivita\Contracts\Billing;
use App\Modules\Soci\Contracts\MemberType;

/** Dati di un'attività (dal Rules::validate_activity del plugin). */
final class ActivityRules
{
    /**
     * @param  array<string, mixed>  $d
     * @return list<string>
     */
    public static function validate(array $d, ?MemberType $instructorType = null): array
    {
        $text = static fn (string $k): string => is_scalar($d[$k] ?? null) ? trim((string) $d[$k]) : '';
        $errors = [];
        if ($text('name') === '') {
            $errors[] = 'Il nome dell\'attività è obbligatorio.';
        } elseif (mb_strlen($text('name')) > 160) {
            $errors[] = 'Il nome dell\'attività è troppo lungo.';
        }
        if (ActivityKind::tryFrom($text('kind')) === null) {
            $errors[] = 'Tipo di attività non valido.';
        }
        if ($text('billing') !== '' && Billing::tryFrom($text('billing')) === null) {
            $errors[] = 'Modalità di pagamento non valida.';
        }
        if (! is_int($d['fee_cents'] ?? 0) || ($d['fee_cents'] ?? 0) < 0) {
            $errors[] = 'Il contributo non può essere negativo.';
        }
        if (($d['guest_fee_cents'] ?? null) !== null && (! is_int($d['guest_fee_cents']) || $d['guest_fee_cents'] < 0)) {
            $errors[] = 'Il contributo per gli ospiti non può essere negativo.';
        }
        if (($d['instructor_person_id'] ?? null) !== null && $instructorType?->canTeach() !== true) {
            $errors[] = 'Il referente di un\'attività dev\'essere un socio volontario.';
        }
        foreach (['starts_on' => 'inizio', 'ends_on' => 'fine'] as $k => $label) {
            $v = $text($k);
            if ($v !== '' && (preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) !== 1 || ! checkdate((int) substr($v, 5, 2), (int) substr($v, 8, 2), (int) substr($v, 0, 4)))) {
                $errors[] = "La data di {$label} non è valida.";
            }
        }
        if ($text('starts_on') !== '' && $text('ends_on') !== '' && $text('ends_on') < $text('starts_on')) {
            $errors[] = 'La data di fine viene prima di quella di inizio.';
        }
        if ($text('kind') === ActivityKind::Event->value && $text('starts_on') === '') {
            $errors[] = 'Indica la data dell\'evento.';
        }
        foreach (is_array($d['weekdays'] ?? null) ? $d['weekdays'] : [] as $day) {
            if (! is_int($day) || $day < 1 || $day > 7) {
                $errors[] = 'Giorno della settimana non valido.';
                break;
            }
        }

        return $errors;
    }
}
