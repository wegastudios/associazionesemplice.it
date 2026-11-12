<?php

declare(strict_types=1);

namespace App\Modules\Portale\Entitlements;

final class SystemDns implements DomainDns
{
    public function cnames(string $domain): array
    {
        $records = @dns_get_record($domain, DNS_CNAME);
        $found = [];
        foreach (is_array($records) ? $records : [] as $record) {
            if (is_string($record['target'] ?? null)) {
                $found[] = rtrim(strtolower($record['target']), '.');
            }
        }

        return $found;
    }
}
