<?php
namespace AssociazioneSemplice\Frontend;

use AssociazioneSemplice\Access;
use AssociazioneSemplice\Attachments;
use AssociazioneSemplice\Bank;
use AssociazioneSemplice\CardToken;
use AssociazioneSemplice\MemberType;
use AssociazioneSemplice\Money;
use AssociazioneSemplice\Plugin;
use AssociazioneSemplice\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Azioni dei soci dal sito (prenota, annulla, aggiungi un ospite, aggiorna il profilo).
 * La logica sta nei metodi `do_*` (lanciano \InvalidArgumentException con un messaggio leggibile);
 * il resto è solo il collegamento con admin-post.php, nonce e ritorno alla pagina di partenza.
 * Ogni azione controlla i permessi con le capability meta di {@see Access}: quindi vale anche la licenza.
 */
final class Actions {

	const MAP = array(
		'asem_front_book'           => 'do_book',
		'asem_front_book_group'     => 'do_book_group',
		'asem_front_request'        => 'do_request',
		'asem_front_course_request' => 'do_course_request',
		'asem_front_accept_rules'   => 'do_accept_rules',
		'asem_front_accept_for'     => 'do_accept_for',
		'asem_front_waitlist_join'  => 'do_waitlist_join',
		'asem_front_waitlist_leave' => 'do_waitlist_leave',
		'asem_front_cancel_booking' => 'do_cancel_booking',
		'asem_front_decline_booking' => 'do_decline_booking',
		'asem_front_confirm_booking' => 'do_confirm_booking',
		'asem_front_transfer_booking' => 'do_transfer_booking',
		'asem_front_add_guest'      => 'do_add_guest',
		'asem_front_profile'        => 'do_profile',
		'asem_front_expense'        => 'do_expense',
		'asem_front_expense_docs'   => 'do_expense_docs',
		'asem_front_checkin'        => 'do_checkin',
		'asem_front_checkin_scan'   => 'do_checkin_scan',
		'asem_front_collect'        => 'do_collect',
		'asem_front_new_member'     => 'do_new_member',
		'asem_front_notice'         => 'do_notice',
		'asem_front_vote'           => 'do_vote',
		'asem_front_bank_email'     => 'do_bank_email',
		'asem_front_regen_qr'       => 'do_regen_qr',
	);

	/** @var array<string,array{0:callable,1:bool}> azioni aggiunte da un'estensione: [gestore, restituisce un indirizzo] */
	private static $extra = array();

	/**
	 * Un'estensione aggiunge un'azione dei moduli del sito. Il gestore riceve i dati inviati e restituisce un messaggio
	 * (oppure, con `$returns_url`, l'indirizzo a cui mandare la persona) o lancia InvalidArgumentException con il motivo del rifiuto.
	 */
	public static function add_handler( string $action, callable $handler, bool $returns_url = false ): void {
		self::$extra[ $action ] = array( $handler, $returns_url );
	}

	private static function bind_extra( string $action, callable $handler, bool $url ): void {
		$run = function () use ( $action, $handler, $url ) {
				\AssociazioneSemplice\Csrf::check( $action );
				$post = \AssociazioneSemplice\Plugin::post_data(); // phpcs:ignore WordPress.Security.NonceVerification
				$back = self::back_url( $post );
				try {
					$res = call_user_func( $handler, $post );
					if ( $url ) {
						wp_redirect( (string) $res ); // phpcs:ignore WordPress.Security.SafeRedirect -- indirizzo restituito dall'estensione (ad esempio il gateway)
						exit;
					}
					self::redirect( $back, (string) $res );
				} catch ( \InvalidArgumentException $e ) {
					self::redirect( $back, '', $e->getMessage() );
				} catch ( \Throwable $e ) {
					self::redirect( $back, '', 'Qualcosa non ha funzionato. Riprova tra poco; se succede ancora, scrivi alla segreteria.' );
				}
			};
		add_action( 'admin_post_' . $action, $run );
		add_action( 'admin_post_nopriv_' . $action, \AssociazioneSemplice\Csrf::nopriv( $run, array( __CLASS__, 'to_login' ) ) );
	}

	/** Chi non ha fatto l'accesso viene mandato al login e poi riportato alla pagina. */
	public static function to_login(): void {
		wp_safe_redirect( wp_login_url( self::back_url( \AssociazioneSemplice\Plugin::post_data() ) ) ); // phpcs:ignore WordPress.Security.NonceVerification
		exit;
	}

	public static function register(): void {
		// Azioni di un'estensione: la risposta è un messaggio oppure un indirizzo esterno (ad esempio la pagina del gateway di pagamento).
		foreach ( self::$extra as $action => $h ) {
			if ( Plugin::wants_action( $action ) ) { // con un'azione chiesta si registra solo quella
				self::bind_extra( $action, $h[0], $h[1] );
			}
		}
		foreach ( self::MAP as $action => $method ) {
			if ( ! Plugin::wants_action( $action ) ) {
				continue;
			}
			$run = function () use ( $action, $method ) {
				// Il profilo si salva solo con l'invio di un modulo: con un GET i campi arriverebbero vuoti e verrebbero cancellati.
				if ( 'asem_front_profile' === $action && 'POST' !== strtoupper( (string) ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
					wp_die( esc_html( 'Questa pagina si apre solo salvando il profilo: torna all\'area soci.' ), '', array( 'response' => 405 ) );
				}
				\AssociazioneSemplice\Csrf::check( $action );
				$post = \AssociazioneSemplice\Plugin::post_data(); // phpcs:ignore WordPress.Security.NonceVerification
				$back = self::back_url( $post );
				try {
					self::redirect( $back, self::$method( $post ) );
				} catch ( \InvalidArgumentException $e ) {
					self::redirect( $back, '', $e->getMessage() );
				} catch ( \Throwable $e ) {
					self::redirect( $back, '', 'Qualcosa non ha funzionato. Riprova tra poco; se succede ancora, scrivi alla segreteria.' );
				}
			};
			add_action( 'admin_post_' . $action, $run );
			// Chi non ha fatto l'accesso viene mandato al login e poi riportato alla pagina (chi ha l'accesso proprio prosegue).
			add_action( 'admin_post_nopriv_' . $action, \AssociazioneSemplice\Csrf::nopriv( $run, array( __CLASS__, 'to_login' ) ) );
		}
	}

	public static function back_url( array $post ): string {
		$url = ! empty( $post['_back'] ) ? esc_url_raw( $post['_back'] ) : home_url( '/' );
		return wp_validate_redirect( $url, home_url( '/' ) );
	}

	private static function redirect( string $url, string $ok = '', string $err = '' ): void {
		wp_safe_redirect( \AssociazioneSemplice\Flash::url( $url, 'asemf', $ok, $err ) );
		exit;
	}

	/** La persona collegata all'utente, solo se è un socio (non un ospite). */
	public static function actor(): array {
		$p = Access::current_person();
		if ( ! $p || ! MemberType::can_login( $p['type'] ) ) {
			throw new \InvalidArgumentException( 'Per farlo devi entrare come socio.' );
		}
		return $p;
	}

	/** Le azioni delle funzioni avanzate si rifiutano se la funzione non c'è in questa edizione. */
	private static function require_feature( string $feature ): void {
		if ( ! \AssociazioneSemplice\Edition::has( $feature ) ) {
			throw new \InvalidArgumentException( \AssociazioneSemplice\Edition::missing_message() ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- messaggio interno, mostrato solo dopo esc_html
		}
	}

	/** Con la tessera di chi si prenota: della persona stessa (o del suo ospitante, che prenota con la propria) oppure, per un minore, del minore. */
	private static function booking_subject( array $actor, int $person_id ): array {
		$ward = \AssociazioneSemplice\Guardians::is_guardian_of( (int) $actor['id'], $person_id ) ? Plugin::people()->get( $person_id ) : null;
		return $ward ?: $actor;
	}

	public static function require_cap( string $ability, int $object_id ): void {
		if ( ! current_user_can( $ability, $object_id ) ) {
			throw new \InvalidArgumentException( 'Non hai il permesso di fare questa operazione.' );
		}
	}

	/**
	 * Messaggio per chi ha la tessera non valida: dice cosa fare e dove (la stessa frase ovunque).
	 *
	 * @param array  $who   la persona la cui tessera non è valida
	 * @param array  $actor chi sta usando l'area soci
	 * @param string $for   cosa voleva fare, all'infinito («prenotare»)
	 */
	public static function card_invalid( array $who, array $actor, string $for ): string {
		$own   = (int) ( $who['id'] ?? 0 ) === (int) ( $actor['id'] ?? -1 );
		$where = self::renew_where();
		return ( $own ? 'La tua tessera non è valida: non puoi ' . $for . '. ' : 'La tessera di ' . trim( (string) ( $who['first_name'] ?? '' ) ) . ' non è valida: per questa persona non puoi ' . $for . '. ' ) . $where;
	}

	/** Il pezzo di frase che diventa un link all'area soci quando il messaggio si mostra (vedi Views::flash). */
	const RENEW_LINK = 'Rinnova online dalla tua area soci';

	/**
	 * Dove e come si rinnova la quota (regola unica, per il socio): con i pagamenti online attivi si rinnova dalla propria area soci
	 * (sezione «Pagamenti»), altrimenti in segreteria, con il numero se l'associazione lo ha indicato.
	 */
	public static function renew_where(): string {
		if ( Plugin::payments()->enabled() ) {
			return self::RENEW_LINK . ' (sezione «Pagamenti»), oppure rivolgiti alla segreteria.';
		}
		$phone = trim( (string) \AssociazioneSemplice\Settings::get( 'secretary_phone' ) );
		return 'Rinnova la quota in segreteria' . ( '' !== $phone ? ' (telefono o WhatsApp ' . $phone . ')' : '' ) . '.';
	}

	/** Messaggio per lo staff che prenota o incassa per un socio con la tessera non valida: la quota si rinnova dalla sua scheda. */
	public static function card_invalid_staff( string $name ): string {
		return 'La tessera di ' . $name . ' non è valida: rinnova la quota dalla scheda del socio, poi riprova a prenotare.';
	}

	/** Chiede di partecipare a un evento con approvazione della segreteria (per sé o per un proprio ospite o minore). @return string messaggio */
	public static function do_request( array $post ): string {
		$actor     = self::actor();
		$person_id = (int) ( $post['person_id'] ?? $actor['id'] );
		self::assert_profile( $actor );
		self::require_cap( 'asem_book_for', $person_id );
		$subject = self::booking_subject( $actor, $person_id );
		$block   = \AssociazioneSemplice\Regulation::booking_block( $subject );
		if ( '' !== $block ) {
			throw new \InvalidArgumentException( $block ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- messaggio interno, mostrato solo dopo esc_html
		}
		if ( ! Plugin::people()->is_active_member( (int) $subject['id'] ) ) {
			throw new \InvalidArgumentException( self::card_invalid( $subject, $actor, 'partecipare' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- messaggio interno, mostrato solo dopo esc_html
		}
		\AssociazioneSemplice\EventRequests::member_request( (int) ( $post['session_id'] ?? 0 ), $person_id );
		return 'Richiesta inviata. La segreteria esamina le richieste in ordine di arrivo e ti scrive.';
	}

	/** Chiede l'iscrizione a un corso (per sé o per un proprio ospite o minore): la segreteria approva. @return string messaggio */
	public static function do_course_request( array $post ): string {
		$actor     = self::actor();
		$person_id = (int) ( $post['person_id'] ?? $actor['id'] );
		self::assert_profile( $actor );
		self::require_cap( 'asem_book_for', $person_id );
		$subject = self::booking_subject( $actor, $person_id );
		if ( ! Plugin::people()->is_active_member( (int) $subject['id'] ) ) {
			throw new \InvalidArgumentException( self::card_invalid( $subject, $actor, 'iscriverti' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- messaggio interno, mostrato solo dopo esc_html
		}
		\AssociazioneSemplice\EventRequests::course_member_request( (int) ( $post['activity_id'] ?? 0 ), $person_id );
		return 'Richiesta inviata. La segreteria esamina le richieste in ordine di arrivo e ti scrive.';
	}

	/** Prenota sé stessi o un proprio ospite. @return string messaggio */
	public static function do_book( array $post ): string {
		$actor     = self::actor();
		\AssociazioneSemplice\MemberThrottle::hit( 'book', (int) $actor['id'], 'member_book_per_10min' );
		$person_id = (int) ( $post['person_id'] ?? $actor['id'] );
		self::assert_profile( $actor );
		self::require_cap( 'asem_book_for', $person_id );
		$ward  = \AssociazioneSemplice\Guardians::is_guardian_of( (int) $actor['id'], $person_id ) ? Plugin::people()->get( $person_id ) : null; // un minore prenota con la propria tessera, per mano del tutore
		$block = \AssociazioneSemplice\Regulation::booking_block( $ward ?: $actor );
		if ( '' !== $block ) {
			throw new \InvalidArgumentException( $block ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- messaggio interno, mostrato solo dopo esc_html
		}
		if ( ! Plugin::people()->is_active_member( (int) ( $ward ? $ward['id'] : $actor['id'] ) ) ) {
			throw new \InvalidArgumentException( self::card_invalid( $ward ?: $actor, $actor, 'prenotare' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- messaggio interno, mostrato solo dopo esc_html
		}
		$session = Plugin::activities()->session( (int) ( $post['session_id'] ?? 0 ) );
		if ( ! $session ) {
			throw new \InvalidArgumentException( 'Questo evento non c\'è più: ricarica la pagina.' );
		}
		if ( ! \AssociazioneSemplice\ActivityService::online_bookable( $session ) ) {
			throw new \InvalidArgumentException( \AssociazioneSemplice\ActivityService::last_day( $session ) >= current_time( 'Y-m-d' ) ? 'Le prenotazioni online si chiudono il giorno prima: per questa data rivolgiti alla segreteria.' : 'Questo evento è già passato.' );
		}
		$list_key = sanitize_key( (string) ( $post['price_list'] ?? '' ) );
		$act_row  = Plugin::activities()->get( (int) $session['activity_id'] );
		if ( '' === $list_key && $act_row && \AssociazioneSemplice\PriceLists::for_activity( $act_row ) ) { // con i listini ne va scelto uno: il contributo base non basta
			throw new \InvalidArgumentException( 'Scegli uno dei contributi proposti per questa attività.' );
		}
		if ( Plugin::activities()->paid_by_another( (int) $session['id'], $person_id, $person_id ) ) {
			throw new \InvalidArgumentException( \AssociazioneSemplice\ActivityService::PAID_BY_ANOTHER ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- messaggio interno, mostrato solo dopo esc_html
		}
		Plugin::activities()->book( (int) $session['id'], $person_id, array( 'price_list' => $list_key ) ); // ospiti e pacchetti già pagati: solo la segreteria
		$person = Plugin::people()->get( $person_id );
		return 'Prenotazione registrata' . ( $person ? ' per ' . $person['first_name'] : '' ) . '.';
	}

	/** Massimo di persone per ogni invio del modulo «prenota più persone». */
	const GROUP_MAX = 10;

	/** Frase unica per ogni motivo per cui un altro socio non si può prenotare: non si dice quale, così nessuno scopre chi è socio, chi ha la tessera valida o chi è già prenotato. */
	const OTHER_MEMBER_FAIL = 'Non è stato possibile prenotare una delle persone indicate: controlla cognome e numero di tessera. Non è stata prenotata nessuna persona.';

	/** Contatori dei tentativi falliti su un altro socio: per socio (ogni 10 minuti) e per indirizzo di rete (ogni ora). */
	private static function other_fail_keys( int $actor_id ): array {
		$ip = hash( 'sha256', \AssociazioneSemplice\ClientIp::get() . wp_salt( 'auth' ) );
		return array(
			array( 'asem_mt_ofm_' . $actor_id, \AssociazioneSemplice\Limits::get( 'member_other_fail_per_10min' ), 600 ),
			array( 'asem_mt_ofip_' . substr( $ip, 0, 24 ), \AssociazioneSemplice\Limits::get( 'ip_other_fail_per_hour' ), 3600 ),
		);
	}

	/** Conta tentativi falliti su altri soci (peso $n) e ne lascia traccia SENZA dati in chiaro (niente cognomi né tessere). Non lancia: si sta già rifiutando. */
	private static function other_fail_count( int $actor_id, int $n ): void {
		foreach ( self::other_fail_keys( $actor_id ) as $k ) {
			\AssociazioneSemplice\MemberThrottle::add( $k[0], $k[2], $n ); // sempre, anche oltre il limite: tanti tentativi in un solo invio non devono passare senza contare
		}
		\AssociazioneSemplice\Audit::log( 'booking.other_member_failed', 'person', $actor_id, array( 'attempts' => $n ) );
	}

	/** Un altro socio per cognome e tessera, o null per QUALUNQUE motivo per cui non si può prenotare (la ragione non si distingue mai). */
	private static function other_member( string $last, string $card, int $actor_id, int $session_id ): ?array {
		$people = Plugin::people();
		$h      = '' !== $card ? $people->find_by_card( $card ) : null;
		if ( ! $h || '' === $last || \AssociazioneSemplice\Text::normalize( (string) $h['last_name'] ) !== \AssociazioneSemplice\Text::normalize( $last ) ) {
			return null;
		}
		$email = trim( (string) $h['email'] );
		if ( (int) $h['id'] === $actor_id || ! \AssociazioneSemplice\MemberType::is_member( (string) $h['type'] ) || ! empty( $h['no_booking_by_others'] )
			|| \AssociazioneSemplice\Age::is_minor( $h['birth_date'] ?? null, \AssociazioneSemplice\Db::today() ) // i minori si prenotano solo dal tutore
			|| '' === $email || ! is_email( $email ) // senza email non si può avvisare chi viene prenotato
			|| ! $people->is_active_member( (int) $h['id'] ) || '' !== \AssociazioneSemplice\Regulation::booking_block( $h )
			|| Plugin::activities()->has_active_booking( $session_id, (int) $h['id'] ) || Plugin::activities()->declined_by_member( $session_id, (int) $h['id'] )
			|| Plugin::activities()->paid_by_another( $session_id, (int) $h['id'], $actor_id ) ) {
			return null;
		}
		return $h;
	}

	/**
	 * Controlli e creazione di un ospite dell'area soci: le stesse regole di «Aggiungi ospite» e dell'invio in gruppo (numero di un socio → risposta generica, doppioni,
	 * cellulare se richiesto dall'associazione). Con $dry_run controlla soltanto.
	 *
	 * @return int id dell'ospite creato (0 con $dry_run)
	 */
	private static function add_guest_checked( array $actor, string $first, string $last, string $phone, string $email, bool $dry_run = false ): int {
		if ( ! \AssociazioneSemplice\Settings::guests_enabled() ) {
			throw new \InvalidArgumentException( 'L\'associazione non prevede ospiti.' );
		}
		if ( ( \AssociazioneSemplice\Limits::flag( 'guest_phone_required' ) || '' !== trim( $phone ) ) && ! \AssociazioneSemplice\Phone::is_valid( $phone ) ) {
			throw new \InvalidArgumentException( 'Per gli ospiti il cellulare è obbligatorio: serve a riconoscerli, evitare doppioni e contattarli su WhatsApp.' );
		}
		// Nessun limite automatico: si evita solo il doppione evidente tra i propri ospiti e chi è già socio (il resto lo segnalano gli elenchi a chi gestisce).
		foreach ( Plugin::people()->find_by_phone( $phone ) as $h ) {
			if ( \AssociazioneSemplice\MemberType::is_member( $h['type'] ) ) {
				throw new \InvalidArgumentException( 'Non puoi aggiungere questo numero come ospite: scrivi alla segreteria.' ); // non si dice perché: nessuno può scoprire chi è socio provando dei numeri
			}
			if ( (int) $h['host_person_id'] === (int) $actor['id'] ) {
				throw new \InvalidArgumentException( 'Hai già questo ospite tra i tuoi.' );
			}
		}
		foreach ( Plugin::people()->guests_of( (int) $actor['id'] ) as $g ) {
			if ( \AssociazioneSemplice\Text::normalize( $g['first_name'] . $g['last_name'] ) === \AssociazioneSemplice\Text::normalize( $first . $last ) ) {
				throw new \InvalidArgumentException( 'Hai già questo ospite tra i tuoi.' );
			}
		}
		if ( $dry_run ) {
			return 0;
		}
		return (int) Plugin::people()->create(
			array(
				'type'           => \AssociazioneSemplice\MemberType::GUEST,
				'host_person_id' => (int) $actor['id'],
				'first_name'     => $first,
				'last_name'      => $last,
				'email'          => $email,
				'phone'          => $phone,
			)
		);
	}

	/**
	 * Prenota insieme più persone: sé stessi, i propri ospiti e minori (esistenti o nuovi) e altri soci indicati con cognome e numero di tessera.
	 * Tutte o nessuna. Il contributo di tutti è di chi prenota. Per sé, ospiti e minori si dice il motivo di ogni rifiuto; per gli altri soci MAI: una sola frase.
	 * Ordine dei controlli: prima tutto ciò che non dipende dall'altro socio (data, posti, evento su richiesta, listini, sé stessi, ospiti), poi l'altro socio.
	 * Campi: session_id, me (1), guest_ids[], ward_ids[], new_guest_first[]/new_guest_last[]/new_guest_phone[]/new_guest_email[], other_last[]/other_card[],
	 * price_list[me|p<ID>|n<i>|o<i>] (un listino per persona, se l'attività ne ha).
	 *
	 * @return string messaggio
	 */
	public static function do_book_group( array $post ): string {
		$token = preg_replace( '/[^A-Za-z0-9]/', '', (string) ( is_scalar( $post['group_token'] ?? '' ) ? $post['group_token'] ?? '' : '' ) );
		if ( '' === $token ) {
			return self::book_group( $post, true );
		}
		$draft = self::group_draft( (int) self::actor()['id'] );
		if ( ! $draft || ! hash_equals( (string) $draft['token'], $token ) ) {
			throw new \InvalidArgumentException( 'Il riepilogo è scaduto: scegli di nuovo le persone.' );
		}
		return self::book_group( $draft['post'], false, (int) $draft['total'] );
	}

	/** Quanto vale il riepilogo prima della conferma, in secondi (i dati scritti nel modulo non restano oltre). */
	const GROUP_TTL = 600;

	private static function group_key( int $person_id ): string {
		return 'asem_group_' . $person_id;
	}

	/** Toglie il riepilogo di una persona (cancellata o resa anonima). */
	public static function forget_group_draft( int $person_id ): void {
		delete_transient( self::group_key( $person_id ) );
	}

	/** Giro orario: toglie i riepiloghi scaduti. Con una cache degli oggetti scadono da soli; senza, WordPress li toglie solo una volta al giorno. @return int riepiloghi tolti */
	public static function purge_group_drafts(): int {
		if ( wp_using_ext_object_cache() ) {
			return 0;
		}
		$db     = \AssociazioneSemplice\Db::db();
		$prefix = '_transient_timeout_asem_group_';
		$names  = $db->get_col( $db->prepare( "SELECT option_name FROM {$db->options} WHERE option_name LIKE %s AND option_value < %d LIMIT 500", $db->esc_like( $prefix ) . '%', time() ) ) ?: array();
		foreach ( $names as $name ) {
			$key = substr( (string) $name, strlen( '_transient_timeout_' ) );
			delete_option( '_transient_' . $key );
			delete_option( '_transient_timeout_' . $key );
		}
		return count( $names );
	}

	/** Il riepilogo in attesa di conferma di questo socio, o null. @return array{token:string,post:array,total:int,lines:array[]}|null */
	public static function group_draft( int $person_id ): ?array {
		$d = get_transient( self::group_key( $person_id ) );
		return is_array( $d ) && isset( $d['token'], $d['post'], $d['total'], $d['lines'] ) ? $d : null;
	}

	/**
	 * Le due fasi della prenotazione di gruppo: con $preview controlla, calcola il contributo e conserva la richiesta per la conferma (nessun altro socio viene cercato);
	 * senza, prenota. $expected: il totale visto da chi conferma.
	 */
	public static function book_group( array $post, bool $preview = false, ?int $expected = null ): string {
		$actor  = self::actor();
		$aid    = (int) $actor['id'];
		$people = Plugin::people();
		$acts   = Plugin::activities();
		$arr    = static function ( string $k ) use ( $post ): array {
			return isset( $post[ $k ] ) && is_array( $post[ $k ] ) ? array_values( $post[ $k ] ) : array();
		};
		$clean  = static function ( $v ): string {
			return trim( sanitize_text_field( (string) $v ) );
		};

		// 1. Che cosa è stato chiesto
		$want = array();
		if ( ! empty( $post['me'] ) ) {
			$want['me'] = $aid;
		}
		foreach ( array_merge( $arr( 'guest_ids' ), $arr( 'ward_ids' ) ) as $pid ) {
			if ( ! in_array( (int) $pid, $want, true ) ) {
				$want[ 'p' . (int) $pid ] = (int) $pid;
			}
		}
		$new_guests = array();
		foreach ( $arr( 'new_guest_first' ) as $i => $f ) {
			$l  = $arr( 'new_guest_last' )[ $i ] ?? '';
			$ph = $arr( 'new_guest_phone' )[ $i ] ?? '';
			$em = $arr( 'new_guest_email' )[ $i ] ?? '';
			if ( '' === $clean( $f ) && '' === $clean( $l ) && '' === $clean( $ph ) ) {
				continue;
			}
			if ( '' === $clean( $f ) || '' === $clean( $l ) ) {
				throw new \InvalidArgumentException( 'Per ogni ospite nuovo servono nome e cognome.' );
			}
			$new_guests[ 'n' . $i ] = array( $clean( $f ), $clean( $l ), $clean( $ph ), $clean( $em ) );
		}
		$others = array();
		foreach ( $arr( 'other_last' ) as $i => $last ) {
			$card = $clean( $arr( 'other_card' )[ $i ] ?? '' );
			if ( '' === $clean( $last ) && '' === $card ) {
				continue;
			}
			$others[ 'o' . $i ] = array( $clean( $last ), $card );
		}
		if ( $others && ! \AssociazioneSemplice\ActivityService::others_allowed() ) { // prima di cercare chiunque
			throw new \InvalidArgumentException( 'Per ora non si possono prenotare altri soci da qui: scrivi alla segreteria.' );
		}
		$n = count( $want ) + count( $new_guests ) + count( $others );
		if ( $n < 1 ) {
			throw new \InvalidArgumentException( 'Scegli almeno una persona da prenotare.' );
		}
		if ( $n > self::GROUP_MAX ) {
			throw new \InvalidArgumentException( 'Puoi prenotare al massimo ' . (int) self::GROUP_MAX . ' persone per volta.' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- messaggio interno, mostrato solo dopo esc_html
		}

		// 2. Limiti: un invio pesa quanto le persone; i tentativi falliti sugli altri soci hanno limiti a parte (si controllano prima di verificare chiunque)
		if ( ! $preview ) {
			\AssociazioneSemplice\MemberThrottle::hit( 'book', $aid, 'member_book_per_10min', $n );
		}
		if ( $new_guests ) { // anche il riepilogo controlla i numeri degli ospiti: conta
			\AssociazioneSemplice\MemberThrottle::hit( 'guest', $aid, 'member_book_per_10min', count( $new_guests ) );
		}
		if ( $others ) {
			foreach ( self::other_fail_keys( $aid ) as $k ) {
				\AssociazioneSemplice\MemberThrottle::strike( $k[0], $k[1], $k[2], 0 );
			}
		}
		self::assert_profile( $actor );

		// 3. Tutto ciò che non dipende dall'altro socio
		$session = $acts->session( (int) ( $post['session_id'] ?? 0 ) );
		if ( ! $session ) {
			throw new \InvalidArgumentException( 'Questo evento non c\'è più: ricarica la pagina.' );
		}
		if ( ! \AssociazioneSemplice\ActivityService::online_bookable( $session ) ) {
			throw new \InvalidArgumentException( \AssociazioneSemplice\ActivityService::last_day( $session ) >= current_time( 'Y-m-d' ) ? 'Le prenotazioni online si chiudono il giorno prima: per questa data rivolgiti alla segreteria.' : 'Questo evento è già passato.' );
		}
		$sid = (int) $session['id'];
		try {
			list( , $act_row ) = $acts->group_precheck( $sid, $n, $aid );
		} catch ( \AssociazioneSemplice\BookingFailure $e ) {
			throw new \InvalidArgumentException( $e->getMessage() ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- messaggio interno, mostrato solo dopo esc_html
		}
		$chosen = isset( $post['price_list'] ) && is_array( $post['price_list'] ) ? $post['price_list'] : array();
		$lists  = array(); // chiave della richiesta => listino
		$has_pl = (bool) \AssociazioneSemplice\PriceLists::for_activity( $act_row );
		foreach ( array_merge( array_keys( $want ), array_keys( $new_guests ), array_keys( $others ) ) as $k ) {
			$key = sanitize_key( (string) ( $chosen[ $k ] ?? '' ) );
			if ( $has_pl && ( '' === $key || ! \AssociazioneSemplice\PriceLists::find( $act_row, $key ) ) ) {
				throw new \InvalidArgumentException( 'Scegli il contributo per ogni persona.' );
			}
			$lists[ $k ] = $has_pl ? $key : '';
		}
		if ( $new_guests || $others ) { // chi prenota per altri deve poterlo fare anche se non prenota sé stesso: regolamento, tessera e permesso di aggiungere ospiti
			$block = \AssociazioneSemplice\Regulation::booking_block( $actor );
			if ( '' !== $block ) {
				throw new \InvalidArgumentException( $block ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- messaggio interno, mostrato solo dopo esc_html
			}
			if ( ! $people->is_active_member( $aid ) ) {
				throw new \InvalidArgumentException( self::card_invalid( $actor, $actor, 'prenotare' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- messaggio interno, mostrato solo dopo esc_html
			}
			if ( $new_guests ) {
				self::require_cap( 'asem_add_guest', $aid );
			}
		}
		$errors = array();
		$mine   = array(); // person_id => chiave della richiesta
		foreach ( $want as $k => $pid ) {
			$p = $people->get( $pid );
			if ( ! $p ) {
				$errors[] = 'Persona non trovata.';
				continue;
			}
			$name = (string) $p['first_name'];
			try {
				self::require_cap( 'asem_book_for', $pid );
			} catch ( \InvalidArgumentException $e ) {
				$errors[] = $name . ': non puoi prenotare questa persona.';
				continue;
			}
			$subject = self::booking_subject( $actor, $pid );
			$block   = \AssociazioneSemplice\Regulation::booking_block( $subject );
			if ( '' !== $block ) {
				$errors[] = $name . ': ' . $block;
				continue;
			}
			if ( ! $people->is_active_member( (int) $subject['id'] ) ) {
				$errors[] = $name . ': ' . self::card_invalid( $subject, $actor, 'prenotare' );
				continue;
			}
			if ( $acts->has_active_booking( $sid, $pid ) ) {
				$errors[] = $name . ': ha già una prenotazione per questa data.';
				continue;
			}
			$conflict = (string) apply_filters( 'asem_booking_errors', '', $sid, $pid );
			if ( '' !== $conflict ) {
				$errors[] = $name . ': ' . $conflict;
				continue;
			}
			if ( $acts->paid_by_another( $sid, $pid, $aid ) ) {
				$errors[] = $name . ': ' . \AssociazioneSemplice\ActivityService::PAID_BY_ANOTHER;
				continue;
			}
			$mine[ $pid ] = $k;
		}
		$seen = array();
		foreach ( $new_guests as $g ) { // due volte la stessa persona, o lo stesso numero, nello stesso invio
			foreach ( array( 'n' . \AssociazioneSemplice\Text::normalize( $g[0] . $g[1] ), '' !== $g[2] ? 't' . preg_replace( '/\D+/', '', $g[2] ) : '' ) as $mark ) {
				if ( '' !== $mark && isset( $seen[ $mark ] ) ) {
					$errors[] = $g[0] . ': compare due volte tra gli ospiti nuovi.';
				}
				$seen[ $mark ] = true;
			}
		}
		foreach ( $new_guests as $k => $g ) {
			try {
				self::add_guest_checked( $actor, $g[0], $g[1], $g[2], $g[3], true );
			} catch ( \InvalidArgumentException $e ) {
				$errors[] = $g[0] . ': ' . $e->getMessage();
			}
		}
		if ( $errors ) {
			throw new \InvalidArgumentException( implode( ' ', $errors ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- messaggio interno, mostrato solo dopo esc_html
		}

		// Il contributo: lo stesso calcolo della prenotazione. Per un altro socio è quello dei soci (non serve sapere chi è)
		$lines = array();
		$total = 0;
		foreach ( $want as $k => $pid ) {
			$p         = $people->get( $pid );
			$lines[]   = array( trim( $p['first_name'] . ' ' . $p['last_name'] ), $acts->preview_cents( $act_row, (string) $p['type'], $lists[ $k ] ) );
		}
		foreach ( $new_guests as $k => $g ) {
			$lines[] = array( $g[0] . ' ' . $g[1] . ' (ospite)', $acts->preview_cents( $act_row, MemberType::GUEST, $lists[ $k ] ) );
		}
		foreach ( $others as $k => $o ) {
			$lines[] = array( 'socio con tessera n. ' . $o[1], $acts->preview_cents( $act_row, MemberType::ORDINARY, $lists[ $k ] ) );
		}
		$total = array_sum( array_column( $lines, 1 ) );
		if ( $preview ) {
			$keep = array_intersect_key( $post, array_flip( array( 'session_id', 'me', 'guest_ids', 'ward_ids', 'new_guest_first', 'new_guest_last', 'new_guest_phone', 'new_guest_email', 'other_last', 'other_card', 'price_list' ) ) );
			set_transient( self::group_key( $aid ), array( 'token' => wp_generate_password( 24, false ), 'post' => $keep, 'total' => $total, 'lines' => $lines ), self::GROUP_TTL );
			return 'Controlla il riepilogo e conferma: finché non confermi nessuno è prenotato.';
		}
		if ( null !== $expected && $expected !== $total ) {
			throw new \InvalidArgumentException( 'Il contributo è cambiato nel frattempo: controlla di nuovo il riepilogo.' );
		}

		// 4. Gli altri soci: da qui in poi qualunque motivo, per ognuno, è la stessa frase
		$other_ids   = array(); // person_id => chiave della richiesta
		$other_cards = array();
		$bad         = 0;
		foreach ( $others as $k => $o ) {
			$h = self::other_member( $o[0], $o[1], $aid, $sid );
			if ( ! $h || isset( $mine[ (int) $h['id'] ] ) || isset( $other_ids[ (int) $h['id'] ] ) ) {
				$bad++;
				continue;
			}
			$other_ids[ (int) $h['id'] ]   = $k;
			$other_cards[ (int) $h['id'] ] = (string) $h['card_number'];
		}
		if ( $bad ) {
			self::other_fail_count( $aid, $bad );
			throw new \InvalidArgumentException( self::OTHER_MEMBER_FAIL ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- messaggio interno, mostrato solo dopo esc_html
		}

		// 5. La prenotazione: tutti insieme, gli ospiti nuovi nascono dentro la stessa transazione
		$price_lists = array();
		foreach ( $mine as $pid => $k ) {
			$price_lists[ $pid ] = $lists[ $k ];
		}
		foreach ( $other_ids as $pid => $k ) {
			$price_lists[ $pid ] = $lists[ $k ];
		}
		$create = function () use ( $actor, $new_guests, $lists ): array {
			$out = array();
			foreach ( $new_guests as $k => $g ) {
				$out[ self::add_guest_checked( $actor, $g[0], $g[1], $g[2], $g[3] ) ] = $lists[ $k ];
			}
			return $out;
		};
		try {
			$ids = $acts->book_many( $sid, array_keys( $price_lists ), $aid, array( 'price_lists' => $price_lists, 'new_people' => $new_guests ? $create : null, 'extra' => count( $new_guests ), 'expected' => $total ) );
		} catch ( \AssociazioneSemplice\BookingFailure $e ) {
			if ( $other_ids ) { // l'altro socio è stato verificato e il tentativo è fallito: conta nel limite; e se riguarda lui non si dice altro
				self::other_fail_count( $aid, count( $other_ids ) );
				// Qualunque motivo: qui i dati dell'altro socio erano giusti, e una risposta diversa lo direbbe
				throw new \InvalidArgumentException( self::OTHER_MEMBER_FAIL ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- messaggio interno, mostrato solo dopo esc_html
			}
			throw new \InvalidArgumentException( $e->getMessage() ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- messaggio interno, mostrato solo dopo esc_html
		}
		delete_transient( self::group_key( $aid ) );
		// 6. Solo dopo il commit: email di riepilogo a chi prenota e avviso agli altri soci
		self::book_group_mails( $actor, $session, $act_row, array_keys( array_diff_key( $ids, $other_ids ) ), array_keys( $other_ids ), $other_cards );
		return count( $ids ) > 1 ? 'Prenotazioni registrate per ' . count( $ids ) . ' persone.' : 'Prenotazione registrata.';
	}

	/** Email dopo una prenotazione di gruppo: riepilogo a chi prenota (gli altri soci solo con il numero di tessera) e avviso a ogni altro socio prenotato. */
	private static function book_group_mails( array $actor, array $session, ?array $activity, array $own_ids, array $other_ids, array $other_cards ): void {
		$assoc = (string) \AssociazioneSemplice\Settings::get( 'association_name' );
		$title = $activity ? (string) $activity['name'] : 'evento';
		$when  = ( new \DateTimeImmutable( (string) $session['session_date'] ) )->format( 'd/m/Y' ) . ( ! empty( $session['start_time'] ) ? ' alle ' . $session['start_time'] : '' );
		$names = array();
		foreach ( $own_ids as $pid ) {
			$p = Plugin::people()->get( (int) $pid );
			if ( $p ) {
				$names[] = trim( $p['first_name'] . ' ' . $p['last_name'] );
			}
		}
		foreach ( $other_ids as $oid ) {
			$names[] = 'socio con tessera n. ' . ( $other_cards[ (int) $oid ] ?? '' ); // mai nome e cognome dal database
		}
		$sign = '' !== $assoc ? "\n\n—\n" . $assoc : '';
		$to   = \AssociazioneSemplice\Reminders::recipient( $actor );
		if ( $to ) {
			\AssociazioneSemplice\Texts::mail(
				$to['email'],
				\AssociazioneSemplice\Texts::subject( 'Prenotazione: ' . $title ),
				'Ciao ' . $to['name'] . ",\n\nhai prenotato «" . $title . '» del ' . $when . ' per: ' . implode( ', ', $names ) . ".\nIl contributo di tutte le persone prenotate è a tuo carico.\n\nPuoi annullare dall'area soci: " . \AssociazioneSemplice\Gatekeeper::area_url() . $sign
			);
		}
		foreach ( $other_ids as $oid ) {
			$o  = Plugin::people()->get( (int) $oid );
			$ot = $o ? \AssociazioneSemplice\Reminders::recipient( $o ) : null;
			if ( ! $ot ) {
				continue;
			}
			\AssociazioneSemplice\Texts::mail(
				$ot['email'],
				\AssociazioneSemplice\Texts::subject( 'Prenotazione per te: ' . $title ),
				'Ciao ' . $ot['name'] . ",\n\n" . trim( $actor['first_name'] . ' ' . $actor['last_name'] ) . ' ha prenotato per te «' . $title . '» del ' . $when . ".\nIl contributo, se previsto, è a suo carico.\n\nSe non vuoi partecipare puoi toglierti dall'area soci: " . \AssociazioneSemplice\Gatekeeper::area_url() . "\nSe non vuoi che altri soci prenotino per te, spegni l'opzione nel tuo profilo." . $sign
			);
		}
	}

	/** Mette in lista d'attesa sé stessi o un proprio ospite per una data senza più posti. */
	public static function do_waitlist_join( array $post ): string {
		$actor     = self::actor();
		\AssociazioneSemplice\MemberThrottle::hit( 'book', (int) $actor['id'], 'member_book_per_10min' );
		$person_id = (int) ( $post['person_id'] ?? $actor['id'] );
		self::assert_profile( $actor );
		self::require_cap( 'asem_book_for', $person_id );
		$subject = self::booking_subject( $actor, $person_id );
		$block   = \AssociazioneSemplice\Regulation::booking_block( $subject );
		if ( '' !== $block ) {
			throw new \InvalidArgumentException( $block ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- messaggio interno, mostrato solo dopo esc_html
		}
		if ( ! Plugin::people()->is_active_member( (int) $subject['id'] ) ) {
			throw new \InvalidArgumentException( self::card_invalid( $subject, $actor, 'prenotare' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- messaggio interno, mostrato solo dopo esc_html
		}
		$sid = (int) ( $post['session_id'] ?? 0 );
		if ( Plugin::activities()->paid_by_another( $sid, $person_id, $person_id ) ) {
			throw new \InvalidArgumentException( \AssociazioneSemplice\ActivityService::PAID_BY_ANOTHER ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- messaggio interno, mostrato solo dopo esc_html
		}
		\AssociazioneSemplice\Waitlist::join( $sid, $person_id, (int) $actor['id'], sanitize_key( (string) ( $post['price_list'] ?? '' ) ) );
		return 'Sei in lista d\'attesa (posizione ' . (int) \AssociazioneSemplice\Waitlist::position( $sid, $person_id ) . '): se si libera un posto vieni prenotato automaticamente e ricevi un\'email.';
	}

	public static function do_waitlist_leave( array $post ): string {
		$actor     = self::actor();
		$person_id = (int) ( $post['person_id'] ?? $actor['id'] );
		self::require_cap( 'asem_book_for', $person_id );
		\AssociazioneSemplice\Waitlist::leave( (int) ( $post['session_id'] ?? 0 ), $person_id );
		return 'Hai lasciato la lista d\'attesa.';
	}

	/** Il socio accetta il regolamento in vigore. */
	public static function do_accept_rules( array $post ): string {
		$actor = self::actor();
		if ( ! \AssociazioneSemplice\Regulation::enabled() ) {
			throw new \InvalidArgumentException( 'Non c\'è nessun regolamento da accettare.' );
		}
		if ( empty( $post['rules_ok'] ) ) {
			throw new \InvalidArgumentException( 'Per continuare spunta la casella di accettazione.' );
		}
		\AssociazioneSemplice\Regulation::accept( (int) $actor['id'], 'web' );
		return 'Grazie: hai accettato il ' . mb_strtolower( \AssociazioneSemplice\Regulation::title(), 'UTF-8' ) . '.';
	}

	/** Il tutore accetta il regolamento e/o dà il consenso privacy per conto di un minore: resta registrato chi ha accettato. */
	public static function do_accept_for( array $post ): string {
		$actor     = self::actor();
		$person_id = (int) ( $post['person_id'] ?? 0 );
		self::require_cap( 'asem_book_for', $person_id );
		$ward = \AssociazioneSemplice\Guardians::is_guardian_of( (int) $actor['id'], $person_id ) ? Plugin::people()->get( $person_id ) : null;
		if ( ! $ward ) {
			throw new \InvalidArgumentException( 'Non sei il tutore di questa persona.' );
		}
		$done = 0;
		if ( ! empty( $post['rules_ok'] ) && \AssociazioneSemplice\Regulation::applies_to( $ward ) && ! \AssociazioneSemplice\Regulation::accepted( $ward ) ) {
			\AssociazioneSemplice\Regulation::accept( $person_id, 'web', (int) $actor['id'] );
			$done++;
		}
		if ( ! empty( $post['privacy_ok'] ) && ! \AssociazioneSemplice\Privacy::has_consent( $ward ) ) {
			\AssociazioneSemplice\Privacy::set_consent( $person_id, 'web', (int) $actor['id'] );
			$done++;
		}
		if ( 0 === $done ) {
			throw new \InvalidArgumentException( 'Per continuare spunta la casella di accettazione.' );
		}
		return 'Registrato: hai accettato per conto di ' . $ward['first_name'] . '.';
	}

	/** Annulla una prenotazione se la regola lo consente: gratis sempre; a pagamento solo se l'evento è cancellabile e nei termini. */
	public static function do_cancel_booking( array $post ): string {
		$actor     = self::actor();
		\AssociazioneSemplice\MemberThrottle::hit( 'cancel', (int) $actor['id'], 'member_cancel_per_10min' );
		$person_id  = (int) ( $post['person_id'] ?? $actor['id'] );
		$session_id = (int) ( $post['session_id'] ?? 0 );
		$session    = Plugin::activities()->session( $session_id );
		$booking    = $session ? Plugin::activities()->booking( $session_id, $person_id ) : null;
		$for_other  = $booking && 'booked' === $booking['status'] && (int) $booking['booked_by'] === (int) $actor['id'] && \AssociazioneSemplice\ActivityService::booked_by_other_member( $booking );
		if ( ! $for_other ) { // chi ha prenotato per un altro socio annulla finché lui non conferma; per tutto il resto serve il permesso sulla persona
			self::require_cap( 'asem_book_for', $person_id );
		}
		if ( ! $session ) {
			throw new \InvalidArgumentException( 'Questo evento non c\'è più: ricarica la pagina.' );
		}
		if ( ! $for_other && $booking && 'booked' === $booking['status'] && \AssociazioneSemplice\ActivityService::booked_by_other_member( $booking ) ) {
			return self::do_decline_booking( $post ); // prenotato da un altro socio: si toglie, senza i limiti di annullamento
		}
		$eval = Plugin::activities()->cancellation_for( $session_id, $person_id );
		if ( ! $eval['allowed'] ) {
			throw new \InvalidArgumentException( $eval['message'] ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- messaggio interno, mostrato solo dopo esc_html
		}
		Plugin::activities()->cancel_booking( $session_id, $person_id );
		if ( $for_other ) {
			$o  = Plugin::people()->get( $person_id );
			$to = $o ? \AssociazioneSemplice\Reminders::recipient( $o ) : null;
			if ( $to ) {
				$what = self::booking_words( $session );
				\AssociazioneSemplice\Texts::mail( $to['email'], \AssociazioneSemplice\Texts::subject( 'Prenotazione annullata: ' . $what[0] ), 'Ciao ' . $to['name'] . ",\n\nla prenotazione fatta per te per «" . $what[0] . '» del ' . $what[1] . ' è stata annullata da chi l\'aveva fatta.' . self::mail_sign() );
			}
		}
		return 'Prenotazione annullata.';
	}

	/** Nome dell'evento e quando, per le email sulle prenotazioni. @return string[] */
	private static function booking_words( array $session ): array {
		$a = Plugin::activities()->get( (int) $session['activity_id'] );
		return array(
			$a ? (string) $a['name'] : 'evento',
			( new \DateTimeImmutable( (string) $session['session_date'] ) )->format( 'd/m/Y' ) . ( ! empty( $session['start_time'] ) ? ' alle ' . $session['start_time'] : '' ),
		);
	}

	private static function mail_sign(): string {
		$assoc = (string) \AssociazioneSemplice\Settings::get( 'association_name' );
		return '' !== $assoc ? "\n\n—\n" . $assoc : '';
	}

	/** Il socio si toglie da una prenotazione che un altro socio ha fatto per lui. Chi ha prenotato lo sa (senza il motivo); la segreteria solo se c'è un versamento da restituire. */
	public static function do_decline_booking( array $post ): string {
		$actor = self::actor();
		$aid   = (int) $actor['id'];
		\AssociazioneSemplice\MemberThrottle::hit( 'cancel', $aid, 'member_cancel_per_10min' );
		$session_id = (int) ( $post['session_id'] ?? 0 );
		$session    = Plugin::activities()->session( $session_id );
		if ( ! $session ) {
			throw new \InvalidArgumentException( 'Questo evento non c\'è più: ricarica la pagina.' );
		}
		$b    = Plugin::activities()->decline_booking( $session_id, $aid ); // sempre e solo la propria
		$what = self::booking_words( $session );
		$who  = Plugin::people()->get( (int) $b['booked_by'] );
		$to   = $who ? \AssociazioneSemplice\Reminders::recipient( $who ) : null;
		$ref  = 'la prenotazione per il socio con tessera n. ' . (string) $actor['card_number'] . ', «' . $what[0] . '» del ' . $what[1];
		if ( $to ) {
			\AssociazioneSemplice\Texts::mail(
				$to['email'],
				\AssociazioneSemplice\Texts::subject( 'Prenotazione annullata: ' . $what[0] ),
				'Ciao ' . $to['name'] . ",\n\n" . $ref . ', è stata annullata dalla persona interessata.' . ( $b['paid'] > 0 ? "\n\nPer il contributo già versato scrivi alla segreteria." : "\nNon devi pagare il contributo per questa persona." ) . self::mail_sign()
			);
		}
		if ( $b['paid'] > 0 ) {
			\AssociazioneSemplice\Texts::mail(
				\AssociazioneSemplice\Applications::notify_email(),
				\AssociazioneSemplice\Texts::subject( 'Contributo da verificare: ' . $what[0] ),
				'L' . substr( $ref, 1 ) . ', è stata annullata dalla persona interessata. Contributo già versato: ' . Money::format( (int) $b['paid'] ) . '. A pagarlo era chi ha prenotato' . ( $who ? ' (' . trim( $who['first_name'] . ' ' . $who['last_name'] ) . ')' : '' ) . '.' . self::mail_sign()
			);
		}
		return 'Fatto: non sei più in questa prenotazione.';
	}

	/** Il socio conferma una prenotazione che un altro socio ha fatto per lui: da qui è sua, con le regole normali. */
	public static function do_confirm_booking( array $post ): string {
		$actor = self::actor();
		\AssociazioneSemplice\MemberThrottle::hit( 'cancel', (int) $actor['id'], 'member_cancel_per_10min' );
		Plugin::activities()->confirm_booking( (int) ( $post['session_id'] ?? 0 ), (int) $actor['id'] );
		return 'Prenotazione confermata: ora la gestisci tu. Il contributo resta a carico di chi ha prenotato.';
	}

	/**
	 * Cambia il nominativo di una prenotazione: a un proprio ospite già inserito, a sé stessi, oppure a un nuovo ospite
	 * (nome e cognome). Se il nuovo partecipante ha un contributo maggiore la differenza resta da pagare.
	 */
	public static function do_transfer_booking( array $post ): string {
		$actor      = self::actor();
		$from_id    = (int) ( $post['person_id'] ?? $actor['id'] );
		$session_id = (int) ( $post['session_id'] ?? 0 );
		self::require_cap( 'asem_book_for', $from_id );
		$from_b = Plugin::activities()->booking( $session_id, $from_id );
		if ( $from_b && \AssociazioneSemplice\ActivityService::booked_by_other_member( $from_b ) ) {
			throw new \InvalidArgumentException( 'Un altro socio ha prenotato questo posto: conferma prima la prenotazione, poi puoi cederlo.' );
		}
		$subject = self::booking_subject( $actor, $from_id );
		if ( ! Plugin::people()->is_active_member( (int) $subject['id'] ) ) {
			throw new \InvalidArgumentException( self::card_invalid( $subject, $actor, 'gestire le prenotazioni' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- messaggio interno, mostrato solo dopo esc_html
		}
		$block = \AssociazioneSemplice\Regulation::booking_block( $subject );
		if ( '' !== $block ) {
			throw new \InvalidArgumentException( $block ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- messaggio interno, mostrato solo dopo esc_html
		}
		$to_id = (int) ( $post['to_person_id'] ?? 0 );
		$first = trim( (string) ( $post['new_first_name'] ?? '' ) );
		$last  = trim( (string) ( $post['new_last_name'] ?? '' ) );
		if ( ! $to_id && ( '' !== $first || '' !== $last ) ) {
			self::require_cap( 'asem_add_guest', (int) $actor['id'] );
			$to_id = Plugin::people()->create( array( 'type' => MemberType::GUEST, 'host_person_id' => (int) $actor['id'], 'first_name' => $first, 'last_name' => $last, 'phone' => (string) ( $post['new_phone'] ?? '' ) ) );
		}
		if ( ! $to_id ) {
			throw new \InvalidArgumentException( 'Scegli a chi cedere il posto, oppure scrivi nome e cognome di un nuovo ospite.' );
		}
		self::require_cap( 'asem_book_for', $to_id );
		Plugin::activities()->transfer_booking( $session_id, $from_id, $to_id, true );
		$b   = Plugin::activities()->bookings_for_session( $session_id );
		$msg = 'Posto ceduto.';
		foreach ( $b as $row ) {
			if ( (int) $row['person_id'] === $to_id && $row['remaining'] > 0 ) {
				$msg .= ' Resta da pagare: ' . \AssociazioneSemplice\Money::format( (int) $row['remaining'] ) . ' (in sede).';
			}
		}
		return $msg;
	}

	/**
	 * Avvia il pagamento online delle voci scelte (dovute da lui o dai suoi ospiti).
	 * @return string indirizzo della pagina di pagamento del gateway
	 */
	/** Chi si è attivato dal sito deve prima completare indirizzo e codice fiscale (vedi Limits «profile_gate»). */
	public static function assert_profile( array $actor ): void {
		if ( Plugin::people()->profile_blocks( $actor ) ) {
			throw new \InvalidArgumentException( 'Prima di prenotare o pagare completa i tuoi dati (indirizzo e codice fiscale) nel riquadro «Il mio profilo».' );
		}
	}


	/** Il socio si fa mandare per email le coordinate del bonifico (con le voci da pagare e la causale): sempre e solo al suo indirizzo. */
	/** Il socio rigenera il codice della propria tessera: il vecchio (anche stampato) smette subito di valere. */
	public static function do_regen_qr( array $post ): string {
		$actor = self::actor();
		\AssociazioneSemplice\QrVersion::regenerate( (int) $actor['id'], (int) $actor['id'] );
		return \AssociazioneSemplice\Texts::t( 'Codice rigenerato: quello vecchio non vale più.' );
	}

	public static function do_bank_email( array $post ): string {
		$actor = self::actor();
		self::require_cap( 'asem_view_payments', (int) $actor['id'] );
		$dues = Plugin::payments()->dues_for( $actor );
		$keys = array_map( 'strval', (array) ( $post['items'] ?? array() ) );
		if ( $keys ) { // solo le voci scelte (se non ne sceglie nessuna, tutte quelle dovute)
			$dues = array_intersect_key( $dues, array_flip( $keys ) );
		}
		Bank::send_to_member( $actor, array_values( $dues ), \AssociazioneSemplice\MemberSession::actor_key() );
		return 'Ti abbiamo inviato le coordinate per il bonifico: controlla la tua email.';
	}

	/** Spesa registrata dal tesoriere (con scontrino e fatture allegati). Non vede né modifica altro della prima nota. */
	public static function do_expense( array $post ): string {
		self::require_cap( 'asem_add_expense', 0 );
		$date = (string) ( $post['date'] ?? '' );
		if ( $date > current_time( 'Y-m-d' ) ) {
			throw new \InvalidArgumentException( 'La data della spesa non può essere nel futuro.' );
		}
		$docs = Attachments::prepare( Attachments::from_request() ); // controllati prima: se un file non va, non si registra nulla
		$tx   = Plugin::ledger()->record_expense(
			array(
				'date'         => $date,
				'account_id'   => (int) ( $post['account_id'] ?? 0 ),
				'method'       => (string) ( $post['method'] ?? '' ),
				'category_id'  => (int) ( $post['category_id'] ?? 0 ),
				'amount_cents' => Money::parse( $post['amount'] ?? '' ) ?? 0,
				'activity_id'  => (int) ( $post['activity_id'] ?? 0 ),
				'description'  => $post['description'] ?? '',
				'person_id'    => (int) apply_filters( 'asem_expense_person', 0, array( 'fund_auto' => '1' ) ), // nell'area soci non si sceglie né il beneficiario né il fondo: un'estensione imposta chi ha anticipato (chi registra) e l'attribuzione è sempre automatica
				'document_ref' => $post['document_ref'] ?? '',
			)
		);
		$ids = Attachments::add( $tx, $docs );
		return 'Spesa registrata' . ( $ids ? ' con ' . count( $ids ) . ( 1 === count( $ids ) ? ' documento.' : ' documenti.' ) : '.' );
	}

	/** Altri documenti su una spesa registrata dallo stesso utente. */
	public static function do_expense_docs( array $post ): string {
		self::require_cap( 'asem_add_expense', 0 );
		$tx   = (int) ( $post['transaction_id'] ?? 0 );
		$mine = Plugin::ledger()->expenses_by_user( get_current_user_id(), 200 );
		if ( ! in_array( $tx, array_map( 'intval', array_column( $mine, 'id' ) ), true ) ) {
			throw new \InvalidArgumentException( 'Puoi aggiungere documenti solo alle spese che hai registrato tu.' );
		}
		$ids = Attachments::add( $tx, Attachments::prepare( Attachments::from_request(), $tx ) );
		if ( ! $ids ) {
			throw new \InvalidArgumentException( 'Scegli almeno un file.' );
		}
		return count( $ids ) . ( 1 === count( $ids ) ? ' documento aggiunto.' : ' documenti aggiunti.' );
	}

	/** Messaggio dopo una registrazione di ingresso: chi, e se c'è un contributo ancora da versare. */
	private static function checkin_message( array $r, int $session_id, int $person_id ): string {
		$name = '';
		$due  = '';
		foreach ( Plugin::activities()->bookings_for_session( $session_id ) as $b ) {
			if ( (int) $b['person_id'] === $person_id ) {
				$name = trim( $b['first_name'] . ' ' . $b['last_name'] );
				if ( $b['active'] && (int) $b['remaining'] > 0 ) {
					$due = \AssociazioneSemplice\ActivityService::owed_by_another( $b ) ? ' (contributo non pagato, a carico di chi ha prenotato)' : ' (contributo da pagare ' . Money::format( (int) $b['remaining'] ) . ')';
				}
			}
		}
		switch ( $r['status'] ) {
			case 'recorded':
				return \AssociazioneSemplice\GuestInviteRules::join( 'Ingresso registrato: ' . $name . $due . '.', (string) ( $r['invite'] ?? '' ) ); // per un ospite non socio, l'avviso «da invitare ad associarsi» in coda
			case 'already':
				return 'Ingresso già registrato alle ' . mysql2date( 'H:i', $r['at'] ) . ': ' . $name . '.';
			case 'undone':
				return 'Ingresso annullato: ' . $name . '.';
		}
		return 'Nessun ingresso da annullare per ' . $name . '.';
	}

	/** Iscrizione di un nuovo socio ordinario dall'area soci (tesoriere): bastano nome e cognome, il resto si completa dopo. */
	public static function do_new_member( array $post ): string {
		self::require_cap( 'asem_register_member', 0 );
		$first = trim( sanitize_text_field( (string) ( $post['first_name'] ?? '' ) ) );
		$last  = trim( sanitize_text_field( (string) ( $post['last_name'] ?? '' ) ) );
		if ( '' === $first || '' === $last ) {
			throw new \InvalidArgumentException( 'Indica nome e cognome.' );
		}
		$email = trim( (string) ( $post['email'] ?? '' ) );
		if ( '' !== $email && ! is_email( $email ) ) {
			throw new \InvalidArgumentException( 'L\'email non è valida.' );
		}
		$level = \AssociazioneSemplice\Levels::get( (int) ( $post['level_id'] ?? 0 ) );
		if ( ! \AssociazioneSemplice\Edition::has( 'levels' ) ) { // una quota sola: il tipo di socio non si sceglie
			$only  = \AssociazioneSemplice\Levels::choices( MemberType::ORDINARY );
			$level = $only ? $only[0] : null;
		}
		if ( ! $level || empty( $level['active'] ) || MemberType::ORDINARY !== $level['base_type'] ) {
			throw new \InvalidArgumentException( 'Scegli il tipo di socio.' );
		}
		$id = Plugin::people()->create(
			array(
				'type' => MemberType::ORDINARY, 'level_id' => (int) $level['id'], 'first_name' => $first, 'last_name' => $last,
				'email' => $email, 'phone' => trim( (string) ( $post['phone'] ?? '' ) ),
			)
		);
		\AssociazioneSemplice\Audit::log( 'person.registered_front', 'person', (int) $id );
		$p = Plugin::people()->get( (int) $id );
		return 'Socio registrato: ' . Plugin::people()->full_name( $p ) . ( ! empty( $p['card_number'] ) ? ' (tessera n. ' . $p['card_number'] . ')' : '' ) . '. Ora puoi incassare la quota associativa.';
	}

	/** Incasso del tesoriere dall'area soci. */
	public static function do_collect( array $post ): string {
		self::require_cap( 'asem_collect', 0 );
		$ledger = Plugin::ledger();
		$lines  = array();
		foreach ( (array) ( $post['lines'] ?? array() ) as $l ) {
			$what = (string) ( $l['what'] ?? '' );
			if ( '' === $what ) {
				continue;
			}
			$cents = Money::parse( $l['amount'] ?? '' ) ?? 0;
			if ( 'm' === $what ) {
				$lines[] = array( 'category_id' => $ledger->category_id_of_kind( 'membership' ), 'amount_cents' => $cents );
			} elseif ( 0 === strpos( $what, 's:' ) ) {
				$s = Plugin::activities()->session( (int) substr( $what, 2 ) );
				if ( ! $s ) {
					throw new \InvalidArgumentException( 'Questa data non c\'è più: ricarica la pagina.' );
				}
				$lines[] = array( 'category_id' => $ledger->category_id_of_kind( 'activity_fee' ), 'amount_cents' => $cents, 'activity_id' => (int) $s['activity_id'], 'session_id' => (int) $s['id'] );
			} elseif ( 0 === strpos( $what, 'k:' ) ) {
				$lines[] = array(
					'category_id' => $ledger->category_id_of_kind( 'activity_fee' ), 'amount_cents' => $cents, 'activity_id' => (int) substr( $what, 2 ),
					'competence_month' => Settings::social_year()->clamp( substr( current_time( 'Y-m-d' ), 0, 7 ) ),
				);
			} elseif ( 0 === strpos( $what, 'c:' ) ) {
				$lines[] = array( 'category_id' => (int) substr( $what, 2 ), 'amount_cents' => $cents );
			}
		}
		if ( ! $lines ) {
			throw new \InvalidArgumentException( 'Scegli almeno una voce da incassare.' );
		}
		$pid = (int) ( $post['person_id'] ?? 0 );
		$acts = Plugin::activities();
		$ledger->in_batch(
			function () use ( $post, $pid, $lines, $acts, $ledger ) {
				$people   = Plugin::people();
				$who      = $people->get( $pid );
				$cat_memb = $ledger->category_id_of_kind( 'membership' );
				$renewing = false;
				foreach ( $lines as $l ) {
					$renewing = $renewing || (int) $l['category_id'] === $cat_memb;
				}
				foreach ( $lines as $l ) {
					// Un socio con la tessera non in regola prenota solo se la rinnova nello stesso incasso
					if ( ! empty( $l['session_id'] ) && $who && MemberType::is_member( $who['type'] ) && ! $renewing && ! $people->is_active_member( $pid ) ) {
						throw new \InvalidArgumentException( 'La tessera di ' . trim( $who['first_name'] . ' ' . $who['last_name'] ) . ' non è valida: aggiungi la quota associativa nello stesso incasso.' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- messaggio interno, mostrato solo dopo esc_html
					}
				}
				foreach ( $lines as $l ) { // corsi: tessera in regola (o rinnovata qui), socio non sospeso, iscrizione se manca
					if ( ! empty( $l['competence_month'] ) && ! empty( $l['activity_id'] ) ) {
						$cname = $who ? trim( $who['first_name'] . ' ' . $who['last_name'] ) : '';
						if ( $who && MemberType::is_member( $who['type'] ) && ! MemberType::is_auto_renewed( $who['type'] ) && ! $renewing && ! $people->is_active_member( $pid ) ) {
							throw new \InvalidArgumentException( $cname . ': per un corso la tessera deve essere valida (aggiungi la quota associativa nello stesso incasso).' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- messaggio interno, mostrato solo dopo esc_html
						}
						if ( $people->is_suspended( $pid ) ) {
							throw new \InvalidArgumentException( $cname . ' è sospeso (inattivo): va riattivato prima di iscriverlo a un corso.' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- messaggio interno, mostrato solo dopo esc_html
						}
						if ( ! in_array( (int) $l['activity_id'], $acts->active_activity_ids( $pid ), true ) ) {
							$acts->enroll( (int) $l['activity_id'], $pid, (string) $l['competence_month'] );
						}
					}
				}
				foreach ( $lines as $l ) {
					if ( ! empty( $l['session_id'] ) && ! $acts->has_active_booking( (int) $l['session_id'], $pid ) ) {
						$acts->book( (int) $l['session_id'], $pid, array( 'approved' => true ) ); // incasso dello staff: chi paga un evento senza prenotazione viene prenotato (se c'e posto)
					}
				}
				$ledger->record_receipt( array( 'date' => current_time( 'Y-m-d' ), 'account_id' => (int) ( $post['account_id'] ?? 0 ), 'method' => '', 'person_id' => $pid, 'check_due' => true, 'lines' => $lines ) );
			}
		);
		return 'Incasso registrato.';
	}

	/** Importo standard di una voce (quando il tesoriere lascia l'importo vuoto). */
	public static function standard_cents( string $kind, int $ref, ?array $person ): int {
		$type = $person ? (string) $person['type'] : MemberType::GUEST;
		if ( 'membership' === $kind ) {
			return \AssociazioneSemplice\Levels::fee_for( $person );
		}
		if ( 'event' === $kind ) {
			$s = Plugin::activities()->session( $ref );
			$a = $s ? Plugin::activities()->get( (int) $s['activity_id'] ) : null;
			if ( ! $a ) {
				return 0;
			}
			if ( $person && Plugin::activities()->has_active_booking( $ref, (int) $person['id'] ) ) {
				foreach ( Plugin::activities()->bookings_for_session( $ref ) as $row ) {
					if ( (int) $row['person_id'] === (int) $person['id'] ) {
						return (int) $row['remaining'];
					}
				}
			}
			return Plugin::activities()->fee_for( $a, $type );
		}
		$a = Plugin::activities()->get( $ref );
		if ( $a && $person && 'course' === $kind && in_array( (string) ( $a['billing'] ?? 'monthly' ), array( 'monthly', 'lessons' ), true ) ) { // chi ha fatto la prova nel mese paga da quel giorno
			return Plugin::activities()->course_month_fee( $a, $person, Settings::social_year()->clamp( substr( current_time( 'Y-m-d' ), 0, 7 ) ) );
		}
		return $a ? Plugin::activities()->fee_for( $a, $type ) : 0;
	}



	/** Registra (o annulla) l'ingresso di una persona prenotata: solo per chi gestisce l'evento (referente, gestori indicati, amministratori). */
	public static function do_checkin( array $post ): string {
		$sid     = (int) ( $post['session_id'] ?? 0 );
		$pid     = (int) ( $post['person_id'] ?? 0 );
		$session = Plugin::activities()->session( $sid );
		if ( ! $session ) {
			throw new \InvalidArgumentException( 'Questa data non c\'è più: ricarica la pagina.' );
		}
		self::require_cap( 'asem_manage_event', (int) $session['activity_id'] );
		$r = Plugin::activities()->check_in( $sid, $pid, ! empty( $post['undo'] ), current_user_can( Plugin::CAP_OPS ) );
		return self::checkin_message( $r, $sid, $pid );
	}


	/** Ingresso da QR scansionato: accetta l'indirizzo letto dal QR (o il solo codice). */
	public static function do_checkin_scan( array $post ): string {
		$raw = trim( (string) ( $post['ticket'] ?? '' ) );
		if ( false !== strpos( $raw, 'asem_ticket=' ) ) {
			parse_str( (string) wp_parse_url( $raw, PHP_URL_QUERY ), $q );
			$raw = (string) ( $q['asem_ticket'] ?? '' );
		}
		$t = CardToken::ticket_parse( $raw );
		if ( ! $t || ! CardToken::ticket_valid( $t[0], $t[1], $t[2], Settings::card_secret_for( $t[1] ) ) ) {
			throw new \InvalidArgumentException( 'QR non valido: non è un biglietto di questo sito.' );
		}
		$session = Plugin::activities()->session( $t[0] );
		if ( ! $session ) {
			throw new \InvalidArgumentException( 'Questa prenotazione non c\'è più: ricarica la pagina.' );
		}
		self::require_cap( 'asem_manage_event', (int) $session['activity_id'] );
		return self::checkin_message( Plugin::activities()->check_in( $t[0], $t[1], false, current_user_can( Plugin::CAP_OPS ) ), $t[0], $t[1] );
	}

	/** Il socio vota: un solo voto per votazione, solo se ha diritto di voto (controllo in Polls). */
	public static function do_vote( array $post ): string {
		$actor   = self::actor();
		$choices = isset( $post['choice'] ) ? (array) $post['choice'] : array();
		\AssociazioneSemplice\Polls::vote( (int) ( $post['poll_id'] ?? 0 ), (int) $actor['id'], array_map( 'intval', $choices ) );
		return 'Voto registrato. Grazie.';
	}

	/** Avviso agli iscritti di un'attività: solo chi la tiene o chi gestisce l'evento (e gli amministratori). */
	public static function do_notice( array $post ): string {
		$aid = (int) ( $post['activity_id'] ?? 0 );
		if ( ! \AssociazioneSemplice\Notices::can_send( $aid ) ) {
			throw new \InvalidArgumentException( 'Non hai il permesso di inviare avvisi per questa attività.' );
		}
		\AssociazioneSemplice\Contacts::assert_can_write(); // invio manuale: servono i contatti della segreteria
		$r = \AssociazioneSemplice\Notices::send( $aid, ! empty( $post['session_id'] ) ? (int) $post['session_id'] : null, (string) ( $post['subject'] ?? '' ), (string) ( $post['body'] ?? '' ) );
		return 'Avviso inviato a ' . $r['recipients'] . ( 1 === $r['recipients'] ? ' persona' : ' persone' ) . ( $r['emailed'] < $r['recipients'] ? ' (' . ( $r['recipients'] - $r['emailed'] ) . ' email non inviate)' : '' ) . '.';
	}

	public static function do_add_guest( array $post ): string {
		$actor = self::actor();
		self::require_cap( 'asem_add_guest', (int) $actor['id'] );
		self::add_guest_checked( $actor, (string) ( $post['first_name'] ?? '' ), (string) ( $post['last_name'] ?? '' ), (string) ( $post['phone'] ?? '' ), (string) ( $post['email'] ?? '' ) ); // le stesse regole dell'invio in gruppo
		return 'Ospite aggiunto.';
	}

	public static function do_profile( array $post ): string {
		$actor = self::actor();
		\AssociazioneSemplice\MemberThrottle::hit( 'profile', (int) $actor['id'], 'member_profile_per_10min' );
		self::require_cap( 'asem_edit_own_profile', (int) $actor['id'] );
		$people = Plugin::people();
		$in     = array(
			'phone' => $post['phone'] ?? '', 'tax_code' => $post['tax_code'] ?? '', 'address' => $post['address'] ?? '', 'zip' => $post['zip'] ?? '',
			'city'  => $post['city'] ?? '', 'province' => $post['province'] ?? '',
		);
		$in['tax_code'] = \AssociazioneSemplice\TaxCode::normalize( (string) $in['tax_code'] );
		if ( '' !== $in['tax_code'] && ! \AssociazioneSemplice\TaxCode::is_valid( $in['tax_code'] ) ) {
			throw new \InvalidArgumentException( 'Il codice fiscale non è valido: controllalo (16 caratteri).' );
		}
		if ( '' !== (string) $in['zip'] && ! preg_match( '/^[0-9A-Za-z\- ]{3,12}$/', (string) $in['zip'] ) ) {
			throw new \InvalidArgumentException( 'Il CAP non è valido.' );
		}
		$people->update( (int) $actor['id'], $in );
		if ( ! empty( $post['booking_by_others_shown'] ) ) { // la spunta c'era nel modulo: spenta = nessun altro socio può prenotare per questa persona
			$people->set_no_booking_by_others( (int) $actor['id'], empty( $post['booking_by_others'] ) );
		}
		$fresh = $people->get( (int) $actor['id'] );
		if ( ! empty( $actor['profile_due'] ) && $fresh && $people->profile_complete( $fresh ) ) {
			$people->set_profile_due( (int) $actor['id'], false ); // dati completi: nessun blocco
			return 'Profilo completato: ora puoi prenotare e pagare.';
		}
		return 'Profilo aggiornato.';
	}
}
