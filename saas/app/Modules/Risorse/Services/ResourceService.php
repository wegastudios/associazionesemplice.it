<?php

declare(strict_types=1);

namespace App\Modules\Risorse\Services;

use App\Modules\Contabilita\Contracts\Ledger;
use App\Modules\Core\Contracts\OrgContext;
use App\Modules\Portale\Contracts\Entitlements;
use App\Modules\Portale\Contracts\Package;
use App\Modules\Risorse\Contracts\ResourceError;
use App\Modules\Risorse\Contracts\Typology;
use App\Modules\Risorse\Models\Resource;
use App\Modules\Risorse\Rules\ResourceRules;

/**
 * Risorse dell'associazione. Quante se ne possono avere attive dipende dal pacchetto, per tipologia (oraria,
 * giornaliera, stagionale): i limiti arrivano dal portale con i diritti dell'associazione.
 */
final readonly class ResourceService
{
    public function __construct(
        private OrgContext $context,
        private Entitlements $entitlements,
        private Ledger $ledger,
    ) {}

    /** @return list<resource> */
    public function all(bool $archived = false): array
    {
        $q = Resource::query()->orderBy('typology')->orderBy('name');
        $archived ? $q->whereNotNull('archived_at') : $q->whereNull('archived_at');

        return array_values($q->get()->all());
    }

    public function find(string $id): Resource
    {
        return Resource::query()->find($id) ?? throw new ResourceError('Risorsa non trovata.');
    }

    /**
     * Quante risorse attive per tipologia e quante ne ammette il pacchetto (null = la tipologia non è compresa).
     *
     * @return array<string, array{label: string, used: int, tier: int|null}>
     */
    public function limits(): array
    {
        $used = Resource::query()->whereNull('archived_at')->selectRaw('typology, count(*) AS n')->groupBy('typology')->pluck('n', 'typology')->all();
        $ent = $this->entitlements->for($this->context->require()->id);
        $out = [];
        foreach (Typology::cases() as $t) {
            $tier = $ent->limit(Package::Resources, $t->value.'.tier');
            $out[$t->value] = ['label' => $t->label(), 'used' => is_numeric($used[$t->value] ?? null) ? (int) $used[$t->value] : 0, 'tier' => is_int($tier) ? $tier : null];
        }

        return $out;
    }

    /** @param  array<string, mixed>  $in  dati già validati dal controller (importi in centesimi, tariffe e servizi come testo) */
    public function save(?string $id, array $in): Resource
    {
        $resource = $id === null ? new Resource : $this->find($id);
        $typology = Typology::tryFrom(self::str($in['typology'] ?? '')) ?? throw new ResourceError('Scegli come si prenota la risorsa.');
        if ($resource->archived_at === null && ($id === null || $resource->typology !== $typology)) {
            $this->assertRoom($typology);
        }
        $category = self::str($in['category'] ?? 'other');
        if (! isset(ResourceRules::categories()[$category])) {
            throw new ResourceError('Tipo di risorsa non valido.');
        }
        $incomeCategory = self::str($in['category_id'] ?? '');
        if ($incomeCategory !== '' && ! in_array($incomeCategory, array_column($this->ledger->incomeCategories(), 'id'), true)) {
            throw new ResourceError('Voce di entrata non valida.');
        }

        $hourly = $typology === Typology::Hourly;
        $open = $hourly ? self::str($in['open_time'] ?? '') : '';
        $close = $hourly ? self::str($in['close_time'] ?? '') : '';
        $slot = $hourly ? max(5, self::int($in['slot_minutes'] ?? 60)) : 60;
        if ($hourly) {
            $o = ResourceRules::minutes($open);
            $c = ResourceRules::minutes($close);
            if ($o === null || $c === null || $c <= $o) {
                throw new ResourceError('Indica l\'orario di apertura e di chiusura (la chiusura dopo l\'apertura).');
            }
            if ($slot > $c - $o) {
                throw new ResourceError('La fascia è più lunga dell\'orario di apertura.');
            }
        }
        $min = max(1, min(48, self::int($in['min_slots'] ?? 1)));
        $max = max($min, min(48, self::int($in['max_slots'] ?? $min)));

        $resource->fill([
            'name' => trim(self::str($in['name'] ?? '')),
            'category' => $category,
            'typology' => $typology,
            'description' => self::nullable($in['description'] ?? null),
            'open_time' => $hourly ? $open : null,
            'close_time' => $hourly ? $close : null,
            'slot_minutes' => $slot,
            'min_slots' => $hourly ? $min : 1,
            'max_slots' => $hourly ? $max : 1,
            'advance_days' => $typology === Typology::Seasonal ? 0 : max(0, min(730, self::int($in['advance_days'] ?? 0))),
            'fee_cents' => max(0, self::int($in['fee_cents'] ?? 0)),
            'nonmember_fee_cents' => is_int($in['nonmember_fee_cents'] ?? null) ? max(0, $in['nonmember_fee_cents']) : null,
            'guests_max' => $typology === Typology::Seasonal ? 0 : max(0, min(100, self::int($in['guests_max'] ?? 0))),
            'guest_fee_cents' => $typology === Typology::Seasonal ? 0 : max(0, self::int($in['guest_fee_cents'] ?? 0)),
            'rates' => $hourly ? ResourceRules::parseRates(self::str($in['rates'] ?? '')) : [],
            'services' => $typology === Typology::Seasonal ? [] : ResourceRules::parseServices(self::str($in['services'] ?? '')),
            'category_id' => $incomeCategory === '' ? null : $incomeCategory,
        ]);
        if ($resource->name === '') {
            throw new ResourceError('Indica il nome della risorsa.');
        }
        $resource->save();

        return $resource;
    }

    /** Archivia (non si prenota più, le prenotazioni restano) o riattiva, se il pacchetto lo consente. */
    public function archive(string $id, bool $archived): void
    {
        $resource = $this->find($id);
        if (! $archived && $resource->archived_at !== null) {
            $this->assertRoom($resource->typology);
        }
        $resource->archived_at = $archived ? now()->toImmutable() : null;
        $resource->save();
    }

    private function assertRoom(Typology $typology): void
    {
        $limit = $this->limits()[$typology->value];
        if ($limit['tier'] === null) {
            throw new ResourceError('Il pacchetto Risorse dell\'associazione non comprende risorse '.mb_strtolower($typology->label()).': puoi aggiungerle dal portale.');
        }
        if ($limit['used'] >= $limit['tier']) {
            throw new ResourceError('Hai già '.$limit['used'].' risorse '.mb_strtolower($typology->label()).' attive, il massimo del tuo pacchetto: archiviane una o aumenta lo scaglione dal portale.');
        }
    }

    private static function str(mixed $v): string
    {
        return is_scalar($v) ? (string) $v : '';
    }

    private static function int(mixed $v): int
    {
        return is_numeric($v) ? (int) $v : 0;
    }

    private static function nullable(mixed $v): ?string
    {
        return is_string($v) && trim($v) !== '' ? trim($v) : null;
    }
}
