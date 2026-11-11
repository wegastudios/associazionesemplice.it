<?php

declare(strict_types=1);

namespace App\Modules\Piattaforma\Identity;

/** Una persona dello staff, senza password. */
final readonly class StaffPerson
{
    public function __construct(public string $id, public string $email, public string $name) {}
}
