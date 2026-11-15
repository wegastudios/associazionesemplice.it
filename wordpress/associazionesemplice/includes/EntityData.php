<?php
namespace AssociazioneSemplice;

defined( 'ABSPATH' ) || exit;

/**
 * Dati dell'ente (identità, sede, partita IVA) letti da un modulo e controllati: li usano la scheda «Dati e fiscalità»
 * e la configurazione guidata, così le regole sono una sola. Con $full = false si considera solo ciò che il modulo contiene,
 * tranne i dati obbligatori (vedi {@see EntityRules}): se il modulo porta la denominazione, tutti gli obbligatori si controllano.
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
		$errs = array();

		foreach ( array( 'association_name', 'runts_number', 'legal_address', 'legal_zip', 'legal_city', 'legal_province' ) as $k ) {
			if ( $has( $k ) ) {
				$vals[ $k ] = $txt( $k );
			}
		}
		if ( isset( $vals['legal_province'] ) ) {
			$vals['legal_province'] = strtoupper( trim( $vals['legal_province'] ) );
		}
		if ( $has( 'tax_code' ) ) {
			$vals['tax_code'] = TaxCode::normalize( $txt( 'tax_code' ) );
		}
		if ( $has( 'vat_number' ) ) { // la partita IVA è un dato dell'ente (anche senza le funzioni IVA)
			$vals['vat_number'] = Fiscal::normalize_vat( $txt( 'vat_number' ) );
		}
		if ( $has( 'entity_form' ) ) {
			$vals['entity_form'] = $txt( 'entity_form' );
		}
		if ( $has( 'runts_section' ) ) { // sezione del RUNTS e numero dei volontari inserito a mano, con l'anno a cui si riferisce
			$sec = $txt( 'runts_section' );
			$vals['runts_section'] = RuntsRules::section_valid( $sec ) ? $sec : '';
		}
		if ( $has( 'runts_volunteers' ) ) {
			$vals['runts_volunteers'] = max( 0, min( 1000000, (int) ( $p['runts_volunteers'] ?? 0 ) ) );
		}
		if ( $has( 'runts_manual_year' ) ) {
			$y = (int) ( $p['runts_manual_year'] ?? 0 );
			$vals['runts_manual_year'] = $y >= 2000 && $y <= 2100 ? $y : 0;
		}
		if ( $has( 'social_year_start_month' ) ) {
			$vals['social_year_start_month'] = max( 1, min( 12, (int) ( $p['social_year_start_month'] ?? 1 ) ) );
		}
		if ( $has( 'fiscal_start_month' ) ) {
			// Con esercizi chiusi il campo è disabilitato e non arriva nel modulo: il mese resta quello in vigore.
			$vals['fiscal_start_month'] = max( 1, min( 12, (int) ( $p['fiscal_start_month'] ?? FiscalYears::start_month() ) ) );
		}
		if ( $has( 'pec' ) ) {
			$pec = trim( $txt( 'pec' ) );
			if ( '' !== $pec && ! is_email( $pec ) ) {
				$errs[] = 'La PEC non è un indirizzo email valido.';
			}
			$vals['pec'] = $pec;
		}
		if ( $has( 'sdi_code' ) ) { // codice destinatario per la fattura elettronica: facoltativo
			$sdi = trim( $txt( 'sdi_code' ) );
			if ( '' !== $sdi && ! EntityRules::sdi_valid( $sdi ) ) {
				$errs[] = Texts::t( 'Il codice destinatario ha 7 caratteri (lettere e cifre).' );
			}
			$vals['sdi_code'] = strtoupper( $sdi );
		}

		if ( $full || array_key_exists( 'association_name', $p ) ) { // dati obbligatori: nella procedura guidata e nei dati dell'ente, anche lato server
			$vals['no_runts'] = ! empty( $p['no_runts'] ) ? 1 : 0;
			$vals['no_pec']   = ! empty( $p['no_pec'] ) ? 1 : 0;
			$vals['no_vat']   = ! empty( $p['no_vat'] ) ? 1 : 0;
			if ( '' !== (string) ( $vals['vat_number'] ?? '' ) ) {
				$vals['no_vat'] = 0;
			}
			if ( '' !== (string) ( $vals['runts_number'] ?? '' ) ) {
				$vals['no_runts'] = 0; // il dato c'è: la casella «non ce l'ha» non vale
			}
			if ( '' !== (string) ( $vals['pec'] ?? '' ) ) {
				$vals['no_pec'] = 0;
			}
			$errs = array_merge( $errs, array_diff( EntityRules::errors( $vals + array( 'association_name' => '' ) ), $errs ) );
			$form = (string) ( $vals['entity_form'] ?? '' );
			$sec  = (string) ( $vals['runts_section'] ?? Settings::get( 'runts_section' ) );
			if ( in_array( $form, array( 'aps', 'odv' ), true ) && '' === $sec ) {
				$vals['runts_section'] = $form; // forma APS/ODV e sezione vuota: la sezione si precompila
			}
		}
		if ( $has( 'secretary_email' ) || $has( 'secretary_phone' ) ) { // contatti della segreteria: obbligatori quando si salva la scheda dell'ente o si completa la procedura
			$mail  = strtolower( trim( sanitize_text_field( (string) ( $p['secretary_email'] ?? '' ) ) ) );
			$phone = trim( sanitize_text_field( (string) ( $p['secretary_phone'] ?? '' ) ) );
			$errs  = array_merge( $errs, Contacts::validate( $mail, $phone ) );
			$vals['secretary_email'] = $mail;
			$vals['secretary_phone'] = $phone;
		}
		try {
			$vals = (array) apply_filters( 'asem_entity_values', $vals, $p, $full ); // le estensioni leggono e controllano i loro campi
		} catch ( \InvalidArgumentException $e ) {
			$errs[] = $e->getMessage();
		}
		if ( $errs ) {
			throw new \InvalidArgumentException( implode( ' ', $errs ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- messaggio interno, mostrato solo dopo esc_html
		}
		return $vals;
	}
}
