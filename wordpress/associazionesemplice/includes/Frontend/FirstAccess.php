<?php
namespace AssociazioneSemplice\Frontend;

use AssociazioneSemplice\AccessRequests;
use AssociazioneSemplice\Audit;
use AssociazioneSemplice\Edition;
use AssociazioneSemplice\Limits;
use AssociazioneSemplice\MemberType;
use AssociazioneSemplice\Phone;
use AssociazioneSemplice\Plugin;
use AssociazioneSemplice\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * "Primo accesso": il socio scrive nome, email e cellulare e riceve il link per scegliere la password.
 * Risponde sempre allo stesso modo, così non si può scoprire chi è socio.
 */
final class FirstAccess {

	const MAX_PER_HOUR = 10;
	const MESSAGE_OWN  = 'Richiesta ricevuta. Se sei un socio ti abbiamo scritto all\'email indicata: apri il link per entrare (controlla anche la posta indesiderata). Altrimenti ti contatta la segreteria su WhatsApp.';
	const MESSAGE      = 'Richiesta ricevuta. Se sei un socio ti abbiamo scritto all\'email indicata: apri il messaggio e scegli la tua password (controlla anche la posta indesiderata). Altrimenti ti contatta la segreteria su WhatsApp.';

	public static function register(): void {
		add_action( 'template_redirect', array( __CLASS__, 'handle' ), 1 );
		add_filter( 'login_message', array( __CLASS__, 'login_message' ) );
	}

	public static function url(): string {
		return add_query_arg( 'asem_first_access', '1', home_url( '/' ) );
	}

	/** Avviso sopra il modulo di accesso di WordPress. */
	public static function login_message( $message ) {
		if ( \AssociazioneSemplice\MemberSession::enabled() && ! \AssociazioneSemplice\MemberSession::wp_users_wanted() ) { // i soci non sono utenti del sito: questa pagina è per chi lo gestisce
			return (string) $message . '<p class="message">Questa pagina è per la segreteria e per chi gestisce il sito. Sei un socio? <a href="' . esc_url( \AssociazioneSemplice\Frontend\MemberLoginPage::url() ) . '"><strong>Entra nell\'area soci</strong></a></p>';
		}
		return (string) $message . '<p class="message">Sei un socio e non hai ancora una password? <a href="' . esc_url( self::url() ) . '"><strong>Primo accesso</strong></a></p>';
	}

	/**
	 * Manda il link per scegliere la password se l'email è di un socio con accesso. @return bool true se è stata inviata una email
	 */
	public static function request( string $email ): bool {
		if ( ! Edition::allows( 'member_area' ) ) {
			return false;
		}
		$email = trim( $email );
		$user  = '' !== $email && is_email( $email ) ? get_user_by( 'email', $email ) : false;
		$employee = $user && \AssociazioneSemplice\Employees::is_employee( $user ); // l'utente del dipendente non è mai quello del socio: i due accessi restano separati
		if ( ! $user || $employee ) {
			if ( ! $user && \AssociazioneSemplice\MemberSession::wp_users_wanted() ) {
				self::decoy( $email ); // nessun utente e nessun accesso proprio: stesso lavoro di una richiesta vera
			}
			return ( ! \AssociazioneSemplice\MemberSession::wp_users_wanted() || ( $employee && \AssociazioneSemplice\MemberSession::enabled() ) ) && \AssociazioneSemplice\MemberLogin::request( $email ); // senza utente WordPress: link e codice per l'accesso proprio
		}
		// Non più di qualche email all'ora allo stesso indirizzo: nessuno può inondare di messaggi la casella di un socio (la risposta del sito resta uguale).
		$mail_key = 'asem_fa_m_' . md5( strtolower( $user->user_email ) );
		$sent     = (int) get_transient( $mail_key );
		if ( $sent >= Limits::get( 'first_access_per_email' ) ) {
			self::decoy( $email );
			return false;
		}
		$person = \AssociazioneSemplice\Access::person_for_user( (int) $user->ID );
		if ( ! $person || ! MemberType::can_login( $person['type'] ) || user_can( $user, Plugin::CAP_OPS ) ) {
			self::decoy( $email );
			return false; // solo i soci (mai gli amministratori)
		}
		$key = get_password_reset_key( $user );
		if ( is_wp_error( $key ) ) {
			return false;
		}
		// Chi si attiva dal sito completa indirizzo e codice fiscale dal suo profilo (finché mancano non prenota né paga online).
		if ( Limits::flag( 'profile_required' ) && ! Plugin::people()->profile_complete( $person ) ) {
			Plugin::people()->set_profile_due( (int) $person['id'], true );
		}
		$assoc = (string) Settings::get( 'association_name' );
		$link  = network_site_url( 'wp-login.php?action=rp&key=' . $key . '&login=' . rawurlencode( $user->user_login ), 'login' );
		$text  = 'Ciao ' . $person['first_name'] . ",\n\necco il link per il tuo primo accesso" . ( '' !== $assoc ? ' a ' . $assoc : '' ) . ":\n\n" . $link
			. "\n\nApri il link, scegli la tua password e poi entra con questo indirizzo email. Il link vale 24 ore: se scade, ripeti il «Primo accesso» dal sito.\n\nSe non hai chiesto tu questo messaggio, ignoralo.";
		$subject = \AssociazioneSemplice\Texts::subject( 'Primo accesso' );
		if ( \AssociazioneSemplice\ResponseMail::deferring() ) { // dalla pagina pubblica: la richiesta si conta subito e l'email parte a risposta consegnata
			set_transient( $mail_key, $sent + 1, HOUR_IN_SECONDS );
			return \AssociazioneSemplice\ResponseMail::send( $user->user_email, $subject, $text );
		}
		$ok = (bool) \AssociazioneSemplice\Texts::mail( $user->user_email, $subject, $text );
		if ( $ok ) { // il limite conta i messaggi partiti davvero: se la posta del sito non funziona il socio può riprovare senza restare bloccato
			set_transient( $mail_key, $sent + 1, HOUR_IN_SECONDS );
		}
		return $ok;
	}

	/**
	 * Lavoro di una richiesta per un indirizzo senza utente (o già al limite, o non ammesso): la stessa chiave di reimpostazione e la stessa
	 * scrittura di una richiesta vera, senza lasciare traccia né spedire niente. Così il tempo di risposta non dipende dall'esito (non è uno sleep).
	 */
	private static function decoy( string $email ): void {
		global $wpdb;
		get_transient( 'asem_fa_m_' . md5( strtolower( trim( $email ) ) ) );
		wp_hash_password( wp_generate_password( 24, false ) ); // l'impronta della chiave di reimpostazione
		$wpdb->update( $wpdb->users, array( 'user_activation_key' => '' ), array( 'ID' => 0 ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- al posto della scrittura vera: nessuna riga corrisponde
	}

	/**
	 * Riconosce il socio dall'email o dal cellulare:
	 *  - email già del socio: gli arriva il link;
	 *  - solo il cellulare è di un socio (con o senza accesso): il cellulare da solo non basta a dimostrare chi è, quindi non si crea né si collega nessun accesso da soli: la richiesta va alla segreteria, che verifica e approva;
	 *  - nessuno dei due: la richiesta va alla segreteria.
	 *
	 * @return string emailed | change_queued | unknown_queued | invalid | none
	 */
	public static function submit( string $name, string $email, string $phone ): string {
		if ( ! Edition::allows( 'member_area' ) ) {
			return 'none';
		}
		$name  = trim( $name );
		$email = trim( $email );
		$phone = trim( $phone );
		if ( '' === $name || ! is_email( $email ) || ! Phone::is_valid( $phone ) ) {
			return 'invalid';
		}
		$people = Plugin::people();
		$queue  = function ( string $kind, int $person_id = 0 ) use ( $name, $email, $phone ) {
			AccessRequests::add( array( 'kind' => $kind, 'person_id' => $person_id, 'name' => $name, 'email' => $email, 'phone' => $phone ) );
		};

		// 1. L'email è già di un socio: caso sicuro, il link va a quell'indirizzo.
		$by_mail = $people->find_by_email( strtolower( $email ) );
		if ( $by_mail && MemberType::can_login( $by_mail['type'] ) && empty( $by_mail['application_status'] ) ) { // chi ha solo chiesto di iscriversi non è ancora socio
			if ( empty( $by_mail['wp_user_id'] ) && \AssociazioneSemplice\MemberSession::wp_users_wanted() ) {
				$people->update( (int) $by_mail['id'], array() ); // crea l'utente che mancava (con l'accesso proprio non serve: il link va all'email)
			}
			return self::request( $email ) ? 'emailed' : 'none';
		}

		// 2. Il cellulare è di un solo socio.
		$members = array();
		foreach ( $people->find_by_phone( $phone ) as $p ) {
			if ( MemberType::is_member( $p['type'] ) ) {
				$members[] = $p;
			}
		}
		if ( 1 === count( $members ) ) {
			$queue( 'change', (int) $members[0]['id'] ); // nessun accesso automatico: lo approva la segreteria dopo aver verificato la persona
			return 'change_queued';
		}

		// 3. Non riconosciuto. Se l'iscrizione è solo su presentazione la richiesta non si registra (la risposta resta la stessa).
		if ( 'invite' !== Settings::get( 'join_mode' ) ) {
			$queue( 'unknown' );
		}
		return 'unknown_queued';
	}

	/** Approva il cambio email chiesto da un socio con accesso già attivo e gli manda il link. */
	public static function approve_change( string $request_id ): void {
		$r = AccessRequests::get( $request_id );
		if ( ! $r || 'change' !== $r['kind'] || ! $r['person_id'] ) {
			throw new \InvalidArgumentException( 'Richiesta non trovata.' );
		}
		$before = Plugin::people()->get( (int) $r['person_id'] );
		Plugin::people()->update( (int) $r['person_id'], array( 'email' => strtolower( $r['email'] ) ) );
		Audit::log( 'person.email_changed_by_request', 'person', (int) $r['person_id'] );
		AccessRequests::remove( $request_id );
		self::request( $r['email'] );
		// L'indirizzo di prima viene avvisato: se la richiesta non era del socio, se ne accorge e scrive alla segreteria.
		$old = $before && ! empty( $before['email'] ) ? strtolower( (string) $before['email'] ) : '';
		if ( '' !== $old && is_email( $old ) && $old !== strtolower( $r['email'] ) ) {
			$assoc = (string) Settings::get( 'association_name' );
			\AssociazioneSemplice\Texts::mail(
				$old,
				\AssociazioneSemplice\Texts::subject( 'Email di accesso cambiata' ),
				'Ciao ' . $before['first_name'] . ",\n\nla segreteria ha cambiato, su tua richiesta, l'email del tuo accesso" . ( '' !== $assoc ? ' a ' . $assoc : '' ) . ": con questo indirizzo non potrai più entrare.\n\nSe non l'hai chiesto tu, scrivi subito alla segreteria."
			);
		}
	}

	public static function page( string $message = '', array $post = array(), string $error = '' ): string {
		$accent = Assets::accent( '#2271b1' ); // colore scelto, altrimenti quello del sito (Elementor o tema)
		$input  = 'width:100%;box-sizing:border-box;padding:10px;font-size:16px;margin:4px 0 12px;border:1px solid #8c8f94;border-radius:8px';
		$field  = function ( string $label, string $name, string $type, string $auto ) use ( $input, $post ) {
			return '<label>' . esc_html( $label ) . '<input style="' . $input . '" type="' . $type . '" name="' . $name . '" value="' . esc_attr( (string) ( $post[ $name ] ?? '' ) ) . '" required autocomplete="' . $auto . '"></label>';
		};
		$body = ( '' !== $message ? '<div class="w" style="color:#1a7f37">' . esc_html( $message ) . '</div>' : '' )
			. ( '' !== $error ? '<div class="w" style="color:#b32d2e">' . esc_html( $error ) . '</div>' : '' )
			. '<div class="n">Primo accesso</div><p>Indica i tuoi dati: ' . ( ! \AssociazioneSemplice\MemberSession::wp_users_wanted() ? 'ti mandiamo il link per entrare e scegliere la tua password. ' : 'ti mandiamo il link per scegliere la password. ' ) . ( 'invite' === Settings::get( 'join_mode' ) ? 'L\'accesso è riservato a chi è già socio: si diventa soci su presentazione, rivolgendosi alla segreteria.' : 'Se non ti riconosciamo, la richiesta arriva alla segreteria, che ti scrive su WhatsApp.' ) . '</p>'
			. '<form method="post" action="' . esc_url( self::url() ) . '">' . MemberLoginPage::sign_field( 'first_access' ) . '<input type="hidden" name="asem_first_go" value="1">'
			. $field( 'Nome e cognome', 'name', 'text', 'name' ) . $field( 'La tua email', 'email', 'email', 'email' ) . $field( 'Il tuo cellulare', 'phone', 'tel', 'tel' )
			. '<button type="submit" style="width:100%;padding:14px;font-size:18px;border:0;border-radius:10px;cursor:pointer;background:' . esc_attr( $accent ) . ';color:#fff">Mandami il link</button></form>'
			. '<p style="margin-top:14px"><a href="' . esc_url( ! \AssociazioneSemplice\MemberSession::wp_users_wanted() ? MemberLoginPage::url() : wp_login_url() ) . '">← Torna all\'accesso</a></p>';
		return CardVerify::layout( 'Primo accesso', $accent, $body );
	}

	public static function handle(): void {
		if ( ! isset( $_GET['asem_first_access'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			return;
		}
		$message = '';
		$error   = '';
		$post    = array();
		if ( isset( $_SERVER['REQUEST_METHOD'], $_POST['asem_first_go'] ) && 'POST' === sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) { // phpcs:ignore WordPress.Security.NonceVerification -- il nonce si controlla qui sotto
			$post   = \AssociazioneSemplice\Plugin::post_data(); // phpcs:ignore WordPress.Security.NonceVerification
			$rl_key = 'asem_fa_' . md5( \AssociazioneSemplice\ClientIp::get() );
			$tries  = (int) get_transient( $rl_key );
			if ( $tries >= Limits::get( 'first_access_per_ip' ) ) {
				$error = 'Troppi tentativi: riprova tra un\'ora.';
			} elseif ( ! MemberLoginPage::from_page( sanitize_text_field( (string) ( $post['_asem_nonce'] ?? '' ) ), 'first_access' ) ) {
				$error = Activation::NOT_FROM_PAGE;
			} else {
				set_transient( $rl_key, $tries + 1, HOUR_IN_SECONDS );
				\AssociazioneSemplice\ResponseMail::defer( true ); // l'email parte a risposta consegnata: il tempo di risposta non rivela chi è socio
				if ( 'invalid' === self::submit( (string) ( $post['name'] ?? '' ), (string) ( $post['email'] ?? '' ), (string) ( $post['phone'] ?? '' ) ) ) {
					$error = 'Controlla i dati: servono nome e cognome, un\'email valida e il numero di cellulare.';
				} else {
					$message = \AssociazioneSemplice\MemberSession::enabled() ? self::MESSAGE_OWN : self::MESSAGE; // sempre la stessa risposta: non si scopre chi è socio
					$post    = array();
				}
			}
		}
		Views::no_cache(); // la pagina porta il segno di chi la apre: mai in cache
		MemberLoginPage::ensure_pre_session();
		CardVerify::send_headers();
		header( 'Cache-Control: no-store, max-age=0' );
		CardVerify::output( self::page( $message, $post, $error ) );
		exit;
	}
}
