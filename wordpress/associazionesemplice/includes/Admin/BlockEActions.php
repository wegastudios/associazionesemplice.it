<?php
namespace AssociazioneSemplice\Admin;

use AssociazioneSemplice\ConsentRules;
use AssociazioneSemplice\Consents;
use AssociazioneSemplice\Edition;
use AssociazioneSemplice\Gateways;
use AssociazioneSemplice\Newsletter;
use AssociazioneSemplice\PaymentConfig;
use AssociazioneSemplice\SatispayApi;
use AssociazioneSemplice\SatispayRules;
use AssociazioneSemplice\Settings;
use AssociazioneSemplice\Sms;

defined( 'ABSPATH' ) || exit;

/**
 * Gestori dei moduli del blocco E (SMS, newsletter, Satispay), funzioni di Associazione Semplice Pro. Si registrano su Actions::add_handler():
 * nonce e permessi li controlla Associazione Semplice; senza la funzione l'azione si rifiuta con il messaggio standard.
 */
final class BlockEActions {

	/** Azione => [metodo, funzione, riservata all'amministratore]. */
	const MAP = array(
		'asem_save_sms'          => array( 'save_sms', 'sms', true ),
		'asem_test_sms'          => array( 'test_sms', 'sms', true ),
		'asem_sms_retry'         => array( 'sms_retry', 'sms', false ),
		'asem_save_newsletter'   => array( 'save_newsletter', 'newsletter', true ),
		'asem_newsletter_lists'  => array( 'newsletter_lists', 'newsletter', true ),
		'asem_test_newsletter'   => array( 'test_newsletter', 'newsletter', true ),
		'asem_newsletter_sync'   => array( 'newsletter_sync', 'newsletter', true ),
		'asem_consent_set'       => array( 'consent_set', '', false ),
		'asem_satispay_connect'  => array( 'satispay_connect', 'payments', true ),
	);

	public static function register(): void {
		foreach ( self::MAP as $action => $row ) {
			Actions::add_handler( $action, self::guarded( $row[0], $row[1] ), $row[2] );
		}
	}

	private static function guarded( string $method, string $feature ): callable {
		return function ( array $p ) use ( $method, $feature ) {
			if ( '' !== $feature && ! Edition::has( $feature ) ) {
				throw new \InvalidArgumentException( Edition::missing_message( $feature ) );
			}
			return self::$method( $p );
		};
	}

	private static function txt( array $p, string $k ): string {
		return sanitize_text_field( (string) ( $p[ $k ] ?? '' ) );
	}

	/** Salva i campi di un gruppo di servizi; i segreti vuoti lasciano quelli già salvati, la spunta «rimuovi» li cancella. */
	private static function service_values( array $p, array $keys ): array {
		$out = array();
		foreach ( $keys as $k => $secret ) {
			$out[ $k ] = self::txt( $p, $k );
		}
		return $out;
	}

	private static function clear_secrets( array $p, array $keys ): void {
		foreach ( $keys as $k => $secret ) {
			if ( $secret && ! empty( $p[ 'clear_' . $k ] ) ) {
				Settings::clear_secret( $k );
			}
		}
	}

	// ---------- SMS ----------

	public static function save_sms( array $p ): array {
		$id   = self::txt( $p, 'sms_provider' );
		$keys = Sms::setting_keys();
		Settings::update(
			self::service_values( $p, $keys ) + array(
				'sms_provider'  => isset( Sms::providers()[ $id ] ) ? $id : '',
				'sms_sender'    => \AssociazioneSemplice\SmsRules::clean_sender( self::txt( $p, 'sms_sender' ) ),
				'sms_reminders' => ! empty( $p['sms_reminders'] ) ? 1 : 0,
			)
		);
		self::clear_secrets( $p, $keys );
		delete_transient( Sms::CREDITS_TR );
		return array( Ui::url( 'asem-tech' ), 'Impostazioni degli SMS salvate.' );
	}

	public static function test_sms( array $p ): array {
		$r = Sms::test();
		if ( ! $r['ok'] ) {
			throw new \InvalidArgumentException( $r['message'] ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- messaggio interno, mostrato solo dopo esc_html
		}
		return array( Ui::url( 'asem-tech' ), $r['message'] . ( null !== $r['credits'] ? ' Crediti o saldo residui: ' . $r['credits'] . '.' : '' ) );
	}

	public static function sms_retry( array $p ): array {
		$bid = isset( $p['broadcast_id'] ) ? (int) $p['broadcast_id'] : 0;
		$n   = Sms::retry_failed( $bid > 0 ? $bid : null );
		$to  = $bid > 0 ? Ui::url( 'asem-messages', array( 'view' => $bid ) ) : Ui::url( 'asem-tech' );
		return array( $to, $n . ( 1 === $n ? ' SMS rimesso in coda.' : ' SMS rimessi in coda.' ) );
	}

	// ---------- Newsletter ----------

	public static function save_newsletter( array $p ): array {
		$id   = self::txt( $p, 'newsletter_provider' );
		$keys = Newsletter::setting_keys();
		$new  = isset( Newsletter::providers()[ $id ] ) ? $id : '';
		$list = $new === (string) Settings::get( 'newsletter_provider' ) ? self::txt( $p, 'newsletter_list' ) : ''; // cambiando servizio la lista va scelta di nuovo
		Settings::update(
			self::service_values( $p, $keys ) + array(
				'newsletter_provider' => $new,
				'newsletter_list'     => $list,
				'newsletter_auto'     => ! empty( $p['newsletter_auto'] ) ? 1 : 0,
				'newsletter_extra'    => ! empty( $p['newsletter_extra'] ) ? 1 : 0,
			)
		);
		self::clear_secrets( $p, $keys );
		return array( Ui::url( 'asem-tech' ), 'Impostazioni della newsletter salvate.' . ( '' !== $new && '' === $list ? ' Ora premi «Leggi le liste» e scegli la lista.' : '' ) );
	}

	public static function newsletter_lists( array $p ): array {
		try {
			$lists = Newsletter::fetch_lists();
		} catch ( \RuntimeException $e ) {
			throw new \InvalidArgumentException( $e->getMessage() ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- messaggio interno, mostrato solo dopo esc_html
		}
		return array( Ui::url( 'asem-tech' ), count( $lists ) . ( 1 === count( $lists ) ? ' lista trovata' : ' liste trovate' ) . ': scegline una e salva.' );
	}

	public static function test_newsletter( array $p ): array {
		$r = Newsletter::test();
		if ( ! $r['ok'] ) {
			throw new \InvalidArgumentException( $r['message'] ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- messaggio interno, mostrato solo dopo esc_html
		}
		return array( Ui::url( 'asem-tech' ), $r['message'] );
	}

	public static function newsletter_sync( array $p ): array {
		$r = Newsletter::sync( Newsletter::BATCH_MANUAL );
		\AssociazioneSemplice\Audit::log( 'newsletter.synced', 'settings', null, array( 'added' => $r['added'], 'removed' => $r['removed'], 'errors' => $r['errors'] ) );
		return array( Ui::url( 'asem-tech' ), $r['message'] );
	}

	// ---------- Consensi ----------

	public static function consent_set( array $p ): array {
		$ch = self::txt( $p, 'channel' );
		if ( ConsentRules::SMS === $ch && ! Edition::has( 'sms' ) || ConsentRules::NEWSLETTER === $ch && ! Edition::has( 'newsletter' ) ) {
			throw new \InvalidArgumentException( Edition::missing_message() ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- messaggio interno, mostrato solo dopo esc_html
		}
		$id = (int) ( $p['id'] ?? 0 );
		Consents::set( $id, $ch, ! empty( $p['grant'] ), ! empty( $p['grant'] ) ? self::txt( $p, 'source' ) : 'admin' );
		return array( Ui::url( 'asem-person', array( 'id' => $id ) ), ! empty( $p['grant'] ) ? 'Consenso registrato.' : 'Consenso revocato.' );
	}

	// ---------- Satispay ----------

	/** Collega Satispay: crea la coppia di chiavi, registra la pubblica con il codice di attivazione e conserva la privata cifrata. */
	public static function satispay_connect( array $p ): array {
		$token = trim( self::txt( $p, 'token' ) );
		if ( '' === $token ) {
			throw new \InvalidArgumentException( 'Incolla il codice di attivazione di Satispay.' );
		}
		$mode = 'live' === (string) Settings::get( 'satispay_mode' ) ? 'live' : 'sandbox';
		try {
			$keys   = SatispayApi::generate_keys();
			$key_id = SatispayApi::register_key( array( Gateways::class, 'wp_http' ), $mode, $token, $keys['public'] );
		} catch ( \RuntimeException $e ) {
			throw new \InvalidArgumentException( $e->getMessage() ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- messaggio interno, mostrato solo dopo esc_html
		}
		Settings::update( array( 'satispay_key_id' => $key_id, 'satispay_private_key' => $keys['private'] ) );
		\AssociazioneSemplice\Audit::log( 'gateway.connected', 'settings', null, array( 'provider' => SatispayRules::ID, 'mode' => $mode ) ); // senza codice né chiavi
		return array( Ui::url( 'asem-payments' ), 'Satispay collegato (' . ( 'live' === $mode ? 'modalità reale' : 'sandbox' ) . '). Scegli Satispay tra i metodi di pagamento e prova il collegamento.' );
	}
}
