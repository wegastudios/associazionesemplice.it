<?php
namespace AssociazioneSemplice;

defined( 'ABSPATH' ) || exit;

/**
 * Consensi a SMS e newsletter, per persona e per canale. Senza consenso non parte nessun SMS e nessun dato va a un servizio di newsletter.
 * Il consenso si può revocare in ogni momento (dall'area soci o dalla segreteria); la revoca ferma subito gli invii e toglie la persona dalla lista.
 */
final class Consents {

	private static function db(): \wpdb {
		return Db::db();
	}

	public static function get( int $person_id, string $channel ): ?array {
		$r = self::db()->get_row( self::db()->prepare( 'SELECT * FROM ' . Db::t( 'consents' ) . ' WHERE person_id = %d AND channel = %s', $person_id, $channel ), ARRAY_A );
		return $r ?: null;
	}

	public static function has( int $person_id, string $channel ): bool {
		return ConsentRules::is_granted( self::get( $person_id, $channel ) );
	}

	/** Persone con il consenso valido per un canale: id => true. @return array<int,bool> */
	public static function granted_map( string $channel ): array {
		$rows = self::db()->get_results( self::db()->prepare( 'SELECT person_id FROM ' . Db::t( 'consents' ) . ' WHERE channel = %s AND granted_at IS NOT NULL AND (revoked_at IS NULL OR revoked_at < granted_at)', $channel ), ARRAY_A ) ?: array();
		$out  = array();
		foreach ( $rows as $r ) {
			$out[ (int) $r['person_id'] ] = true;
		}
		return $out;
	}

	/** Dà o revoca il consenso. @throws \InvalidArgumentException */
	public static function set( int $person_id, string $channel, bool $granted, string $source = 'admin' ): void {
		if ( ! ConsentRules::is_channel( $channel ) ) {
			throw new \InvalidArgumentException( 'Canale non valido.' );
		}
		$person = Plugin::people()->get( $person_id );
		if ( ! $person || ! empty( $person['anonymized_at'] ) ) {
			throw new \InvalidArgumentException( 'Persona non trovata.' );
		}
		$db  = self::db();
		$tbl = Db::t( 'consents' );
		$now = Db::now();
		$src = ConsentRules::source( $source );
		$by  = get_current_user_id() ?: null;
		if ( $granted ) {
			$db->query( $db->prepare( "INSERT INTO $tbl (person_id, channel, granted_at, revoked_at, source, updated_by) VALUES (%d, %s, %s, NULL, %s, %d) ON DUPLICATE KEY UPDATE granted_at = VALUES(granted_at), revoked_at = NULL, source = VALUES(source), updated_by = VALUES(updated_by)", $person_id, $channel, $now, $src, (int) $by ) );
		} else {
			$db->query( $db->prepare( "INSERT INTO $tbl (person_id, channel, granted_at, revoked_at, source, updated_by) VALUES (%d, %s, NULL, %s, %s, %d) ON DUPLICATE KEY UPDATE revoked_at = VALUES(revoked_at), source = VALUES(source), updated_by = VALUES(updated_by)", $person_id, $channel, $now, $src, (int) $by ) );
		}
		Audit::log( $granted ? 'consent.granted' : 'consent.revoked', 'person', $person_id, array( 'channel' => $channel, 'source' => $src ) );
		do_action( 'asem_consent_changed', $person_id, $channel, $granted ); // la newsletter toglie subito chi ha revocato
	}

	/** La persona è stata anonimizzata: i consensi si cancellano. */
	public static function forget( int $person_id ): void {
		self::db()->delete( Db::t( 'consents' ), array( 'person_id' => $person_id ) );
	}

	public static function privacy_export( array $data, int $person_id ): array {
		$out = array();
		foreach ( ConsentRules::channels() as $ch => $label ) {
			$r = self::get( $person_id, $ch );
			if ( $r ) {
				$out[] = array( 'canale' => $label, 'consenso_il' => $r['granted_at'], 'revocato_il' => $r['revoked_at'], 'modalita' => ConsentRules::SOURCES[ $r['source'] ] ?? '' );
			}
		}
		$data['consensi_sms_newsletter'] = $out;
		return $data;
	}
}
