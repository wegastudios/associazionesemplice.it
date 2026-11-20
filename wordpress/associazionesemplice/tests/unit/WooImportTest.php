<?php
use AssociazioneSemplice\PaymentConfig as P;
use AssociazioneSemplice\WooImport;
use PHPUnit\Framework\TestCase;

/** Lettura delle chiavi di pagamento salvate dai plugin di WooCommerce (opzioni finte: nessun WordPress). */
final class WooImportTest extends TestCase {

	private function options( array $all ): callable {
		return function ( string $name ) use ( $all ) {
			return $all[ $name ] ?? false;
		};
	}

	public function test_nothing_found_on_a_site_without_woocommerce_gateways(): void {
		$this->assertSame( array(), WooImport::detect( $this->options( array() ) ) );
		$this->assertSame( array(), WooImport::notes( $this->options( array() ) ) );
		$this->assertSame( array(), WooImport::detect( $this->options( array( 'woocommerce_stripe_settings' => 'rotto', 'woocommerce_sumup_settings' => array( 'api_key' => null, 'merchant_id' => null ) ) ) ) );
	}

	public function test_stripe_takes_the_keys_of_the_mode_in_use_and_never_the_webhook_secret(): void {
		$s = array(
			'testmode' => 'no', 'publishable_key' => 'pk_live_51Abc', 'secret_key' => ' sk_live_51Abc ', 'webhook_secret' => 'whsec_woo',
			'test_publishable_key' => 'pk_test_51Abc', 'test_secret_key' => 'sk_test_51Abc',
		);
		$r = WooImport::detect( $this->options( array( 'woocommerce_stripe_settings' => $s ) ) )['stripe'];
		$this->assertSame( array( 'stripe_mode' => 'live', 'stripe_publishable_key' => 'pk_live_51Abc', 'stripe_secret_key' => 'sk_live_51Abc' ), $r['values'] );
		$this->assertSame( 'WooCommerce Stripe Gateway', $r['source'] );
		$this->assertCount( 1, $r['notes'], 'solo l\'avviso sul webhook' );
		$this->assertSame( array(), P::validate_gateway( $r['values'], 'stripe' )['errors'], 'le chiavi importate passano i controlli del plugin' );

		$t = WooImport::detect( $this->options( array( 'woocommerce_stripe_settings' => array( 'testmode' => 'yes' ) + $s ) ) )['stripe'];
		$this->assertSame( 'test', $t['values']['stripe_mode'] );
		$this->assertSame( 'sk_test_51Abc', $t['values']['stripe_secret_key'] );
	}

	public function test_stripe_falls_back_to_the_other_mode_when_the_one_in_use_has_no_keys(): void {
		$r = WooImport::detect( $this->options( array( 'woocommerce_stripe_settings' => array( 'testmode' => 'yes', 'publishable_key' => 'pk_live_1', 'secret_key' => 'rk_live_1' ) ) ) );
		$this->assertSame( 'live', $r['stripe']['values']['stripe_mode'] );
		$this->assertSame( 'rk_live_1', $r['stripe']['values']['stripe_secret_key'] );
	}

	public function test_stripe_connected_as_app_is_not_importable_and_says_why(): void {
		$o = $this->options( array( 'woocommerce_stripe_settings' => array( 'testmode' => 'no', 'publishable_key' => 'pk_live_1', 'secret_key' => 'sk_live_1', 'connection_type' => 'app', 'refresh_token' => 'x' ) ) );
		$this->assertArrayNotHasKey( 'stripe', WooImport::detect( $o ) );
		$this->assertStringContainsString( 'un\'ora', implode( ' ', WooImport::notes( $o ) ) );
	}

	public function test_stripe_connect_keys_are_imported_with_a_warning(): void {
		$r = WooImport::detect( $this->options( array( 'woocommerce_stripe_settings' => array( 'publishable_key' => 'pk_live_1', 'secret_key' => 'sk_live_1', 'connection_type' => 'connect' ) ) ) )['stripe'];
		$this->assertCount( 2, $r['notes'] );
	}

	public function test_paypal_new_settings_win_over_the_old_ones_and_tell_sandbox_from_live(): void {
		$id  = str_repeat( 'A', 40 );
		$sec = str_repeat( 'b', 40 );
		$new = array( 'client_id' => $id, 'client_secret' => $sec, 'sandbox_merchant' => false, 'use_sandbox' => true, 'use_manual_connection' => true );
		$old = array( 'client_id' => 'vecchio', 'client_secret' => 'vecchio', 'sandbox_on' => true );
		$r   = WooImport::detect( $this->options( array( 'woocommerce-ppcp-data-common' => $new, 'woocommerce-ppcp-settings' => $old ) ) )['paypal'];
		$this->assertSame( array( 'paypal_mode' => 'live', 'paypal_client_id' => $id, 'paypal_client_secret' => $sec ), $r['values'], 'conta sandbox_merchant, non l\'interruttore use_sandbox' );
		$this->assertSame( array(), $r['notes'] );
		$this->assertSame( array(), P::validate_gateway( $r['values'], 'paypal' )['errors'] );

		$connect = WooImport::detect( $this->options( array( 'woocommerce-ppcp-data-common' => array( 'use_manual_connection' => false, 'sandbox_merchant' => true ) + $new ) ) )['paypal'];
		$this->assertSame( 'sandbox', $connect['values']['paypal_mode'] );
		$this->assertCount( 1, $connect['notes'], 'collegato con «Connetti»: avviso' );
	}

	public function test_paypal_old_settings_are_read_when_the_new_ones_are_empty(): void {
		$old = array( 'client_id' => str_repeat( 'A', 40 ), 'client_secret' => str_repeat( 'b', 40 ), 'sandbox_on' => true );
		$r   = WooImport::detect( $this->options( array( 'woocommerce-ppcp-data-common' => array( 'client_id' => '', 'client_secret' => '' ), 'woocommerce-ppcp-settings' => $old ) ) )['paypal'];
		$this->assertSame( 'sandbox', $r['values']['paypal_mode'] );
		$this->assertSame( $old['client_id'], $r['values']['paypal_client_id'] );
	}

	public function test_sumup_needs_both_the_api_key_and_the_merchant_code(): void {
		$r = WooImport::detect( $this->options( array( 'woocommerce_sumup_settings' => array( 'api_key' => 'sup_sk_abcdefghijklmnop', 'merchant_id' => 'MABCD123', 'pay_to_email' => 'x@example.org' ) ) ) )['sumup'];
		$this->assertSame( array( 'sumup_merchant_code' => 'MABCD123', 'sumup_api_key' => 'sup_sk_abcdefghijklmnop' ), $r['values'] );
		$this->assertSame( array(), P::validate_gateway( $r['values'], 'sumup' )['errors'] );
		$this->assertSame( array(), WooImport::detect( $this->options( array( 'woocommerce_sumup_settings' => array( 'api_key' => 'sup_sk_abcdefghijklmnop' ) ) ) ) );
	}

	public function test_woopayments_and_paypal_standard_are_explained_not_imported(): void {
		$o = $this->options( array( 'wcpay_account_data' => array( 'data' => array( 'live_publishable_key' => 'pk_live_1' ) ), 'woocommerce_paypal_settings' => array( 'enabled' => 'yes', 'email' => 'x@example.org' ) ) );
		$this->assertSame( array(), WooImport::detect( $o ) );
		$this->assertCount( 2, WooImport::notes( $o ) );
	}

	public function test_every_imported_key_has_a_name_on_screen(): void {
		$all = array(
			'woocommerce_stripe_settings'  => array( 'publishable_key' => 'pk_live_1', 'secret_key' => 'sk_live_1' ),
			'woocommerce-ppcp-data-common' => array( 'client_id' => 'id', 'client_secret' => 'sec' ),
			'woocommerce_sumup_settings'   => array( 'api_key' => 'k', 'merchant_id' => 'M1' ),
		);
		$found = WooImport::detect( $this->options( $all ) );
		$this->assertSame( array( 'stripe', 'paypal', 'sumup' ), array_keys( $found ) );
		foreach ( $found as $row ) {
			foreach ( array_keys( $row['values'] ) as $k ) {
				$this->assertTrue( isset( WooImport::KEY_NAMES[ $k ] ) || '_mode' === substr( $k, -5 ), $k );
			}
		}
	}
}
