<?php
namespace AssociazioneSemplice;

defined( 'ABSPATH' ) || exit;

/**
 * Dati dell'ente (identità, sede, partita IVA) letti da un modulo e controllati: li usano la scheda «Dati e fiscalità»
 * e la configurazione guidata, così le regole sono una sola. Con $full = false si considera solo ciò che il modulo contiene.
 */
final class EntityData {

	/**
	 * @param array $p    dati inviati
	 * @param bool  $full true: il modulo contiene tutti i campi (una casella non spuntata vale «no»); false: solo i campi presenti
	 * @return array valori per Settings::update()
	 * @throws \InvalidArgumentException
	 */
	public static function values( array $p, bool $full ): array {
		$txt  = function ( string $k ) use ( $p ): string {
			return sanitize_text_field( (string) ( $p[ $k ] ?? '' ) );
		};
		$has  = function ( string $k ) use ( $p, $full ): bool {
			return $full || array_key_exists( $k, $p );
		};
		$vals = array();

		foreach ( array( 'association_name', 'runts_number', 'legal_address', 'legal_zip', 'legal_city', 'legal_province' ) as $k ) {
			if ( $has( $k ) ) {
				$vals[ $k ] = $txt( $k );
			}
		}
		if ( $has( 'tax_code' ) ) {
			$cf = TaxCode::normalize( $txt( 'tax_code' ) );
			if ( '' !== $cf && ! Fiscal::is_valid_entity_tax_code( $cf ) ) {
				throw new \InvalidArgumentException( 'Il codice fiscale dell\'ente non è valido: controllalo (11 cifre, oppure 16 caratteri se è una persona fisica).' );
			}
			$vals['tax_code'] = $cf;
		}
		if ( $has( 'social_year_start_month' ) ) {
			$vals['social_year_start_month'] = (int) ( $p['social_year_start_month'] ?? 9 );
		}
		if ( $has( 'pec' ) ) {
			$pec = trim( $txt( 'pec' ) );
			if ( '' !== $pec && ! is_email( $pec ) ) {
				throw new \InvalidArgumentException( 'La PEC non è un indirizzo email valido.' );
			}
			$vals['pec'] = $pec;
		}
		return (array) apply_filters( 'asem_entity_values', $vals, $p, $full ); // le estensioni leggono e controllano i loro campi
	}
}
