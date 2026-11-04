<?php
namespace AssociazioneSemplice;

defined( 'ABSPATH' ) || exit;

/**
 * Newsletter (funzione Pro): sincronizza i soci con Brevo, Mailchimp o MailPoet. Parte a mano (pulsante in Strumenti → Integrazioni) e con un
 * lavoro periodico. Nessun dato lascia il sito senza il consenso alla newsletter: chi lo revoca, cambia indirizzo o viene anonimizzato si toglie dalla lista.
 */
final class Newsletter {

	const HOOK         = 'asem_newsletter_sync';
	const OPT_LAST     = 'asem_newsletter_last';
	const OPT_LISTS    = 'asem_newsletter_lists';
	const BATCH_MANUAL = 300;
	const BATCH_CRON   = 200;

	/** @var callable|null rete iniettabile per i test */
	private static $http = null;
	/** @var object|null API di MailPoet iniettabile per i test */
	private static $mailpoet = null;

	public static function set_http( ?callable $http ): void {
		self::$http = $http;
	}

	public static function set_mailpoet( ?object $api ): void {
		self::$mailpoet = $api;
	}

	private static function http(): callable {
		return self::$http ?: array( Gateways::class, 'wp_http' );
	}

	private static function db(): \wpdb {
		return Db::db();
	}

	// ---------- Servizio e impostazioni ----------

	/** @return array<string,class-string<NewsletterProvider>> */
	public static function providers(): array {
		return (array) apply_filters( 'asem_newsletter_providers', array( 'brevo' => BrevoNewsletter::class, 'mailchimp' => MailchimpNewsletter::class, 'mailpoet' => MailPoetNewsletter::class ) );
	}

	/** Chiavi di impostazione di tutti i servizi: chiave => è un segreto. @return array<string,bool> */
	public static function setting_keys(): array {
		$out = array();
		foreach ( self::providers() as $class ) {
			foreach ( $class::fields() as $k => $label ) {
				$out[ $k ] = in_array( $k, $class::secret_fields(), true );
			}
		}
		return $out;
	}

	public static function config(): array {
		$c = array();
		foreach ( self::setting_keys() as $k => $secret ) {
			$c[ $k ] = $secret ? Settings::secret( $k ) : (string) Settings::get( $k );
		}
		return $c;
	}

	public static function provider(): ?NewsletterProvider {
		$id    = (string) Settings::get( 'newsletter_provider' );
		$class = self::providers()[ $id ] ?? null;
		if ( ! $class ) {
			return null;
		}
		return MailPoetNewsletter::class === $class ? new MailPoetNewsletter( null, array(), self::$mailpoet ) : new $class( self::http(), self::config() );
	}

	public static function list_id(): string {
		return (string) Settings::get( 'newsletter_list' );
	}

	/** Servizio scelto, configurato e con una lista scelta? */
	public static function configured(): bool {
		$p = self::provider();
		return null !== $p && '' === $p->missing() && '' !== self::list_id();
	}

	public static function available(): bool {
		return Edition::has( 'newsletter' ) && self::configured();
	}

	public static function extra(): bool {
		return ! empty( Settings::get( 'newsletter_extra' ) );
	}

	/** Liste del servizio, lette ora e conservate per la scelta nel modulo. @return array<string,string> @throws \RuntimeException */
	public static function fetch_lists(): array {
		$p = self::provider();
		if ( ! $p ) {
			throw new \RuntimeException( 'Scegli prima un servizio di newsletter.' );
		}
		if ( '' !== $p->missing() ) {
			throw new \RuntimeException( $p->missing() ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- messaggio interno, mostrato solo dopo esc_html
		}
		$lists = $p->lists();
		update_option( self::OPT_LISTS, array( 'provider' => $p->id(), 'lists' => $lists ), false );
		return $lists;
	}

	/** Liste già lette per il servizio scelto. @return array<string,string> */
	public static function known_lists(): array {
		$o = (array) get_option( self::OPT_LISTS, array() );
		return ( $o['provider'] ?? '' ) === (string) Settings::get( 'newsletter_provider' ) ? (array) ( $o['lists'] ?? array() ) : array();
	}

	// ---------- Sincronizzazione ----------

	private static function rows(): array {
		$db   = self::db();
		$rows = $db->get_results( $db->prepare( 'SELECT * FROM ' . Db::t( 'newsletter_sync' ) . ' WHERE provider = %s AND list_id = %s', (string) Settings::get( 'newsletter_provider' ), self::list_id() ), ARRAY_A ) ?: array();
		$out  = array();
		foreach ( $rows as $r ) {
			$out[ (int) $r['person_id'] ] = $r;
		}
		return $out;
	}

	private static function save_row( int $person_id, array $data ): void {
		$db  = self::db();
		$key = array( 'person_id' => $person_id, 'provider' => (string) Settings::get( 'newsletter_provider' ), 'list_id' => self::list_id() );
		$row = $db->get_var( $db->prepare( 'SELECT id FROM ' . Db::t( 'newsletter_sync' ) . ' WHERE person_id = %d AND provider = %s AND list_id = %s', $key['person_id'], $key['provider'], $key['list_id'] ) );
		$data['synced_at'] = Db::now();
		if ( $row ) {
			$db->update( Db::t( 'newsletter_sync' ), $data, array( 'id' => (int) $row ) );
		} else {
			$db->insert( Db::t( 'newsletter_sync' ), $key + $data + array( 'email' => '', 'status' => NewsletterRules::ST_ERROR ) );
		}
	}

	/**
	 * Allinea la lista ai soci. Iscrive chi ha il consenso, aggiorna chi è cambiato, toglie chi non ha più diritto di starci.
	 * Lavora a gruppi: se le modifiche sono più di $max il resto si fa al passaggio successivo.
	 *
	 * @return array{added:int,updated:int,removed:int,errors:int,unchanged:int,partial:bool,message:string}
	 */
	public static function sync( int $max = self::BATCH_MANUAL ): array {
		$out = array( 'added' => 0, 'updated' => 0, 'removed' => 0, 'errors' => 0, 'unchanged' => 0, 'partial' => false, 'message' => '' );
		if ( ! self::available() ) {
			$out['message'] = 'La newsletter non è pronta: scegli il servizio e la lista.';
			return $out;
		}
		$p       = self::provider();
		$list    = self::list_id();
		$today   = current_time( 'Y-m-d' );
		$extra   = self::extra() && 'mailpoet' !== $p->id();
		$consent = Consents::granted_map( ConsentRules::NEWSLETTER );
		$rows    = self::rows();
		$people  = array();
		foreach ( Plugin::people()->search() as $person ) {
			$people[ (int) $person['id'] ] = $person;
		}
		$active_emails = array(); // gli indirizzi che restano iscritti: chi condivide l'indirizzo con un altro non lo fa togliere
		foreach ( $people as $id => $person ) {
			if ( NewsletterRules::eligible( $person, ! empty( $consent[ $id ] ) ) ) {
				$active_emails[ Text::lower( trim( (string) $person['email'] ) ) ] = true;
			}
		}
		$work = 0;
		foreach ( $people as $id => $person ) {
			$row    = $rows[ $id ] ?? null;
			$action = NewsletterRules::action( $person, ! empty( $consent[ $id ] ), $row, $today, $extra );
			if ( NewsletterRules::KEEP === $action ) {
				$out['unchanged']++;
				continue;
			}
			if ( NewsletterRules::SKIP === $action ) {
				continue;
			}
			if ( $work >= $max ) {
				$out['partial'] = true;
				break;
			}
			$work++;
			if ( NewsletterRules::REMOVE === $action ) {
				self::remove_row( $p, $list, $id, $row, $active_emails, $out );
				continue;
			}
			$contact = NewsletterRules::contact( $person, $today );
			try {
				if ( $row && NewsletterRules::ST_SUBSCRIBED === $row['status'] && '' !== (string) $row['email'] && (string) $row['email'] !== $contact['email'] && empty( $active_emails[ (string) $row['email'] ] ) ) {
					$p->remove( (string) $row['email'], $list ); // ha cambiato indirizzo: il vecchio esce dalla lista
				}
				$p->subscribe( $contact, $list, $extra );
				self::save_row( $id, array( 'email' => $contact['email'], 'status' => NewsletterRules::ST_SUBSCRIBED, 'hash' => NewsletterRules::hash( $contact, $extra ), 'error' => null ) );
				$row && NewsletterRules::ST_SUBSCRIBED === $row['status'] ? $out['updated']++ : $out['added']++;
			} catch ( \Throwable $e ) {
				self::save_row( $id, array( 'email' => $contact['email'], 'status' => NewsletterRules::ST_ERROR, 'hash' => null, 'error' => mb_substr( $e->getMessage(), 0, 240 ) ) );
				$out['errors']++;
			}
		}
		foreach ( $rows as $id => $row ) { // persone cancellate del tutto: via anche dalla lista
			if ( ! isset( $people[ $id ] ) && in_array( $row['status'], array( NewsletterRules::ST_SUBSCRIBED, NewsletterRules::ST_REMOVE_ERR ), true ) && $work < $max ) {
				$work++;
				self::remove_row( $p, $list, $id, $row, $active_emails, $out );
			}
		}
		update_option( self::OPT_LAST, array( 't' => time(), 'result' => $out ), false );
		$out['message'] = sprintf( 'Newsletter: %d iscritti, %d aggiornati, %d tolti, %d invariati%s%s.', $out['added'], $out['updated'], $out['removed'], $out['unchanged'], $out['errors'] ? ', ' . $out['errors'] . ' con errore' : '', $out['partial'] ? ' (altri al prossimo passaggio)' : '' );
		return $out;
	}

	/** Toglie dalla lista l'indirizzo di una riga. */
	private static function remove_row( NewsletterProvider $p, string $list, int $person_id, ?array $row, array $active_emails, array &$out ): void {
		if ( ! $row || '' === (string) $row['email'] ) {
			return;
		}
		try {
			if ( empty( $active_emails[ (string) $row['email'] ] ) ) {
				$p->remove( (string) $row['email'], $list );
			}
			self::save_row( $person_id, array( 'email' => '', 'status' => NewsletterRules::ST_REMOVED, 'hash' => null, 'error' => null ) ); // l'indirizzo non si conserva più
			$out['removed']++;
		} catch ( \Throwable $e ) {
			self::save_row( $person_id, array( 'status' => NewsletterRules::ST_REMOVE_ERR, 'error' => mb_substr( $e->getMessage(), 0, 240 ) ) ); // si riprova al prossimo passaggio
			$out['errors']++;
		}
	}

	/** Toglie subito una persona dalla lista (consenso revocato, persona anonimizzata). Se il servizio non risponde, lo fa il prossimo passaggio. */
	public static function drop_person( int $person_id ): void {
		if ( ! self::available() ) {
			return;
		}
		$rows = self::rows();
		$out  = array( 'removed' => 0, 'errors' => 0 );
		if ( isset( $rows[ $person_id ] ) && in_array( $rows[ $person_id ]['status'], array( NewsletterRules::ST_SUBSCRIBED, NewsletterRules::ST_REMOVE_ERR ), true ) ) {
			$active = array();
			$consent = Consents::granted_map( ConsentRules::NEWSLETTER );
			foreach ( Plugin::people()->search() as $person ) {
				if ( (int) $person['id'] !== $person_id && NewsletterRules::eligible( $person, ! empty( $consent[ (int) $person['id'] ] ) ) ) {
					$active[ Text::lower( trim( (string) $person['email'] ) ) ] = true;
				}
			}
			self::remove_row( self::provider(), self::list_id(), $person_id, $rows[ $person_id ], $active, $out );
		}
	}

	/** Un consenso è cambiato: se è stato revocato la persona esce subito dalla lista. */
	public static function on_consent_changed( $person_id, $channel, $granted ): void {
		if ( ConsentRules::NEWSLETTER === $channel && ! $granted ) {
			self::drop_person( (int) $person_id );
		}
	}

	/** Persona anonimizzata (l'email è già stata tolta dalla scheda: si usa quella conservata nella riga di sincronizzazione). */
	public static function on_anonymized( $person_id ): void {
		self::drop_person( (int) $person_id );
		self::db()->delete( Db::t( 'newsletter_sync' ), array( 'person_id' => (int) $person_id, 'status' => NewsletterRules::ST_REMOVED ) ); // se la rimozione non è riuscita la riga resta, con l'indirizzo, per il prossimo passaggio
	}

	/** Lavoro periodico. */
	public static function job(): void {
		if ( Edition::has( 'newsletter' ) && ! empty( Settings::get( 'newsletter_auto' ) ) && self::configured() ) {
			self::sync( self::BATCH_CRON );
		}
	}

	// ---------- Stato ----------

	/** Iscritti, in errore e ultima sincronizzazione. @return array{subscribed:int,errors:int,last:?array} */
	public static function status(): array {
		$db   = self::db();
		$rows = $db->get_results( $db->prepare( 'SELECT status, COUNT(*) AS n FROM ' . Db::t( 'newsletter_sync' ) . ' WHERE provider = %s AND list_id = %s GROUP BY status', (string) Settings::get( 'newsletter_provider' ), self::list_id() ), ARRAY_A ) ?: array();
		$by   = array();
		foreach ( $rows as $r ) {
			$by[ (string) $r['status'] ] = (int) $r['n'];
		}
		$last = get_option( self::OPT_LAST, null );
		return array(
			'subscribed' => $by[ NewsletterRules::ST_SUBSCRIBED ] ?? 0,
			'errors'     => ( $by[ NewsletterRules::ST_ERROR ] ?? 0 ) + ( $by[ NewsletterRules::ST_REMOVE_ERR ] ?? 0 ),
			'last'       => is_array( $last ) ? $last : null,
		);
	}

	/** Prova di collegamento. @return array{ok:bool,message:string} */
	public static function test(): array {
		$p = self::provider();
		if ( ! $p ) {
			return array( 'ok' => false, 'message' => 'Scegli prima un servizio di newsletter.' );
		}
		if ( '' !== $p->missing() ) {
			return array( 'ok' => false, 'message' => $p->missing() );
		}
		try {
			return $p->check();
		} catch ( \Throwable $e ) {
			return array( 'ok' => false, 'message' => 'Connessione non riuscita: ' . $e->getMessage() );
		}
	}
}
