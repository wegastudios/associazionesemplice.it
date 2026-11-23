<?php

declare(strict_types=1);

namespace App\Modules\Soci\Services;

use App\Modules\Core\Contracts\OrgContext;
use App\Modules\Core\Contracts\OrgSettings;
use App\Modules\Piattaforma\Contracts\Catalog;
use App\Modules\Portale\Contracts\Entitlements;
use App\Modules\Soci\Contracts\Members;
use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\DatabaseManager;

/**
 * Limite di soci del piano (decisione della titolare, 13/11/2026). Il tetto è nei diritti (piano più pacchetti di
 * soci); le percentuali e i giorni sono nelle Condizioni del catalogo (terms.members). Contano soci ed ex soci
 * (chi non li vuole li cancella) e gli ospiti dell'anno corrente oltre la quota libera; non gli ospiti degli anni
 * passati. Avviso all'80%, al 100% si può proseguire fino al +10% per 15 giorni, poi si bloccano solo i NUOVI soci:
 * accesso, dati e soci già inseriti restano come sono.
 *
 * @phpstan-type Status array{limit: int|null, count: int, state: 'none'|'ok'|'warn'|'tolerance'|'blocked', message: string, tolerated: int|null, block_on: string|null, plan: string|null, next_plan: string|null}
 */
final readonly class MemberLimit
{
    public const REACHED_ON = 'soci.limit_reached_on';

    private const DEFAULTS = ['warn_percent' => 80, 'full_percent' => 100, 'tolerance_percent' => 10, 'tolerance_days' => 15, 'guests_free_percent' => 10];

    public function __construct(
        private Entitlements $entitlements,
        private Catalog $catalog,
        private DatabaseManager $db,
        private OrgContext $context,
        private OrgSettings $settings,
        private Members $members,
    ) {}

    /** @return Status */
    public function status(?CarbonImmutable $today = null): array
    {
        $today ??= CarbonImmutable::today();
        $org = $this->context->require()->id;
        $limit = $this->entitlements->for($org)->membersLimit;
        $plan = $this->plans($org);
        if ($limit === null || $limit <= 0) {
            return ['limit' => null, 'count' => 0, 'state' => 'none', 'message' => '', 'tolerated' => null, 'block_on' => null] + $plan;
        }

        $rules = $this->rules();
        $count = $this->count($limit, $rules['guests_free_percent']);
        $tolerated = $limit + (int) ceil($limit * $rules['tolerance_percent'] / 100);
        $name = $plan['plan'] ?? 'attuale';
        $next = $plan['next_plan'];
        $nextText = $next !== null ? "il piano {$next}" : 'un pacchetto di soci in più';

        $reached = $this->reachedOn($count >= $limit, $today);
        $blockOn = $reached?->addDays($rules['tolerance_days'])->toDateString();
        $full = $count * 100 >= $limit * $rules['full_percent'];

        if ($full && ($count >= $tolerated || ($blockOn !== null && $today->toDateString() > $blockOn))) {
            return ['limit' => $limit, 'count' => $count, 'state' => 'blocked', 'tolerated' => $tolerated, 'block_on' => $blockOn,
                'message' => self::blocked($limit, $name, $nextText)] + $plan;
        }
        if ($full) {
            $until = $blockOn !== null ? CarbonImmutable::parse($blockOn)->format('d/m/Y') : '';
            $untilText = $until !== '' ? ", fino al {$until}" : '';
            $dayWord = $rules['tolerance_days'] === 1 ? 'giorno' : 'giorni';

            return ['limit' => $limit, 'count' => $count, 'state' => 'tolerance', 'tolerated' => $tolerated, 'block_on' => $blockOn,
                'message' => "Hai raggiunto i {$limit} soci del piano {$name}. Puoi continuare ad aggiungerne fino a {$tolerated} per {$rules['tolerance_days']} {$dayWord}{$untilText}. Poi serve {$nextText}."] + $plan;
        }
        if ($count * 100 >= $limit * $rules['warn_percent']) {
            return ['limit' => $limit, 'count' => $count, 'state' => 'warn', 'tolerated' => $tolerated, 'block_on' => null,
                'message' => "Hai {$count} soci su {$limit} del piano {$name}. Quando arrivi al limite, per aggiungerne altri serve {$nextText}."] + $plan;
        }

        return ['limit' => $limit, 'count' => $count, 'state' => 'ok', 'message' => '', 'tolerated' => $tolerated, 'block_on' => null] + $plan;
    }

    /** Messaggio di blocco se i nuovi soci non sono più ammessi, altrimenti null. */
    public function blockMessage(): ?string
    {
        $status = $this->status();

        return $status['state'] === 'blocked' ? $status['message'] : null;
    }

    /**
     * Quanti nuovi soci si possono ancora aggiungere adesso (null: nessun limite) e il messaggio di blocco per quando sono
     * finiti: gli import contano in memoria, una volta per blocco, invece di ricontare a ogni riga. Stesse regole di
     * status(): il blocco scatta alla tolleranza, o al pieno se i giorni di tolleranza sono già passati.
     *
     * @return array{left: int|null, message: string}
     */
    public function allowance(?CarbonImmutable $today = null): array
    {
        $today ??= CarbonImmutable::today();
        $status = $this->status($today);
        if ($status['limit'] === null) {
            return ['left' => null, 'message' => ''];
        }
        $limit = $status['limit'];
        $message = self::blocked($limit, $status['plan'] ?? 'attuale', $status['next_plan'] !== null ? "il piano {$status['next_plan']}" : 'un pacchetto di soci in più');
        if ($status['state'] === 'blocked') {
            return ['left' => 0, 'message' => $message];
        }
        $fullAt = (int) ceil($limit * $this->rules()['full_percent'] / 100);
        $late = $status['block_on'] !== null && $today->toDateString() > $status['block_on'];

        return ['left' => max(0, ($late ? $fullAt : max($fullAt, (int) $status['tolerated'])) - $status['count']), 'message' => $message];
    }

    private static function blocked(int $limit, string $plan, string $next): string
    {
        return "Hai raggiunto i {$limit} soci del piano {$plan}. Per aggiungerne altri passa a {$next}: i soci e i dati che hai già restano come sono.";
    }

    /** Soci ed ex soci (non cancellati) più gli ospiti dell'anno corrente oltre la quota libera. */
    private function count(int $limit, int $guestsFreePercent): int
    {
        $c = $this->connection();
        $members = $c->table('app.people')->whereNull('deleted_at')->whereNull('anonymized_at')
            ->whereIn('type', ['founder', 'ordinary', 'volunteer'])->count();

        $year = $this->members->membershipYearInfo($this->members->membershipYearLabel());
        $guests = $year === null ? 0 : $c->table('app.people')->whereNull('deleted_at')->whereNull('anonymized_at')->where('type', 'guest')
            ->whereRaw('coalesce(joined_on, created_at::date) between ? and ?', [$year['start'], $year['end']])->count();
        $free = (int) floor($limit * $guestsFreePercent / 100);

        return $members + max(0, $guests - $free);
    }

    /** Il giorno in cui si è arrivati al limite: si ricorda finché si resta sopra, si azzera appena si scende. */
    private function reachedOn(bool $atLimit, CarbonImmutable $today): ?CarbonImmutable
    {
        $saved = $this->settings->get(self::REACHED_ON);
        if (! $atLimit) {
            if ($saved !== null) {
                $this->settings->set(self::REACHED_ON, null);
            }

            return null;
        }
        if (is_string($saved) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $saved) === 1) {
            return CarbonImmutable::parse($saved);
        }
        $this->settings->set(self::REACHED_ON, $today->toDateString());

        return $today;
    }

    /** @return array{warn_percent: int, full_percent: int, tolerance_percent: int, tolerance_days: int, guests_free_percent: int} */
    private function rules(): array
    {
        $terms = $this->catalog->published()->document['terms'] ?? null;
        $m = is_array($terms) && is_array($terms['members'] ?? null) ? $terms['members'] : [];
        $out = self::DEFAULTS;
        foreach (self::DEFAULTS as $key => $default) {
            $out[$key] = is_int($m[$key] ?? null) ? $m[$key] : $default;
        }

        return $out;
    }

    /** @return array{plan: string|null, next_plan: string|null} */
    private function plans(string $org): array
    {
        $e = $this->entitlements->for($org);
        $published = $this->catalog->published();
        $current = $e->plan !== null && $e->planVersion !== null ? $this->catalog->plan($e->plan, $e->planVersion) : null;
        $order = is_array($current) && is_int($current['order'] ?? null) ? $current['order'] : null;
        $name = is_array($current) && is_string($current['name'] ?? null) ? $current['name'] : null;

        $next = null;
        $nextOrder = null;
        foreach (is_array($published->document['plans'] ?? null) ? $published->document['plans'] : [] as $p) {
            if (! is_array($p) || ($p['visible'] ?? false) !== true || ! is_int($p['order'] ?? null) || ! is_string($p['name'] ?? null)) {
                continue;
            }
            if ($order !== null && $p['order'] > $order && ($nextOrder === null || $p['order'] < $nextOrder)) {
                $next = $p['name'];
                $nextOrder = $p['order'];
            }
        }

        return ['plan' => $name, 'next_plan' => $next];
    }

    private function connection(): ConnectionInterface
    {
        return $this->db->connection($this->context->require()->connection);
    }
}
