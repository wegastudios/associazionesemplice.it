<?php

declare(strict_types=1);

namespace App\Modules\Risorse\Services;

use App\Modules\Risorse\Contracts\ResourceBookings;
use App\Modules\Risorse\Contracts\ResourceError;
use App\Modules\Risorse\Models\Reservation;
use App\Modules\Risorse\Models\Resource;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\QueryException;
use Illuminate\Support\Str;

/** Le risorse e le loro prenotazioni viste da fuori del modulo (le attività). */
final readonly class ResourceBookingsService implements ResourceBookings
{
    public const FAR = '9999-12-31 00:00:00';

    public function __construct(private DatabaseManager $db) {}

    public function options(): array
    {
        $out = [];
        foreach (Resource::query()->whereNull('archived_at')->orderBy('name')->get(['id', 'name']) as $r) {
            $out[] = ['id' => $r->id, 'name' => $r->name];
        }

        return $out;
    }

    public function names(array $ids): array
    {
        $ids = array_values(array_filter($ids, static fn (string $i): bool => Str::isUuid($i)));
        $out = [];
        if ($ids !== []) {
            foreach (Resource::query()->whereIn('id', $ids)->get(['id', 'name']) as $r) {
                $out[$r->id] = $r->name;
            }
        }

        return $out;
    }

    public function busy(string $resourceId, string $from, string $to): array
    {
        if (! Str::isUuid($resourceId)) {
            return [];
        }
        $out = [];
        $rows = Reservation::query()->where('resource_id', $resourceId)->where('status', 'booked')
            ->where('starts_at', '<', $to)
            ->where(static fn ($w) => $w->whereNull('ends_at')->orWhere('ends_at', '>', $from))
            ->orderBy('starts_at')->get();
        foreach ($rows as $r) {
            $out[] = [
                'id' => $r->id,
                'kind' => $r->kind,
                'from' => self::text($r->starts_at),
                'to' => $r->ends_at === null ? null : self::text($r->ends_at),
                'label' => $r->kind === 'block' ? 'un blocco'.($r->note !== null && $r->note !== '' ? ' ('.$r->note.')' : '') : 'una prenotazione',
            ];
        }

        return $out;
    }

    public function move(array $ids, string $toResourceId): void
    {
        if ($ids === []) {
            return;
        }
        $this->db->connection('pgsql')->transaction(function () use ($ids, $toResourceId): void {
            try {
                foreach (Reservation::query()->whereIn('id', $ids)->where('status', 'booked')->get() as $r) {
                    foreach ($this->busy($toResourceId, self::text($r->starts_at), $r->ends_at === null ? self::FAR : self::text($r->ends_at)) as $other) {
                        if ($other['id'] !== $r->id) {
                            throw new ResourceError('Anche sulla risorsa che si libera c\'è già '.$other['label'].' il '.date('d/m/Y \a\l\l\e H:i', (int) strtotime($other['from'])).'.');
                        }
                    }
                    $r->resource_id = $toResourceId;
                    $r->save();
                }
            } catch (QueryException $e) {
                if (($e->errorInfo[0] ?? null) === '23P01') {
                    throw new ResourceError('Sulla risorsa che si libera uno degli orari è già occupato.');
                }
                throw $e;
            }
        });
    }

    private static function text(mixed $v): string
    {
        return is_string($v) ? $v : (is_scalar($v) ? (string) $v : '');
    }
}
