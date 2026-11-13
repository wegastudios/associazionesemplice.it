<?php

namespace App\Support;

/** Prodotti serviti dal supporto e scelta del piano del cliente per il ticket (dati dall'accesso unico del portale). */
class ProductPlans
{
    public const PRODUCTS = ['plugin' => 'Plugin WordPress', 'saas' => 'Gestionale', 'billing' => 'Pagamenti, fatture e account'];

    /** Livelli di assistenza: free, paid, top (etichette ai clienti: Gratuita, Standard, Top). */
    public const LEVELS = ['free' => 0, 'paid' => 1, 'top' => 2];

    public const LEVEL_LABELS = ['free' => 'Gratuita', 'paid' => 'Standard', 'top' => 'Top'];

    /** Vocabolario precedente del portale, accettato durante la transizione. */
    private const LEVEL_ALIASES = ['base' => 'free', 'standard' => 'paid', 'priority' => 'top'];

    public static function level(mixed $value): string
    {
        $value = is_string($value) ? (self::LEVEL_ALIASES[$value] ?? $value) : null;

        return isset(self::LEVELS[$value]) ? $value : 'free';
    }

    public const STATUSES = ['active', 'trial', 'past_due', 'suspended', 'cancelled'];

    /**
     * Piano e livello del ticket: il livello piu alto tra i piani attivi (o in prova) del prodotto; per «fatturazione e account»
     * tra tutti i prodotti. Con licenza o abbonamento non attivi il livello e base.
     *
     * @param  array<int, array{product: string, plan: string, support_level: string, status: string}>  $plans
     * @return array{plan_slug: ?string, support_level: ?string}
     */
    public static function pick(array $plans, string $product): array
    {
        $best = null;
        foreach ($plans as $p) {
            if ($product !== 'billing' && $p['product'] !== $product) {
                continue;
            }
            $active = in_array($p['status'], ['active', 'trial'], true);
            $rank = ($active ? 10 : 0) + self::LEVELS[self::level($p['support_level'])];
            if ($best === null || $rank > $best[0]) {
                $best = [$rank, $p, $active];
            }
        }
        if ($best === null) {
            return ['plan_slug' => null, 'support_level' => null];
        }

        return ['plan_slug' => $best[1]['plan'], 'support_level' => $best[2] ? self::level($best[1]['support_level']) : 'free'];
    }

    /**
     * Elenco `plans` del token: voci non valide scartate, valori sconosciuti ridotti (livello gratuito, stato non attivo), massimo 20.
     *
     * @return array<int, array{product: string, plan: string, support_level: string, status: string}>
     */
    public static function sanitize(mixed $plans): array
    {
        if (! is_array($plans)) {
            return [];
        }
        $out = [];
        foreach (array_slice(array_values($plans), 0, 20) as $p) {
            if (! is_array($p) || ! in_array($p['product'] ?? null, ['plugin', 'saas'], true)
                || ! is_string($p['plan'] ?? null) || ! preg_match('/^[A-Za-z0-9_-]{1,60}$/', $p['plan'])) {
                continue;
            }
            $out[] = [
                'product' => $p['product'],
                'plan' => $p['plan'],
                'support_level' => self::level($p['support_level'] ?? null),
                'status' => in_array($p['status'] ?? null, self::STATUSES, true) ? $p['status'] : 'cancelled',
            ];
        }

        return $out;
    }
}
