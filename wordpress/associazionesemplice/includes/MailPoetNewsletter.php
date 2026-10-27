<?php
namespace AssociazioneSemplice;

defined( 'ABSPATH' ) || exit;

/**
 * Newsletter con MailPoet, se il plugin è attivo sul sito: si usa la sua API PHP (nessuna chiave, nessuna rete).
 * Chi si è già disiscritto da MailPoet resta disiscritto. Tipo di socio e stato della tessera non si possono inviare a MailPoet da qui.
 */
final class MailPoetNewsletter implements NewsletterProvider {

	const API_CLASS = '\\MailPoet\\API\\API';

	/** @var object|null l'API di MailPoet (iniettabile per i test) */
	private $api;

	/** @param callable $http non usato (c'è per avere lo stesso costruttore degli altri servizi) */
	public function __construct( $http = null, array $cfg = array(), ?object $api = null ) {
		$this->api = $api;
	}

	public function id(): string {
		return 'mailpoet';
	}

	public function label(): string {
		return 'MailPoet';
	}

	public static function fields(): array {
		return array();
	}

	public static function secret_fields(): array {
		return array();
	}

	private function api(): object {
		if ( null === $this->api ) {
			$class = self::API_CLASS;
			if ( ! class_exists( $class ) ) {
				throw new \RuntimeException( 'MailPoet non è attivo su questo sito.' );
			}
			$this->api = $class::MP( 'v1' );
		}
		return $this->api;
	}

	public function missing(): string {
		return null === $this->api && ! class_exists( self::API_CLASS ) ? 'MailPoet non è attivo su questo sito: installalo e attivalo, oppure scegli un altro servizio.' : '';
	}

	public function lists(): array {
		$out = array();
		foreach ( (array) $this->api()->getLists() as $l ) {
			if ( is_array( $l ) && isset( $l['id'] ) ) {
				$out[ (string) $l['id'] ] = (string) ( $l['name'] ?? $l['id'] );
			}
		}
		return $out;
	}

	/** @return array|null il contatto, o null se MailPoet non lo conosce */
	private function find( string $email ): ?array {
		try {
			$s = $this->api()->getSubscriber( $email );
		} catch ( \Exception $e ) {
			return null;
		}
		return is_array( $s ) ? $s : null;
	}

	public function subscribe( array $contact, string $list, bool $extra ): void {
		$opts = array( 'send_confirmation_email' => false, 'schedule_welcome_email' => false, 'skip_subscriber_notification' => true );
		try {
			$s = $this->find( $contact['email'] );
			if ( $s ) {
				if ( 'unsubscribed' === ( $s['status'] ?? '' ) ) {
					return; // si è disiscritto da solo: non si reiscrive
				}
				$this->api()->subscribeToLists( (int) $s['id'], array( (int) $list ), $opts );
				return;
			}
			$this->api()->addSubscriber( array( 'email' => $contact['email'], 'first_name' => $contact['first_name'], 'last_name' => $contact['last_name'] ), array( (int) $list ), $opts );
		} catch ( \Exception $e ) {
			throw new \RuntimeException( 'MailPoet: ' . $e->getMessage() ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- messaggio interno, mostrato solo dopo esc_html
		}
	}

	public function remove( string $email, string $list ): void {
		$s = $this->find( $email );
		if ( ! $s ) {
			return;
		}
		try {
			$this->api()->unsubscribeFromLists( (int) $s['id'], array( (int) $list ) );
		} catch ( \Exception $e ) {
			throw new \RuntimeException( 'MailPoet: ' . $e->getMessage() ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- messaggio interno, mostrato solo dopo esc_html
		}
	}

	public function check(): array {
		if ( '' !== $this->missing() ) {
			return array( 'ok' => false, 'message' => $this->missing() );
		}
		try {
			$n = count( $this->lists() );
		} catch ( \Throwable $e ) {
			return array( 'ok' => false, 'message' => 'MailPoet: ' . $e->getMessage() );
		}
		return array( 'ok' => true, 'message' => 'MailPoet: attivo, ' . $n . ( 1 === $n ? ' lista trovata.' : ' liste trovate.' ) );
	}
}
