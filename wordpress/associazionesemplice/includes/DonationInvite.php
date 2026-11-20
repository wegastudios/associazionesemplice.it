<?php
namespace AssociazioneSemplice;

defined( 'ABSPATH' ) || exit;

/**
 * L'invito «Non puoi partecipare? Sostienici con una donazione»: una riga con il link alla pagina delle donazioni, da mostrare
 * (se l'associazione lo vuole, posto per posto) sulla pagina dell'evento o del corso, nella conferma dopo l'iscrizione, nell'area soci
 * e in fondo alle email ai soci. Tutto spento di default; non compare mai se non c'è una pagina delle donazioni da raggiungere.
 */
final class DonationInvite {

	const PLACES = array(
		'event'   => 'donate_invite_event',
		'confirm' => 'donate_invite_confirm',
		'area'    => 'donate_invite_area',
		'mail'    => 'donate_invite_mail',
	);

	public static function place_on( string $place ): bool {
		return isset( self::PLACES[ $place ] ) && ! empty( Settings::get( self::PLACES[ $place ] ) );
	}

	/** Dove porta l'invito: la pagina indicata dall'associazione, altrimenti la prima pagina che contiene il modulo delle donazioni. */
	public static function url(): string {
		$set = trim( (string) Settings::get( 'donate_invite_page' ) );
		if ( '' !== $set ) {
			return esc_url_raw( $set );
		}
		if ( ! Donations::enabled() ) {
			return '';
		}
		$found = get_posts(
			array(
				'post_type'      => array( 'page', 'post' ),
				'post_status'    => 'publish',
				's'              => '[associazionesemplice_donazioni',
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
			)
		);
		return $found ? (string) get_permalink( (int) $found[0] ) : '';
	}

	/** «È anche detraibile.» solo se l'associazione ha acceso la sua opzione fiscale (spenta di default), con l'invito a sentire il commercialista. */
	public static function deductible_text(): string {
		return empty( Settings::get( 'donation_deductible' ) ) ? '' : 'È anche detraibile. Verifica con il tuo commercialista se la tua associazione ne ha diritto.';
	}

	/** Le frasi dell'invito: [testo prima del link, testo del link, coda]. */
	private static function parts(): array {
		return array( 'Non puoi partecipare? ', 'Sostienici con una donazione', self::deductible_text() );
	}

	/** Il blocco per le pagine del sito (vuoto se il posto è spento o manca la pagina). */
	public static function html( string $place ): string {
		$url = self::place_on( $place ) ? self::url() : '';
		if ( '' === $url ) {
			return '';
		}
		list( $before, $link, $tail ) = self::parts();
		return '<p class="asemf-donate-invite">' . esc_html( $before ) . '<a href="' . esc_url( $url ) . '">' . esc_html( $link ) . '</a>.'
			. ( '' !== $tail ? ' ' . esc_html( $tail ) : '' ) . '</p>';
	}

	/** La riga per le email ai soci (testo semplice, con l'indirizzo). */
	public static function mail_line(): string {
		$url = self::place_on( 'mail' ) ? self::url() : '';
		if ( '' === $url ) {
			return '';
		}
		list( $before, $link, $tail ) = self::parts();
		return $before . $link . ': ' . $url . ( '' !== $tail ? "\n" . $tail : '' );
	}

	/** Il messaggio di esito è la conferma di un'iscrizione o di una prenotazione? */
	public static function is_confirmation( string $message ): bool {
		return (bool) preg_match( '/^(Prenotazione|Iscrizione|Richiesta)\b/u', $message );
	}

	/** Chi riceve la mail è un socio? (le email alla segreteria e agli altri indirizzi non portano l'invito) */
	public static function is_member_address( $to ): bool {
		$list = is_array( $to ) ? $to : explode( ',', (string) $to );
		if ( 1 !== count( $list ) ) {
			return false;
		}
		$email = trim( (string) reset( $list ) );
		if ( preg_match( '/<([^>]+)>/', $email, $m ) ) {
			$email = $m[1];
		}
		return is_email( $email ) && null !== Plugin::people()->find_by_email( $email );
	}
}
