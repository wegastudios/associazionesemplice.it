<?php
namespace AssociazioneSemplice;

defined( 'ABSPATH' ) || exit;

/**
 * Regole pure (nessun database) della domanda di adesione online: controllo dei dati scritti nel modulo.
 * Gli errori dicono solo se i dati sono scritti bene, mai se la persona o l'email esistono già.
 */
final class ApplicationRules {

	const MAX_AGE = 110;

	const NAME_MAX   = 60; // caratteri per nome o per cognome
	const NAME_ERROR = 'Nome non valido: usa solo lettere, spazi, apostrofi e trattini.';

	/**
	 * Nome o cognome dei moduli pubblici: solo lettere (anche con accento, di qualunque alfabeto), spazi, apostrofi (anche tipografici) e trattini,
	 * da 1 a NAME_MAX caratteri, che inizia con una lettera; spazi doppi ridotti a uno. Niente a capo, cifre, simboli, indirizzi o link.
	 *
	 * @return string il nome pulito, oppure '' se non è valido
	 */
	public static function clean_name( string $name ): string {
		if ( 1 === preg_match( '/[\p{C}]/u', $name ) || ! mb_check_encoding( $name, 'UTF-8' ) ) { // controlli, a capo, caratteri invisibili
			return '';
		}
		if ( class_exists( 'Normalizer' ) ) {
			$norm = \Normalizer::normalize( $name, \Normalizer::FORM_C );
			$name = false === $norm ? $name : $norm;
		}
		$name = trim( (string) preg_replace( '/\s+/u', ' ', $name ) );
		$len  = mb_strlen( $name, 'UTF-8' );
		if ( $len < 1 || $len > self::NAME_MAX ) {
			return '';
		}
		if ( 1 !== preg_match( "/^\\p{L}[\\p{L}\\p{M}' \\x{2018}\\x{2019}\\x{02BC}\\x{2010}\\x{2011}-]*$/u", $name ) || 1 === preg_match( "/['\\x{2018}\\x{2019}\\x{02BC}\\x{2010}\\x{2011}-]{2}/u", $name ) ) {
			return '';
		}
		return $name;
	}

	/** Un nome (anche già salvato) da scrivere in un'email o in un avviso: una sola riga, senza caratteri di controllo, non oltre NAME_MAX. */
	public static function mail_name( string $name ): string {
		$name = (string) preg_replace( '/[\p{C}\s]+/u', ' ', $name );
		return trim( mb_substr( trim( $name ), 0, self::NAME_MAX, 'UTF-8' ) );
	}

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
			'birth_place' => mb_substr( $t( 'birth_place' ), 0, 120, 'UTF-8' ),
			'image_ok'   => ! empty( $in['image_ok'] ),
			'address'    => mb_substr( $t( 'address' ), 0, 190, 'UTF-8' ),
			'zip'        => mb_substr( $t( 'zip' ), 0, 12, 'UTF-8' ),
			'city'       => mb_substr( $t( 'city' ), 0, 100, 'UTF-8' ),
			'province'   => strtoupper( mb_substr( $t( 'province' ), 0, 5, 'UTF-8' ) ),
			'guardian'   => array(),
		);
		if ( '' === $data['first_name'] || '' === $data['last_name'] ) {
			$errors[] = 'Scrivi nome e cognome.';
		} else {
			foreach ( array( 'first_name', 'last_name' ) as $k ) {
				$clean = self::clean_name( $data[ $k ] );
				if ( '' === $clean ) {
					$errors[] = self::NAME_ERROR;
				} else {
					$data[ $k ] = $clean;
				}
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
				$errors[] = $minor ? 'L\'email non è valida.' : 'Scrivi un\'email valida: lì ricevi il link per entrare.';
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
			} else {
				foreach ( array( 'first_name', 'last_name' ) as $k ) {
					$clean = self::clean_name( $g[ $k ] );
					if ( '' === $clean ) {
						$errors[] = self::NAME_ERROR;
					} else {
						$g[ $k ] = $clean;
					}
				}
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
