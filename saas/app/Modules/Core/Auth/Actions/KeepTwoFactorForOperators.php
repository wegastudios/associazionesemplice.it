<?php

declare(strict_types=1);

namespace App\Modules\Core\Auth\Actions;

use App\Modules\Core\Access\OrgTwoFactor;
use App\Modules\Core\Models\User;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Actions\DisableTwoFactorAuthentication;

/**
 * La verifica in due passaggi è una scelta personale: chi l'ha accesa la può spegnere, tranne quando c'è l'obbligo per
 * tutti o quando una delle associazioni in cui lavora la chiede (OrgTwoFactor).
 */
final class KeepTwoFactorForOperators extends DisableTwoFactorAuthentication
{
    public function __construct(private readonly OrgTwoFactor $twoFactor) {}

    /** @param  mixed  $user */
    public function __invoke($user): void
    {
        if ($user instanceof User && $this->twoFactor->mustKeep($user->id)) {
            $orgs = $this->twoFactor->requiringOrgs($user->id);
            throw ValidationException::withMessages([
                'two_factor' => match (count($orgs)) {
                    0 => 'Per chi lavora nel gestionale la verifica in due passaggi è obbligatoria.',
                    1 => "Non puoi disattivarla: l'associazione «{$orgs[0]}» chiede la verifica in due passaggi a chi ci lavora.",
                    default => 'Non puoi disattivarla: le associazioni '.implode(', ', array_map(static fn (string $n): string => "«{$n}»", $orgs)).' chiedono la verifica in due passaggi a chi ci lavora.',
                },
            ]);
        }

        parent::__invoke($user);
    }
}
