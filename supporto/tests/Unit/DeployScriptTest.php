<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/** Il rilascio non deve mai copiare public/: il suo .htaccess, sul server, porta il gestore PHP 8.4 di cPanel. */
class DeployScriptTest extends TestCase
{
    private function script(): string
    {
        return (string) file_get_contents(__DIR__.'/../../scripts/deploy.sh');
    }

    public function test_deploy_never_copies_public(): void
    {
        $this->assertSame(1, preg_match('/^CARTELLE=\(([^)]*)\)/m', $this->script(), $m), 'manca la riga CARTELLE=(...)');
        $folders = preg_split('/\s+/', trim($m[1]));

        $this->assertNotContains('public', $folders);
        $this->assertContains('app', $folders);
    }

    public function test_deploy_saves_the_htaccess_and_checks_the_site(): void
    {
        $script = $this->script();

        $this->assertStringContainsString('public/.htaccess', $script);
        $this->assertStringContainsString('/up', $script);
        $this->assertStringContainsString('controlla', $script);
    }
}
