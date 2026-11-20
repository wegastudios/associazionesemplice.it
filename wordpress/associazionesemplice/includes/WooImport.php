<?php
namespace AssociazioneSemplice;

defined( 'ABSPATH' ) || exit;

/**
 * Chiavi di pagamento già inserite nei plugin di WooCommerce di questo sito (Stripe, PayPal Payments, SumUp), lette dalle loro opzioni
 * per copiarle nelle impostazioni del plugin: chi smette di usare il negozio continua a incassare con gli stessi conti.
 * Qui c'è solo la LETTURA (pura: le opzioni arrivano da una funzione passata da fuori); il salvataggio lo fa l'azione dell'amministratore.
 *
 * Nomi delle opzioni verificati sul sorgente dei plugin il 20/11/2026: WooCommerce Stripe Gateway 11.2.0, WooCommerce PayPal Payments 4.2.2
 * (e 2.9.6 per le impostazioni di prima), SumUp Payment Gateway for WooCommerce 2.18.0.
 */
final class WooImport {

	/** Nomi a schermo delle chiavi importate (le altre voci di «values» sono modalità). */
	const KEY_NAMES = array(
		'stripe_publishable_key' => 'chiave pubblicabile',
		'stripe_secret_key'      => 'chiave segreta',
		'paypal_client_id'       => 'Client ID',
		'paypal_client_secret'   => 'Client Secret',
		'sumup_merchant_code'    => 'codice commerciante',
		'sumup_api_key'          => 'chiave API',
	);

	/**
	 * Metodi con chiavi importabili trovati su questo sito.
	 *
	 * @return array<string,array{name:string,source:string,mode:string,values:array<string,string>,notes:string[]}> gateway => riga
	 */
	public static function found(): array {
		return self::detect( self::reader() );
	}

	/** Frasi sui metodi di WooCommerce presenti ma senza chiavi da importare (WooPayments, PayPal Standard, Stripe collegato come app). @return string[] */
	public static function not_importable(): array {
		return self::notes( self::reader() );
	}

	/** @param callable $option function( string $name ): mixed — il valore di un'opzione di WordPress */
	public static function detect( callable $option ): array {
		$out = array();
		foreach ( array( self::stripe( $option ), self::paypal( $option ), self::sumup( $option ) ) as $row ) {
			if ( $row ) {
				$out[ $row['gateway'] ] = array( 'name' => $row['name'], 'source' => $row['source'], 'mode' => $row['mode'], 'values' => $row['values'], 'notes' => $row['notes'] );
			}
		}
		return $out;
	}

	/** @param callable $option function( string $name ): mixed */
	public static function notes( callable $option ): array {
		$out = array();
		$s   = self::arr( $option( 'woocommerce_stripe_settings' ) );
		if ( $s && ! self::stripe( $option ) && ( 'app' === self::str( $s, 'connection_type' ) || 'app' === self::str( $s, 'test_connection_type' ) ) ) {
			$out[] = 'Stripe è collegato a WooCommerce con il pulsante «Connetti»: la chiave salvata vale per un\'ora sola e non si può importare. Crea una chiave tua in Stripe (Sviluppatori → Chiavi API) e inseriscila più sopra.';
		}
		if ( self::arr( $option( 'wcpay_account_data' ) ) ) {
			$out[] = 'WooPayments non ha chiavi da importare: il conto di incasso è gestito da WooCommerce. Per incassare senza negozio apri un conto Stripe dell\'associazione e inserisci le sue chiavi più sopra.';
		}
		$pp = self::arr( $option( 'woocommerce_paypal_settings' ) );
		if ( 'yes' === self::str( $pp, 'enabled' ) && ! self::paypal( $option ) ) {
			$out[] = 'PayPal Standard (il vecchio metodo di WooCommerce) non ha le credenziali che servono qui: crea Client ID e Client Secret su developer.paypal.com e inseriscili più sopra.';
		}
		return $out;
	}

	/** WooCommerce Stripe Gateway: opzione woocommerce_stripe_settings, chiavi di prova con il prefisso test_. */
	private static function stripe( callable $option ): ?array {
		$s = self::arr( $option( 'woocommerce_stripe_settings' ) );
		if ( ! $s ) {
			return null;
		}
		$active = 'yes' === self::str( $s, 'testmode' ) ? 'test_' : '';
		foreach ( array( $active, '' === $active ? 'test_' : '' ) as $prefix ) { // prima la modalità in uso nel negozio, poi l'altra
			$pk   = self::str( $s, $prefix . 'publishable_key' );
			$sk   = self::str( $s, $prefix . 'secret_key' );
			$type = self::str( $s, $prefix . 'connection_type' );
			if ( '' === $pk || '' === $sk || 'app' === $type ) { // collegamento «app»: la chiave è un accesso che scade dopo un'ora
				continue;
			}
			$notes = array( 'Il segreto del webhook non si importa: vale solo per l\'indirizzo di WooCommerce. Dopo l\'importazione crea un webhook nuovo con l\'indirizzo che trovi in questa pagina, nella sezione di Stripe.' );
			if ( 'connect' === $type ) {
				$notes[] = 'Queste chiavi nascono dal collegamento «Connetti» di WooCommerce: se scolleghi Stripe dal negozio possono smettere di funzionare. Per sicurezza crea chiavi tue in Stripe (Sviluppatori → Chiavi API).';
			}
			return array(
				'gateway' => PaymentConfig::STRIPE,
				'name'    => 'Stripe',
				'source'  => 'WooCommerce Stripe Gateway',
				'mode'    => '' === $prefix ? 'Reale (live)' : 'Prova (test)',
				'values'  => array( 'stripe_mode' => '' === $prefix ? 'live' : 'test', 'stripe_publishable_key' => $pk, 'stripe_secret_key' => $sk ),
				'notes'   => $notes,
			);
		}
		return null;
	}

	/** WooCommerce PayPal Payments: dalla 3.0 opzione woocommerce-ppcp-data-common, prima woocommerce-ppcp-settings. */
	private static function paypal( callable $option ): ?array {
		$new = self::arr( $option( 'woocommerce-ppcp-data-common' ) );
		$old = self::arr( $option( 'woocommerce-ppcp-settings' ) );
		if ( '' !== self::str( $new, 'client_id' ) && '' !== self::str( $new, 'client_secret' ) ) {
			$id      = self::str( $new, 'client_id' );
			$secret  = self::str( $new, 'client_secret' );
			$sandbox = ! empty( $new['sandbox_merchant'] );
			$manual  = ! empty( $new['use_manual_connection'] );
		} elseif ( '' !== self::str( $old, 'client_id' ) && '' !== self::str( $old, 'client_secret' ) ) {
			$id      = self::str( $old, 'client_id' );
			$secret  = self::str( $old, 'client_secret' );
			$sandbox = ! empty( $old['sandbox_on'] );
			$manual  = true; // nelle impostazioni di prima non si distingue: nessun avviso in più
		} else {
			return null;
		}
		return array(
			'gateway' => PaymentConfig::PAYPAL,
			'name'    => 'PayPal',
			'source'  => 'WooCommerce PayPal Payments',
			'mode'    => $sandbox ? 'Prova (sandbox)' : 'Reale (live)',
			'values'  => array( 'paypal_mode' => $sandbox ? 'sandbox' : 'live', 'paypal_client_id' => $id, 'paypal_client_secret' => $secret ),
			'notes'   => $manual ? array() : array( 'Queste credenziali nascono dal collegamento «Connetti» di WooCommerce: se dopo l\'importazione la verifica della connessione non riesce, creane di tue su developer.paypal.com.' ),
		);
	}

	/** SumUp Payment Gateway for WooCommerce: opzione woocommerce_sumup_settings (scollegando, le voci restano ma vuote). */
	private static function sumup( callable $option ): ?array {
		$s    = self::arr( $option( 'woocommerce_sumup_settings' ) );
		$key  = self::str( $s, 'api_key' );
		$code = self::str( $s, 'merchant_id' );
		if ( '' === $key || '' === $code ) {
			return null;
		}
		return array(
			'gateway' => PaymentConfig::SUMUP,
			'name'    => 'SumUp',
			'source'  => 'SumUp Payment Gateway for WooCommerce',
			'mode'    => 'Reale',
			'values'  => array( 'sumup_merchant_code' => $code, 'sumup_api_key' => $key ),
			'notes'   => array(),
		);
	}

	/** Lettore delle opzioni di WordPress (senza WordPress, nelle prove, non trova nulla). */
	private static function reader(): callable {
		return function ( string $name ) {
			return function_exists( 'get_option' ) ? get_option( $name ) : null;
		};
	}

	private static function arr( $value ): array {
		return is_array( $value ) ? $value : array();
	}

	private static function str( array $a, string $key ): string {
		return isset( $a[ $key ] ) && is_scalar( $a[ $key ] ) ? trim( (string) $a[ $key ] ) : '';
	}
}
