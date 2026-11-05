<?php

declare(strict_types=1);

namespace App\Modules\AreaSoci\Models;

use App\Modules\Audit\Contracts\Audited;
use App\Modules\Cifratura\Contracts\Encrypted;
use App\Modules\Cifratura\Contracts\HasEncryptedFields;
use App\Modules\Core\Contracts\OrgModel;

/**
 * I conti di pagamento online dell'associazione. Le chiavi segrete (Stripe, segreto del webhook, PayPal) sono cifrate
 * con la chiave dell'associazione e non tornano mai al browser: le pagine dicono solo se sono impostate.
 *
 * @property string $id
 * @property bool $stripe_enabled
 * @property string|null $stripe_secret
 * @property string|null $stripe_webhook
 * @property bool $paypal_enabled
 * @property string|null $paypal_client_id
 * @property string|null $paypal_secret
 * @property bool $paypal_live
 * @property string|null $account_id
 */
final class PaymentSettings extends OrgModel
{
    use Audited;
    use HasEncryptedFields;

    protected $table = 'app.payment_settings';

    /** @var list<string> */
    protected $fillable = ['stripe_enabled', 'stripe_secret', 'stripe_webhook', 'paypal_enabled', 'paypal_client_id', 'paypal_secret', 'paypal_live', 'account_id'];

    /** @var list<string> */
    protected $hidden = ['stripe_secret_enc', 'stripe_webhook_enc', 'paypal_secret_enc'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'stripe_enabled' => 'boolean',
            'stripe_secret' => Encrypted::class,
            'stripe_webhook' => Encrypted::class,
            'paypal_enabled' => 'boolean',
            'paypal_secret' => Encrypted::class,
            'paypal_live' => 'boolean',
        ];
    }

    /** @return list<string> i metodi pronti (attivi e con le chiavi) */
    public function providers(): array
    {
        $out = [];
        if ($this->stripe_enabled && $this->stripe_secret !== null && $this->account_id !== null) {
            $out[] = 'stripe';
        }
        if ($this->paypal_enabled && $this->paypal_client_id !== null && $this->paypal_secret !== null && $this->account_id !== null) {
            $out[] = 'paypal';
        }

        return $out;
    }
}
