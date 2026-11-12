<?php
namespace AssociazioneSemplice;

use AssociazioneSemplice\Frontend\Application;
use AssociazioneSemplice\Frontend\Assets;
use AssociazioneSemplice\Frontend\CardVerify;
use AssociazioneSemplice\Frontend\Restrict;

defined( 'ABSPATH' ) || exit;

/**
 * Prenotazione dalla pagina pubblica dell'evento per chi non è socio («ospiti»).
 *
 * Percorso: modulo con tutti i dati → email con un collegamento da aprire entro 24 ore (conferma dell'indirizzo) → se l'evento lo prevede la
 * segreteria approva, altrimenti la prenotazione è automatica → l'email dice come pagare: bonifico istantaneo e ricevuta alla segreteria entro
 * il termine (ore impostabili nei Limiti), altrimenti il posto si libera. Chi non è socio non ha un pannello: le disdette le gestisce la segreteria.
 *
 * Minori: prenota sempre un adulto (il tutore), che dichiara di rispondere dei minori indicati come accompagnati; un minorenne da solo non prenota.
 * Gli ospiti che hanno già raggiunto il limite di partecipazioni devono iscriversi, salvo che l'evento costi di più agli ospiti che ai soci.
 * La risposta del modulo è sempre la stessa: non si scopre chi è già socio. Le richieste non confermate o non approvate si cancellano dopo 30 giorni.
 */
final class EventRequests {

	const HOOK      = 'asem_event_requests';
	const PARAM     = 'asem_evreq';
	const KEEP_DAYS = 30;
	const CONFIRM_H = 24;
	const MAX_SEATS = 10;
	const MESSAGE   = 'Richiesta ricevuta. Ti abbiamo scritto un\'email: apri il collegamento che contiene entro 24 ore per confermare l\'indirizzo (controlla anche la posta indesiderata).';

	/** Esito dell'invio già elaborato nella richiesta in corso (il testo della pagina può passare più volte dal filtro). */
	private static $handled = null;

	private static function db(): \wpdb {
		return Db::db();
	}

	public static function register(): void {
		add_action( 'template_redirect', array( __CLASS__, 'handle_confirm' ), 1 );
	}

	// ---------- Regole ----------

	/** Dalla pagina di questo evento i non soci possono chiedere un posto? */
	public static function available( array $activity ): bool {
		if ( ! ActivityKind::uses_sessions( (string) $activity['kind'] ) || ! Settings::guests_enabled() || ! Edition::allows( 'member_area' ) ) {
			return false;
		}
		return PublicEvents::options( (int) $activity['id'] )['guests'];
	}

	private static function pay_hours(): int {
		return max( 1, Limits::get( 'event_pay_hours' ) );
	}

	/** Contributo di un ospite (centesimi). */
	private static function guest_fee( array $a ): int {
		return Plugin::activities()->fee_for( $a, MemberType::GUEST );
	}

	/** Dopo il limite di partecipazioni, un ospite deve iscriversi se per lui il contributo è uguale a quello dei soci. */
	private static function must_join( array $a ): bool {
		return self::guest_fee( $a ) <= Plugin::activities()->fee_for( $a, MemberType::ORDINARY );
	}

	private static function free_seats( array $s ): ?int {
		if ( null === $s['capacity'] ) {
			return null;
		}
		$taken = (int) self::db()->get_var( self::db()->prepare( 'SELECT COUNT(*) FROM ' . Db::t( 'bookings' ) . " WHERE session_id = %d AND status = 'booked'", (int) $s['id'] ) );
		return max( 0, (int) $s['capacity'] - $taken );
	}

	// ---------- Modulo ----------

	private static function field( string $label, string $name, string $type, array $post, string $auto = '', bool $required = false, string $extra = '' ): string {
		return '<label>' . esc_html( $label ) . ( $required ? ' *' : '' ) . ' <input type="' . esc_attr( $type ) . '" name="' . esc_attr( $name ) . '" value="' . esc_attr( (string) ( $post[ $name ] ?? '' ) ) . '"'
			. ( $required ? ' required' : '' ) . ( '' !== $auto ? ' autocomplete="' . esc_attr( $auto ) . '"' : '' ) . $extra . '></label>';
	}

	/** Riquadro «Non sei socio?» con il modulo (o l'esito, dopo l'invio). @param array[] $sessions date prenotabili */
	public static function form_html( array $activity, array $sessions ): string {
		Assets::enqueue();
		$opt  = PublicEvents::options( (int) $activity['id'] );
		$post = array();
		$errors = array();
		$sent   = false;
		if ( 'POST' === ( isset( $_SERVER['REQUEST_METHOD'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) : '' ) && isset( $_POST['asem_evreq_go'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification -- gettone firmato verificato in handle_submit (la pagina può essere in cache)
			$post = Plugin::post_data(); // phpcs:ignore WordPress.Security.NonceVerification
			if ( null === self::$handled ) {
				self::$handled = self::handle_submit( $post, $activity, $opt );
			}
			$errors = self::$handled['errors'];
			$sent   = self::$handled['sent'];
		}
		if ( $sent ) {
			return '<div class="asemf-notice asemf-ok" role="status">' . esc_html( self::MESSAGE ) . '</div>';
		}
		$html = '';
		if ( $errors ) {
			$html .= '<div class="asemf-notice asemf-err" role="alert"><ul style="margin:0;padding-left:1.2em">';
			foreach ( $errors as $e ) {
				$html .= '<li>' . esc_html( $e ) . '</li>';
			}
			$html .= '</ul></div>';
		}
		$fee  = self::guest_fee( $activity );
		$cap  = 0 === $opt['max_seats'] ? self::MAX_SEATS : min( self::MAX_SEATS, $opt['max_seats'] );
		$rows = '';
		$sel  = (int) ( $post['session_id'] ?? 0 );
		foreach ( $sessions as $s ) {
			$free = self::free_seats( $s );
			if ( null !== $free && $free < 1 ) {
				continue;
			}
			$id    = (int) $s['id'];
			$rows .= '<label class="asemf-check"><input type="radio" name="session_id" value="' . $id . '"' . checked( $sel === $id || ( 0 === $sel && '' === $rows ), true, false ) . '> '
				. esc_html( Frontend\Views::date_long( (string) $s['session_date'] ) . ( $s['start_time'] ? ' · ore ' . $s['start_time'] : '' ) . ( null !== $free ? ' · ' . $free . ' posti liberi' : '' ) ) . '</label>';
		}
		if ( '' === $rows ) {
			return $html . '<p class="asemf-small asemf-muted">I posti per le date in programma sono esauriti.</p>';
		}
		$form = '<form class="asemf-form" method="post" action="' . esc_url( Restrict::current_url() ) . '">'
			. '<input type="hidden" name="asem_evreq_go" value="1"><input type="hidden" name="t" value="' . esc_attr( Application::make_token( time() ) ) . '">'
			. '<div style="position:absolute;left:-9999px;top:auto;width:1px;height:1px;overflow:hidden" aria-hidden="true"><label>Non compilare questo campo <input type="text" name="website" value="" tabindex="-1" autocomplete="off"></label></div>'
			. '<fieldset><legend>Scegli la data</legend>' . $rows . '</fieldset>'
			. '<p class="asemf-small">I tuoi dati (chi prenota è una persona maggiorenne):</p><div class="asemf-fields">'
			. self::field( 'Nome', 'first_name', 'text', $post, 'given-name', true ) . self::field( 'Cognome', 'last_name', 'text', $post, 'family-name', true )
			. self::field( 'Data di nascita', 'birth_date', 'date', $post, 'bday', true, ' max="' . esc_attr( gmdate( 'Y-m-d' ) ) . '"' ) . self::field( 'Luogo di nascita', 'birth_place', 'text', $post, 'off', false, ' maxlength="120"' )
			. self::field( 'Codice fiscale', 'tax_code', 'text', $post, 'off', true, ' maxlength="16"' ) . self::field( 'Indirizzo di residenza', 'address', 'text', $post, 'street-address', false, ' maxlength="190"' )
			. self::field( 'CAP', 'zip', 'text', $post, 'postal-code', false, ' maxlength="12"' ) . self::field( 'Comune', 'city', 'text', $post, 'address-level2', false, ' maxlength="100"' ) . self::field( 'Provincia', 'province', 'text', $post, 'address-level1', false, ' maxlength="5"' )
			. self::field( 'Email', 'email', 'email', $post, 'email', true ) . self::field( 'Cellulare', 'phone', 'tel', $post, 'tel', true, ' inputmode="tel"' )
			. '</div>';
		$ui = min( $cap, 6 ); // nel modulo al massimo 5 accompagnatori: oltre si passa dalla segreteria
		if ( $ui > 1 ) {
			$form .= '<details class="asemf-more"><summary>Prenoti anche per altre persone?</summary>';
			$form .= '<p class="asemf-small">Scrivi (fino a ' . (int) ( $ui - 1 ) . ' persone) i loro dati: per ognuna serve un posto. Se sono minorenni, ci pensi tu come genitore o tutore.</p>';
			for ( $i = 1; $i < $ui; $i++ ) {
				$c      = isset( $post['c'][ $i ] ) && is_array( $post['c'][ $i ] ) ? $post['c'][ $i ] : array();
				$form  .= '<details' . ( ! empty( $c['first_name'] ) ? ' open' : '' ) . '><summary>Accompagnatore ' . (int) $i . '</summary><div class="asemf-fields">';
				foreach ( array( 'first_name' => array( 'Nome', 'text' ), 'last_name' => array( 'Cognome', 'text' ), 'birth_date' => array( 'Data di nascita', 'date' ), 'tax_code' => array( 'Codice fiscale', 'text' ) ) as $k => $def ) {
					$form .= '<label>' . esc_html( $def[0] ) . ' <input type="' . esc_attr( $def[1] ) . '" name="c[' . (int) $i . '][' . esc_attr( $k ) . ']" value="' . esc_attr( (string) ( $c[ $k ] ?? '' ) ) . '"' . ( 'tax_code' === $k ? ' maxlength="16"' : '' ) . ' autocomplete="off"></label>';
				}
				$form .= '</div></details>';
			}
			$form .= '<label class="asemf-check"><input type="checkbox" name="guardian_ok" value="1"' . checked( ! empty( $post['guardian_ok'] ), true, false ) . '> Se tra le persone indicate ci sono minorenni, dichiaro di essere genitore o tutore e di rispondere di loro.</label>'; $form .= '</details>';
		}
		$url   = (string) Settings::get( 'privacy_url' );
		$form .= '<div class="asemf-rules-box">' . ( '' === $url ? '<div style="max-height:200px;overflow:auto;border:1px solid #c3c4c7;border-radius:8px;padding:10px 12px;margin:8px 0;background:#fff">' . PrivacyNotice::html() . '</div>' : '' )
			. '<label class="asemf-check"><input type="checkbox" name="privacy_ok" value="1"' . checked( ! empty( $post['privacy_ok'] ), true, false ) . '> Ho letto ' . ( '' !== $url ? '<a href="' . esc_url( $url ) . '" target="_blank" rel="noopener">l\'informativa sulla privacy</a>' : 'l\'informativa sulla privacy' ) . ' e acconsento al trattamento dei dati.</label></div>';
		$form .= '<p class="asemf-small asemf-muted">' . ( $fee > 0 ? 'Contributo: ' . esc_html( Money::format( $fee ) ) . ' a persona, da pagare in anticipo con bonifico istantaneo (te lo spieghiamo nell\'email) e da inviare in ricevuta alla segreteria entro ' . (int) self::pay_hours() . ' ore dalla conferma; altrimenti il posto viene cancellato. ' : 'Partecipazione gratuita. ' )
			. ( $opt['approval'] ? 'La partecipazione è confermata dalla segreteria dopo la tua richiesta.' : 'La prenotazione è automatica dopo la conferma dell\'email.' ) . ' Per disdire scrivi alla segreteria.</p>'
			. '<button type="submit" class="asemf-btn wp-element-button">' . ( $opt['approval'] ? 'Invia la richiesta di partecipazione' : 'Prenota' ) . '</button></form>';
		return $html . '<section class="asemf-guest-request"><h3 class="asemf-event-h">Non sei socio?</h3><details class="asemf-guest-details"' . ( $errors || $post ? ' open' : '' ) . '><summary class="asemf-btn wp-element-button">Prenota senza iscriverti</summary><p class="asemf-small">Puoi partecipare anche senza essere socio: compila i dati qui sotto.</p>' . $form . '</details></section>';
	}

	// ---------- Invio ----------

	/** @return array{errors:string[],sent:bool} */
	private static function handle_submit( array $post, array $activity, array $opt ): array {
		$out = array( 'errors' => array(), 'sent' => false );
		if ( ! empty( $post['website'] ) ) {
			$out['sent'] = true; // campo esca compilato: stessa risposta, nessuna richiesta
			return $out;
		}
		$key = 'asem_evq_ip_' . md5( ClientIp::get() );
		$n   = (int) get_transient( $key );
		if ( $n >= Limits::get( 'apply_per_ip' ) ) {
			$out['errors'][] = 'Troppi tentativi: riprova tra qualche minuto.';
			return $out;
		}
		$token = Application::check_token( (string) ( $post['t'] ?? '' ), time() );
		if ( 'invalid' === $token ) {
			$out['errors'][] = 'Sessione scaduta: ricarica la pagina e riprova.';
			return $out;
		}
		set_transient( $key, $n + 1, HOUR_IN_SECONDS );
		if ( 'early' === $token ) {
			$out['sent'] = true;
			return $out;
		}
		$res = self::create( $post, $activity, $opt );
		if ( 'invalid' === $res['status'] ) {
			$out['errors'] = $res['errors'];
			return $out;
		}
		$out['sent'] = true;
		return $out;
	}

	/**
	 * Controlla i dati e registra la richiesta (in attesa della conferma dell'email).
	 *
	 * @return array{status:string,errors:string[]} status: invalid | ok (anche quando la richiesta non viene registrata: stessa risposta per tutti)
	 */
	public static function create( array $in, array $activity, array $opt ): array {
		$ok  = array( 'status' => 'ok', 'errors' => array() );
		$sid = (int) ( $in['session_id'] ?? 0 );
		$s   = $sid ? Plugin::activities()->session( $sid ) : null;
		if ( ! $s || (int) $s['activity_id'] !== (int) $activity['id'] || $s['cancelled_at'] || (string) $s['session_date'] < Db::today() ) {
			return array( 'status' => 'invalid', 'errors' => array( 'Scegli una delle date indicate.' ) );
		}
		$today = Db::today();
		$chk   = ApplicationRules::check( array_merge( $in, array( 'type' => MemberType::ORDINARY ) ), $today, false, array( MemberType::ORDINARY ) );
		if ( $chk['minor'] ) {
			return array( 'status' => 'invalid', 'errors' => array( 'Chi prenota deve essere maggiorenne: per un minorenne prenota un genitore o tutore, oppure rivolgiti alla segreteria.' ) );
		}
		$errors = $chk['errors'];
		$people = array( $chk['data'] );
		$cap    = 0 === $opt['max_seats'] ? self::MAX_SEATS : min( self::MAX_SEATS, $opt['max_seats'] );
		$minor  = false;
		$comp   = isset( $in['c'] ) && is_array( $in['c'] ) ? $in['c'] : array();
		for ( $i = 1; $i < $cap; $i++ ) {
			$c = isset( $comp[ $i ] ) && is_array( $comp[ $i ] ) ? $comp[ $i ] : array();
			if ( '' === trim( (string) ( $c['first_name'] ?? '' ) ) && '' === trim( (string) ( $c['last_name'] ?? '' ) ) ) {
				continue;
			}
			$birth = Age::parse( trim( (string) ( $c['birth_date'] ?? '' ) ) );
			$tax   = TaxCode::normalize( trim( (string) ( $c['tax_code'] ?? '' ) ) );
			$fn    = mb_substr( trim( (string) ( $c['first_name'] ?? '' ) ), 0, 120, 'UTF-8' );
			$ln    = mb_substr( trim( (string) ( $c['last_name'] ?? '' ) ), 0, 120, 'UTF-8' );
			if ( '' === $fn || '' === $ln || null === $birth || $birth > $today || ! TaxCode::is_valid( $tax ) ) {
				$errors[] = 'Accompagnatore ' . $i . ': servono nome, cognome, data di nascita e codice fiscale validi.';
				continue;
			}
			if ( Age::is_minor( $birth, $today ) ) {
				$minor = true;
			}
			$people[] = array( 'first_name' => $fn, 'last_name' => $ln, 'birth_date' => $birth, 'tax_code' => $tax, 'email' => '', 'phone' => '', 'birth_place' => '', 'address' => '', 'zip' => '', 'city' => '', 'province' => '' );
		}
		if ( $minor && empty( $in['guardian_ok'] ) ) {
			$errors[] = 'Spunta la casella con cui dichiari di essere genitore o tutore dei minorenni indicati.';
		}
		$taxes = array();
		foreach ( $people as $p ) {
			$taxes[] = $p['tax_code'];
		}
		if ( count( $taxes ) !== count( array_unique( $taxes ) ) ) {
			$errors[] = 'Ogni persona ha un codice fiscale diverso: controlla i dati.';
		}
		if ( $errors ) {
			return array( 'status' => 'invalid', 'errors' => array_values( array_unique( $errors ) ) );
		}
		// Da qui in poi l'esito per chi scrive è sempre lo stesso.
		$email = $people[0]['email'];
		$ekey  = 'asem_evq_em_' . md5( $email );
		if ( (int) get_transient( $ekey ) >= 3 ) {
			return $ok; // un solo flusso di email per indirizzo (impedisce di riempire una casella o di inviare due volte per un doppio clic)
		}
		set_transient( $ekey, (int) get_transient( $ekey ) + 1, HOUR_IN_SECONDS );
		$open = (int) self::db()->get_var( 'SELECT COUNT(*) FROM ' . Db::t( 'event_requests' ) . " WHERE status IN ('new','approval')" );
		if ( $open >= Limits::get( 'applications_max' ) ) {
			return $ok;
		}
		$token = wp_generate_password( 32, false, false );
		$now   = Db::now();
		$done  = self::db()->insert(
			Db::t( 'event_requests' ),
			array(
				'activity_id' => (int) $activity['id'],
				'session_id'  => $sid,
				'seats'       => count( $people ),
				'status'      => 'new',
				'token_hash'  => hash( 'sha256', $token ),
				'email'       => $email,
				'data'        => wp_json_encode( array( 'people' => $people, 'image_ok' => ! empty( $in['image_ok'] ) ) ),
				'created_at'  => $now,
				'updated_at'  => $now,
			)
		);
		if ( ! $done ) {
			return $ok;
		}
		$id = (int) self::db()->insert_id;
		Texts::mail(
			$email,
			'Conferma la tua richiesta: ' . $activity['name'] . self::assoc_suffix(),
			'Ciao ' . $people[0]['first_name'] . ",\n\nhai chiesto " . count( $people ) . ( 1 === count( $people ) ? ' posto' : ' posti' ) . ' per «' . $activity['name'] . '» del ' . $s['session_date'] . ".\n\n"
			. 'Per confermare che l\'indirizzo è tuo apri questo collegamento entro ' . self::CONFIRM_H . " ore:\n" . self::confirm_url( $id, $token ) . "\n\n"
			. 'Se non sei stato tu, ignora questo messaggio: la richiesta non avrà seguito.'
		);
		Audit::log( 'event_request.received', 'activity', (int) $activity['id'], array( 'seats' => count( $people ) ) ); // niente dati personali nel registro
		return $ok;
	}

	private static function assoc_suffix(): string {
		$assoc = (string) Settings::get( 'association_name' );
		return '' !== $assoc ? ' — ' . $assoc : '';
	}

	public static function confirm_url( int $id, string $token ): string {
		return add_query_arg( self::PARAM, $id . '.' . $token, home_url( '/' ) );
	}

	// ---------- Conferma dell'email ----------

	private static function stop( string $title, string $text, string $color = '#2271b1' ): void {
		CardVerify::send_headers();
		CardVerify::output( CardVerify::layout( $title, $color, '<p>' . esc_html( $text ) . '</p><p><a href="' . esc_url( home_url( '/' ) ) . '">← Torna al sito</a></p>' ) );
		exit;
	}

	public static function handle_confirm(): void {
		if ( ! isset( $_GET[ self::PARAM ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			return;
		}
		$param = sanitize_text_field( wp_unslash( $_GET[ self::PARAM ] ) ); // phpcs:ignore WordPress.Security.NonceVerification
		$key   = 'asem_evq_cf_' . md5( ClientIp::get() );
		$n     = (int) get_transient( $key );
		if ( $n >= Limits::get( 'apply_per_ip' ) * 2 ) {
			self::stop( 'Conferma della richiesta', 'Troppi tentativi: riprova tra qualche minuto.', '#b32d2e' );
		}
		set_transient( $key, $n + 1, HOUR_IN_SECONDS );
		$r = self::confirm( $param );
		self::stop( 'Conferma della richiesta', $r['message'], $r['ok'] ? '#1a7f37' : '#b32d2e' );
	}

	/** @return array{ok:bool,message:string} */
	public static function confirm( string $param ): array {
		if ( ! preg_match( '/^(\d{1,12})\.([A-Za-z0-9]{32})$/', $param, $m ) ) {
			return array( 'ok' => false, 'message' => 'Il collegamento non è valido.' );
		}
		$row = self::row( (int) $m[1] );
		if ( ! $row || ! hash_equals( (string) $row['token_hash'], hash( 'sha256', $m[2] ) ) ) {
			return array( 'ok' => false, 'message' => 'Il collegamento non è valido o la richiesta è stata cancellata.' );
		}
		if ( 'new' !== $row['status'] ) {
			return array( 'ok' => true, 'message' => 'Hai già confermato questa richiesta: ti abbiamo scritto un\'email con le informazioni.' );
		}
		if ( ( new \DateTimeImmutable( (string) $row['created_at'], wp_timezone() ) )->getTimestamp() < time() - self::CONFIRM_H * HOUR_IN_SECONDS ) {
			return array( 'ok' => false, 'message' => 'Il collegamento è scaduto: invia di nuovo la richiesta dalla pagina dell\'evento.' );
		}
		$a = Plugin::activities()->get( (int) $row['activity_id'] );
		if ( ! $a ) {
			return array( 'ok' => false, 'message' => 'L\'evento non esiste più.' );
		}
		if ( PublicEvents::options( (int) $a['id'] )['approval'] ) {
			self::set( (int) $row['id'], array( 'status' => 'approval' ) );
			Texts::mail(
				Applications::notify_email(),
				'Richiesta di partecipazione da approvare: ' . $a['name'] . self::assoc_suffix(),
				'È arrivata una richiesta di partecipazione a «' . $a['name'] . '» (' . (int) $row['seats'] . ' posti).' . "\n\nApprovala o rifiutala dalla scheda dell'attività: " . admin_url( 'admin.php?page=asem-activity&id=' . (int) $a['id'] )
			);
			return array( 'ok' => true, 'message' => 'Email confermata. La segreteria esamina la richiesta e ti scrive appena è approvata.' );
		}
		return self::book_request( (int) $row['id'] );
	}

	// ---------- Prenotazione ----------

	private static function row( int $id ): ?array {
		$r = self::db()->get_row( self::db()->prepare( 'SELECT * FROM ' . Db::t( 'event_requests' ) . ' WHERE id = %d', $id ), ARRAY_A );
		return $r ?: null;
	}

	private static function set( int $id, array $fields ): void {
		self::db()->update( Db::t( 'event_requests' ), array_merge( $fields, array( 'updated_at' => Db::now() ) ), array( 'id' => $id ) );
	}

	/** Chiude la richiesta con un rifiuto e lo scrive a chi l'ha fatta. */
	private static function fail( array $row, string $status, string $mail_text, ?string $subject = null ): array {
		self::set( (int) $row['id'], array( 'status' => $status, 'data' => null ) );
		$to = self::recipient( $row );
		if ( '' !== $to ) {
			Texts::mail( $to, $subject ?? 'Richiesta non accolta' . self::assoc_suffix(), $mail_text );
		}
		return array( 'ok' => false, 'message' => $mail_text );
	}

	/** A chi scrivere: l'email della richiesta (non soci) o quella del socio. */
	private static function recipient( array $row ): string {
		if ( is_email( (string) $row['email'] ) ) {
			return (string) $row['email'];
		}
		$p = ! empty( $row['person_id'] ) ? Plugin::people()->get( (int) $row['person_id'] ) : null;
		return $p && is_email( (string) $p['email'] ) ? (string) $p['email'] : '';
	}

	// ---------- Richieste dei soci (eventi con approvazione) ----------

	/** C'è già una richiesta in attesa di questa persona per questa data? */
	public static function pending_for( int $session_id, int $person_id ): bool {
		return (bool) self::db()->get_var( self::db()->prepare( 'SELECT COUNT(*) FROM ' . Db::t( 'event_requests' ) . " WHERE session_id = %d AND person_id = %d AND status = 'approval'", $session_id, $person_id ) );
	}

	/** Un socio (o un suo ospite o minore) chiede di partecipare: la segreteria le esamina nell'ordine di arrivo. @throws \InvalidArgumentException */
	public static function member_request( int $session_id, int $person_id ): void {
		$s = Plugin::activities()->session( $session_id );
		$p = Plugin::people()->get( $person_id );
		if ( ! $s || ! $p || $s['cancelled_at'] || (string) $s['session_date'] < Db::today() ) {
			throw new \InvalidArgumentException( 'Evento non disponibile.' );
		}
		if ( ! PublicEvents::options( (int) $s['activity_id'] )['approval'] ) {
			throw new \InvalidArgumentException( 'Questo evento si prenota direttamente.' );
		}
		if ( Plugin::activities()->has_active_booking( $session_id, $person_id ) || self::pending_for( $session_id, $person_id ) ) {
			throw new \InvalidArgumentException( 'C\'è già una prenotazione o una richiesta per questa data.' );
		}
		$now = Db::now();
		self::db()->insert(
			Db::t( 'event_requests' ),
			array( 'activity_id' => (int) $s['activity_id'], 'session_id' => $session_id, 'person_id' => $person_id, 'seats' => 1, 'status' => 'approval', 'token_hash' => hash( 'sha256', wp_generate_password( 32, false, false ) ), 'created_at' => $now, 'updated_at' => $now )
		);
		Audit::log( 'event_request.member', 'activity', (int) $s['activity_id'], array( 'person_id' => $person_id ) );
		$a = Plugin::activities()->get( (int) $s['activity_id'] );
		Texts::mail( Applications::notify_email(), 'Richiesta di partecipazione da approvare' . ( $a ? ': ' . $a['name'] : '' ) . self::assoc_suffix(), "È arrivata una richiesta di partecipazione di un socio.\n\nEsaminala dalla scheda dell'attività: " . admin_url( 'admin.php?page=asem-activity&id=' . (int) $s['activity_id'] ) );
	}

	/** Prenota un socio la cui richiesta è stata approvata (contributo e blocco posti come per ogni prenotazione). */
	private static function book_member( array $row ): array {
		$svc = Plugin::activities();
		$a   = $svc->get( (int) $row['activity_id'] );
		$s   = $svc->session( (int) $row['session_id'] );
		if ( ! $a || ! $s || $s['cancelled_at'] || (string) $s['session_date'] < Db::today() ) {
			return self::fail( $row, 'rejected', 'La data richiesta non è più disponibile. Scusaci: controlla il calendario degli eventi sul sito.' );
		}
		try {
			$svc->book( (int) $s['id'], (int) $row['person_id'] );
		} catch ( \InvalidArgumentException $e ) {
			return array( 'ok' => false, 'message' => 'Non è stato possibile prenotare: ' . $e->getMessage() ); // la richiesta resta in attesa
		}
		self::set( (int) $row['id'], array( 'status' => 'booked', 'data' => null ) );
		$to = self::recipient( $row );
		if ( '' !== $to ) {
			Texts::mail( $to, 'Richiesta accolta: ' . $a['name'] . self::assoc_suffix(), 'La tua richiesta di partecipazione a «' . $a['name'] . '» del ' . $s['session_date'] . ' è stata accolta: il posto è prenotato. Lo trovi nella tua area, tra le prenotazioni.' );
		}
		return array( 'ok' => true, 'message' => 'Richiesta accolta: il socio è prenotato.' );
	}

	/**
	 * Crea gli ospiti e le prenotazioni di una richiesta confermata (automatica, o approvata dalla segreteria) e scrive come pagare.
	 *
	 * @return array{ok:bool,message:string}
	 */
	public static function book_request( int $id ): array {
		$row = self::row( $id );
		if ( ! $row || ! in_array( $row['status'], array( 'new', 'approval' ), true ) ) {
			return array( 'ok' => false, 'message' => 'Richiesta non trovata o già lavorata.' );
		}
		if ( ! empty( $row['person_id'] ) ) {
			return self::book_member( $row );
		}
		$data = json_decode( (string) $row['data'], true );
		$list = is_array( $data ) && ! empty( $data['people'] ) ? $data['people'] : array();
		$svc  = Plugin::activities();
		$a    = $svc->get( (int) $row['activity_id'] );
		$s    = $svc->session( (int) $row['session_id'] );
		if ( ! $list || ! $a || ! $s || $s['cancelled_at'] || (string) $s['session_date'] < Db::today() ) {
			return self::fail( $row, 'rejected', 'La data richiesta non è più disponibile. Scusaci: controlla il calendario degli eventi sul sito.' );
		}
		$free = self::free_seats( $s );
		if ( null !== $free && $free < count( $list ) ) {
			return self::fail( $row, 'expired', 'I posti per questa data sono esauriti (o non bastano per tutte le persone indicate). Scusaci: puoi scrivere alla segreteria o scegliere un\'altra data.' );
		}
		// Chi è già socio prenota dal proprio accesso; chi ha raggiunto il limite di partecipazioni da ospite deve iscriversi (salvo contributo maggiore per gli ospiti).
		$existing = array();
		$counts   = array();
		foreach ( $list as $i => $p ) {
			$found = self::db()->get_row( self::db()->prepare( 'SELECT * FROM ' . Db::t( 'people' ) . ' WHERE deleted_at IS NULL AND tax_code = %s LIMIT 1', $p['tax_code'] ), ARRAY_A );
			if ( $found && MemberType::GUEST !== $found['type'] ) {
				return self::fail( $row, 'rejected', "Tra le persone indicate c'è chi risulta già socio: i soci prenotano dal proprio accesso al sito (area soci). Per qualsiasi dubbio scrivi alla segreteria." );
			}
			$existing[ $i ] = $found ?: null;
			if ( $found ) {
				$counts[ $i ] = $svc->participation_counts( array( (int) $found['id'] ) )[ (int) $found['id'] ] ?? 0;
			}
		}
		$limit = $svc->guest_limit();
		if ( $limit > 0 && self::must_join( $a ) ) {
			foreach ( $counts as $n ) {
				if ( $n >= $limit ) {
					$page = (int) ( Pages::existing()['adesione'] ?? 0 );
					return self::fail( $row, 'rejected', 'Hai già partecipato ' . $limit . ' volte come ospite: per continuare serve iscriversi come soci.' . ( $page ? ' Puoi farlo qui: ' . get_permalink( $page ) : ' Rivolgiti alla segreteria.' ) );
				}
			}
		}
		$booked = array();
		$fee    = 0;
		try {
			foreach ( $list as $i => $p ) {
				$pid = $existing[ $i ] ? (int) $existing[ $i ]['id'] : self::new_guest( $p, ! empty( $data['image_ok'] ), 0 === $i ? (string) $row['email'] : '' );
				$svc->book( (int) $s['id'], $pid );
				$booked[] = array( 'person_id' => $pid, 'session_id' => (int) $s['id'] );
				$fee     += self::guest_fee( $a );
			}
		} catch ( \InvalidArgumentException $e ) {
			foreach ( $booked as $b ) {
				$svc->cancel_booking( (int) $b['session_id'], (int) $b['person_id'] );
			}
			return self::fail( $row, 'expired', 'Non è stato possibile completare la prenotazione (' . $e->getMessage() . '). Scusaci: scrivi alla segreteria.' );
		}
		$deadline = $fee > 0 ? wp_date( 'Y-m-d H:i:s', time() + self::pay_hours() * HOUR_IN_SECONDS ) : null;
		self::set( $id, array( 'status' => 'booked', 'deadline' => $deadline, 'data' => wp_json_encode( array( 'bookings' => $booked ) ) ) );
		Audit::log( 'event_request.booked', 'activity', (int) $a['id'], array( 'seats' => count( $booked ) ) );
		$text = 'Ciao ' . $list[0]['first_name'] . ",\n\nil tuo posto per «" . $a['name'] . '» del ' . $s['session_date'] . ( $s['start_time'] ? ' alle ' . $s['start_time'] : '' ) . ' (' . count( $booked ) . ( 1 === count( $booked ) ? ' persona' : ' persone' ) . ") è prenotato.\n\n";
		if ( $fee > 0 ) {
			$text .= 'Per tenerlo, paga ' . Money::format( $fee ) . ' con bonifico istantaneo entro ' . self::pay_hours() . ' ore e invia la ricevuta alla segreteria (' . Applications::notify_email() . "), indicando il nome dell'evento e il tuo cognome. Se la ricevuta non arriva entro il termine il posto viene cancellato.\n\n"
				. ( Bank::enabled() ? Bank::text_block() . "\n\n" : '' );
		}
		$text .= 'Per disdire scrivi alla segreteria.';
		Texts::mail( $row['email'], 'Prenotazione: ' . $a['name'] . self::assoc_suffix(), $text );
		return array( 'ok' => true, 'message' => $fee > 0 ? 'Prenotazione registrata. Ti abbiamo scritto come pagare con bonifico istantaneo: invia la ricevuta alla segreteria entro ' . self::pay_hours() . ' ore, altrimenti il posto viene cancellato.' : 'Prenotazione confermata: ti abbiamo scritto un\'email di riepilogo.' );
	}

	/** Registra un ospite senza socio ospitante (la prenotazione online non lo richiede). */
	private static function new_guest( array $p, bool $image_ok, string $email ): int {
		$db = self::db();
		if ( '' !== $email && Plugin::people()->find_by_email( $email ) ) {
			$email = ''; // già usata da un'altra persona: resta solo nella richiesta
		}
		$now = Db::now();
		$ok  = $db->insert(
			Db::t( 'people' ),
			array(
				'type'             => MemberType::GUEST,
				'first_name'       => $p['first_name'],
				'last_name'        => $p['last_name'],
				'email'            => '' === $email ? null : $email,
				'phone'            => '' === ( $p['phone'] ?? '' ) ? null : $p['phone'],
				'tax_code'         => $p['tax_code'],
				'birth_date'       => $p['birth_date'],
				'birth_place'      => '' === ( $p['birth_place'] ?? '' ) ? null : $p['birth_place'],
				'address'          => '' === ( $p['address'] ?? '' ) ? null : $p['address'],
				'zip'              => '' === ( $p['zip'] ?? '' ) ? null : $p['zip'],
				'city'             => '' === ( $p['city'] ?? '' ) ? null : $p['city'],
				'province'         => '' === ( $p['province'] ?? '' ) ? null : $p['province'],
				'image_consent'    => $image_ok ? 1 : 0,
				'image_consent_at' => $now,
				'joined_on'        => Db::today(),
				'created_at'       => $now,
				'updated_at'       => $now,
			)
		);
		if ( ! $ok ) {
			throw new \InvalidArgumentException( 'registrazione non riuscita' );
		}
		$id = (int) $db->insert_id;
		Privacy::set_consent( $id, 'web' );
		Audit::log( 'person.created', 'person', $id, array( 'type' => MemberType::GUEST, 'via' => 'event' ) );
		return $id;
	}

	// ---------- Segreteria ----------

	/** Richieste in attesa di approvazione di un'attività. @return array[] */
	public static function awaiting( int $activity_id ): array {
		$rows = self::db()->get_results( self::db()->prepare( 'SELECT * FROM ' . Db::t( 'event_requests' ) . " WHERE activity_id = %d AND status = 'approval' ORDER BY id", $activity_id ), ARRAY_A ) ?: array();
		foreach ( $rows as &$r ) {
			$d          = json_decode( (string) $r['data'], true );
			$r['names'] = array();
			if ( ! empty( $r['person_id'] ) ) {
				$mp           = Plugin::people()->get( (int) $r['person_id'] );
				$r['names'][] = $mp ? trim( $mp['first_name'] . ' ' . $mp['last_name'] ) . ' (socio)' : 'Socio';
			}
			foreach ( is_array( $d ) && ! empty( $d['people'] ) ? $d['people'] : array() as $p ) {
				$r['names'][] = trim( $p['first_name'] . ' ' . $p['last_name'] );
			}
		}
		unset( $r );
		return $rows;
	}

	public static function approve( int $id ): string {
		$r   = self::book_request( $id );
		$now = self::row( $id );
		if ( ! $r['ok'] && $now && 'approval' === $now['status'] ) {
			throw new \InvalidArgumentException( $r['message'] ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- messaggio interno, mostrato solo dopo esc_html
		}
		return $r['message'];
	}

	public static function reject( int $id ): string {
		$row = self::row( $id );
		if ( ! $row || 'approval' !== $row['status'] ) {
			throw new \InvalidArgumentException( 'Richiesta non trovata o già lavorata.' );
		}
		self::fail( $row, 'rejected', "Purtroppo la tua richiesta di partecipazione non è stata accolta. Per informazioni scrivi alla segreteria." );
		return 'Richiesta rifiutata: chi l\'ha fatta è stato avvisato.';
	}

	// ---------- Lavoro periodico ----------

	/** Libera i posti di chi non ha pagato entro il termine e cancella le richieste vecchie (30 giorni). */
	public static function run(): void {
		$db  = self::db();
		$t   = Db::t( 'event_requests' );
		$svc = Plugin::activities();
		foreach ( $db->get_results( $db->prepare( "SELECT * FROM $t WHERE status = 'booked' AND deadline IS NOT NULL AND deadline <= %s", Db::now() ), ARRAY_A ) ?: array() as $r ) {
			$d    = json_decode( (string) $r['data'], true );
			$list = is_array( $d ) && ! empty( $d['bookings'] ) ? $d['bookings'] : array();
			$paid = false;
			foreach ( $list as $b ) {
				foreach ( $svc->bookings_for_session( (int) $b['session_id'] ) as $x ) {
					if ( (int) $x['person_id'] === (int) $b['person_id'] && (int) $x['paid'] > 0 ) {
						$paid = true;
					}
				}
			}
			if ( $paid ) {
				self::set( (int) $r['id'], array( 'deadline' => null ) ); // pagato: il posto resta
				continue;
			}
			foreach ( $list as $b ) {
				$svc->cancel_booking( (int) $b['session_id'], (int) $b['person_id'] ); // libera il posto e fa entrare il primo della lista d'attesa
			}
			self::set( (int) $r['id'], array( 'status' => 'expired', 'deadline' => null ) );
			$a = $svc->get( (int) $r['activity_id'] );
			Audit::log( 'event_request.released', 'activity', (int) $r['activity_id'], array( 'seats' => count( $list ) ) );
			if ( is_email( (string) $r['email'] ) ) {
				Texts::mail( $r['email'], 'Posto liberato' . ( $a ? ': ' . $a['name'] : '' ) . self::assoc_suffix(), "Il pagamento non è arrivato entro il termine, quindi il posto è stato cancellato e liberato.\n\nSe vuoi ancora partecipare, prenota di nuovo dalla pagina dell'evento (se ci sono posti) oppure scrivi alla segreteria." );
			}
		}
		$db->query( $db->prepare( "DELETE FROM $t WHERE updated_at < %s", wp_date( 'Y-m-d H:i:s', time() - self::KEEP_DAYS * DAY_IN_SECONDS ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- tabella interna, valori preparati
	}

	/** Riquadro per la scheda dell'attività: richieste da approvare. Già escapato; vuoto se non ce ne sono. */
	public static function admin_box( int $activity_id, string $back ): string {
		$rows = self::awaiting( $activity_id );
		if ( ! $rows ) {
			return '';
		}
		$h = '<div class="asem-card"><h2>Richieste di partecipazione</h2><p class="description">Persone che hanno chiesto un posto dalla pagina pubblica e confermato l\'email. Approvando si registrano come ospiti e si prenotano.</p><ul>';
		foreach ( $rows as $r ) {
			$h .= '<li><strong>' . esc_html( implode( ', ', $r['names'] ) ) . '</strong> <span class="description">(' . (int) $r['seats'] . ' posti, ' . esc_html( mysql2date( 'd/m H:i', $r['created_at'] ) ) . ')</span> '
				. self::admin_form( 'asem_event_request_approve', (int) $r['id'], $back, 'Approva' ) . ' ' . self::admin_form( 'asem_event_request_reject', (int) $r['id'], $back, 'Rifiuta' ) . '</li>';
		}
		return $h . '</ul></div>';
	}

	private static function admin_form( string $action, int $id, string $back, string $label ): string {
		return '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline"><input type="hidden" name="action" value="' . esc_attr( $action ) . '"><input type="hidden" name="_back" value="' . esc_url( $back ) . '">'
			. wp_nonce_field( $action, '_wpnonce', true, false ) . '<input type="hidden" name="id" value="' . (int) $id . '"><button class="button button-small">' . esc_html( $label ) . '</button></form>';
	}
}
