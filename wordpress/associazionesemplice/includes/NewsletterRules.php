<?php
namespace AssociazioneSemplice;

defined( 'ABSPATH' ) || exit;

/**
 * Newsletter: chi si iscrive e chi si toglie (regole pure, senza rete). Si iscrive solo chi ha dato il consenso alla newsletter, ha un'email valida
 * e non è stato anonimizzato; chi revoca il consenso, cambia indirizzo o viene anonimizzato va tolto dalla lista.
 */
final class NewsletterRules {

	const SUBSCRIBE = 'subscribe';
	const REMOVE    = 'remove';
	const KEEP      = 'keep';
	const SKIP      = 'skip';

	/** Stati della riga di sincronizzazione. */
	const ST_SUBSCRIBED = 'subscribed';
	const ST_REMOVED    = 'removed';
	const ST_ERROR      = 'error';
	const ST_REMOVE_ERR = 'remove_err';

	/** Può essere iscritta? Consenso, email valida, non anonimizzata. */
	public static function eligible( array $person, bool $consent ): bool {
		if ( ! $consent || ! empty( $person['anonymized_at'] ) ) {
			return false;
		}
		$email = trim( (string) ( $person['email'] ?? '' ) );
		return '' !== $email && false !== filter_var( $email, FILTER_VALIDATE_EMAIL );
	}

	/** Stato della tessera per la lista: attiva, scaduta, sospesa o nessuna. */
	public static function card_state( array $person, string $today ): string {
		if ( ! MemberType::is_member( (string) ( $person['type'] ?? '' ) ) ) {
			return 'nessuna';
		}
		if ( ! empty( $person['suspended_at'] ) ) {
			return 'sospesa';
		}
		if ( MemberType::is_auto_renewed( (string) $person['type'] ) ) {
			return 'attiva';
		}
		return ! empty( $person['active_until'] ) && (string) $person['active_until'] >= $today ? 'attiva' : 'scaduta';
	}

	/** I dati che vanno alla lista. @return array{email:string,first_name:string,last_name:string,type:string,card:string} */
	public static function contact( array $person, string $today ): array {
		return array(
			'email'      => Text::lower( trim( (string) $person['email'] ) ),
			'first_name' => mb_substr( trim( (string) ( $person['first_name'] ?? '' ) ), 0, 100 ),
			'last_name'  => mb_substr( trim( (string) ( $person['last_name'] ?? '' ) ), 0, 100 ),
			'type'       => MemberType::label( (string) ( $person['type'] ?? '' ) ),
			'card'       => self::card_state( $person, $today ),
		);
	}

	/** Impronta dei dati inviati: se non cambia, non si richiama il servizio. */
	public static function hash( array $contact, bool $extra ): string {
		return md5( (string) json_encode( array( $contact, $extra ) ) );
	}

	/** Impronta dell'indirizzo come la vuole Mailchimp. */
	public static function mailchimp_hash( string $email ): string {
		return md5( strtolower( trim( $email ) ) );
	}

	/**
	 * Cosa fare di una persona.
	 *
	 * @param array|null $synced riga di sincronizzazione precedente (status, email, hash) o null
	 * @return string SUBSCRIBE | REMOVE | KEEP | SKIP
	 */
	public static function action( array $person, bool $consent, ?array $synced, string $today, bool $extra ): string {
		$listed = $synced && in_array( (string) $synced['status'], array( self::ST_SUBSCRIBED, self::ST_REMOVE_ERR ), true );
		if ( ! self::eligible( $person, $consent ) ) {
			return $listed ? self::REMOVE : self::SKIP;
		}
		$contact = self::contact( $person, $today );
		if ( $synced && self::ST_SUBSCRIBED === $synced['status'] && (string) $synced['email'] === $contact['email'] && (string) $synced['hash'] === self::hash( $contact, $extra ) ) {
			return self::KEEP;
		}
		return self::SUBSCRIBE;
	}

	/** Indirizzo del servizio Mailchimp dalla chiave («abc123-us21» → us21). Vuoto se la chiave non ha il formato giusto. */
	public static function mailchimp_dc( string $key ): string {
		return preg_match( '/-([a-z]{2}\d{1,2})$/', trim( $key ), $m ) ? $m[1] : '';
	}
}
