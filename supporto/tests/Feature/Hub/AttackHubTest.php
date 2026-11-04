<?php

namespace Tests\Feature\Hub;

use Illuminate\Support\Str;
use Tests\HubTestCase;

class AttackHubTest extends HubTestCase
{
    private function mk(string $token, array $d = [])
    {
        return $this->postJson('/api/v1/tickets', $d + ['request_uuid' => (string) Str::uuid(), 'subject' => 'S', 'customer_name' => 'M', 'customer_email' => 'm@example.com'], $this->bearer($token));
    }

    public function test_bearer_fuzz_is_rejected_without_500(): void
    {
        ['site' => $site, 'token' => $token] = $this->makeSite();
        [$id, $secret] = explode('.', $token, 2);
        $bad = ['', '.', $id.'.', '.'.$secret, $id, $secret, $id.'.'.$secret.'x', substr($id, 1).'.'.$secret, "' OR 1=1 --", $id.'.'."' OR '1'='1", str_repeat('a', 100000), $id.'..'.$secret, strtoupper($token), $id.'.'.strtoupper($secret), "$id.$secret\0", $id.'.[]'];
        foreach ($bad as $b) {
            $r = $this->getJson('/api/v1/rules', ['Authorization' => 'Bearer '.$b]);
            $this->assertSame(401, $r->getStatusCode(), 'token accettato o errore: '.substr($b, 0, 30));
        }
        $this->getJson('/api/v1/rules', ['Authorization' => 'Basic '.base64_encode($token)])->assertStatus(401);
        $this->getJson('/api/v1/rules?token='.$token)->assertStatus(401);
        $this->getJson('/api/v1/rules', ['X-Api-Key' => $token])->assertStatus(401);
        $this->getJson('/api/v1/rules', $this->bearer($token))->assertOk();
    }

    public function test_tenant_isolation_with_shared_uuid_and_mass_assignment(): void
    {
        ['site' => $sa, 'token' => $a] = $this->makeSite('A', email: 'a@example.com');
        ['site' => $sb, 'token' => $b] = $this->makeSite('B', email: 'b@example.com');
        $uuid = (string) Str::uuid();
        $ida = $this->mk($a, ['request_uuid' => $uuid, 'subject' => 'Segreto di A'])->json('data.id');
        $rb = $this->mk($b, ['request_uuid' => $uuid, 'subject' => 'Di B']);
        $this->assertNotSame($ida, $rb->json('data.id'), 'lo stesso uuid di un altro portale non deve restituire il ticket altrui');
        $this->assertStringNotContainsString('Segreto di A', (string) $rb->getContent());
        $rb2 = $this->mk($b, ['site_id' => $sa->id, 'id' => $ida, 'status' => 'closed', 'closed_at' => '2000-01-01', 'priority' => 0]);
        $tid = $rb2->json('data.id');
        $this->assertDatabaseHas('tickets', ['id' => $tid, 'site_id' => $sb->id]);
        $this->assertDatabaseMissing('tickets', ['id' => $ida, 'status' => 'closed']);
        foreach (["/api/v1/tickets/$ida", '/api/v1/tickets/0', '/api/v1/tickets/-1', '/api/v1/tickets/abc', '/api/v1/tickets/1%20OR%201=1', '/api/v1/tickets/99999999999999999999'] as $u) {
            $r = $this->getJson($u, $this->bearer($b));
            $this->assertContains($r->getStatusCode(), [404, 422], $u.' => '.$r->getStatusCode());
        }
    }

    public function test_input_fuzz_never_500(): void
    {
        ['token' => $t] = $this->makeSite();
        $payloads = [
            ['subject' => str_repeat('a', 10000)], ['subject' => ['a']], ['subject' => 123], ['subject' => ''], ['customer_email' => 'a@b'], ['customer_email' => "a@b.it\r\nBcc: x@y.it"],
            ['customer_name' => str_repeat('é', 400)], ['priority' => '1; DROP TABLE tickets'], ['priority' => -1], ['priority' => 99], ['priority' => 1.5], ['priority' => []],
            ['topic' => str_repeat('t', 500)], ['plan' => ['x']], ['related_ticket_id' => 'abc'], ['related_ticket_id' => 0], ['related_ticket_id' => 9999999999999999999],
            ['privacy_accepted_at' => 'ieri'], ['privacy_accepted_at' => '9999-99-99'], ['privacy_version' => str_repeat('v', 100)], ['request_uuid' => 'non-uuid'], ['request_uuid' => ''],
            ['subject' => '<script>alert(1)</script>'], ['subject' => "\u{202e}rtl\u{0000}nul"],
        ];
        foreach ($payloads as $p) {
            $r = $this->mk($t, $p);
            $this->assertLessThan(500, $r->getStatusCode(), json_encode($p).' => '.$r->getStatusCode());
        }
        $r = $this->call('POST', '/api/v1/tickets', [], [], [], ['HTTP_AUTHORIZATION' => 'Bearer '.$t, 'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'], '{"subject": ');
        $this->assertLessThan(500, $r->getStatusCode());
        $r = $this->call('POST', '/api/v1/tickets', [], [], [], ['HTTP_AUTHORIZATION' => 'Bearer '.$t, 'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'], str_repeat('[', 100000));
        $this->assertLessThan(500, $r->getStatusCode());
    }

    public function test_revoked_and_rotated_secrets(): void
    {
        ['site' => $site, 'token' => $token] = $this->makeSite();
        $site->forceFill(['revoked_at' => now()])->save();
        $this->getJson('/api/v1/rules', $this->bearer($token))->assertStatus(401);
        $site->forceFill(['revoked_at' => null])->save();
        $this->getJson('/api/v1/rules', $this->bearer($token))->assertOk();
    }

    public function test_api_does_not_leak_internal_fields(): void
    {
        ['token' => $t] = $this->makeSite();
        $r = $this->mk($t)->assertCreated();
        $keys = array_keys($r->json('data'));
        foreach (['site_id', 'customer_email', 'customer_name', 'request_uuid', 'secret_hash', 'password'] as $k) {
            $this->assertNotContains($k, $keys, "campo interno esposto: $k");
        }
    }

    public function test_hub_routes_do_not_expose_the_site_ones(): void
    {
        foreach (['/ticket/nuovo', '/sso', '/i-miei-ticket', '/cerca'] as $u) {
            $this->assertSame(404, $this->get($u)->getStatusCode(), $u);
        }
    }
}
