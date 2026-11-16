<?php
namespace AssociazioneSemplice;

defined( 'ABSPATH' ) || exit;

/**
 * Ripulisce all'ingresso i testi semplici che arrivano da un ripristino di una copia di sicurezza: stesse regole dei servizi (niente tag).
 * Vale solo per le colonne di testo libero scritte a mano dagli utenti; nomi di colonna e di tabella sono fissi.
 */
final class RowClean {

	/** Nome della tabella (senza prefisso del database) => colonne di testo semplice. */
	const COLUMNS = array(
		'asem_people'       => array( 'card_number', 'first_name', 'last_name', 'email', 'phone', 'tax_code', 'birth_place', 'address', 'zip', 'city', 'province', 'left_reason', 'notes' ),
		'asem_activities'   => array( 'name', 'sport', 'discipline', 'location', 'notes' ),
		'asem_transactions' => array( 'description', 'document_ref', 'void_reason' ),
		'asem_accounts'     => array( 'name' ),
		'asem_categories'   => array( 'name' ),
	);

	/** @return array la riga con i testi semplici senza tag (i valori non testuali e le altre colonne restano come sono) */
	public static function row( string $table, array $row ): array {
		foreach ( self::COLUMNS[ $table ] ?? array() as $col ) {
			if ( isset( $row[ $col ] ) && is_string( $row[ $col ] ) ) {
				$row[ $col ] = Text::plain( $row[ $col ] );
			}
		}
		return $row;
	}
}
