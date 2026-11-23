<?php

declare(strict_types=1);

namespace App\Modules\Soci\Services;

use App\Modules\Audit\Contracts\AuditLog;
use App\Modules\Core\Contracts\ImportJobs;
use App\Modules\Core\Contracts\OrgContext;
use App\Modules\Documenti\Contracts\SheetReader;
use App\Modules\Soci\Contracts\MemberType;
use App\Modules\Soci\Contracts\PeopleError;
use App\Modules\Soci\Import\PeopleFinder;
use App\Modules\Soci\Import\PeopleSheet;
use App\Modules\Soci\Models\Person;
use Illuminate\Database\DatabaseManager;
use InvalidArgumentException;

/**
 * Import di soci, ospiti e genitori da Excel o CSV (dall'ImportService del plugin). Prima l'anteprima (cosa nasce,
 * cosa si aggiorna, cosa non va), poi l'applicazione dello stesso file. Una riga che non va non ferma le altre.
 *
 * L'applicazione è un lavoro in coda (H10): a blocchi, una transazione per blocco, senza query ripetute per riga (limite
 * soci contato in memoria, ospitanti e tutori cercati in memoria, tessere scritte insieme) e con una sola riga di registro
 * per tutto l'import. apply() fa lo stesso subito, nella richiesta: stesso esito.
 *
 * @phpstan-import-type Plan from PeopleSheet
 * @phpstan-import-type Existing from PeopleSheet
 *
 * @phpstan-type Result array{created: int, updated: int, memberships: int, guardians: int, failed: list<string>}
 * @phpstan-type Link array{0: string, 1: string, 2: string}
 * @phpstan-type Item array{pass: int, plan: Plan}
 */
final readonly class PeopleImportService
{
    public const MAX_ROWS = 5000;

    public const REASON = 'Importata da file';

    public const KIND = 'people';

    public function __construct(
        private DatabaseManager $db,
        private OrgContext $context,
        private PeopleService $people,
        private MembershipService $memberships,
        private GuardianService $guardians,
        private MemberLimit $limit,
        private AuditLog $audit,
        private ImportJobs $jobs,
    ) {}

    /**
     * @return array{error: string}|array{plan: list<Plan>, sheets: list<string>}
     */
    public function preview(string $path, string $originalName, MemberType $defaultType): array
    {
        try {
            $sheets = SheetReader::read($path, $originalName);
        } catch (InvalidArgumentException $e) {
            return ['error' => $e->getMessage()];
        }

        $rows = [];
        $used = [];
        $firstError = null;
        foreach ($sheets as $sheet) {
            $parsed = PeopleSheet::parseTable($sheet['rows'], $sheet['lines'], count($sheets) > 1 ? $sheet['name'] : '');
            if (isset($parsed['error'])) {
                $firstError ??= $parsed['error'];

                continue;
            }
            $used[] = $sheet['name'];
            array_push($rows, ...$parsed['rows']);
        }
        if ($used === []) {
            return ['error' => $firstError ?? 'Nel file non c\'è un elenco di persone.'];
        }
        if (count($rows) > self::MAX_ROWS) {
            return ['error' => 'Il file ha più di '.self::MAX_ROWS.' righe: dividilo in più file.'];
        }

        return ['plan' => PeopleSheet::plan($rows, $this->existing(), $defaultType), 'sheets' => $used];
    }

    /**
     * Colonne con un anno («2025») che con l'anno fiscale non da gennaio diventano un'altra etichetta («2025/2026»):
     * non cambiano in silenzio, la persona le vede in anteprima e conferma.
     *
     * @param  list<Plan>  $plan
     * @return array{count: int, examples: list<string>}
     */
    public function yearsNote(array $plan): array
    {
        $startMonth = $this->memberships->startMonth();
        $count = 0;
        $examples = [];
        foreach ($plan as $p) {
            if ($p['action'] === 'error' || ! in_array($p['type'], [MemberType::Ordinary->value, MemberType::Volunteer->value], true)) {
                continue;
            }
            foreach ($p['row']['years'] as $y) {
                if ($startMonth !== 1 && preg_match('/^\d{4}$/', $y) === 1) {
                    $count++;
                    $examples[$y] = $y.' → '.$y.'/'.((int) $y + 1);
                }
            }
        }

        return ['count' => $count, 'examples' => array_slice(array_values($examples), 0, 5)];
    }

    /**
     * Mette in coda l'import dello stesso piano dell'anteprima. null se ce n'è già uno di soci in corso.
     *
     * @param  list<Plan>  $plan
     */
    public function queue(array $plan, bool $markCurrentYear, ?string $userId): ?string
    {
        return $this->jobs->create(self::KIND, self::items($plan), ['mark_current' => $markCurrentYear], self::emptyState(), $userId);
    }

    /**
     * L'import di soci da mostrare a schermo (in coda, in corso, fermo, o finito da poco), o null.
     *
     * @return array{id: string, status: string, total: int, done: int, stalled: bool, result: Result}|null
     */
    public function current(): ?array
    {
        $job = $this->jobs->current(self::KIND);

        return $job === null ? null : ['id' => $job['id'], 'status' => $job['status'], 'total' => $job['total'], 'done' => $job['done'],
            'stalled' => $job['stalled'], 'result' => self::result($job['state'])];
    }

    public function cancel(string $id): bool
    {
        return $this->jobs->cancel($id, self::KIND);
    }

    public function resume(string $id): bool
    {
        return $this->jobs->resume($id, self::KIND);
    }

    /** Un giro del lavoro in coda: false se resta da fare (chi chiama lo rimette in coda). */
    public function runQueued(string $id, int $blockSize, int $seconds): bool
    {
        $finder = null;

        return $this->jobs->run(
            $id,
            $blockSize,
            $seconds,
            function (array $items, array $state, array $options) use (&$finder): array {
                return $this->block($items, $state, ($options['mark_current'] ?? false) === true, $finder);
            },
            fn (array $state): array => $this->finish($state),
        );
    }

    /**
     * Applica subito il piano, a blocchi, come il lavoro in coda.
     *
     * @param  list<Plan>  $plan
     * @return Result
     */
    public function apply(array $plan, bool $markCurrentYear, int $blockSize = 100): array
    {
        $connection = $this->db->connection($this->context->require()->connection);
        $state = self::emptyState();
        $finder = null;
        foreach (array_chunk(self::items($plan), max(1, $blockSize)) as $chunk) {
            $state = $connection->transaction(function () use ($chunk, $state, $markCurrentYear, &$finder): array {
                return $this->block($chunk, $state, $markCurrentYear, $finder);
            });
        }

        return self::result($connection->transaction(fn (): array => $this->finish($state)));
    }

    /**
     * Le righe da applicare, in ordine: prima i soci (l'ospitante può essere nuovo), poi gli ospiti.
     *
     * @param  list<Plan>  $plan
     * @return list<Item>
     */
    private static function items(array $plan): array
    {
        $byPass = [0 => [], 1 => []];
        foreach ($plan as $p) {
            if ($p['action'] !== 'error') {
                $pass = $p['type'] === MemberType::Guest->value ? 1 : 0;
                $byPass[$pass][] = ['pass' => $pass, 'plan' => $p];
            }
        }

        return [...$byPass[0], ...$byPass[1]];
    }

    /** @return array{result: Result, links: list<Link>} */
    private static function emptyState(): array
    {
        return ['result' => ['created' => 0, 'updated' => 0, 'memberships' => 0, 'guardians' => 0, 'failed' => []], 'links' => []];
    }

    /**
     * Un blocco, dentro la sua transazione. Le schede si salvano senza la riga di registro di ognuna: alla fine ne va una
     * sola per tutto l'import.
     *
     * @param  list<mixed>  $items
     * @param  array<string, mixed>  $state
     * @return array{result: Result, links: list<Link>}
     */
    private function block(array $items, array $state, bool $markCurrentYear, ?PeopleFinder &$finder): array
    {
        /** @var list<Item> $items */
        $res = self::result($state);
        /** @var list<Link> $links */
        $links = is_array($state['links'] ?? null) ? array_values($state['links']) : [];
        $current = $this->memberships->membershipYear();
        $startMonth = $this->memberships->startMonth();
        $allowance = $this->limit->allowance();
        $left = $allowance['left'];
        $ids = array_values(array_filter(array_map(static fn (array $i): ?string => $i['plan']['action'] === 'update' ? $i['plan']['matched_id'] : null, $items)));
        $matched = $ids === [] ? [] : Person::query()->whereIn('id', $ids)->get()->keyBy('id')->all();
        $grants = [];

        Person::withoutAudit(function () use ($items, $markCurrentYear, $current, $startMonth, $allowance, $matched, &$left, &$res, &$links, &$grants, &$finder): void {
            foreach ($items as ['pass' => $pass, 'plan' => $p]) {
                $r = $p['row'];
                $where = ($r['sheet'] !== '' ? $r['sheet'].' ' : '').'riga '.$r['line'];
                try {
                    $data = $this->data($r, $pass === 0);
                    if ($pass === 1) {
                        $finder ??= new PeopleFinder($this->existing()); // con le schede appena nate: ospitanti e tutori possono essere nel file
                        $host = $p['host_id'] ?? $finder->find((string) $p['host_text']);
                        if ($host === null) {
                            throw new PeopleError(['socio ospitante «'.$p['host_text'].'» non trovato']);
                        }
                        $data['host_person_id'] = $host;
                    }
                    if ($p['action'] === 'create') {
                        $counts = MemberType::tryFrom((string) $p['type'])?->isMember() === true;
                        if ($counts && $left !== null && $left <= 0) {
                            throw new PeopleError([$allowance['message']]);
                        }
                        $person = $this->people->create($data + ['type' => $p['type']], limitChecked: true);
                        $res['created']++;
                    } else {
                        $existing = $matched[(string) $p['matched_id']] ?? throw new PeopleError(['La scheda da aggiornare non c\'è più.']);
                        if ($p['type_given'] && $pass === 0) {
                            $data['type'] = $p['type'];
                        }
                        // Passare da non socio a socio conta nel limite come un socio nuovo.
                        $counts = MemberType::tryFrom(is_string($data['type'] ?? null) ? $data['type'] : '')?->isMember() === true && ! $existing->type->isMember();
                        if ($counts && $left !== null && $left <= 0) {
                            throw new PeopleError([$allowance['message']]);
                        }
                        $person = $this->people->update($existing, $data, limitChecked: true);
                        $res['updated']++;
                    }
                    if ($counts && $left !== null) {
                        $left--;
                    }
                    if ($r['guardian'] !== null) {
                        $links[] = [$person->id, $r['guardian'], $where];
                    }
                    if (in_array($person->type, [MemberType::Ordinary, MemberType::Volunteer], true)) {
                        $years = array_map(static fn (string $y): string => match ($y) {
                            '@current' => $current->label(),
                            '@previous' => $current->previous()->label(),
                            default => $startMonth === 1 ? $y : $y.'/'.((int) $y + 1),
                        }, $r['years']);
                        if ($markCurrentYear) {
                            $years[] = $current->label();
                        }
                        if ($years !== [] && $person->left_on !== null) {
                            throw new PeopleError(['Questa persona è uscita dall\'associazione: non può avere la tessera.']);
                        }
                        foreach (array_unique($years) as $label) {
                            $grants[] = [$person->id, $label];
                        }
                    }
                } catch (PeopleError $e) {
                    $res['failed'][] = $where.': '.implode(' ', $e->messages);
                }
            }
        });
        $res['memberships'] += $this->memberships->grantImported($grants);

        return ['result' => $res, 'links' => $links];
    }

    /**
     * Alla fine, nella transazione dell'ultimo blocco: i tutori (possono essere nello stesso file) e la riga di registro.
     *
     * @param  array<string, mixed>  $state
     * @return array{result: Result, links: list<Link>}
     */
    private function finish(array $state): array
    {
        $res = self::result($state);
        /** @var list<Link> $links */
        $links = is_array($state['links'] ?? null) ? array_values($state['links']) : [];
        $finder = $links === [] ? null : new PeopleFinder($this->existing());
        foreach ($links as [$personId, $ref, $where]) {
            $guardian = $finder?->find($ref);
            if ($guardian === null) {
                $res['failed'][] = $where.': tutore «'.$ref.'» non trovato o ambiguo (indica la tessera o l\'email)';

                continue;
            }
            try {
                $res['guardians'] += $this->guardians->link($personId, $guardian) ? 1 : 0;
            } catch (PeopleError $e) {
                $res['failed'][] = $where.': '.implode(' ', $e->messages);
            }
        }
        // Il giorno in cui si arriva al limite del piano resta ricordato, come quando si ricontava a ogni scheda.
        $this->limit->status();
        $this->audit->record('people.imported', 'app.people', null, [
            'created' => $res['created'], 'updated' => $res['updated'], 'memberships' => $res['memberships'],
            'guardians' => $res['guardians'], 'failed' => count($res['failed']),
        ]);

        return ['result' => $res, 'links' => []];
    }

    /**
     * L'esito salvato nello stato del lavoro.
     *
     * @param  array<string, mixed>  $state
     * @return Result
     */
    public static function result(array $state): array
    {
        $r = is_array($state['result'] ?? null) ? $state['result'] : [];
        $int = static fn (string $k): int => is_int($r[$k] ?? null) ? $r[$k] : 0;

        return [
            'created' => $int('created'), 'updated' => $int('updated'), 'memberships' => $int('memberships'), 'guardians' => $int('guardians'),
            'failed' => is_array($r['failed'] ?? null) ? array_values(array_filter($r['failed'], 'is_string')) : [],
        ];
    }

    /**
     * Campi della scheda dal foglio: solo quelli presenti, così un aggiornamento non cancella i dati già in scheda.
     *
     * @param  array<string, mixed>  $r
     * @return array<string, mixed>
     */
    private function data(array $r, bool $withCard): array
    {
        $data = ['first_name' => $r['first'], 'last_name' => $r['last']];
        if ($r['entity']) {
            $data['is_entity'] = true;
            if ($r['same_section'] !== null) {
                $data['entity_same_section'] = $r['same_section'];
            }
        }
        foreach (['email' => 'email', 'phone' => 'phone', 'tax' => 'tax_code', 'notes' => 'notes', 'since' => 'joined_on', 'birth' => 'birth_date'] as $from => $to) {
            if (is_string($r[$from] ?? null) && $r[$from] !== '') {
                // Le note stanno entro 2.000 caratteri, come nella scheda a mano.
                $data[$to] = $to === 'notes' ? mb_substr($r[$from], 0, 2000) : $r[$from];
            }
        }
        if ($withCard && is_string($r['card'] ?? null)) {
            $data['card_number'] = $r['card'];
        }
        $place = trim(implode(' ', array_filter([is_string($r['zip'] ?? null) ? $r['zip'] : null, is_string($r['city'] ?? null) ? $r['city'] : null])));
        if (is_string($r['prov'] ?? null) && $r['prov'] !== '') {
            $place .= ' ('.strtoupper($r['prov']).')';
        }
        $address = implode(', ', array_filter([is_string($r['addr'] ?? null) ? $r['addr'] : '', trim($place)], static fn (string $s): bool => $s !== ''));
        if ($address !== '') {
            $data['address'] = ['text' => mb_substr($address, 0, 190)];
        }

        return $data;
    }

    /** @return list<Existing> */
    private function existing(): array
    {
        $out = [];
        foreach (Person::query()->whereNull('anonymized_at')->orderBy('id')->lazy(500) as $p) {
            $out[] = [
                'id' => $p->id, 'card' => $p->card_number, 'first' => $p->first_name, 'last' => $p->last_name,
                'email' => $p->email, 'tax' => $p->tax_code, 'phone' => $p->phone, 'type' => $p->type->value, 'host_id' => $p->host_person_id, 'entity' => $p->is_entity,
            ];
        }

        return $out;
    }
}
