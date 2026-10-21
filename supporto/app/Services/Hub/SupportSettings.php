<?php

namespace App\Services\Hub;

use Illuminate\Support\Facades\DB;

/** Impostazioni globali dell'hub (tabella settings): indirizzi avvisati per le priorita alte ed etichette dei livelli. */
class SupportSettings
{
    /** @return list<string> indirizzi aggiuntivi rispetto a quelli dell'ambiente */
    public function extraNotifyEmails(): array
    {
        return self::parseEmails((string) DB::table('settings')->where('key', 'support.notify_emails')->value('value'));
    }

    /** @param  list<string>  $emails */
    public function saveExtraNotifyEmails(array $emails): void
    {
        $this->put('support.notify_emails', implode("\n", $emails));
    }

    /** @return array<int, string> */
    public function priorityLabels(): array
    {
        $labels = config('support.priority_labels');
        $stored = DB::table('settings')->where('key', 'support.priority_labels')->value('value');
        $custom = $stored ? json_decode($stored, true) : null;

        if (is_array($custom)) {
            foreach ($labels as $level => $default) {
                if (isset($custom[$level]) && is_string($custom[$level]) && trim($custom[$level]) !== '') {
                    $labels[$level] = trim($custom[$level]);
                }
            }
        }

        return $labels;
    }

    /** @param  array<int, string>  $labels */
    public function savePriorityLabels(array $labels): void
    {
        $clean = [];
        foreach (config('support.priority_levels') as $level) {
            $clean[$level] = mb_substr(trim((string) ($labels[$level] ?? '')), 0, 30);
        }
        $this->put('support.priority_labels', json_encode($clean, JSON_UNESCAPED_UNICODE));
    }

    /** @return list<string> */
    public static function parseEmails(string $text): array
    {
        $parts = preg_split('/[\s,;]+/', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $valid = array_filter($parts, fn (string $e) => filter_var($e, FILTER_VALIDATE_EMAIL) !== false);

        return array_values(array_unique(array_map('strtolower', $valid)));
    }

    private function put(string $key, string $value): void
    {
        DB::table('settings')->updateOrInsert(['key' => $key], ['value' => $value, 'updated_at' => now(), 'created_at' => now()]);
    }
}
