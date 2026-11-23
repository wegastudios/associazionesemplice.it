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

	/**
	 * Il campo di ricerca da mettere accanto a un elenco a discesa (il suo id è $select_id). Con $members_only i risultati senza tessera (gli ospiti) non si propongono;
	 * con $name il campo porta il nome «$name_q»: senza JavaScript chi scrive il nome per intero e invia il modulo viene riconosciuto dal server ({@see resolve_typed()}).
	 */
	public static function input( string $select_id, bool $members_only = false, string $name = '' ): string {
		static $nonce = null; // lo stesso gettone per tutti i campi della pagina
		if ( null === $nonce ) {
			$nonce = wp_create_nonce( self::NONCE );
		}
		return '<input type="search" class="asem-minsearch" data-target="#' . esc_attr( $select_id ) . '" data-nonce="' . esc_attr( $nonce ) . '"' . ( $members_only ? ' data-members-only="1"' : '' )
			. ( '' !== $name ? ' name="' . esc_attr( $name ) . '_q"' : '' ) . ' placeholder="'
			. esc_attr( \AssociazioneSemplice\Texts::t( 'Cerca per nome o tessera (almeno 3 lettere)' ) ) . '" autocomplete="off"> ';
	}

	/**
	 * Senza JavaScript la scelta non arriva dall'elenco ma dal testo scritto nel campo «{chiave}_q»: se la persona non è stata scelta e il testo
	 * corrisponde a una sola persona, è lei. Altrimenti si chiede di essere più precisi. Chi non ha scritto nulla lascia il modulo com'è (l'azione dirà «scegli una persona»).
	 *
	 * @param array<string,mixed> $post
	 * @param string[]            $keys
	 * @return array<string,mixed>
	 */
	public static function resolve_typed( array $post, array $keys ): array {
		foreach ( $keys as $key ) {
			$text = isset( $post[ $key . '_q' ] ) ? PersonSearchRules::clean( $post[ $key . '_q' ] ) : '';
			if ( ! empty( $post[ $key ] ) || '' === $text ) {
				continue;
			}
			$found = self::find( $text );
			if ( 1 !== count( $found ) ) {
				$msg = $found
					? \AssociazioneSemplice\Texts::t( 'Più persone corrispondono a quel nome: scrivilo per intero o con il numero di tessera.' )
					: \AssociazioneSemplice\Texts::t( 'Nessuna persona trovata con quel nome: scrivi almeno tre lettere o il numero di tessera.' );
				throw new \InvalidArgumentException( $msg ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- messaggio interno, mostrato solo dopo esc_html
			}
			$post[ $key ] = (string) $found[0]['id'];
		}
		return $post;
	}
}
