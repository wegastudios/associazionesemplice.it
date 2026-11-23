<?php
namespace AssociazioneSemplice\Frontend;

use AssociazioneSemplice\Applications;
use AssociazioneSemplice\ClientIp;
use AssociazioneSemplice\Flash;
use AssociazioneSemplice\Limits;
use AssociazioneSemplice\Money;
use AssociazioneSemplice\Pages;
use AssociazioneSemplice\Plugin;
use AssociazioneSemplice\PrivacyNotice;
use AssociazioneSemplice\Regulation;
use AssociazioneSemplice\ResponseMail;
use AssociazioneSemplice\Settings;
use AssociazioneSemplice\Texts;

defined( 'ABSPATH' ) || exit;

/**
 * Modulo pubblico della domanda di adesione (shortcode `[associazionesemplice_adesione]`, blocco e widget) e link per pagare la quota online.
 *
 * Protezioni: limite di domande per connessione, campo esca invisibile, tempo minimo di compilazione e un gettone firmato al posto del nonce
 * (la pagina può stare in una cache di pagina). La risposta è sempre la stessa, qualunque cosa succeda ai dati: non si può scoprire chi è già socio.
 */
final class Application {

	const MIN_SECONDS = 3;
	const TOKEN_DAYS  = 7;
	const MESSAGE     = 'Ultimo passo: controlla la posta. Ti è arrivata un\'email con un link: aprilo e premi il pulsante, così la domanda arriva alla segreteria. Il link vale 48 ore. Se non la trovi, guarda nella posta indesiderata; se non arriva, riprova tra un\'ora.';
	const LINK_BAD    = 'Il link non vale più: è scaduto o è già stato usato. Se vuoi, compila di nuovo il modulo.';

	// ---------- Gettone del modulo ----------

	public static function make_token( int $now ): string {
		return $now . '.' . substr( hash_hmac( 'sha256', 'apply-form|' . $now, wp_salt( 'auth' ) ), 0, 20 );
	}

	/** @return string ok | early (compilato troppo in fretta: è un programma) | invalid (firma sbagliata o scaduto) */
	public static function check_token( string $token, int $now ): string {
		if ( ! preg_match( '/^(\d{9,11})\.([a-f0-9]{20})$/', $token, $m ) || ! hash_equals( substr( hash_hmac( 'sha256', 'apply-form|' . $m[1], wp_salt( 'auth' ) ), 0, 20 ), $m[2] ) ) {
			return 'invalid';
		}
		$age = $now - (int) $m[1];
		if ( $age > self::TOKEN_DAYS * DAY_IN_SECONDS ) {
			return 'invalid';
		}
		return $age < self::MIN_SECONDS ? 'early' : 'ok';
	}

	// ---------- Modulo ----------

	private static function field( string $label, string $name, string $type, array $post, string $auto = '', bool $required = false, string $extra = '' ): string {
		return '<label>' . esc_html( $label ) . ( $required ? ' *' : '' ) . ' <input type="' . esc_attr( $type ) . '" name="' . esc_attr( $name ) . '" value="' . esc_attr( (string) ( $post[ $name ] ?? '' ) ) . '"'
			. ( $required ? ' required' : '' ) . ( '' !== $auto ? ' autocomplete="' . esc_attr( $auto ) . '"' : '' ) . $extra . '></label>';
	}

	/** Il modulo (o l'esito, dopo l'invio). */
	public static function form_html(): string {
		Assets::enqueue();
		if ( ! Applications::enabled() ) {
			return Texts::html( '<div class="asemf asemf-apply"><div class="asemf-notice">Le domande di adesione online non sono attive. Per iscriverti rivolgiti alla segreteria.</div></div>' );
		}
		$msg    = Flash::read( 'asemf' ); // ritorno dal pagamento della quota
		$errors = array();
		$sent   = false;
		$post   = array();
		if ( 'POST' === ( isset( $_SERVER['REQUEST_METHOD'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) : '' ) && isset( $_POST['asem_apply_go'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification -- gettone firmato verificato qui sotto (la pagina può essere in cache)
			$post = Plugin::post_data(); // phpcs:ignore WordPress.Security.NonceVerification
			$key  = 'asem_ap_' . md5( ClientIp::get() );
			$n    = (int) get_transient( $key );
			if ( ! empty( $post['website'] ) ) {
				$sent = true; // il campo esca è compilato: stessa risposta, nessuna domanda
			} elseif ( $n >= Limits::get( 'apply_per_ip' ) ) {
				$errors[] = 'Troppi tentativi: riprova tra un\'ora.';
			} else {
				$token = self::check_token( (string) ( $post['t'] ?? '' ), time() );
				if ( 'invalid' === $token ) {
					$errors[] = 'La pagina è rimasta aperta troppo a lungo: ricaricala e riprova.';
				} else {
					set_transient( $key, $n + 1, HOUR_IN_SECONDS );
					if ( 'early' === $token ) {
						$sent = true;
					} else {
						ResponseMail::defer( true ); // le email partono a risposta consegnata: il tempo di risposta non dice se l'indirizzo è già registrato
						$res = Applications::submit( $post );
						if ( 'invalid' === $res['status'] ) {
							$errors = $res['errors'];
						} else {
							$sent = true;
						}
					}
				}
			}
		}
		$inner = '';
		if ( '' !== $msg['err'] ) {
			$inner .= '<div class="asemf-notice asemf-err" role="alert">' . esc_html( $msg['err'] ) . '</div>';
		} elseif ( '' !== $msg['ok'] ) {
			$inner .= '<div class="asemf-notice asemf-ok" role="status">' . esc_html( $msg['ok'] ) . '</div>';
		}
		if ( $sent ) {
			$inner .= '<div class="asemf-notice asemf-ok" role="status">' . esc_html( self::MESSAGE ) . '</div>';
			return Texts::html( '<div class="asemf asemf-apply">' . $inner . '</div>' );
		}
		if ( $errors ) {
			$inner .= '<div class="asemf-notice asemf-err" role="alert"><ul style="margin:0;padding-left:1.2em">';
			foreach ( $errors as $e ) {
				$inner .= '<li>' . esc_html( $e ) . '</li>';
			}
			$inner .= '</ul></div>';
		}
		return Texts::html( '<div class="asemf asemf-apply">' . $inner . self::form( $post ) . '</div>' );
	}


	/** Frase sulla quota, uguale per tutti (non dipende da chi scrive). */
	private static function fee_note(): string {
		$fee = Applications::fee_cents( \AssociazioneSemplice\MemberType::ORDINARY );
		if ( $fee <= 0 ) {
			return '';
		}
		return Applications::online_payment()
			? ' Dopo la conferma della domanda ti arriva un\'email con il link per pagare subito la quota online; se la domanda non è accettata, riavrai la quota.'
			: ' La quota associativa si paga in sede, alla segreteria.';
	}

	private static function form( array $post ): string {
		$types = Applications::types();
		$fee   = Applications::fee_cents( \AssociazioneSemplice\MemberType::ORDINARY );
		$html  = '<form class="asemf-form" method="post" action="' . esc_url( Restrict::current_url() ) . '">'
			. '<input type="hidden" name="asem_apply_go" value="1">' . '<input type="hidden" name="t" value="' . esc_attr( self::make_token( time() ) ) . '">'
			. '<div style="position:absolute;left:-9999px;top:auto;width:1px;height:1px;overflow:hidden" aria-hidden="true"><label>Non compilare questo campo <input type="text" name="website" value="" tabindex="-1" autocomplete="off"></label></div>'
			. '<p class="asemf-small asemf-muted">I campi con * sono obbligatori. Email e cellulare servono se hai almeno 18 anni.</p>'
			. '<div class="asemf-fields">'
			. self::field( 'Nome', 'first_name', 'text', $post, 'given-name', true ) . self::field( 'Cognome', 'last_name', 'text', $post, 'family-name', true )
			. self::field( 'Data di nascita', 'birth_date', 'date', $post, 'bday', true, ' data-asemf-birth max="' . esc_attr( gmdate( 'Y-m-d' ) ) . '"' )
			. self::field( 'Luogo di nascita', 'birth_place', 'text', $post, 'off', false, ' maxlength="120"' )
			. self::field( 'Codice fiscale', 'tax_code', 'text', $post, 'off', true, ' maxlength="16"' )
			. self::field( 'Indirizzo di residenza', 'address', 'text', $post, 'street-address', false, ' maxlength="190"' )
			. self::field( 'CAP', 'zip', 'text', $post, 'postal-code', false, ' maxlength="12"' ) . self::field( 'Comune', 'city', 'text', $post, 'address-level2', false, ' maxlength="100"' ) . self::field( 'Provincia', 'province', 'text', $post, 'address-level1', false, ' maxlength="5"' )
			. self::field( 'Email *', 'email', 'email', $post, 'email', false, ' data-asemf-adult-required' ) . self::field( 'Cellulare *', 'phone', 'tel', $post, 'tel', false, ' inputmode="tel" data-asemf-adult-required' )
			. '</div>';
		if ( count( $types ) > 1 ) {
			$sel = (string) ( $post['type'] ?? '' );
			$html .= '<p><label>Tipo di socio *  <select name="type" required>';
			foreach ( $types as $k => $label ) {
				$html .= '<option value="' . esc_attr( $k ) . '"' . selected( $sel, $k, false ) . '>' . esc_html( $label ) . '</option>';
			}
			$html .= '</select></label></p>';
		} else {
			$html .= '<input type="hidden" name="type" value="' . esc_attr( (string) key( $types ) ) . '">';
		}
		$html .= '<fieldset class="asemf-guardian" data-asemf-guardian><legend>Per chi ha meno di 18 anni: genitore o tutore</legend>'
			. '<p class="asemf-small asemf-muted">La domanda di un minorenne la presenta un genitore o tutore: qui vanno i suoi dati. Per i maggiorenni questa parte resta vuota.</p><div class="asemf-fields">'
			. self::field( 'Nome del genitore', 'guardian_first_name', 'text', $post, 'off' ) . self::field( 'Cognome del genitore', 'guardian_last_name', 'text', $post, 'off' )
			. self::field( 'Email del genitore', 'guardian_email', 'email', $post, 'off' ) . self::field( 'Cellulare del genitore', 'guardian_phone', 'tel', $post, 'off', false, ' inputmode="tel"' )
			. self::field( 'Codice fiscale del genitore (facoltativo)', 'guardian_tax_code', 'text', $post, 'off', false, ' maxlength="16"' )
			. '</div><label class="asemf-check"><input type="checkbox" name="guardian_ok" value="1"' . checked( ! empty( $post['guardian_ok'] ), true, false ) . '> Dichiaro di essere il genitore o il tutore e accetto regolamento e informativa a nome del minore.</label></fieldset>';
		if ( Regulation::enabled() ) {
			$title = mb_strtolower( Regulation::title(), 'UTF-8' );
			$html .= '<div class="asemf-rules-box"><strong>' . esc_html( Regulation::title() ) . '</strong>' . Regulation::box_html()
				. '<label class="asemf-check"><input type="checkbox" name="rules_ok" value="1"' . checked( ! empty( $post['rules_ok'] ), true, false ) . '> Ho letto e accetto il ' . esc_html( $title ) . '.</label></div>';
		}
		$url   = (string) Settings::get( 'privacy_url' );
		$html .= '<div class="asemf-rules-box">' . ( '' === $url ? '<div style="max-height:200px;overflow:auto;border:1px solid #c3c4c7;border-radius:8px;padding:10px 12px;margin:8px 0;background:#fff">' . PrivacyNotice::html() . '</div>' : '' )
			. '<label class="asemf-check"><input type="checkbox" name="privacy_ok" value="1"' . checked( ! empty( $post['privacy_ok'] ), true, false ) . '> Ho letto ' . ( '' !== $url ? '<a href="' . esc_url( $url ) . '" target="_blank" rel="noopener">l\'informativa sulla privacy</a>' : 'l\'informativa sulla privacy' ) . ' e acconsento al trattamento dei dati.</label></div>';
		if ( '' !== \AssociazioneSemplice\Contacts::rights_line() ) {
			$html .= '<p class="asemf-small asemf-muted">' . esc_html( \AssociazioneSemplice\Contacts::rights_line() ) . '</p>';
		}
		$html .= '<div class="asemf-rules-box"><label class="asemf-check"><input type="checkbox" name="image_ok" value="1"' . checked( ! empty( $post['image_ok'] ), true, false ) . '> Acconsento alla pubblicazione di foto e video dell\'attività (facoltativo). Per un minore lo dichiara il genitore o tutore, che ne risponde anche verso l\'altro genitore.</label></div>';
		if ( $fee > 0 ) {
			$html .= '<p class="asemf-small asemf-muted">Quota associativa: ' . esc_html( Money::format( $fee ) ) . '.' . esc_html( self::fee_note() ) . '</p>';
		}
		return $html . '<button type="submit" class="asemf-btn wp-element-button">Invia la domanda</button></form>';
	}

	// ---------- Pagamento della quota ----------

	/** Indirizzo della pagina con il modulo (a cui si torna dopo il pagamento). */
	private static function back_url(): string {
		$id = (int) ( Pages::existing()['adesione'] ?? 0 );
		return $id ? (string) get_permalink( $id ) : home_url( '/' );
	}

	private static function stop( string $text ): void {
		CardVerify::send_headers();
		CardVerify::output( CardVerify::layout( 'Pagamento della quota', Assets::accent( '#2271b1' ), '<p>' . esc_html( $text ) . '</p><p><a href="' . esc_url( home_url( '/' ) ) . '">← Torna al sito</a></p>' ) );
		exit;
	}

	/** Pagina semplice con un titolo, un testo e, se serve, un pulsante (modulo con il solo indirizzo di conferma). */
	private static function confirm_page( string $title, string $text, string $button_url = '', bool $again = false ): void {
		CardVerify::send_headers();
		$body = '<p>' . esc_html( $text ) . '</p>';
		if ( '' !== $button_url ) {
			$body .= '<form method="post" action="' . esc_url( $button_url ) . '"><p><button type="submit" style="padding:.7em 1.4em;font-size:1em;cursor:pointer">Conferma la domanda</button></p></form>';
		} elseif ( $again ) {
			$body .= '<p><a href="' . esc_url( self::back_url() ) . '">Compila il modulo</a></p>';
		} else {
			$body .= '<p><a href="' . esc_url( home_url( '/' ) ) . '">← Torna al sito</a></p>';
		}
		CardVerify::output( CardVerify::layout( $title, Assets::accent( '#2271b1' ), $body ) );
		exit;
	}

	/**
	 * Dal link ricevuto per email: una pagina con un pulsante. La conferma vera è l'invio del modulo (POST), così i controlli automatici della posta
	 * che aprono i link non la consumano. Il link vale una volta sola e per 48 ore.
	 */
	public static function handle_confirm(): void {
		if ( ! isset( $_GET['asem_apply_confirm'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			return;
		}
		$param = sanitize_text_field( wp_unslash( $_GET['asem_apply_confirm'] ) ); // phpcs:ignore WordPress.Security.NonceVerification
		$key   = 'asem_ap_cf_' . md5( ClientIp::get() );
		$n     = (int) get_transient( $key );
		if ( $n >= Limits::get( 'apply_per_ip' ) * 2 ) {
			self::confirm_page( 'Domanda di adesione', 'Troppi tentativi: riprova tra un\'ora.' );
		}
		set_transient( $key, $n + 1, HOUR_IN_SECONDS );
		$posted = 'POST' === ( isset( $_SERVER['REQUEST_METHOD'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) : '' );
		if ( $posted && ! \AssociazioneSemplice\Plugin::schema_ready() ) { // aggiornamento dello schema a metà: la conferma aspetta, il link resta valido
			self::confirm_page( 'Domanda di adesione', \AssociazioneSemplice\Texts::t( \AssociazioneSemplice\SchemaGuard::MESSAGE ), add_query_arg( 'asem_apply_confirm', $param, home_url( '/' ) ) );
		}
		if ( $posted ) {
			if ( Applications::confirm( $param ) ) {
				self::confirm_page( 'Domanda confermata.', 'La segreteria la esamina e ti risponde per email.' );
			}
			self::confirm_page( 'Domanda di adesione', self::LINK_BAD, '', true );
		}
		if ( 'ok' !== Applications::resolve_confirm( $param )['status'] ) {
			self::confirm_page( 'Domanda di adesione', self::LINK_BAD, '', true );
		}
		self::confirm_page( 'Conferma la domanda', 'Premi il pulsante per mandare la domanda alla segreteria.', add_query_arg( 'asem_apply_confirm', $param, home_url( '/' ) ) );
	}

	/** Dal link ricevuto per email porta alla pagina di pagamento del gateway. */
	public static function handle_pay(): void {
		if ( ! isset( $_GET['asem_apply_pay'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			return;
		}
		$param = sanitize_text_field( wp_unslash( $_GET['asem_apply_pay'] ) ); // phpcs:ignore WordPress.Security.NonceVerification
		$key   = 'asem_ap_pay_' . md5( ClientIp::get() );
		$n     = (int) get_transient( $key );
		if ( $n >= Limits::get( 'apply_per_ip' ) * 2 ) {
			self::stop( 'Troppi tentativi: riprova tra un\'ora.' );
		}
		set_transient( $key, $n + 1, HOUR_IN_SECONDS );
		$r = Applications::resolve_pay( $param );
		if ( 'ok' !== $r['status'] ) {
			self::stop( 'expired' === $r['status'] ? 'Il link è scaduto: scrivi alla segreteria.' : ( 'closed' === $r['status'] ? 'Questa domanda è già stata esaminata.' : 'Il link non è valido: riaprilo dall\'email o scrivi alla segreteria.' ) );
		}
		$p   = $r['person'];
		$fee = Applications::fee_cents( (string) $p['type'] );
		if ( ! Applications::online_payment() || $fee <= 0 ) {
			self::stop( 'Il pagamento online non è attivo: la quota si paga in sede, alla segreteria.' );
		}
		if ( Plugin::payments()->application_paid( (int) $p['id'] ) ) {
			self::stop( 'Hai già pagato la quota, grazie.' );
		}
		try {
			$url = Plugin::payments()->application_checkout( $p, $fee, self::back_url() );
		} catch ( \InvalidArgumentException $e ) {
			self::stop( $e->getMessage() );
		}
		wp_redirect( $url ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- pagina di pagamento del gateway (indirizzo esterno)
		exit;
	}
}
