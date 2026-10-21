<?php

namespace Tests\Unit;

use App\Services\Hub\SupportSettings;
use PHPUnit\Framework\TestCase;

class ParseEmailsTest extends TestCase
{
    public function test_splits_on_lines_commas_and_semicolons_and_drops_invalid_and_duplicates(): void
    {
        $result = SupportSettings::parseEmails("Info@Example.com\nuno@example.com, due@example.com;uno@example.com  non-una-email");

        $this->assertSame(['info@example.com', 'uno@example.com', 'due@example.com'], $result);
    }

    public function test_empty_text_gives_no_addresses(): void
    {
        $this->assertSame([], SupportSettings::parseEmails("  \n "));
    }
}
