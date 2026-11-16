<?php

declare(strict_types=1);

namespace App\Modules\AreaSoci\Payments;

use Illuminate\Http\Client\Response;
use RuntimeException;

/** Esito incerto: il gestore ha avuto un errore suo (5xx), ha chiesto di riprovare (408, 429) o ha risposto «bene» in modo illeggibile. */
final class GatewayUnknown extends RuntimeException
{
    public static function applies(Response $res): bool
    {
        $status = $res->status();

        return $status >= 500 || $status === 408 || $status === 429 || $res->successful();
    }
}
