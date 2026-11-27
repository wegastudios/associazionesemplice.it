<?php

declare(strict_types=1);

namespace App\Modules\Core\Access;

use App\Modules\Core\Contracts\OrgContext;
use App\Modules\Core\Contracts\OrgSettings;

/**
 * Verifica in due passaggi per chi lavora nel gestionale (decisione del titolare del 26/11/2026): è una scelta personale,
 * ma un'associazione può renderla obbligatoria per tutti i suoi operatori (titolare, amministratori, operatori,
 * dipendenti, commercialista, ruoli personalizzati). Le associazioni nuove nascono con l'obbligo spento.
 *
 * L'obbligo per tutti (`saas.auth.require_two_factor`) resta: acceso, vale per ogni operatore come prima.
 */
final readonly class OrgTwoFactor
{
    /** Impostazione dell'associazione (app.settings): true = obbligatoria per chi ci lavora. */
    public const KEY = 'core.two_factor_staff';

    public function __construct(
        private Memberships $memberships,
        private OrgContext $context,
        private OrgSettings $settings,
    ) {}

    /** L'associazione corrente chiede la verifica a chi ci lavora. */
    public function requiredHere(): bool
    {
        return $this->settings->get(self::KEY, false) === true;
    }

    public function setRequiredHere(bool $required): void
    {
        $this->settings->set(self::KEY, $required);
    }

    /**
     * Nomi delle associazioni di questa persona che chiedono la verifica (vuoto se nessuna).
     *
     * @return list<string>
     */
    public function requiringOrgs(string $userId): array
    {
        $names = [];
        foreach ($this->memberships->forUser($userId) as $operator) {
            if ($this->context->run($operator->orgId, fn (): bool => $this->requiredHere())) {
                $names[] = $operator->orgName;
            }
        }

        return $names;
    }

    /** Non può spegnerla: c'è l'obbligo per tutti, oppure una delle sue associazioni la chiede. */
    public function mustKeep(string $userId): bool
    {
        if (! $this->memberships->isOperator($userId)) {
            return false;
        }

        return config('saas.auth.require_two_factor') !== false || $this->requiringOrgs($userId) !== [];
    }
}
