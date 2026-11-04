<?php

declare(strict_types=1);

namespace App\Modules\Core\Org;

use App\Modules\Audit\Contracts\AuditLog;
use App\Modules\Core\Contracts\Operator;
use App\Modules\Core\Contracts\OrgContext;
use App\Modules\Core\Contracts\OrgSettings;
use App\Modules\Core\Contracts\SetupStep;
use Illuminate\Contracts\Container\Container;
use Illuminate\Database\DatabaseManager;

/**
 * Configurazione guidata al primo accesso (dal Wizard del plugin): in un'unica pagina le impostazioni principali, salvate
 * insieme. Si propone a titolare e amministratore finché non è completata o rimandata, e si riapre quando serve.
 */
final readonly class SetupWizard
{
    public const STATUS = 'core.setup_status';

    public const DONE = 'done';

    public const SKIPPED = 'skipped';

    public function __construct(
        private Container $container,
        private OrgSettings $settings,
        private OrgContext $context,
        private DatabaseManager $db,
        private AuditLog $audit,
    ) {}

    public function status(): ?string
    {
        $s = $this->settings->get(self::STATUS);

        return in_array($s, [self::DONE, self::SKIPPED], true) ? $s : null;
    }

    public function pending(): bool
    {
        return $this->status() === null;
    }

    /** @return list<SetupStep> i passi per questo operatore, in ordine */
    public function steps(Operator $operator): array
    {
        $steps = [];
        foreach ($this->container->tagged(SetupStep::TAG) as $step) {
            if ($step instanceof SetupStep && $step->visible($operator)) {
                $steps[] = $step;
            }
        }
        usort($steps, static fn (SetupStep $a, SetupStep $b): int => $a->order() <=> $b->order());

        return $steps;
    }

    /**
     * Controlla tutti i passi e, se vanno bene, li salva insieme.
     *
     * @param  array<mixed>  $input  steps[<key>][campo]
     * @return array<string, list<string>> errori per passo (vuoto se salvato)
     */
    public function save(Operator $operator, array $input): array
    {
        $steps = $this->steps($operator);
        $errors = [];
        foreach ($steps as $step) {
            $e = $step->errors(self::part($input, $step->key()));
            if ($e !== []) {
                $errors[$step->key()] = $e;
            }
        }
        if ($errors !== []) {
            return $errors;
        }

        $this->db->connection($this->context->require()->connection)->transaction(function () use ($steps, $input): void {
            foreach ($steps as $step) {
                $step->save(self::part($input, $step->key()));
            }
            $this->settings->set(self::STATUS, self::DONE);
            $this->audit->record('setup.completed', 'app.settings', null, ['steps' => implode(',', array_map(static fn (SetupStep $s): string => $s->key(), $steps))]);
        });

        return [];
    }

    public function skip(): void
    {
        if ($this->pending()) {
            $this->settings->set(self::STATUS, self::SKIPPED);
            $this->audit->record('setup.skipped', 'app.settings', null);
        }
    }

    /**
     * @param  array<mixed>  $input
     * @return array<mixed>
     */
    private static function part(array $input, string $key): array
    {
        $steps = $input['steps'] ?? null;

        return is_array($steps) && is_array($steps[$key] ?? null) ? $steps[$key] : [];
    }
}
