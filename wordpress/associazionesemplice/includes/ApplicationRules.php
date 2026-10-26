<?php
namespace AssociazioneSemplice;

defined( 'ABSPATH' ) || exit;

/**
 * Regole pure (nessun database) della domanda di adesione online: controllo dei dati scritti nel modulo.
 * Gli errori dicono solo se i dati sono scritti bene, mai se la persona o l'email esistono già.
 */
final class ApplicationRules {

	const MAX_AGE = 110;

	/**
	 * @param array    $in          dati del modulo: first_name, last_name, email, phone, tax_code, birth_date, type, rules_ok, privacy_ok, guardian_ok, guardian_first_name, guardian_last_name, guardian_email, guardian_phone, guardian_tax_code
	 * @param string   $today       oggi (AAAA-MM-GG)
	 * @param bool     $needs_rules il regolamento va accettato
	 * @param string[] $types       tipi di socio richiedibili
	 * @return array{errors:string[], minor:bool, data:array} dati puliti (se non ci sono errori) e se il richiedente è minorenne
	 */
	public static function check( array $in, string $today, bool $needs_rules, array $types ): array {
		$errors = array();
		$t      = function ( string $k ) use ( $in ): string {
			return trim( (string) ( $in[ $k ] ?? '' ) );
		};
		$data = array(
			'first_name' => $t( 'first_name' ),
			'last_name'  => $t( 'last_name' ),
			'email'      => strtolower( $t( 'email' ) ),
			'phone'      => $t( 'phone' ),
			'tax_code'   => TaxCode::normalize( $t( 'tax_code' ) ),
			'type'       => $t( 'type' ),
			'birth_date' => null,
			'guardian'   => array(),
		);
		if ( '' === $data['first_name'] || '' === $data['last_name'] ) {
			$errors[] = 'Scrivi nome e cognome.';
		}
		foreach ( array( 'first_name' => 'Il nome', 'last_name' => 'Il cognome' ) as $k => $label ) {
			if ( mb_strlen( $data[ $k ], 'UTF-8' ) > 120 ) {
				$errors[] = $label . ' è troppo lungo.';
			}
		}
		$birth = Age::parse( $t( 'birth_date' ) );
		$minor = false;
		if ( null === $birth ) {
			$errors[] = 'Scrivi la data di nascita (giorno, mese e anno).';
		} elseif ( $birth > $today ) {
			$errors[] = 'La data di nascita è nel futuro.';
		} elseif ( (int) Age::years( $birth, $today ) > self::MAX_AGE ) {
			$errors[] = 'Controlla la data di nascita.';
		} else {
			$data['birth_date'] = $birth;
			$minor              = Age::is_minor( $birth, $today );
		}
		if ( ! TaxCode::is_valid( $data['tax_code'] ) ) {
			$errors[] = 'Il codice fiscale non è valido: controllalo (16 caratteri).';
		}
		if ( ! in_array( $data['type'], $types, true ) ) {
			$errors[] = 'Scegli che tipo di socio vuoi essere.';
		}
		// Un maggiorenne ha bisogno di email e cellulare; per un minore contano quelli del genitore.
		if ( ! $minor || '' !== $data['email'] ) {
			if ( '' === $data['email'] || ! filter_var( $data['email'], FILTER_VALIDATE_EMAIL ) ) {
				$errors[] = $minor ? 'L\'email non è valida.' : 'Scrivi un\'email valida: ci arriva il link per entrare.';
			}
		}
		if ( ! $minor || '' !== $data['phone'] ) {
			if ( ! Phone::is_valid( $data['phone'] ) ) {
				$errors[] = $minor ? 'Il cellulare non è valido.' : 'Scrivi il numero di cellulare.';
			}
		}
		if ( $minor ) {
			$g = array(
				'first_name' => $t( 'guardian_first_name' ),
				'last_name'  => $t( 'guardian_last_name' ),
				'email'      => strtolower( $t( 'guardian_email' ) ),
				'phone'      => $t( 'guardian_phone' ),
				'tax_code'   => TaxCode::normalize( $t( 'guardian_tax_code' ) ),
			);
			if ( '' !== $data['email'] && $data['email'] === $g['email'] ) {
				$data['email'] = ''; // il minore ha scritto l'email del genitore: resta solo al genitore (due persone non possono avere la stessa)
			}
			if ( '' === $g['first_name'] || '' === $g['last_name'] ) {
				$errors[] = 'Chi ha meno di 18 anni si iscrive con un genitore o tutore: scrivi il suo nome e cognome.';
			}
			if ( '' === $g['email'] || ! filter_var( $g['email'], FILTER_VALIDATE_EMAIL ) ) {
				$errors[] = 'Scrivi l\'email del genitore o tutore.';
			}
			if ( ! Phone::is_valid( $g['phone'] ) ) {
				$errors[] = 'Scrivi il cellulare del genitore o tutore.';
			}
			if ( '' !== $g['tax_code'] && ! TaxCode::is_valid( $g['tax_code'] ) ) {
				$errors[] = 'Il codice fiscale del genitore non è valido.';
			}
			if ( empty( $in['guardian_ok'] ) ) {
				$errors[] = 'Spunta la casella con cui dichiari di essere il genitore o il tutore.';
			}
			$data['guardian'] = $g;
		}
		if ( $needs_rules && empty( $in['rules_ok'] ) ) {
			$errors[] = 'Per iscriverti devi accettare il regolamento.';
		}
		if ( empty( $in['privacy_ok'] ) ) {
			$errors[] = 'Per iscriverti devi accettare l\'informativa sulla privacy.';
		}
		return array( 'errors' => array_values( array_unique( $errors ) ), 'minor' => $minor, 'data' => $data );
	}
}
