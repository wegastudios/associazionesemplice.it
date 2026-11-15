<?php
namespace AssociazioneSemplice;

defined( 'ABSPATH' ) || exit;

/**
 * Punto unico da cui passano i testi che il plugin mostra o invia (pagine, email, PDF, messaggi).
 *
 * Nell'edizione gratuita i testi sono quelli originali. Un'estensione può personalizzarli con i filtri `asem_text_plain` e `asem_text_html`.
 */
final class Texts {

	/** La personalizzazione dei testi è disponibile? */
	public static function enabled(): bool {
		return Edition::has( 'texts' );
	}

	/** Testo semplice (email, PDF, messaggi) con le personalizzazioni, se ci sono. */
	public static function plain( string $s, ?array $map = null ): string {
		return self::enabled() ? (string) apply_filters( 'asem_text_plain', $s, $map ) : $s;
	}

	/**
	 * Messaggio con variabili: il modello (frase intera con segnaposto `{nome}`) si traduce PRIMA di inserire i valori, perché i pacchetti
	 * di lingua hanno solo corrispondenze esatte. Si scrive `Texts::t( 'Voce {n}: importo non valido.', array( 'n' => $n ) )` e nel
	 * pacchetto (languages/en.json) la chiave è la frase col segnaposto. Senza traduzione (o in italiano) esce il modello italiano con i valori.
	 *
	 * @param array<string,scalar> $vars nome => valore (sostituiti una sola volta: un valore che contiene `{altro}` non viene riespanso)
	 */
	public static function t( string $template, array $vars = array() ): string {
		$s = self::enabled() ? (string) apply_filters( 'asem_text_template', $template ) : $template;
		if ( ! $vars ) {
			return $s;
		}
		$map = array();
		foreach ( $vars as $k => $v ) {
			$map[ '{' . $k . '}' ] = (string) $v;
		}
		return strtr( $s, $map );
	}

	/** HTML con le personalizzazioni (solo il testo, mai tag, attributi tecnici o indirizzi). */
	public static function html( string $html, ?array $map = null ): string {
		return self::enabled() ? (string) apply_filters( 'asem_text_html', $html, $map ) : $html;
	}

	/** Dopo un salvataggio o un test: rilegge dal database. */
	public static function flush(): void {
		do_action( 'asem_texts_flush' );
	}

	/** Amministrazione: la pagina passa dalla sostituzione dei testi (solo con un'estensione che lo fa). */
	public static function start_admin_buffer(): void {
		if ( self::enabled() ) {
			do_action( 'asem_texts_admin_buffer' );
		}
	}

	/** wp_mail con i testi personalizzati (oggetto e messaggio); l'esito resta nel registro della posta in uscita. */
	public static function mail( $to, string $subject, string $message, $headers = '', $attachments = array() ): bool {
		MailLog::begin();
		$subject = self::plain( $subject );
		$ok      = (bool) wp_mail( $to, $subject, self::plain( $message ), $headers, $attachments );
		MailLog::record( $to, $subject, $ok );
		return $ok;
	}
}
