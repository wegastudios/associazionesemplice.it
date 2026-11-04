<?php

declare(strict_types=1);

namespace App\Modules\Core\Access;

use App\Modules\Core\Contracts\CurrentOperator;
use App\Modules\Core\Contracts\Operator;

final class RequestOperator implements CurrentOperator
{
    private ?Operator $operator = null;

    public function get(): ?Operator
    {
        return $this->operator;
    }

    public function set(?Operator $operator): void
    {
        $this->operator = $operator;
    }
}
