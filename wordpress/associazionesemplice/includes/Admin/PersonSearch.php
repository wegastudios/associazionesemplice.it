<?php
namespace AssociazioneSemplice\Admin;

use AssociazioneSemplice\Db;
use AssociazioneSemplice\MemberType;
use AssociazioneSemplice\PersonSearchRules;
use AssociazioneSemplice\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * Ricerca minima delle persone (almeno tre lettere, al più dieci risultati, solo nome, tessera e stato della quota) per chi sceglie chi paga o chi
 * iscrivere senza avere l'area «Anagrafica soci». Le regole stanno in {@see PersonSearchRules}.
 */
final class PersonSearch {

	const NONCE = 'asem_person_search';

	public static function register(): void {
		add_action( 'wp_ajax_asem_person_search', array( __CLASS__, 'ajax' ) );
	}

	/** @return array<int,array{id:int,label:string,type:string,quota:string}> */
	public static function find( string $q ): array {
		$clean = PersonSearchRules::clean( $q );
		if ( ! PersonSearchRules::is_searchable( $clean ) ) {
			return array();
		}
		// la ricerca in anagrafica porta solo le persone che corrispondono al nome o alla tessera, poi si tengono i primi dieci
		return PersonSearchRules::results(
			Plugin::people()->search( array( 'q' => $clean ) ),
			$clean,
			Db::today(),
			array( MemberType::class, 'is_member' ),
			array( Ui::class, 'person_label' )
		);
	}

	public static function ajax(): void {
		check_ajax_referer( self::NONCE, 'nonce' );
		if ( ! current_user_can( Plugin::CAP_OPS ) ) {
			wp_send_json_error( 'Non hai il permesso per questa operazione: chiedilo a un amministratore.', 403 );
		}
		$q = isset( $_POST['q'] ) ? sanitize_text_field( wp_unslash( $_POST['q'] ) ) : '';
		wp_send_json_success( array( 'results' => self::find( $q ) ) );
	}

	/** Il campo di ricerca da mettere accanto a un elenco a discesa (il suo id è $select_id). */
	public static function input( string $select_id ): string {
		return '<input type="search" class="asem-minsearch" data-target="#' . esc_attr( $select_id ) . '" data-nonce="' . esc_attr( wp_create_nonce( self::NONCE ) ) . '" placeholder="'
			. esc_attr( \AssociazioneSemplice\Texts::t( 'Cerca per nome o tessera (almeno 3 lettere)' ) ) . '" autocomplete="off"> ';
	}
}
