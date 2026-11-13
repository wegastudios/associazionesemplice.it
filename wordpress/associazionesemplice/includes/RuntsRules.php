<?php
namespace AssociazioneSemplice;

defined( 'ABSPATH' ) || exit;

/**
 * Regole (pure, senza database) dei «Dati per il RUNTS»: data di riferimento, minimo di soci di ODV e APS, scadenze.
 *
 * ATTENZIONE: queste regole arrivano da commenti professionali (studi di consulenza e centri di servizio per il volontariato), NON dal testo del
 * decreto. Per questo i testi che le mostrano dicono sempre «controlla sul portale del RUNTS». I numeri (7 persone fisiche, 3 enti, 30 giorni) sono
 * di legge: sono costanti, non impostazioni.
 */
final class RuntsRules {

	/** Sezioni del Registro unico del Terzo settore che il plugin distingue: organizzazione di volontariato, associazione di promozione sociale. */
	const SECTION_ODV   = 'odv';
	const SECTION_APS   = 'aps';
	const SECTION_OTHER = 'other';

	/** Minimo di soci di una ODV o di una APS: sette persone fisiche oppure tre enti (della stessa sezione). */
	const MIN_PERSONS  = 7;
	const MIN_ENTITIES = 3;

	/** Giorni per comunicare al RUNTS una variazione. */
	const NOTICE_DAYS = 30;

	/** Tipo di soggetto della scheda socio. */
	const PERSON = 'person';
	const ENTITY = 'entity';

	/** @return array<string,string> */
	public static function sections(): array {
		return array(
			''                  => 'Non indicata',
			self::SECTION_ODV   => 'Organizzazione di volontariato (ODV)',
			self::SECTION_APS   => 'Associazione di promozione sociale (APS)',
			self::SECTION_OTHER => 'Altra sezione del RUNTS',
		);
	}

	public static function section_valid( string $s ): bool {
		return isset( self::sections()[ $s ] );
	}

	/** Il minimo di soci vale per ODV e APS. */
	public static function has_minimum( string $section ): bool {
		return in_array( $section, array( self::SECTION_ODV, self::SECTION_APS ), true );
	}

	/** «ODV» / «APS» per i testi. */
	public static function section_short( string $section ): string {
		return self::SECTION_ODV === $section ? 'ODV' : ( self::SECTION_APS === $section ? 'APS' : '' );
	}

	/** Data di riferimento dei dati del RUNTS per un anno: sempre il 31 dicembre, qualunque sia il mese di inizio dell'esercizio. */
	public static function reference_date( int $year ): string {
		return sprintf( '%04d-12-31', $year );
	}

	/** Ultimo 31 dicembre già passato (o oggi): l'anno a cui si riferiscono i dati da comunicare. */
	public static function last_reference_year( string $today ): int {
		$y = (int) substr( $today, 0, 4 );
		return $today >= self::reference_date( $y ) ? $y : $y - 1;
	}

	/** Scadenza del 30 giugno per aggiornare i dati riferiti al 31 dicembre dell'anno $year (quindi il 30 giugno dell'anno dopo). */
	public static function june_deadline( int $year ): string {
		return sprintf( '%04d-06-30', $year + 1 );
	}

	/** Il promemoria di giugno compare dal 1° maggio al 30 giugno: restituisce l'anno di riferimento dei dati (quello precedente), altrimenti null. */
	public static function june_reminder_year( string $today ): ?int {
		$y = (int) substr( $today, 0, 4 );
		return $today >= sprintf( '%04d-05-01', $y ) && $today <= sprintf( '%04d-06-30', $y ) ? $y - 1 : null;
	}

	/** Si è sotto il minimo? Il minimo è soddisfatto da sette persone fisiche OPPURE da tre enti della stessa sezione. */
	public static function below_minimum( int $persons, int $entities_same_section ): bool {
		return $persons < self::MIN_PERSONS && $entities_same_section < self::MIN_ENTITIES;
	}

	/**
	 * Quante persone fisiche e quanti enti della stessa sezione sono soci a una data: entrato entro quel giorno e non ancora cessato (chi cessa in
	 * quella data non è più socio da quel giorno). Non guarda la scadenza della tessera: una tessera non rinnovata non è una cessazione.
	 *
	 * @param array[] $members vedi {@see RuntsRules::below_since()}
	 * @return int[] [persone fisiche, enti della stessa sezione]
	 */
	public static function counts_on( array $members, string $d ): array {
		$p = 0;
		$e = 0;
		foreach ( $members as $m ) {
			$in = ( '' === (string) $m['joined'] || (string) $m['joined'] <= $d ) && ( '' === (string) $m['left'] || (string) $m['left'] > $d );
			if ( ! $in ) {
				continue;
			}
			if ( self::ENTITY === $m['kind'] ) {
				$e += ! empty( $m['same'] ) ? 1 : 0;
			} else {
				++$p;
			}
		}
		return array( $p, $e );
	}

	/**
	 * Da quando si è sotto il minimo, in modo continuativo fino a oggi. Si scorrono le date in cui qualcuno entra o esce.
	 *
	 * @param array[] $members ciascuno: kind (person|entity), same (bool, ente della stessa sezione), joined (Y-m-d o ''), left (Y-m-d o '')
	 * @return string|null la data da cui il numero è sotto il minimo; null se oggi non lo è, oppure se lo è da sempre (non si può dire da quando)
	 */
	public static function below_since( array $members, string $today ): ?string {
		$count = function ( string $d ) use ( $members ): array {
			return self::counts_on( $members, $d );
		};
		$dates = array( $today => true );
		foreach ( $members as $m ) {
			foreach ( array( (string) $m['joined'], (string) $m['left'] ) as $d ) {
				if ( '' !== $d && $d <= $today ) {
					$dates[ $d ] = true;
				}
			}
		}
		$dates = array_map( 'strval', array_keys( $dates ) );
		sort( $dates );
		list( $p, $e ) = $count( $today );
		if ( ! self::below_minimum( $p, $e ) ) {
			return null;
		}
		$since = null;
		for ( $i = count( $dates ) - 1; $i >= 0; $i-- ) {
			list( $p, $e ) = $count( $dates[ $i ] );
			if ( ! self::below_minimum( $p, $e ) ) {
				break;
			}
			$since = $dates[ $i ];
		}
		return $since === $dates[0] ? null : $since; // sotto il minimo fin dalla prima data conosciuta: non si può dire da quando
	}

	/** Ultimo giorno per comunicare la variazione: 30 giorni dopo. */
	public static function notice_deadline( string $since ): string {
		return ( new \DateTimeImmutable( $since ) )->modify( '+' . self::NOTICE_DAYS . ' days' )->format( 'Y-m-d' );
	}

	/** Messaggio d'errore sul codice fiscale di un ente (11 cifre con controllo, oppure 16 caratteri); stringa vuota se va bene o se è vuoto. */
	public static function entity_tax_code_error( string $cf ): string {
		$cf = TaxCode::normalize( $cf );
		return '' !== $cf && ! Fiscal::is_valid_entity_tax_code( $cf ) ? 'Il codice fiscale dell\'ente non è valido: controllalo (11 cifre, oppure 16 caratteri).' : '';
	}

	/** Il tipo di soggetto, ricondotto a uno dei due valori. */
	public static function kind( $v ): string {
		return self::ENTITY === (string) $v ? self::ENTITY : self::PERSON;
	}
}
