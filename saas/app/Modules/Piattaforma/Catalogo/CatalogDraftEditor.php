<?php

declare(strict_types=1);

namespace App\Modules\Piattaforma\Catalogo;

use App\Modules\Piattaforma\Contracts\Catalog;
use InvalidArgumentException;

/**
 * Modifica la bozza del catalogo a pezzi (un piano, un pacchetto, le promozioni) a partire dai moduli dell'editor.
 * Ogni salvataggio rimette il documento intero nel validatore (CatalogPublisher::saveDraft): se qualcosa non va, la
 * bozza non cambia.
 */
final readonly class CatalogDraftEditor
{
    public function __construct(private CatalogPublisher $publisher, private Catalog $catalog) {}

    /** @return array<string, mixed> */
    public function document(): array
    {
        return $this->publisher->draft();
    }

    /**
     * Cosa cambia la bozza rispetto al catalogo in vigore, in frasi: serve a controllare prima di pubblicare.
     *
     * @return list<string>
     */
    public function changes(): array
    {
        $draft = $this->document();
        $published = CatalogValidator::input($this->catalog->published()->document);
        $out = [];

        foreach (['name' => 'Nome del catalogo', 'description' => 'Descrizione del catalogo'] as $key => $label) {
            if (($draft[$key] ?? null) !== ($published[$key] ?? null)) {
                $out[] = "{$label} cambiato.";
            }
        }
        if (self::canonical($draft['features'] ?? []) !== self::canonical($published['features'] ?? [])) {
            $out[] = 'Elenco delle caratteristiche (confronto) cambiato.';
        }

        $old = [];
        foreach ($this->list($published, 'plans') as $plan) {
            $old[$this->planKey($plan)] = $plan;
        }
        foreach ($this->list($draft, 'plans') as $plan) {
            $key = $this->planKey($plan);
            $name = is_string($plan['name'] ?? null) ? $plan['name'] : (is_string($plan['code'] ?? null) ? $plan['code'] : '?');
            $version = is_int($plan['plan_version'] ?? null) ? $plan['plan_version'] : 0;
            if (! isset($old[$key])) {
                $out[] = "Piano «{$name}»: nuova versione {$version}, ".self::summary($plan, $this->previousPlan($published, $plan)).'.';
            } elseif (self::canonical($old[$key]) !== self::canonical($plan)) {
                $out[] = "Piano «{$name}» versione {$version}: modificata, ma è già in vigore e non si può cambiare (non si potrà pubblicare).";
            }
        }

        $oldAddons = [];
        foreach ($this->list($published, 'addons') as $addon) {
            $oldAddons[$this->str($addon['code'] ?? null)] = $addon;
        }
        foreach ($this->list($draft, 'addons') as $addon) {
            $code = is_string($addon['code'] ?? null) ? $addon['code'] : '';
            $name = is_string($addon['name'] ?? null) ? $addon['name'] : $code;
            if (! isset($oldAddons[$code])) {
                $out[] = "Pacchetto «{$name}»: nuovo.";
            } elseif (self::canonical($oldAddons[$code]) !== self::canonical($addon)) {
                $out[] = "Pacchetto «{$name}»: ".self::summary($addon, $oldAddons[$code]).'.';
            }
        }
        foreach ($oldAddons as $code => $addon) {
            if (! in_array($code, array_column($this->list($draft, 'addons'), 'code'), true)) {
                $out[] = "Pacchetto «{$code}»: tolto dal catalogo.";
            }
        }

        $oldPromos = count($this->list($published, 'promos'));
        $newPromos = count($this->list($draft, 'promos'));
        if (self::canonical($draft['promos'] ?? []) !== self::canonical($published['promos'] ?? [])) {
            $out[] = "Promozioni cambiate (erano {$oldPromos}, ora {$newPromos}).";
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $published
     * @param  array<string, mixed>  $plan
     * @return array<string, mixed>|null
     */
    private function previousPlan(array $published, array $plan): ?array
    {
        $best = null;
        foreach ($this->list($published, 'plans') as $p) {
            if (($p['code'] ?? null) === ($plan['code'] ?? '') && (! is_array($best) || $this->num($p['plan_version'] ?? null) > $this->num($best['plan_version'] ?? null))) {
                $best = $p;
            }
        }

        return $best;
    }

    /**
     * Che campi sono diversi, in parole.
     *
     * @param  array<string, mixed>  $new
     * @param  array<string, mixed>|null  $old
     */
    private static function summary(array $new, ?array $old): string
    {
        if ($old === null) {
            return 'nuovo';
        }
        $labels = ['name' => 'nome', 'audience' => 'destinatari', 'visible' => 'visibilità', 'support_level' => 'assistenza', 'trial_days' => 'giorni di prova', 'features' => 'caratteristiche', 'limits' => 'limiti', 'prices' => 'prezzi', 'grants' => 'cosa dà'];
        $diff = [];
        foreach ($labels as $key => $label) {
            if (self::canonical($new[$key] ?? null) !== self::canonical($old[$key] ?? null)) {
                $diff[] = $label;
            }
        }

        return $diff === [] ? 'nessuna differenza' : 'cambiano '.implode(', ', $diff);
    }

    private static function canonical(mixed $value): string
    {
        return json_encode(self::sorted($value), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    }

    private static function sorted(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        $value = array_map(self::sorted(...), $value);
        if (! array_is_list($value)) {
            ksort($value);
        }

        return $value;
    }

    /**
     * L'ultima versione di un piano nella bozza.
     *
     * @return array<string, mixed>|null
     */
    public function latestPlan(string $code): ?array
    {
        $latest = null;
        foreach ($this->list($this->document(), 'plans') as $plan) {
            if (($plan['code'] ?? null) === $code && (! is_array($latest) || $this->num($plan['plan_version'] ?? null) > $this->num($latest['plan_version'] ?? null))) {
                $latest = $plan;
            }
        }

        return $latest;
    }

    /**
     * Salva un piano dal modulo. Se l'ultima versione è già stata pubblicata non si modifica: si crea la successiva.
     *
     * @param  array<string, mixed>  $input  campi del modulo
     * @return int la versione salvata
     *
     * @throws InvalidArgumentException
     */
    public function savePlan(string $code, array $input, string $staffId, bool $isNew = false): int
    {
        $document = $this->document();
        $latest = $isNew ? null : $this->latestPlan($code);
        $version = 1;
        if ($latest !== null) {
            $version = $this->num($latest['plan_version'] ?? null);
            if ($this->catalog->plan($code, $version) !== null) {
                $version++;
            }
        } elseif (! $isNew) {
            throw new InvalidArgumentException('Piano sconosciuto.');
        }

        $plan = $this->planFromInput($code, $version, $input, $latest, array_column($this->list($document, 'features'), 'id'));
        $plans = [];
        $replaced = false;
        foreach ($this->list($document, 'plans') as $existing) {
            if (($existing['code'] ?? null) === $code && $this->num($existing['plan_version'] ?? null) === $version) {
                $plans[] = $plan;
                $replaced = true;
            } else {
                $plans[] = $existing;
            }
        }
        if (! $replaced) {
            $plans[] = $plan;
        }
        $document['plans'] = $plans;
        $this->publisher->saveDraft($document, $staffId);

        return $version;
    }

    /**
     * @param  array<string, mixed>  $input
     * @param  array<string, mixed>|null  $base  versione di partenza (per i campi che il modulo non tocca)
     * @param  list<mixed>  $featureIds
     * @return array<string, mixed>
     */
    private function planFromInput(string $code, int $version, array $input, ?array $base, array $featureIds): array
    {
        $features = [];
        $inputFeatures = is_array($input['features'] ?? null) ? $input['features'] : [];
        foreach ($featureIds as $id) {
            if (! is_string($id)) {
                continue;
            }
            $f = is_array($inputFeatures[$id] ?? null) ? $inputFeatures[$id] : [];
            $state = is_string($f['state'] ?? null) ? $f['state'] : 'excluded';
            $features[$id] = $state === 'limit' ? ['state' => 'limit', 'text' => is_string($f['text'] ?? null) ? $f['text'] : ''] : ['state' => $state];
        }

        $limits = is_array($input['limits'] ?? null) ? $input['limits'] : [];
        $baseLimits = is_array($base['limits'] ?? null) ? $base['limits'] : [];

        return [
            'code' => $code,
            'plan_version' => $version,
            'name' => $this->str($input['name'] ?? null),
            'audience' => $this->str($input['audience'] ?? null),
            'order' => CatalogForm::int($input['order'] ?? null),
            'visible' => ($input['visible'] ?? null) === '1',
            'support_level' => $this->str($input['support_level'] ?? null),
            'trial_days' => CatalogForm::int($input['trial_days'] ?? null) ?? 0,
            'features' => $features,
            'limits' => [
                'members' => CatalogForm::int($limits['members'] ?? null),
                'operators' => CatalogForm::int($limits['operators'] ?? null),
                'storage_mb_per_member' => CatalogForm::int($limits['storage_mb_per_member'] ?? null),
                'storage_mb_min' => CatalogForm::int($limits['storage_mb_min'] ?? null),
                // Pacchetti inclusi nel piano (JSON); se il campo non arriva restano quelli della versione.
                'packages' => array_key_exists('packages_json', $input) ? $this->limits($input['packages_json']) : ($baseLimits['packages'] ?? []),
            ],
            'prices' => CatalogForm::prices($input['prices'] ?? null),
        ];
    }

    /**
     * Salva un pacchetto o servizio (addon) dal modulo.
     *
     * @param  array<string, mixed>  $input
     *
     * @throws InvalidArgumentException
     */
    public function saveAddon(string $code, array $input, string $staffId, bool $isNew = false): void
    {
        $document = $this->document();
        $addons = $this->list($document, 'addons');
        $existing = null;
        foreach ($addons as $addon) {
            if (($addon['code'] ?? null) === $code) {
                $existing = $addon;
            }
        }
        if ($isNew && $existing !== null) {
            throw new InvalidArgumentException("Il codice {$code} esiste già.");
        }
        if (! $isNew && $existing === null) {
            throw new InvalidArgumentException('Pacchetto sconosciuto.');
        }

        // Il tipo di un pacchetto già creato non cambia mai: cambierebbe cosa ha comprato chi lo ha già.
        $kind = is_array($existing) ? $this->str($existing['kind'] ?? null) : $this->str($input['kind'] ?? null);
        $grants = match ($kind) {
            'operators' => ['operators' => CatalogForm::int($input['grant_operators'] ?? null)],
            'storage' => ['storage_gb' => CatalogForm::int($input['grant_storage_gb'] ?? null)],
            'package' => ['package' => $this->str($input['grant_package'] ?? null), 'limits' => $this->limits($input['grant_limits'] ?? null)],
            default => [],
        };
        $addon = [
            'code' => $code,
            'name' => $this->str($input['name'] ?? null),
            'kind' => $kind,
            'visible' => ($input['visible'] ?? null) === '1',
            'grants' => $grants,
            'prices' => CatalogForm::prices($input['prices'] ?? null),
        ];

        $out = [];
        $replaced = false;
        foreach ($addons as $a) {
            if (($a['code'] ?? null) === $code) {
                $out[] = $addon;
                $replaced = true;
            } else {
                $out[] = $a;
            }
        }
        if (! $replaced) {
            $out[] = $addon;
        }
        $document['addons'] = $out;
        $this->publisher->saveDraft($document, $staffId);
    }

    /**
     * Sostituisce l'elenco delle promozioni con quello del modulo (le righe da togliere non ci sono più).
     *
     * @param  array<int|string, mixed>  $rows
     *
     * @throws InvalidArgumentException
     */
    public function savePromos(array $rows, string $staffId): void
    {
        $promos = [];
        foreach ($rows as $row) {
            if (! is_array($row) || ($row['delete'] ?? null) === '1' || $this->str($row['name'] ?? null) === '' && $this->str($row['code'] ?? null) === '') {
                continue;
            }
            $type = $this->str($row['type'] ?? null);
            $plans = $row['plans'] ?? [];
            $promos[] = [
                'code' => $this->str($row['code'] ?? null) === '' ? null : mb_strtoupper($this->str($row['code'] ?? null)),
                'name' => $this->str($row['name'] ?? null),
                'discount' => ['type' => $type, 'value' => $type === 'amount_cents' ? CatalogForm::cents($row['value'] ?? null) : CatalogForm::int($row['value'] ?? null)],
                'plans' => is_array($plans) && $plans !== [] ? array_values(array_filter($plans, is_string(...))) : null,
                'valid_from' => $this->str($row['valid_from'] ?? null),
                'valid_to' => $this->str($row['valid_to'] ?? null),
                'max_uses' => CatalogForm::int($row['max_uses'] ?? null),
                'stackable' => false,
            ];
        }

        $document = $this->document();
        $document['promos'] = $promos;
        $this->publisher->saveDraft($document, $staffId);
    }

    /**
     * Condizioni generali dal modulo: IVA, commissione sui pagamenti online, limiti della prova.
     *
     * @param  array<string, mixed>  $input
     *
     * @throws InvalidArgumentException
     */
    public function saveTerms(array $input, string $staffId): void
    {
        $exempt = ($input['vat_mode'] ?? null) === 'exempt';
        $document = $this->document();
        $document['terms'] = [
            'vat' => ['mode' => $exempt ? 'exempt' : 'standard', 'note' => $exempt ? $this->str($input['vat_note'] ?? null) : ''],
            'payments' => [
                'own_gateway_percent' => CatalogForm::int($input['own_gateway_percent'] ?? null),
                'collected_by_us_percent' => CatalogForm::int($input['collected_by_us_percent'] ?? null),
            ],
            'trial' => [
                'exports' => ($input['trial_exports'] ?? null) === '1',
                'accountant_access' => ($input['trial_accountant_access'] ?? null) === '1',
            ],
        ];
        $this->publisher->saveDraft($document, $staffId);
    }

    /**
     * @return array<string, mixed>
     *
     * @throws InvalidArgumentException
     */
    private function limits(mixed $text): array
    {
        $raw = is_string($text) ? trim($text) : '';
        if ($raw === '' || $raw === '[]' || $raw === '{}') {
            return [];
        }
        $decoded = json_decode($raw, true);
        if (! is_array($decoded) || array_is_list($decoded)) {
            throw new InvalidArgumentException('I limiti del pacchetto devono essere un oggetto JSON, per esempio {"yearly": 50}.');
        }

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    /** @param  array<string, mixed>  $plan */
    private function planKey(array $plan): string
    {
        return $this->str($plan['code'] ?? null).'@'.$this->num($plan['plan_version'] ?? null);
    }

    private function num(mixed $value): int
    {
        return is_int($value) ? $value : 0;
    }

    private function str(mixed $value): string
    {
        return is_string($value) ? trim($value) : '';
    }

    /**
     * @param  array<string, mixed>  $document
     * @return list<array<string, mixed>>
     */
    private function list(array $document, string $key): array
    {
        $out = [];
        foreach (is_array($document[$key] ?? null) ? $document[$key] : [] as $item) {
            if (is_array($item)) {
                /** @var array<string, mixed> $item */
                $out[] = $item;
            }
        }

        return $out;
    }
}
