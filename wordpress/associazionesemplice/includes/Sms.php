<?php
namespace AssociazioneSemplice;

defined( 'ABSPATH' ) || exit;

/**
 * SMS ai soci (funzione Pro): coda, invio, nuovi tentativi e registro. Gli SMS partono dalle comunicazioni di massa e, se l'associazione lo sceglie,
 * come copia breve dei promemoria. Si manda solo a chi ha un numero valido e ha dato il consenso agli SMS; chi lo revoca esce subito dalla coda.
 * Il fornitore (Brevo, Twilio o uno aggiunto con il filtro `asem_sms_providers`) si sceglie in Strumenti → Integrazioni.
 */
final class Sms {

	const HOOK       = 'asem_sms_queue';
	const BATCH      = 20;
	const KEEP_DAYS  = 120;
	const CREDITS_TR = 'asem_sms_credits';

	/** @var callable|null rete iniettabile per i test */
	private static $http = null;

	public static function set_http( ?callable $http ): void {
		self::$http = $http;
	}

	private static function http(): callable {
		return self::$http ?: array( Gateways::class, 'wp_http' );
	}

	private static function db(): \wpdb {
		return Db::db();
	}

	// ---------- Fornitore e impostazioni ----------

	/** Fornitori disponibili: id => classe. @return array<string,class-string<SmsProvider>> */
	public static function providers(): array {
		return (array) apply_filters( 'asem_sms_providers', array( 'brevo' => BrevoSms::class, 'twilio' => TwilioSms::class ) );
	}

	/** Chiavi di impostazione di tutti i fornitori: chiave => è un segreto. @return array<string,bool> */
	public static function setting_keys(): array {
		$out = array();
		foreach ( self::providers() as $class ) {
			foreach ( $class::fields() as $k => $label ) {
				$out[ $k ] = in_array( $k, $class::secret_fields(), true );
			}
		}
		return $out;
	}

	/** Impostazioni dei fornitori con i segreti in chiaro (uso interno: mai da stampare). */
	public static function config(): array {
		$c = array();
		foreach ( self::setting_keys() as $k => $secret ) {
			$c[ $k ] = $secret ? Settings::secret( $k ) : (string) Settings::get( $k );
		}
		$c['sms_sender'] = (string) Settings::get( 'sms_sender' );
		return $c;
	}

	public static function provider(): ?SmsProvider {
		$id    = (string) Settings::get( 'sms_provider' );
		$class = self::providers()[ $id ] ?? null;
		return $class ? new $class( self::http(), self::config() ) : null;
	}

	/** C'è un fornitore scelto e configurato? */
	public static function configured(): bool {
		$p = self::provider();
		return null !== $p && '' === $p->missing();
	}

	/** Si possono mandare SMS ora? (funzione disponibile con la licenza e fornitore pronto) */
	public static function available(): bool {
		return Edition::has( 'sms' ) && self::configured();
	}

	public static function sender(): string {
		$s = SmsRules::clean_sender( (string) Settings::get( 'sms_sender' ) );
		return '' !== $s ? $s : SmsRules::clean_sender( (string) Settings::get( 'association_name' ) );
	}

	public static function reminders_enabled(): bool {
		return ! empty( Settings::get( 'sms_reminders' ) );
	}

	// ---------- Destinatari ----------

	/**
	 * Chi può ricevere un SMS tra le persone date: numero valido e consenso. Un solo SMS per numero.
	 *
	 * @param array[] $people
	 * @return array{list:array[],no_phone:int,no_consent:int}  list: person_id, phone (E.164), name
	 */
	public static function recipients_from( array $people ): array {
		$consent = Consents::granted_map( ConsentRules::SMS );
		$seen    = array();
		$list    = array();
		$no_ph   = 0;
		$no_cons = 0;
		foreach ( $people as $p ) {
			if ( ! empty( $p['anonymized_at'] ) ) {
				continue;
			}
			$phone = SmsRules::e164( (string) ( $p['phone'] ?? '' ) );
			if ( '' === $phone ) {
				$no_ph++;
				continue;
			}
			if ( empty( $consent[ (int) $p['id'] ] ) ) {
				$no_cons++;
				continue;
			}
			if ( isset( $seen[ $phone ] ) ) {
				continue;
			}
			$seen[ $phone ] = true;
			$list[]         = array( 'person_id' => (int) $p['id'], 'phone' => $phone, 'name' => trim( (string) $p['first_name'] . ' ' . (string) $p['last_name'] ) );
		}
		return array( 'list' => $list, 'no_phone' => $no_ph, 'no_consent' => $no_cons );
	}

	// ---------- Coda ----------

	/** Mette un SMS in coda. @return int id della riga */
	public static function enqueue( int $person_id, string $phone, string $name, string $text, string $kind, ?int $broadcast_id = null ): int {
		$db = self::db();
		$db->insert(
			Db::t( 'sms_queue' ),
			array(
				'broadcast_id' => $broadcast_id, 'person_id' => $person_id ?: null, 'kind' => mb_substr( $kind, 0, 12 ), 'phone' => mb_substr( $phone, 0, 20 ), 'name' => mb_substr( $name, 0, 120 ),
				'body' => $text, 'status' => 'queued', 'attempts' => 0, 'created_at' => Db::now(),
			)
		);
		return (int) $db->insert_id;
	}

	/**
	 * Manda il prossimo gruppo di SMS in coda.
	 *
	 * @return array{sent:int,failed:int,retry:int,skipped:int}
	 */
	public static function process( ?int $limit = null, ?int $broadcast_id = null ): array {
		$out = array( 'sent' => 0, 'failed' => 0, 'retry' => 0, 'skipped' => 0 );
		if ( ! self::available() ) {
			return $out; // senza licenza o senza fornitore la coda resta ferma: riprende da sola
		}
		$provider = self::provider();
		$db       = self::db();
		$tbl      = Db::t( 'sms_queue' );
		$where    = "status = 'queued' AND (next_try_at IS NULL OR next_try_at <= %s)" . ( null !== $broadcast_id ? $db->prepare( ' AND broadcast_id = %d', $broadcast_id ) : '' );
		$rows     = $db->get_results( $db->prepare( "SELECT * FROM $tbl WHERE $where ORDER BY id LIMIT %d", Db::now(), $limit ?: self::BATCH ), ARRAY_A ) ?: array(); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $where è già preparato
		$sender   = self::sender();
		foreach ( $rows as $r ) {
			if ( 1 !== (int) $db->update( $tbl, array( 'status' => 'sending' ), array( 'id' => (int) $r['id'], 'status' => 'queued' ) ) ) {
				continue; // lo sta mandando un altro passaggio
			}
			if ( ! empty( $r['person_id'] ) && 'test' !== $r['kind'] && ! Consents::has( (int) $r['person_id'], ConsentRules::SMS ) ) {
				$db->update( $tbl, array( 'status' => 'skipped', 'error' => 'Consenso revocato.' ), array( 'id' => (int) $r['id'] ) );
				$out['skipped']++;
				continue;
			}
			try {
				$res = $provider->send( (string) $r['phone'], (string) $r['body'], $sender );
			} catch ( \Throwable $e ) {
				$res = array( 'ok' => false, 'ref' => '', 'error' => mb_substr( $e->getMessage(), 0, 240 ), 'permanent' => false, 'parts' => 0 );
			}
			$attempts = (int) $r['attempts'] + 1;
			if ( $res['ok'] ) {
				$db->update( $tbl, array( 'status' => 'sent', 'attempts' => $attempts, 'provider' => $provider->id(), 'provider_ref' => mb_substr( (string) $res['ref'], 0, 80 ), 'parts' => (int) $res['parts'], 'error' => null, 'sent_at' => Db::now(), 'next_try_at' => null ), array( 'id' => (int) $r['id'] ) );
				$out['sent']++;
				continue;
			}
			$wait = SmsRules::retry_after_minutes( $attempts, ! empty( $res['permanent'] ) );
			if ( null === $wait ) {
				$db->update( $tbl, array( 'status' => 'failed', 'attempts' => $attempts, 'provider' => $provider->id(), 'error' => mb_substr( (string) $res['error'], 0, 250 ), 'next_try_at' => null ), array( 'id' => (int) $r['id'] ) );
				$out['failed']++;
			} else {
				$db->update( $tbl, array( 'status' => 'queued', 'attempts' => $attempts, 'provider' => $provider->id(), 'error' => mb_substr( (string) $res['error'], 0, 250 ), 'next_try_at' => gmdate( 'Y-m-d H:i:s', strtotime( Db::now() ) + $wait * 60 ) ), array( 'id' => (int) $r['id'] ) );
				$out['retry']++;
			}
		}
		delete_transient( self::CREDITS_TR );
		if ( self::pending() > 0 && ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_single_event( time() + 120, self::HOOK );
		}
		return $out;
	}

	/** Lavoro periodico: manda gli SMS in coda (compresi i nuovi tentativi) e ripulisce il registro vecchio. */
	public static function job(): void {
		if ( ! Edition::has( 'sms' ) ) {
			return;
		}
		self::process( 100 );
		$old = gmdate( 'Y-m-d H:i:s', time() - self::KEEP_DAYS * DAY_IN_SECONDS );
		self::db()->query( self::db()->prepare( 'DELETE FROM ' . Db::t( 'sms_queue' ) . " WHERE status IN ('sent','failed','skipped') AND created_at < %s", $old ) );
	}

	/** SMS ancora da mandare (in coda, anche in attesa di un nuovo tentativo). */
	public static function pending(): int {
		return (int) self::db()->get_var( 'SELECT COUNT(*) FROM ' . Db::t( 'sms_queue' ) . " WHERE status IN ('queued','sending')" );
	}

	/** Rimette in coda gli SMS non partiti (di una comunicazione o tutti). @return int rimessi in coda */
	public static function retry_failed( ?int $broadcast_id = null ): int {
		$db = self::db();
		$n  = (int) $db->query( 'UPDATE ' . Db::t( 'sms_queue' ) . " SET status = 'queued', attempts = 0, next_try_at = NULL, error = NULL WHERE status = 'failed'" . ( null !== $broadcast_id ? $db->prepare( ' AND broadcast_id = %d', $broadcast_id ) : '' ) );
		if ( $n > 0 ) {
			self::process( null, $broadcast_id );
		}
		return $n;
	}

	// ---------- Registro e conteggi ----------

	/** Ultimi SMS, dal più recente. @return array[] */
	public static function recent( int $limit = 30, ?int $broadcast_id = null ): array {
		$db = self::db();
		$w  = null !== $broadcast_id ? $db->prepare( ' WHERE broadcast_id = %d', $broadcast_id ) : '';
		return $db->get_results( $db->prepare( 'SELECT * FROM ' . Db::t( 'sms_queue' ) . $w . ' ORDER BY id DESC LIMIT %d', $limit ), ARRAY_A ) ?: array(); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $w è già preparato
	}

	/** Conteggio per stato di una comunicazione (o di tutto il registro). @return array<string,int> */
	public static function counts( ?int $broadcast_id = null ): array {
		$db   = self::db();
		$w    = null !== $broadcast_id ? $db->prepare( ' WHERE broadcast_id = %d', $broadcast_id ) : '';
		$rows = $db->get_results( 'SELECT status, COUNT(*) AS n FROM ' . Db::t( 'sms_queue' ) . $w . ' GROUP BY status', ARRAY_A ) ?: array(); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $w è già preparato
		$out  = array( 'queued' => 0, 'sending' => 0, 'sent' => 0, 'failed' => 0, 'skipped' => 0 );
		foreach ( $rows as $r ) {
			$out[ (string) $r['status'] ] = (int) $r['n'];
		}
		return $out;
	}

	/** SMS e messaggi (parti) inviati dall'inizio del mese corrente. @return array{sms:int,parts:int} */
	public static function month_usage(): array {
		$db    = self::db();
		$start = gmdate( 'Y-m-01 00:00:00', strtotime( Db::now() ) );
		$row   = $db->get_row( $db->prepare( 'SELECT COUNT(*) AS n, COALESCE(SUM(parts),0) AS p FROM ' . Db::t( 'sms_queue' ) . " WHERE status = 'sent' AND sent_at >= %s", $start ), ARRAY_A );
		return array( 'sms' => (int) ( $row['n'] ?? 0 ), 'parts' => (int) ( $row['p'] ?? 0 ) );
	}

	/** Crediti o saldo residui secondo il fornitore (in memoria per qualche minuto). Null se il fornitore non li dà o non risponde. */
	public static function credits(): ?string {
		$cached = get_transient( self::CREDITS_TR );
		if ( is_array( $cached ) ) {
			return $cached['v'];
		}
		$p = self::provider();
		if ( ! $p || '' !== $p->missing() ) {
			return null;
		}
		try {
			$c = $p->check();
		} catch ( \Throwable $e ) {
			return null;
		}
		$v = $c['ok'] ? $c['credits'] : null;
		set_transient( self::CREDITS_TR, array( 'v' => $v ), 10 * MINUTE_IN_SECONDS );
		return $v;
	}

	/** Prova di collegamento (e crediti) per il pulsante in amministrazione. @return array{ok:bool,message:string,credits:?string} */
	public static function test(): array {
		$p = self::provider();
		if ( ! $p ) {
			return array( 'ok' => false, 'message' => 'Scegli prima un fornitore di SMS.', 'credits' => null );
		}
		if ( '' !== $p->missing() ) {
			return array( 'ok' => false, 'message' => $p->missing(), 'credits' => null );
		}
		try {
			$r = $p->check();
		} catch ( \Throwable $e ) {
			return array( 'ok' => false, 'message' => 'Connessione non riuscita: ' . $e->getMessage(), 'credits' => null );
		}
		delete_transient( self::CREDITS_TR );
		return $r;
	}

	// ---------- Promemoria ----------

	/**
	 * Un promemoria è partito per email: se l'associazione ha scelto di mandarlo anche per SMS, lo si accoda a chi ha quell'indirizzo, un numero e il consenso.
	 * Si aggancia all'azione `asem_notify_email` dei promemoria (che porta indirizzo, oggetto e testo).
	 */
	public static function on_reminder( $email, $subject, $body ): void {
		if ( ! self::reminders_enabled() || ! self::available() || '' === (string) $email ) {
			return;
		}
		$db  = self::db();
		$ids = $db->get_col( $db->prepare( 'SELECT id FROM ' . Db::t( 'people' ) . ' WHERE email = %s AND anonymized_at IS NULL LIMIT 5', (string) $email ) ) ?: array();
		$assoc = (string) Settings::get( 'association_name' );
		foreach ( self::recipients_from( array_values( array_filter( array_map( function ( $id ) {
			return Plugin::people()->get( (int) $id );
		}, $ids ) ) ) )['list'] as $r ) {
			self::enqueue( $r['person_id'], $r['phone'], $r['name'], SmsRules::reminder_text( $assoc, (string) $subject, (string) $body ), 'reminder' );
		}
		self::process( 5 );
	}

	// ---------- Dati della persona ----------

	public static function forget( int $person_id ): void {
		self::db()->delete( Db::t( 'sms_queue' ), array( 'person_id' => $person_id ) );
	}
}
