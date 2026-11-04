<?php

declare(strict_types=1);

namespace App\Modules\Soci\Http\Controllers;

use App\Modules\Core\Contracts\OrgContext;
use App\Modules\Core\Contracts\OrgDirectory;
use App\Modules\Core\Contracts\OrgSettings;
use App\Modules\Soci\Contracts\ConsentChannel;
use App\Modules\Soci\Contracts\MemberType;
use App\Modules\Soci\Services\ApplicationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Modulo pubblico della domanda di adesione: /adesione/{indirizzo dell'associazione}. Esiste solo se l'associazione è
 * attiva e ha acceso le domande online; le risposte non dicono mai se una persona è già iscritta.
 */
final readonly class PublicApplicationController
{
    public const PRIVACY_URL = 'soci.privacy_url';

    public const RULES_URL = 'soci.rules_url';

    public function __construct(
        private OrgDirectory $directory,
        private OrgContext $context,
        private OrgSettings $settings,
        private ApplicationService $applications,
    ) {}

    public function show(Request $request, string $slug): Response
    {
        $org = $this->enter($slug);

        return Inertia::render('Soci/Adesione', [
            'org' => ['name' => $org['name'], 'slug' => $slug],
            'sent' => $request->session()->get('application_sent') === $slug,
            'needsRules' => $this->applications->needsRules(),
            'types' => array_map(static fn (string $t): array => ['value' => $t, 'label' => MemberType::from($t)->label()], ApplicationService::TYPES),
            'notices' => [
                'privacy' => ConsentChannel::Privacy->notice($org['name']),
                'rules' => ConsentChannel::Rules->notice($org['name']),
                'images' => ConsentChannel::Images->notice($org['name']),
            ],
            'privacyUrl' => $this->url(self::PRIVACY_URL),
            'rulesUrl' => $this->url(self::RULES_URL),
        ]);
    }

    public function store(Request $request, string $slug): RedirectResponse
    {
        $org = $this->enter($slug);
        $back = redirect()->route('application.form', $slug);

        // Campo trappola: le persone non lo vedono, i programmi automatici lo riempiono. Stessa risposta di un invio vero.
        if (trim($request->string('website')->toString()) !== '') {
            return $back->with('application_sent', $slug);
        }

        $input = array_map(
            static fn (mixed $v): string => is_scalar($v) ? mb_substr((string) $v, 0, 200) : '',
            $request->only([
                'first_name', 'last_name', 'email', 'phone', 'tax_code', 'type', 'birth_date', 'birth_place', 'address',
                'guardian_first_name', 'guardian_last_name', 'guardian_email', 'guardian_phone', 'guardian_tax_code',
                'privacy_ok', 'rules_ok', 'images_ok', 'guardian_ok',
            ]),
        );
        $input = array_map(static fn (string $v): string => in_array($v, ['false', '0'], true) ? '' : $v, $input);

        $errors = $this->applications->submit($input, $org['name']);
        if ($errors !== []) {
            $messages = [];
            foreach ($errors as $i => $message) {
                $messages["form_{$i}"] = $message;
            }
            throw ValidationException::withMessages($messages);
        }

        return $back->with('application_sent', $slug);
    }

    /** @return array{id: string, name: string} */
    private function enter(string $slug): array
    {
        $org = $this->directory->activeBySlug($slug);
        abort_if($org === null, 404);
        $this->context->enter($org['id']);
        abort_unless($this->applications->enabled(), 404);

        return $org;
    }

    private function url(string $key): ?string
    {
        $value = $this->settings->get($key);

        return is_string($value) && str_starts_with($value, 'https://') ? $value : null;
    }
}
