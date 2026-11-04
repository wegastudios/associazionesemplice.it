<?php
namespace AssociazioneSemplice\Admin;

use AssociazioneSemplice\Affiliations;
use AssociazioneSemplice\Certificates;
use AssociazioneSemplice\Documents;
use AssociazioneSemplice\Edition;
use AssociazioneSemplice\ExpiryRules;
use AssociazioneSemplice\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Gestori dei moduli di certificati medici e affiliazioni (funzioni di Associazione Semplice Pro). Si registrano su Actions::add_handler(): nonce e
 * permessi li controlla Associazione Semplice; senza la funzione l'azione si rifiuta con il messaggio standard.
 */
final class BlockCActions {

	/** Azione => [metodo, funzione, riservata all'amministratore]. */
	const MAP = array(
		'asem_cert_add'      => array( 'cert_add', 'medical', false ),
		'asem_cert_delete'   => array( 'cert_delete', 'medical', false ),
		'asem_aff_save'      => array( 'aff_save', 'affiliations', false ),
		'asem_aff_delete'    => array( 'aff_delete', 'affiliations', false ),
		'asem_card_add'      => array( 'card_add', 'affiliations', false ),
		'asem_card_update'   => array( 'card_update', 'affiliations', false ),
		'asem_card_delete'   => array( 'card_delete', 'affiliations', false ),
		'asem_card_bulk'     => array( 'card_bulk', 'affiliations', false ),
		'asem_save_cert_settings' => array( 'save_cert_settings', 'medical', true ),
		'asem_save_aff_settings'  => array( 'save_aff_settings', 'affiliations', true ),
	);

	public static function register(): void {
		foreach ( self::MAP as $action => $row ) {
			Actions::add_handler( $action, self::guarded( $row[0], $row[1] ), $row[2] );
		}
	}

	private static function guarded( string $method, string $feature ): callable {
		return function ( array $p ) use ( $method, $feature ) {
			if ( ! Edition::has( $feature ) ) {
				throw new \InvalidArgumentException( Edition::missing_message( $feature ) );
			}
			return self::$method( $p );
		};
	}

	/** Dove tornare: la scheda della persona se la richiesta viene da lì, altrimenti la pagina dell'elenco. */
	private static function back( array $p, string $page ): string {
		return ! empty( $p['_back'] ) ? esc_url_raw( (string) $p['_back'] ) : Ui::url( $page );
	}

	// ---------- Certificati medici ----------

	public static function cert_add( array $p ): array {
		Certificates::add( (int) ( $p['person_id'] ?? 0 ), $p, Documents::prepare_upload( 'file' ) );
		return array( self::back( $p, 'asem-medical' ), 'Certificato registrato.' );
	}

	public static function cert_delete( array $p ): array {
		Certificates::delete( (int) ( $p['id'] ?? 0 ) );
		return array( self::back( $p, 'asem-medical' ), 'Certificato eliminato.' );
	}

	public static function save_cert_settings( array $p ): array {
		Settings::update(
			array(
				'cert_reminders'   => ! empty( $p['cert_reminders'] ) ? 1 : 0,
				'cert_warn_days'   => ExpiryRules::warn_days( $p['cert_warn_days'] ?? 0 ),
				'cert_show_member' => ! empty( $p['cert_show_member'] ) ? 1 : 0,
			)
		);
		return array( Ui::url( 'asem-medical' ), 'Impostazioni dei certificati medici salvate.' );
	}

	// ---------- Affiliazioni e tessere ----------

	public static function aff_save( array $p ): array {
		Affiliations::save_affiliation( (int) ( $p['id'] ?? 0 ), $p );
		return array( Ui::url( 'asem-affiliations' ), 'Affiliazione salvata.' );
	}

	public static function aff_delete( array $p ): array {
		Affiliations::delete_affiliation( (int) ( $p['id'] ?? 0 ) );
		return array( Ui::url( 'asem-affiliations' ), 'Affiliazione eliminata.' );
	}

	public static function card_add( array $p ): array {
		Affiliations::add_card( (int) ( $p['person_id'] ?? 0 ), $p );
		return array( self::back( $p, 'asem-affiliations' ), 'Tessera registrata.' );
	}

	public static function card_update( array $p ): array {
		Affiliations::update_card(
			(int) ( $p['id'] ?? 0 ),
			array(
				'card_number' => (string) ( $p['card_number'] ?? '' ),
				'expires_on'  => (string) ( $p['expires_on'] ?? '' ),
			)
		);
		return array( self::back( $p, 'asem-affiliations' ), 'Tessera aggiornata.' );
	}

	public static function card_delete( array $p ): array {
		Affiliations::delete_card( (int) ( $p['id'] ?? 0 ) );
		return array( self::back( $p, 'asem-affiliations' ), 'Tessera eliminata.' );
	}

	public static function card_bulk( array $p ): array {
		$scope = (string) ( $p['scope'] ?? 'all' );
		$aid   = 'activity' === $scope ? (int) ( $p['activity_id'] ?? 0 ) : 0;
		if ( 'activity' === $scope && $aid <= 0 ) {
			throw new \InvalidArgumentException( 'Scegli l\'attività di cui tesserare gli iscritti.' );
		}
		$r = Affiliations::bulk_add( $p, $aid );
		return array(
			Ui::url( 'asem-affiliations' ),
			$r['created'] . ( 1 === $r['created'] ? ' tessera creata' : ' tessere create' ) . ( $r['skipped'] > 0 ? '; ' . $r['skipped'] . ' soci la avevano già' : '' ) . '. Il numero di tessera si aggiunge dall\'elenco, quando l\'ente lo assegna.',
		);
	}

	public static function save_aff_settings( array $p ): array {
		Settings::update(
			array(
				'aff_reminders' => ! empty( $p['aff_reminders'] ) ? 1 : 0,
				'aff_warn_days' => ExpiryRules::warn_days( $p['aff_warn_days'] ?? 0 ),
			)
		);
		return array( Ui::url( 'asem-affiliations' ), 'Impostazioni delle affiliazioni salvate.' );
	}
}
