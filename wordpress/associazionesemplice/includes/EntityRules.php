<?php
namespace AssociazioneSemplice;

defined( 'ABSPATH' ) || exit;

/**
 * Dati obbligatori dell'ente: forma, codice fiscale, sede, legale rappresentante, contatti della segreteria e, solo se esistono,
 * partita IVA, numero RUNTS e PEC (ognuno con la casella «non ce l'ha»).
 * Le stesse regole valgono nella configurazione guidata e in Impostazioni › Ente, anche lato server; ciò che manca compare nella banda fissa (MissingBand).
 */
final class EntityRules {

	/** Chiavi delle forme dell'ente (le etichette sono in {@see forms()}). */
	const FORM_KEYS = array( 'asd', 'aps', 'odv', 'ets', 'circolo', 'altro' );

	/** @return array<string,string> forma => etichetta */
	public static function forms(): array {
		return array(
			'asd'     => Texts::t( 'ASD – associazione sportiva dilettantistica' ),
			'aps'     => Texts::t( 'APS – associazione di promozione sociale' ),
			'odv'     => Texts::t( 'ODV – organizzazione di volontariato' ),
			'ets'     => Texts::t( 'ETS – altro ente del Terzo settore' ),
			'circolo' => Texts::t( 'Circolo' ),
			'altro'   => Texts::t( 'Altro' ),
		);
	}

	/** Sigla breve della forma, per le frasi (ASD, APS…). */
	public static function form_short( string $form ): string {
		return in_array( $form, array( 'circolo', 'altro' ), true ) ? ucfirst( $form ) : strtoupper( $form );
	}

	/** Codice fiscale dell'ente: 11 cifre oppure 16 caratteri alfanumerici (formato ragionevole, senza il calcolo del carattere di controllo). */
	public static function tax_code_shape( string $raw ): bool {
		$cf = strtoupper( (string) preg_replace( '/\s+/', '', $raw ) );
		return 1 === preg_match( '/^[0-9]{11}$/', $cf ) || 1 === preg_match( '/^[A-Z0-9]{16}$/', $cf );
	}

	public static function zip_valid( string $z ): bool {
		return 1 === preg_match( '/^[0-9]{5}$/', trim( $z ) );
	}

	public static function province_valid( string $p ): bool {
		return 1 === preg_match( '/^[A-Za-z]{2}$/', trim( $p ) );
	}

	/** Codice destinatario SDI: 7 caratteri, lettere e cifre. */
	public static function sdi_valid( string $c ): bool {
		return 1 === preg_match( '/^[A-Za-z0-9]{7}$/', trim( $c ) );
	}

	/** Sezione del RUNTS in breve, per le frasi. */
	private static function section_short( string $sec ): string {
		return RuntsRules::SECTION_ODV === $sec ? 'ODV' : ( RuntsRules::SECTION_APS === $sec ? 'APS' : Texts::t( 'altra sezione' ) );
	}

	/**
	 * Controlla i dati obbligatori (già puliti: testi tagliati, sigla in maiuscolo).
	 *
	 * @param array $d association_name, entity_form, tax_code, legal_address, legal_zip, legal_city, legal_province, runts_number, no_runts, pec, no_pec
	 * @return string[] un messaggio per ogni dato mancante o sbagliato
	 */
	public static function errors( array $d ): array {
		$e = array();
		if ( '' === trim( (string) ( $d['association_name'] ?? '' ) ) ) {
			$e[] = Texts::t( 'Indica la denominazione dell\'associazione.' );
		}
		if ( ! isset( self::forms()[ (string) ( $d['entity_form'] ?? '' ) ] ) ) {
			$e[] = Texts::t( 'Indica la forma dell\'ente.' );
		}
		$cf = trim( (string) ( $d['tax_code'] ?? '' ) );
		if ( '' === $cf ) {
			$e[] = Texts::t( 'Indica il codice fiscale dell\'ente.' );
		} elseif ( ! self::tax_code_shape( $cf ) ) {
			$e[] = Texts::t( 'Il codice fiscale ha 11 cifre (oppure 16 caratteri se è una persona fisica).' );
		}
		if ( '' === trim( (string) ( $d['legal_address'] ?? '' ) ) ) {
			$e[] = Texts::t( 'Indica l\'indirizzo della sede.' );
		}
		$zip = trim( (string) ( $d['legal_zip'] ?? '' ) );
		if ( '' === $zip ) {
			$e[] = Texts::t( 'Indica il CAP della sede.' );
		} elseif ( ! self::zip_valid( $zip ) ) {
			$e[] = Texts::t( 'Il CAP ha 5 cifre.' );
		}
		if ( '' === trim( (string) ( $d['legal_city'] ?? '' ) ) ) {
			$e[] = Texts::t( 'Indica il comune della sede.' );
		}
		if ( ! self::province_valid( (string) ( $d['legal_province'] ?? '' ) ) ) {
			$e[] = Texts::t( 'Indica la provincia della sede (sigla di 2 lettere).' );
		}
		$vat = trim( (string) ( $d['vat_number'] ?? '' ) );
		if ( '' === $vat && empty( $d['no_vat'] ) ) {
			$e[] = Texts::t( 'Indica la partita IVA oppure spunta «L\'associazione non ha la partita IVA».' );
		} elseif ( '' !== $vat && ! Fiscal::is_valid_vat( $vat ) ) {
			$e[] = Texts::t( 'La partita IVA non è valida: sono 11 cifre, l\'ultima è di controllo.' );
		}
		if ( '' === trim( (string) ( $d['runts_number'] ?? '' ) ) && empty( $d['no_runts'] ) ) {
			$e[] = Texts::t( 'Indica il numero RUNTS (con la sezione) oppure spunta «L\'associazione non è iscritta al RUNTS».' );
		}
		if ( '' === trim( (string) ( $d['pec'] ?? '' ) ) && empty( $d['no_pec'] ) ) {
			$e[] = Texts::t( 'Indica la PEC oppure spunta «L\'associazione non ha una PEC».' );
		}
		return $e;
	}

	/**
	 * Avviso (non bloccante) se la forma dell'ente è APS/ODV e la sezione del RUNTS è un'altra; stringa vuota se coerenti o se la sezione manca.
	 */
	public static function mismatch_warning( string $form, string $section ): string {
		if ( ! in_array( $form, array( 'aps', 'odv' ), true ) || '' === $section || $section === $form ) {
			return '';
		}
		return Texts::t( 'Hai indicato {forma} come forma dell\'ente ma {sezione} come sezione del RUNTS: controlla quale delle due è giusta.', array( 'forma' => self::form_short( $form ), 'sezione' => self::section_short( $section ) ) );
	}

	/** Forma da proporre (da confermare) in un sito che ha già una sezione del RUNTS ma non la forma. */
	public static function suggested_form( array $s ): string {
		if ( '' !== (string) ( $s['entity_form'] ?? '' ) ) {
			return '';
		}
		$sec = (string) ( $s['runts_section'] ?? '' );
		return in_array( $sec, array( RuntsRules::SECTION_APS, RuntsRules::SECTION_ODV ), true ) ? $sec : '';
	}

	/**
	 * Ciò che manca, nell'ordine della banda.
	 *
	 * @return array<int,array{key:string,label:string}>
	 */
	public static function missing(): array {
		$s   = Settings::all();
		$out = array();
		$add = function ( string $key, string $label ) use ( &$out ): void {
			$out[] = array( 'key' => $key, 'label' => $label );
		};
		if ( '' === trim( (string) $s['association_name'] ) ) {
			$add( 'name', Texts::t( 'denominazione' ) );
		}
		if ( ! isset( self::forms()[ (string) $s['entity_form'] ] ) ) {
			$add( 'form', Texts::t( 'forma dell\'ente' ) );
		}
		$cf = trim( (string) $s['tax_code'] );
		if ( '' === $cf || ! self::tax_code_shape( $cf ) ) {
			$add( 'tax_code', Texts::t( 'codice fiscale' ) );
		}
		if ( '' === trim( (string) $s['legal_address'] ) || ! self::zip_valid( (string) $s['legal_zip'] ) || '' === trim( (string) $s['legal_city'] ) || ! self::province_valid( (string) $s['legal_province'] ) ) {
			$add( 'seat', Texts::t( 'sede legale' ) );
		}
		if ( '' === PrivacyNotice::president() ) {
			$add( 'president', Texts::t( 'legale rappresentante (indica il presidente tra le persone)' ) );
		}
		if ( '' === Contacts::email() ) {
			$add( 'email', Texts::t( 'email della segreteria' ) );
		}
		if ( '' === Contacts::phone() ) {
			$add( 'phone', Texts::t( 'telefono della segreteria' ) );
		}
		if ( '' === trim( (string) $s['vat_number'] ) && empty( $s['no_vat'] ) ) {
			$add( 'vat', Texts::t( 'partita IVA' ) );
		}
		if ( '' === trim( (string) $s['runts_number'] ) && empty( $s['no_runts'] ) ) {
			$add( 'runts', Texts::t( 'numero RUNTS' ) );
		}
		if ( '' === trim( (string) $s['pec'] ) && empty( $s['no_pec'] ) ) {
			$add( 'pec', Texts::t( 'PEC' ) );
		}
		return $out;
	}
}
