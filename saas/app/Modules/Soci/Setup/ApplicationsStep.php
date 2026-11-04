<?php

declare(strict_types=1);

namespace App\Modules\Soci\Setup;

use App\Modules\Core\Contracts\Ability;
use App\Modules\Core\Contracts\Operator;
use App\Modules\Core\Contracts\OrgSettings;
use App\Modules\Core\Contracts\SetupStep;
use App\Modules\Soci\Http\Controllers\PublicApplicationController;
use App\Modules\Soci\Services\ApplicationService;
use App\Modules\Soci\Services\ReminderService;

/** Domande di adesione online, informativa privacy e regolamento, promemoria delle scadenze. */
final readonly class ApplicationsStep implements SetupStep
{
    public function __construct(private OrgSettings $settings) {}

    public function key(): string
    {
        return 'soci';
    }

    public function title(): string
    {
        return 'Domande di adesione e promemoria';
    }

    public function order(): int
    {
        return 40;
    }

    public function visible(Operator $operator): bool
    {
        return $operator->can(Ability::ManageSettings);
    }

    public function props(): array
    {
        $text = fn (string $k): string => is_string($v = $this->settings->get($k)) ? $v : '';

        return [
            'applications_enabled' => $this->settings->get(ApplicationService::ENABLED, false) === true,
            'applications_rules' => $this->settings->get(ApplicationService::NEEDS_RULES, false) === true,
            'privacy_url' => $text(PublicApplicationController::PRIVACY_URL),
            'rules_url' => $text(PublicApplicationController::RULES_URL),
            'reminders_enabled' => $this->settings->get(ReminderService::ENABLED, false) === true,
        ];
    }

    public function errors(array $input): array
    {
        $errors = [];
        foreach (['privacy_url' => 'dell\'informativa privacy', 'rules_url' => 'del regolamento'] as $k => $label) {
            $url = is_string($input[$k] ?? null) ? trim($input[$k]) : '';
            if ($url !== '' && (! str_starts_with($url, 'https://') || filter_var($url, FILTER_VALIDATE_URL) === false || mb_strlen($url) > 300)) {
                $errors[] = "L'indirizzo {$label} deve essere una pagina https://.";
            }
        }
        if (! empty($input['applications_enabled']) && (! is_string($input['privacy_url'] ?? null) || trim($input['privacy_url']) === '')) {
            $errors[] = 'Per accettare domande online serve l\'indirizzo dell\'informativa privacy.';
        }

        return $errors;
    }

    public function save(array $input): void
    {
        $url = static fn (string $k): ?string => is_string($input[$k] ?? null) && trim($input[$k]) !== '' ? trim($input[$k]) : null;
        $this->settings->set(ApplicationService::ENABLED, ! empty($input['applications_enabled']));
        $this->settings->set(ApplicationService::NEEDS_RULES, ! empty($input['applications_rules']));
        $this->settings->set(PublicApplicationController::PRIVACY_URL, $url('privacy_url'));
        $this->settings->set(PublicApplicationController::RULES_URL, $url('rules_url'));
        $this->settings->set(ReminderService::ENABLED, ! empty($input['reminders_enabled']));
    }
}
