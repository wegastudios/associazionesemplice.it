<?php
namespace AssociazioneSemplice;

use AssociazioneSemplice\Frontend\Assets;
use AssociazioneSemplice\Frontend\CardVerify;

defined( 'ABSPATH' ) || exit;

/**
 * Conferma del genitore di un minore già in anagrafica.
 *
 * Quando una domanda di adesione di un minore indica come genitore o tutore una persona che esiste già (stessa email), all'approvazione il
 * collegamento si crea ma i consensi (regolamento, informativa privacy, liberatoria immagini) NON vengono attribuiti a lei: serve prima che li
 * confermi. All'indirizzo già registrato per lei arriva un link a tempo, monouso; la pagina del link mostra un pulsante «Confermo» (il solo
 * aprire il link non conferma nulla). Il token è casuale e nel database c'è solo la sua impronta. Finché non conferma, la scheda del minore
 * lo dice alla segreteria, che può rimandare il messaggio.
 *
 * Lo stato in attesa sta nella colonna application_data della persona approvata (che per le persone approvate è altrimenti vuota).
 */
final class GuardianConsent {

	const PARAM           = 'asem_guardian_cf';
	const DAYS            = 7;
	const RESEND_PER_HOUR = 3;

	private static function db(): \wpdb {
		return Db::db();
	}

	/** Un nome da scrivere in un'email o in una pagina: una sola riga, senza caratteri di controllo, non oltre 60 caratteri. */
	private static function line( string $name ): string {
		return trim( mb_substr( trim( (string) preg_replace( '/[\p{C}\s]+/u', ' ', $name ) ), 0, 60, 'UTF-8' ) );
	}

	// ---------- Stato ----------

	/** @return array{guardian:int,hash:string,expires:int,sent:string}|null */
	private static function marker( ?array $person ): ?array {
		if ( ! $person || ! empty( $person['application_status'] ) ) {
			return null;
		}
		$d = json_decode( (string) ( $person['application_data'] ?? '' ), true );
		$m = is_array( $d ) && ! empty( $d['guardian_confirm'] ) && is_array( $d['guardian_confirm'] ) ? $d['guardian_confirm'] : null;
		if ( ! $m || empty( $m['guardian'] ) || empty( $m['hash'] ) ) {
			return null;
		}
		return array( 'guardian' => (int) $m['guardian'], 'hash' => (string) $m['hash'], 'expires' => (int) ( $m['expires'] ?? 0 ), 'sent' => (string) ( $m['sent'] ?? '' ) );
	}

	/**
	 * La conferma del genitore è in attesa per questa persona? (per la scheda della segreteria)
	 *
	 * @return array{guardian:int,sent:string,expired:bool}|null
	 */
	public static function pending( array $person ): ?array {
		$m = self::marker( $person );
		return $m ? array( 'guardian' => $m['guardian'], 'sent' => $m['sent'], 'expired' => $m['expires'] < time() ) : null;
	}

	public static function url( int $person_id, string $token ): string {
		return add_query_arg( self::PARAM, $person_id . '.' . $token, home_url( '/' ) );
	}

	// ---------- Richiesta ----------

	/**
	 * Mette la conferma «in attesa» e scrive al genitore, all'indirizzo già registrato per lui (non a quello eventualmente scritto nella domanda).
	 * Un nuovo invio annulla il link precedente.
	 *
	 * @return bool true se l'email è partita
	 */
	public static function begin( int $minor_id, int $guardian_id ): bool {
		$people   = Plugin::people();
		$minor    = $people->get( $minor_id );
		$guardian = $people->get( $guardian_id );
		if ( ! $minor || ! $guardian ) {
			return false;
		}
		$token  = wp_generate_password( 32, false, false );
		$marker = array(
			'guardian' => $guardian_id,
			'hash'     => hash( 'sha256', $token ),
			'expires'  => time() + self::DAYS * DAY_IN_SECONDS,
			'sent'     => Db::now(),
		);
		self::db()->update( Db::t( 'people' ), array( 'application_data' => wp_json_encode( array( 'guardian_confirm' => $marker ) ), 'updated_at' => Db::now() ), array( 'id' => $minor_id ) );
		Audit::log( 'guardian.consent_requested', 'person', $minor_id, array( 'guardian' => $guardian_id ) );
		$to = (string) $guardian['email'];
		if ( ! is_email( $to ) ) {
			return false;
		}
		$assoc = (string) Settings::get( 'association_name' );
		$what  = ( Regulation::enabled() ? 'il regolamento, ' : '' ) . 'l\'informativa sulla privacy e la scelta sulle immagini';
		$text  = 'Ciao ' . self::line( (string) $guardian['first_name'] ) . ",\n\n"
			. self::line( (string) $minor['first_name'] ) . ' è stato iscritto' . ( '' !== $assoc ? ' a ' . $assoc : '' ) . ' e tu risulti genitore o tutore. '
			. 'Per registrare che sei stato tu ad accettare, a nome suo, ' . $what . ', apri questo link entro ' . self::DAYS . " giorni e tocca «Confermo»:\n\n" . self::url( $minor_id, $token )
			. "\n\nSe non sei tu o non sei d'accordo, ignora questo messaggio e scrivi alla segreteria: finché non confermi, queste accettazioni non vengono attribuite a te.";
		return Texts::mail( $to, 'Conferma del genitore o tutore' . ( '' !== $assoc ? ' — ' . $assoc : '' ), $text );
	}

	/**
	 * La segreteria rimanda il messaggio di conferma (non più di RESEND_PER_HOUR all'ora per persona).
	 *
	 * @return string messaggio per la segreteria
	 * @throws \InvalidArgumentException
	 */
	public static function resend( int $minor_id ): string {
		$minor = Plugin::people()->get( $minor_id );
		$m     = self::marker( $minor );
		if ( ! $m ) {
			throw new \InvalidArgumentException( 'Nessuna conferma del genitore in attesa per questa persona.' );
		}
		$key = 'asem_gcf_r_' . $minor_id;
		$n   = (int) get_transient( $key );
		if ( $n >= self::RESEND_PER_HOUR ) {
			throw new \InvalidArgumentException( 'Hai già rimandato il messaggio più volte: riprova tra un\'ora.' );
		}
		set_transient( $key, $n + 1, HOUR_IN_SECONDS );
		return self::begin( $minor_id, $m['guardian'] ) ? 'Email di conferma rimandata al genitore.' : 'Non è stato possibile mandare l\'email: controlla l\'indirizzo del genitore.';
	}

	// ---------- Conferma ----------

	/** Controlli di sola lettura sul link. @return array{person:array,marker:array}|array{error:string,ok:bool} */
	private static function lookup( string $param ): array {
		if ( ! preg_match( '/^(\d{1,12})\.([A-Za-z0-9]{32})$/', $param, $m ) ) {
			return array( 'error' => 'Il link non è valido.', 'ok' => false );
		}
		$person = Plugin::people()->get( (int) $m[1] );
		$marker = self::marker( $person );
		if ( ! $person || ! $marker || ! hash_equals( $marker['hash'], hash( 'sha256', $m[2] ) ) ) {
			return array( 'error' => 'Il link non è valido o la conferma è già stata data.', 'ok' => false );
		}
		if ( $marker['expires'] < time() ) {
			return array( 'error' => 'Il link è scaduto: scrivi alla segreteria, che ti manda un nuovo messaggio.', 'ok' => false );
		}
		return array( 'person' => $person, 'marker' => $marker );
	}

	/** La pagina del link: non modifica nulla e non consuma il link. */
	public static function confirm_page( string $param ): string {
		$title = 'Conferma del genitore o tutore';
		$r     = self::lookup( $param );
		if ( isset( $r['error'] ) ) {
			return CardVerify::layout( $title, '#b32d2e', '<p>' . esc_html( $r['error'] ) . '</p><p><a href="' . esc_url( home_url( '/' ) ) . '">← Torna al sito</a></p>' );
		}
		$p      = $r['person'];
		$accent = Assets::accent( '#2271b1' );
		$what   = ( Regulation::enabled() ? 'il regolamento, ' : '' ) . 'l\'informativa sulla privacy e la scelta sulle immagini';
		$body   = '<div class="n">' . esc_html( self::line( (string) $p['first_name'] . ' ' . (string) $p['last_name'] ) ) . '</div>'
			. '<p>Confermi di essere il genitore o il tutore e di aver accettato tu, a nome suo, ' . esc_html( $what ) . '?</p>'
			. '<form method="post" action="' . esc_url( add_query_arg( self::PARAM, $param, home_url( '/' ) ) ) . '">'
			. '<input type="hidden" name="asem_gcf_go" value="1">' . wp_nonce_field( 'asem_gcf_' . (int) $p['id'], '_asem_nonce', false, false )
			. '<button type="submit" style="width:100%;padding:14px;font-size:18px;border:0;border-radius:10px;cursor:pointer;background:' . esc_attr( $accent ) . ';color:#fff">Confermo</button></form>';
		return CardVerify::layout( $title, $accent, $body );
	}

	/**
	 * Conferma dal pulsante della pagina: vale solo con il nonce emesso dalla pagina stessa, una volta sola.
	 *
	 * @return array{ok:bool,message:string}
	 */
	public static function submit( string $param, string $nonce ): array {
		if ( ! preg_match( '/^(\d{1,12})\.[A-Za-z0-9]{32}$/', $param, $m ) || ! wp_verify_nonce( $nonce, 'asem_gcf_' . (int) $m[1] ) ) {
			return array( 'ok' => false, 'message' => 'La pagina è scaduta: riapri il link che hai ricevuto per email.' );
		}
		$r = self::lookup( $param );
		if ( isset( $r['error'] ) ) {
			return array( 'ok' => $r['ok'], 'message' => $r['error'] );
		}
		$p  = $r['person'];
		$by = (int) $r['marker']['guardian'];
		if ( ! in_array( $by, array_map( 'intval', array_column( Guardians::of( (int) $p['id'] ), 'id' ) ), true ) ) {
			return array( 'ok' => false, 'message' => 'Il collegamento con il minore non esiste più: scrivi alla segreteria.' );
		}
		$upd = array( 'privacy_consent_by' => $by, 'image_consent_by' => $by, 'application_data' => null, 'updated_at' => Db::now() );
		if ( Regulation::enabled() ) {
			$upd['rules_accepted_by'] = $by;
		}
		// Una sola volta: l'aggiornamento riesce solo se la richiesta in attesa è ancora quella letta.
		$won = self::db()->update( Db::t( 'people' ), $upd, array( 'id' => (int) $p['id'], 'application_data' => (string) $p['application_data'] ) );
		if ( 1 !== (int) $won ) {
			return array( 'ok' => false, 'message' => 'Il link non è valido o la conferma è già stata data.' );
		}
		Audit::log( 'guardian.consent_confirmed', 'person', (int) $p['id'], array( 'guardian' => $by ) );
		return array( 'ok' => true, 'message' => 'Grazie, confermato.' );
	}

	// ---------- Pagina pubblica ----------

	private static function stop( string $text, string $color = '#2271b1' ): void {
		CardVerify::send_headers();
		CardVerify::output( CardVerify::layout( 'Conferma del genitore o tutore', $color, '<p>' . esc_html( $text ) . '</p><p><a href="' . esc_url( home_url( '/' ) ) . '">← Torna al sito</a></p>' ) );
		exit;
	}

	public static function handle(): void {
		if ( ! isset( $_GET[ self::PARAM ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			return;
		}
		$param = sanitize_text_field( wp_unslash( $_GET[ self::PARAM ] ) ); // phpcs:ignore WordPress.Security.NonceVerification
		$post  = 'POST' === ( isset( $_SERVER['REQUEST_METHOD'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) : '' ) && isset( $_POST['asem_gcf_go'] ); // phpcs:ignore WordPress.Security.NonceVerification -- il nonce si controlla in submit
		if ( ! $post ) { // aprire il link (anche in un'anteprima dell'email) mostra soltanto la richiesta: non conferma nulla
			CardVerify::send_headers();
			CardVerify::output( self::confirm_page( $param ) );
			exit;
		}
		$key = 'asem_gcf_ip_' . md5( ClientIp::get() );
		$n   = (int) get_transient( $key );
		if ( $n >= Limits::get( 'apply_per_ip' ) * 2 ) {
			self::stop( 'Troppi tentativi: riprova tra qualche minuto.', '#b32d2e' );
		}
		set_transient( $key, $n + 1, HOUR_IN_SECONDS );
		$r = self::submit( $param, sanitize_text_field( wp_unslash( $_POST['_asem_nonce'] ?? '' ) ) ); // phpcs:ignore WordPress.Security.NonceVerification -- verificato in submit
		self::stop( $r['message'], $r['ok'] ? '#1a7f37' : '#b32d2e' );
	}
}
