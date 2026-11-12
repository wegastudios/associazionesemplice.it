<?php

declare(strict_types=1);

namespace App\Modules\Portale\Entitlements;

final class SystemDns implements DomainDns
{
    public function nameservers(string $domain): array
    {
        $records = @dns_get_record($domain, DNS_NS);
        $found = [];
        foreach (is_array($records) ? $records : [] as $record) {
            if (is_string($record['target'] ?? null)) {
                $found[] = rtrim(strtolower($record['target']), '.');
            }
        }

        return $found;
    }

    public function hasCertificate(string $domain): bool
    {
        $context = stream_context_create(['ssl' => ['verify_peer' => true, 'verify_peer_name' => true, 'peer_name' => $domain, 'SNI_enabled' => true]]);
        $socket = @stream_socket_client("ssl://{$domain}:443", $errno, $error, 5, STREAM_CLIENT_CONNECT, $context);
        if ($socket === false) {
            return false;
        }
        fclose($socket);

        return true;
    }
}
