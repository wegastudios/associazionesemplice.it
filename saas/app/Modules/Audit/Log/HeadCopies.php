<?php

declare(strict_types=1);

namespace App\Modules\Audit\Log;

use App\Modules\Cifratura\Contracts\Bytea;
use App\Modules\Core\Contracts\OrgContext;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Filesystem\Factory as Disks;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\DatabaseManager;
use RuntimeException;

/**
 * Copia fuori dal database, ogni giorno, del numero e del sigillo di testa del registro di ogni associazione (e della
 * sua ancora), nello spazio dei backup. La catena da sola non dice se qualcuno ha tolto le ultime righe o riscritto
 * l'ancora insieme alla testa: confrontata con una copia fatta prima, sì.
 *
 * Il file porta un sigillo calcolato con una chiave derivata da quella dell'applicazione: chi scrive solo sullo spazio
 * dei backup non può sostituirlo con un altro.
 */
final class HeadCopies
{
    private const FOLDER = 'audit-heads';

    /** Copie più vecchie di così si cancellano (giorni). */
    private const KEEP_DAYS = 400;

    /** @var array{at: CarbonImmutable, orgs: array<string, array{head: array{seq: int, hash: string}, anchor: array{seq: int, hash: string}|null}>}|null */
    private ?array $latest = null;

    private bool $loaded = false;

    public function __construct(
        private readonly DatabaseManager $db,
        private readonly OrgContext $context,
        private readonly Repository $config,
        private readonly Disks $disks,
    ) {}

    /**
     * Scrive la copia di oggi per tutte le associazioni non cancellate.
     *
     * @return array{path: string, orgs: int, removed: int}
     */
    public function export(CarbonImmutable $now): array
    {
        $orgs = [];
        $ids = $this->db->connection('pgsql')->table('core.orgs')->whereNull('deleted_at')->orderBy('id')->pluck('id')->all();
        foreach ($ids as $orgId) {
            if (! is_string($orgId)) {
                continue;
            }
            $state = $this->context->run($orgId, fn () => $this->headOf($orgId));
            if ($state !== null) {
                $orgs[$orgId] = $state;
            }
        }

        $data = json_encode(['v' => 1, 'created_at' => $now->utc()->format('Y-m-d\TH:i:s\Z'), 'orgs' => $orgs], JSON_THROW_ON_ERROR);
        $path = sprintf('%s/%s/%s/heads-%s.json', $this->prefix(), self::FOLDER, $now->utc()->format('Y/m'), $now->utc()->format('Ymd\THis\Z'));
        $this->disk()->put($path, (string) json_encode(['data' => $data, 'mac' => $this->mac($data)], JSON_THROW_ON_ERROR));
        $this->loaded = false;

        return ['path' => $path, 'orgs' => count($orgs), 'removed' => $this->prune($now)];
    }

    /**
     * L'ultima copia. Null se non ce n'è nessuna.
     *
     * @return array{at: CarbonImmutable, orgs: array<string, array{head: array{seq: int, hash: string}, anchor: array{seq: int, hash: string}|null}>}|null
     *
     * @throws RuntimeException se il file non è autentico
     */
    public function latest(): ?array
    {
        if ($this->loaded) {
            return $this->latest;
        }

        $last = array_key_last($this->files());
        $this->latest = $last === null ? null : $this->read($last);
        $this->loaded = true;

        return $this->latest;
    }

    /**
     * Confronta la testa e l'ancora di un'associazione con l'ultima copia esterna. Restituisce il motivo del conflitto,
     * o null se tutto torna (o se non c'è una copia da confrontare).
     *
     * @param  array{seq: int, hash: string}|null  $anchor  ancora attuale (hash binario)
     */
    public function conflict(string $orgId, ConnectionInterface $connection, int $headSeq, string $headHash, ?array $anchor): ?string
    {
        try {
            $copy = $this->latest();
        } catch (RuntimeException) {
            return 'copia esterna della testa del registro non autentica';
        }
        $saved = $copy['orgs'][$orgId] ?? null;
        if ($saved === null) {
            return null;
        }

        $anchorSeq = $anchor['seq'] ?? 0;
        if ($saved['anchor'] !== null) {
            if ($anchor === null || $anchor['seq'] < $saved['anchor']['seq']
                || ($anchor['seq'] === $saved['anchor']['seq'] && ! hash_equals($saved['anchor']['hash'], bin2hex($anchor['hash'])))) {
                return 'ancora della catena diversa dalla copia esterna';
            }
        }

        $seq = $saved['head']['seq'];
        if ($seq > $headSeq) {
            return 'righe in fondo alla catena mancanti rispetto alla copia esterna';
        }
        if ($seq < $anchorSeq) {
            return null; // già cancellato dalla pulizia annuale: l'ancora lo ha preso al suo posto
        }

        $current = match (true) {
            $seq === $headSeq => bin2hex($headHash),
            $seq === $anchorSeq && $anchor !== null => bin2hex($anchor['hash']),
            default => $this->hashAt($connection, $orgId, $seq),
        };

        return $current !== null && hash_equals($saved['head']['hash'], $current) ? null : 'sigillo di testa diverso dalla copia esterna';
    }

    /**
     * Testa e ancora dell'associazione corrente (da chiamare dentro OrgContext::run).
     *
     * @return array{head: array{seq: int, hash: string}, anchor: array{seq: int, hash: string}|null}|null
     */
    private function headOf(string $orgId): ?array
    {
        $connection = $this->db->connection($this->context->require()->connection);
        $head = $connection->table('audit.heads')->where('org_id', $orgId)->first(['seq', 'hash']);
        if ($head === null) {
            return null;
        }
        $anchor = $connection->table('audit.anchors')->where('org_id', $orgId)->first(['seq', 'hash']);

        return [
            'head' => ['seq' => Entry::seq($head->seq), 'hash' => bin2hex(Bytea::decode($head->hash))],
            'anchor' => $anchor === null ? null : ['seq' => Entry::seq($anchor->seq), 'hash' => bin2hex(Bytea::decode($anchor->hash))],
        ];
    }

    private function hashAt(ConnectionInterface $connection, string $orgId, int $seq): ?string
    {
        $row = $connection->table('audit.log')->where('org_id', $orgId)->where('seq', $seq)->first(['hash']);

        return $row === null ? null : bin2hex(Bytea::decode($row->hash));
    }

    /**
     * @return array{at: CarbonImmutable, orgs: array<string, array{head: array{seq: int, hash: string}, anchor: array{seq: int, hash: string}|null}>}
     */
    private function read(string $path): array
    {
        $outer = json_decode((string) $this->disk()->get($path), true, 4);
        $data = is_array($outer) && is_string($outer['data'] ?? null) ? $outer['data'] : null;
        $mac = is_array($outer) && is_string($outer['mac'] ?? null) ? $outer['mac'] : '';
        if ($data === null || ! hash_equals($this->mac($data), $mac)) {
            throw new RuntimeException('Copia della testa del registro non autentica.');
        }
        $body = json_decode($data, true, 8);
        $at = is_array($body) && is_string($body['created_at'] ?? null) ? CarbonImmutable::parse($body['created_at']) : null;
        $list = is_array($body) && is_array($body['orgs'] ?? null) ? $body['orgs'] : null;
        if ($at === null || $list === null) {
            throw new RuntimeException('Copia della testa del registro non valida.');
        }

        $orgs = [];
        foreach ($list as $id => $state) {
            $head = is_array($state) ? self::point($state['head'] ?? null) : null;
            if (! is_string($id) || $head === null) {
                continue;
            }
            $orgs[$id] = ['head' => $head, 'anchor' => is_array($state) ? self::point($state['anchor'] ?? null) : null];
        }

        return ['at' => $at, 'orgs' => $orgs];
    }

    /** @return array{seq: int, hash: string}|null */
    private static function point(mixed $value): ?array
    {
        return is_array($value) && is_int($value['seq'] ?? null) && is_string($value['hash'] ?? null)
            ? ['seq' => $value['seq'], 'hash' => $value['hash']]
            : null;
    }

    /** @return array<string, CarbonImmutable> percorso => ora della copia, dalla più vecchia */
    private function files(): array
    {
        $found = [];
        foreach ($this->disk()->allFiles($this->prefix().'/'.self::FOLDER) as $file) {
            if (preg_match('/heads-(\d{8}T\d{6}Z)\.json$/', $file, $m) === 1) {
                $found[$file] = CarbonImmutable::createFromFormat('Ymd\THis\Z', $m[1], 'UTC') ?: CarbonImmutable::now();
            }
        }
        asort($found);

        return $found;
    }

    private function prune(CarbonImmutable $now): int
    {
        $old = [];
        foreach ($this->files() as $path => $at) {
            if ($at->lt($now->subDays(self::KEEP_DAYS))) {
                $old[] = $path;
            }
        }
        if ($old !== []) {
            $this->disk()->delete($old);
        }

        return count($old);
    }

    private function mac(string $data): string
    {
        $key = $this->config->get('app.key');
        $raw = is_string($key) && str_starts_with($key, 'base64:') ? base64_decode(substr($key, 7), true) : $key;
        if (! is_string($raw) || $raw === '') {
            throw new RuntimeException('Chiave dell\'applicazione mancante.');
        }

        return hash_hmac('sha256', $data, hash_hmac('sha256', 'saas-audit-heads-v1', $raw, true));
    }

    private function disk(): Filesystem
    {
        return $this->disks->disk('backups');
    }

    /** Stesso prefisso dei backup: un ambiente non tocca le copie di un altro. */
    private function prefix(): string
    {
        $env = $this->config->get('app.env');

        return is_string($env) && preg_match('/^[a-z]+$/', $env) === 1 ? $env : 'local';
    }
}
