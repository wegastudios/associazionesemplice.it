<?php
namespace AssociazioneSemplice;

defined( 'ABSPATH' ) || exit;

/**
 * Regole pure del tetto orario delle email (senza WordPress: si provano con i test unitari). Il conteggio e la memoria stanno in {@see MailBudget}.
 *
 * Cosa conta: ogni indirizzo di destinatario di una email spedita dal plugin (un messaggio a tre persone conta tre), qualunque sia l'esito.
 * Cosa non conta: le email di WordPress e degli altri plugin del sito (accessi, WooCommerce, moduli di contatto…).
 * L'ora è l'ora piena del calendario (dalle :00 alle :59), come nei limiti della maggior parte degli hosting.
 */
final class MailBudgetRules {

	const SLOT_SECONDS = 3600;

	/** Plugin che mandano la posta attraverso un servizio esterno (cartella/file del plugin), per riconoscerli senza interrogarli. */
	const EXTERNAL_PLUGINS = array(
		'wp-mail-smtp/wp_mail_smtp.php',
		'wp-mail-smtp-pro/wp_mail_smtp.php',
		'post-smtp/postman-smtp.php',
		'fluent-smtp/fluent-smtp.php',
		'easy-wp-smtp/easy-wp-smtp.php',
		'wp-smtp/wp-smtp.php',
		'smtp-mailer/main.php',
		'mailin/sendinblue.php',
		'woocommerce-sendinblue-newsletter-subscription/sendinblue.php',
		'sendgrid-email-delivery-simplified/wpsendgrid.php',
		'mailgun/mailgun.php',
		'wp-ses/wp-ses.php',
		'amazon-ses-email/ses.php',
		'mailjet-for-wordpress/wp-mailjet.php',
		'postmark-approved-wordpress-plugin/postmark.php',
		'sparkpost/wordpress-sparkpost.php',
		'gmail-smtp/main.php',
		'suremails/suremails.php',
	);

	/** Numero dell'ora piena a cui appartiene un momento. */
	public static function slot( int $time ): int {
		return intdiv( max( 0, $time ), self::SLOT_SECONDS );
	}

	/** L'inizio dell'ora piena successiva. */
	public static function next_slot_at( int $time ): int {
		return ( self::slot( $time ) + 1 ) * self::SLOT_SECONDS;
	}

	/**
	 * Quanto del tetto resta riservato alle email singole (conferme, ricevute, link di accesso…), che non si fermano mai: un quinto, almeno 5.
	 * Le comunicazioni e i promemoria usano il resto, così un invio grande non lascia senza posto la conferma di una prenotazione.
	 */
	public static function reserve( int $cap ): int {
		$cap = max( 1, $cap );
		return min( $cap - 1, max( 5, (int) ceil( $cap / 5 ) ) );
	}

	/** Quante email di comunicazioni e promemoria possono ancora partire in quest'ora. */
	public static function bulk_room( int $cap, int $used ): int {
		return max( 0, $cap - self::reserve( $cap ) - max( 0, $used ) );
	}

	/** Quanti destinatari ha un messaggio: un indirizzo, un elenco separato da virgole, oppure un elenco (anche con «Nome <indirizzo>»). Almeno uno. @param string|array $to */
	public static function recipients( $to ): int {
		$items = is_array( $to ) ? $to : array( $to );
		$n     = 0;
		foreach ( $items as $item ) {
			foreach ( explode( ',', (string) $item ) as $one ) {
				if ( false !== strpos( $one, '@' ) ) {
					++$n;
				}
			}
		}
		return max( 1, $n );
	}

	/**
	 * La posta passa da un servizio esterno (SMTP di un fornitore, servizio con interfaccia web)? Si rileva da: il modo di invio configurato e il suo server
	 * (un SMTP sullo stesso server non conta), un plugin che prende in carico l'invio, uno dei plugin di posta noti attivi.
	 *
	 * @param string[] $active_plugins file dei plugin attivi (cartella/file.php)
	 */
	public static function looks_external( string $mailer, string $host, bool $takes_over_sending, array $active_plugins ): bool {
		$host = strtolower( trim( $host ) );
		if ( 'smtp' === strtolower( $mailer ) && '' !== $host && ! in_array( $host, array( 'localhost', '127.0.0.1', '::1' ), true ) ) {
			return true;
		}
		if ( $takes_over_sending ) {
			return true;
		}
		return (bool) array_intersect( self::EXTERNAL_PLUGINS, $active_plugins );
	}
}
