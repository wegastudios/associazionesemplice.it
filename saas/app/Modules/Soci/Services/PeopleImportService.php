<?php

declare(strict_types=1);

namespace App\Modules\Soci\Services;

use App\Modules\Audit\Contracts\AuditLog;
use App\Modules\Core\Contracts\OrgContext;
use App\Modules\Soci\Contracts\MemberType;
use App\Modules\Soci\Contracts\PeopleError;
use App\Modules\Soci\Import\PeopleSheet;
use App\Modules\Soci\Import\SheetReader;
use App\Modules\Soci\Import\Text;
use App\Modules\Soci\Models\Person;
use App\Modules\Soci\Rules\SocialYear;
use Illuminate\Database\DatabaseManager;
use InvalidArgumentException;

/**
 * Import di soci, ospiti e genitori da Excel o CSV (dall'ImportService del plugin). Prima l'anteprima (cosa nasce,
 * cosa si aggiorna, cosa non va), poi l'applicazione dello stesso file. Una riga che non va non ferma le altre.
 *
 * @phpstan-import-type Plan from PeopleSheet
 * @phpstan-import-type Existing from PeopleSheet
 */
final readonly class PeopleImportService
{
    public const MAX_ROWS = 5000;

    public const REASON = 'Importata da file';

    public function __construct(
        private DatabaseManager $db,
        private OrgContext $context,
        private PeopleService $people,
        private MembershipService $memberships,
        private GuardianService $guardians,
        private AuditLog $audit,
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
     * @param  list<Plan>  $plan
     * @return array{created: int, updated: int, memberships: int, guardians: int, failed: list<string>}
     */
    public function apply(array $plan, bool $markCurrentYear): array
    {
        $res = ['created' => 0, 'updated' => 0, 'memberships' => 0, 'guardians' => 0, 'failed' => []];
        $current = $this->memberships->socialYear();
        $startMonth = $this->memberships->startMonth();

        $this->db->connection($this->context->require()->connection)->transaction(function () use ($plan, $markCurrentYear, $current, $startMonth, &$res): void {
            $byPass = [0 => [], 1 => []];
            foreach ($plan as $p) {
                if ($p['action'] !== 'error') {
                    $byPass[$p['type'] === MemberType::Guest->value ? 1 : 0][] = $p; // prima i soci: l'ospitante può essere nuovo
                }
            }
            $links = [];
            $known = [];
            foreach ($byPass as $pass => $items) {
                if ($pass === 1 && $items !== []) {
                    $known = $this->existing(); // con le schede appena nate: ospitanti e tutori possono essere nel file
                }
                foreach ($items as $p) {
                    $r = $p['row'];
                    $where = ($r['sheet'] !== '' ? $r['sheet'].' ' : '').'riga '.$r['line'];
                    try {
                        $data = $this->data($r, $pass === 0);
                        if ($pass === 1) {
                            $host = $p['host_id'] ?? $this->findPerson($known, (string) $p['host_text']);
                            if ($host === null) {
                                throw new PeopleError(['socio ospitante «'.$p['host_text'].'» non trovato']);
                            }
                            $data['host_person_id'] = $host;
                        }
                        if ($p['action'] === 'create') {
                            $person = $this->people->create($data + ['type' => $p['type']]);
                            $res['created']++;
                        } else {
                            if ($p['type_given'] && $pass === 0) {
                                $data['type'] = $p['type'];
                            }
                            $person = $this->people->update((string) $p['matched_id'], $data);
                            $res['updated']++;
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
                            foreach (array_unique($years) as $label) {
                                $res['memberships'] += $this->grant($person->id, $label, $startMonth);
                            }
                        }
                    } catch (PeopleError $e) {
                        $res['failed'][] = $where.': '.implode(' ', $e->messages);
                    }
                }
            }
            $known = $this->existing();
            foreach ($links as [$personId, $ref, $where]) { // i tutori alla fine: possono essere nello stesso file
                $guardian = $this->findPerson($known, $ref);
                if ($guardian === null) {
                    $res['failed'][] = $where.': tutore «'.$ref.'» non trovato o ambiguo (indica la tessera o l\'email)';

                    continue;
                }
                try {
                    $before = count($this->guardians->of($personId));
                    $this->guardians->link($personId, $guardian);
                    $res['guardians'] += count($this->guardians->of($personId)) - $before;
                } catch (PeopleError $e) {
                    $res['failed'][] = $where.': '.implode(' ', $e->messages);
                }
            }
            $this->audit->record('people.imported', 'app.people', null, [
                'created' => $res['created'], 'updated' => $res['updated'], 'memberships' => $res['memberships'],
                'guardians' => $res['guardians'], 'failed' => count($res['failed']),
            ]);
        });

        return $res;
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
        foreach (['email' => 'email', 'phone' => 'phone', 'tax' => 'tax_code', 'notes' => 'notes', 'since' => 'joined_on', 'birth' => 'birth_date'] as $from => $to) {
            if (is_string($r[$from] ?? null) && $r[$from] !== '') {
                $data[$to] = $r[$from];
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

    /** Tessera dell'anno senza incasso; 0 se c'è già. */
    private function grant(string $personId, string $label, int $startMonth): int
    {
        $year = SocialYear::fromLabel($label, $startMonth);
        try {
            $this->memberships->grantManually($personId, $year->label(), self::REASON);
        } catch (PeopleError $e) {
            if (str_contains(implode(' ', $e->messages), 'già')) {
                return 0;
            }
            throw $e;
        }

        return 1;
    }

    /**
     * Un socio (non ospite) per tessera, email o nome e cognome, se ce n'è uno solo.
     *
     * @param  list<Existing>  $known
     */
    private function findPerson(array $known, string $text): ?string
    {
        $text = trim($text);
        if ($text === '') {
            return null;
        }
        $card = Text::lower(PeopleSheet::cleanCard($text));
        $name = Text::normalize($text);
        $found = [];
        foreach ($known as $p) {
            if (($p['type'] ?? '') === MemberType::Guest->value) {
                continue;
            }
            if (($p['card'] !== null && Text::lower($p['card']) === $card)
                || ($p['email'] !== null && Text::lower($p['email']) === Text::lower($text))
                || Text::normalize($p['first'].$p['last']) === $name || Text::normalize($p['last'].$p['first']) === $name) {
                $found[$p['id']] = true;
            }
        }

        return count($found) === 1 ? (string) array_key_first($found) : null;
    }

    /** @return list<Existing> */
    private function existing(): array
    {
        $out = [];
        foreach (Person::query()->whereNull('anonymized_at')->orderBy('id')->lazy(500) as $p) {
            $out[] = [
                'id' => $p->id, 'card' => $p->card_number, 'first' => $p->first_name, 'last' => $p->last_name,
                'email' => $p->email, 'tax' => $p->tax_code, 'phone' => $p->phone, 'type' => $p->type->value, 'host_id' => $p->host_person_id,
            ];
        }

        return $out;
    }
}
