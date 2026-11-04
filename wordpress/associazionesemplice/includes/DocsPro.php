<?php
namespace AssociazioneSemplice;

defined( 'ABSPATH' ) || exit;

/** Documenti delle funzioni aggiuntive (registro dei volontari e assicurazione, presenze, rendiconto per cassa), richiesti tramite il filtro `asem_document`. */
final class DocsPro {

	public static function register(): void {
		add_filter( 'asem_document', array( __CLASS__, 'build' ), 10, 4 );
	}

	/** @param mixed $doc già pronto da un altro filtro @return mixed */
	public static function build( $doc, string $what, array $g, string $format ) {
		$needs = array( 'volunteers' => 'insurance', 'attendance' => 'insurance', 'statement' => 'fiscal' );
		if ( ! isset( $needs[ $what ] ) ) {
			return $doc;
		}
		if ( ! Edition::has( $needs[ $what ] ) ) {
			wp_die( 'Questo documento non è disponibile in questa edizione.', 403 );
		}
		switch ( $what ) {
			case 'volunteers':
				return self::volunteers( $format );
			case 'attendance':
				return self::attendance( (int) ( $g['activity'] ?? 0 ), (string) ( $g['from'] ?? '' ), (string) ( $g['to'] ?? '' ), $format );
			default:
				return self::statement( (int) ( $g['year'] ?? 0 ) );
		}
	}

	private static function vol_cols(): array {
		return array(
			array( 'label' => 'Cognome e nome', 'w' => 140 ), array( 'label' => 'Codice fiscale', 'w' => 96 ), array( 'label' => 'Compagnia', 'w' => 90 ),
			array( 'label' => 'Polizza', 'w' => 66 ), array( 'label' => 'Copertura fino al', 'w' => 52 ), array( 'label' => 'Stato', 'w' => 51 ),
		);
	}

	/** @return array{body:string,filename:string,mime:string} */
	public static function volunteers( string $format = 'pdf' ): array {
		$labels = Insurance::status_labels();
		$cells  = array();
		foreach ( Insurance::register() as $r ) {
			$p       = $r['person'];
			$pol     = $r['policy'];
			$cells[] = array( trim( $p['last_name'] . ' ' . $p['first_name'] ), (string) $p['tax_code'], $pol ? (string) $pol['company'] : '', $pol ? (string) $pol['policy_no'] : '', $pol ? Docs::d( $pol['valid_to'] ) : '', $labels[ $r['status'] ] );
		}
		if ( 'csv' === $format ) {
			return array( 'body' => Docs::csv( self::vol_cols(), $cells ), 'filename' => 'registro-volontari.csv', 'mime' => 'text/csv; charset=utf-8' );
		}
		$sub = 'Aggiornato al ' . Docs::d( Db::today() ) . ' · ' . count( $cells ) . ' volontari';
		return array( 'body' => Docs::table_pdf( 'REGISTRO DEI VOLONTARI E ASSICURAZIONE', $sub, self::vol_cols(), $cells ), 'filename' => 'registro-volontari.pdf', 'mime' => 'application/pdf' );
	}

	// ---------- Registro presenze ----------

	/** @return array{body:string,filename:string,mime:string} @throws \InvalidArgumentException */
	public static function attendance( int $activity_id, string $from, string $to, string $format = 'pdf' ): array {
		$a = Plugin::activities()->get( $activity_id );
		if ( ! $a ) {
			throw new \InvalidArgumentException( 'Attività non trovata.' );
		}
		foreach ( array( $from, $to ) as $day ) {
			if ( ! preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $day, $m ) || ! checkdate( (int) $m[2], (int) $m[3], (int) $m[1] ) ) {
				throw new \InvalidArgumentException( 'Indica il periodo (dal / al).' );
			}
		}
		$s     = Attendance::summary( $activity_id, $from, $to );
		$dates = $s['dates'];
		$matrix = count( $dates ) <= 10;
		$cols  = array( array( 'label' => 'Cognome e nome', 'w' => $matrix ? 125 : 255 ) );
		if ( $matrix ) {
			foreach ( $dates as $d ) {
				$cols[] = array( 'label' => substr( $d, 8, 2 ) . '/' . substr( $d, 5, 2 ), 'w' => 33, 'align' => 'C' );
			}
			$cols[] = array( 'label' => 'Presenze', 'w' => 40, 'align' => 'R' );
		} else {
			$cols[] = array( 'label' => 'Presenze', 'w' => 80, 'align' => 'R' );
			$cols[] = array( 'label' => 'Lezioni', 'w' => 80, 'align' => 'R' );
			$cols[] = array( 'label' => '%', 'w' => 80, 'align' => 'R' );
		}
		$cells = array();
		foreach ( $s['people'] as $p ) {
			$row = array( trim( $p['last_name'] . ' ' . $p['first_name'] ) );
			if ( $matrix ) {
				foreach ( $dates as $d ) {
					$row[] = isset( $p['by_date'][ $d ] ) ? ( $p['by_date'][ $d ] ? 'P' : 'A' ) : '';
				}
				$row[] = $p['presenze'] . '/' . $p['totale'];
			} else {
				$row[] = (string) $p['presenze'];
				$row[] = (string) $p['totale'];
				$row[] = $p['totale'] ? (string) round( 100 * $p['presenze'] / $p['totale'] ) . '%' : '';
			}
			$cells[] = $row;
		}
		$slug = sanitize_title( (string) $a['name'] );
		if ( 'csv' === $format ) {
			$csv_cols = $cols;
			return array( 'body' => Docs::csv( $csv_cols, $cells ), 'filename' => 'presenze-' . $slug . '.csv', 'mime' => 'text/csv; charset=utf-8' );
		}
		$sub = $a['name'] . ' · dal ' . Docs::d( $from ) . ' al ' . Docs::d( $to ) . ' · ' . count( $dates ) . ' lezioni registrate';
		return array( 'body' => Docs::table_pdf( 'REGISTRO PRESENZE', $sub, $cols, $cells, 'P = presente, A = assente.' ), 'filename' => 'presenze-' . $slug . '.pdf', 'mime' => 'application/pdf' );
	}

	/** @return array{body:string,filename:string,mime:string} */
	public static function statement( int $year ): array {
		if ( $year < 2000 || $year > (int) substr( Db::today(), 0, 4 ) + 1 ) {
			throw new \InvalidArgumentException( 'Anno non valido.' );
		}
		$s = Statement::data( $year );
		Pdf::$filter = array( Texts::class, 'plain' );
		$pdf         = new Pdf();
		$y           = Docs::head( $pdf, 'RENDICONTO PER CASSA — ANNO ' . $year, 'Entrate e uscite dal 1° gennaio al 31 dicembre ' . $year . ', con il confronto con l\'anno ' . ( $year - 1 ) );
		$cx          = Docs::RIGHT - 3;
		$px          = Docs::RIGHT - 3 - 85;
		$ensure      = function ( float $need ) use ( $pdf, &$y ) {
			if ( $y + $need > Docs::BOTTOM ) {
				$pdf->add_page();
				$y = 60.0;
			}
		};
		$colhead     = function () use ( $pdf, &$y, $cx, $px, $year ) {
			$pdf->rect( Docs::LEFT, $y - 11, Docs::RIGHT - Docs::LEFT, 17 );
			$pdf->text( $px, $y + 1, (string) ( $year - 1 ), 8, true, 'R' );
			$pdf->text( $cx, $y + 1, (string) $year, 8, true, 'R' );
			$y += 20;
		};
		$section = function ( string $label, array $groups, array $total ) use ( $pdf, &$y, $cx, $px, $ensure, $colhead ) {
			$ensure( 50 );
			$pdf->text( Docs::LEFT + 3, $y + 1, $label, 10, true );
			$colhead();
			foreach ( $groups as $g ) {
				$ensure( 30 );
				$pdf->text( Docs::LEFT + 3, $y, Docs::fit( $g['group'], 330, 9, true ), 9, true );
				$pdf->text( $px, $y, Money::format( $g['prev'] ), 9, true, 'R' );
				$pdf->text( $cx, $y, Money::format( $g['cur'] ), 9, true, 'R' );
				$y += 13;
				foreach ( $g['lines'] as $l ) {
					$ensure( 14 );
					$pdf->text( Docs::LEFT + 14, $y, Docs::fit( $l['name'], 320, 8 ), 8 );
					$pdf->text( $px, $y, Money::format( $l['prev'] ), 8, false, 'R' );
					$pdf->text( $cx, $y, Money::format( $l['cur'] ), 8, false, 'R' );
					$y += 12;
				}
				$y += 3;
			}
			$ensure( 20 );
			$pdf->line( Docs::LEFT, $y - 8, Docs::RIGHT, $y - 8 );
			$pdf->text( Docs::LEFT + 3, $y + 3, 'Totale', 9, true );
			$pdf->text( $px, $y + 3, Money::format( $total['prev'] ), 9, true, 'R' );
			$pdf->text( $cx, $y + 3, Money::format( $total['cur'] ), 9, true, 'R' );
			$y += 24;
		};
		$section( 'ENTRATE', $s['income'], $s['total_income'] );
		$section( 'USCITE', $s['expenses'], $s['total_expense'] );
		$ensure( 40 );
		$pdf->rect( Docs::LEFT, $y - 11, Docs::RIGHT - Docs::LEFT, 19, 0.9 );
		$pdf->text( Docs::LEFT + 3, $y + 2, $s['result']['cur'] >= 0 ? 'AVANZO DI GESTIONE' : 'DISAVANZO DI GESTIONE', 10, true );
		$pdf->text( $px, $y + 2, Money::format( $s['result']['prev'] ), 10, true, 'R' );
		$pdf->text( $cx, $y + 2, Money::format( $s['result']['cur'] ), 10, true, 'R' );
		$y += 34;

		$ensure( 60 );
		$pdf->text( Docs::LEFT + 3, $y, 'CASSA E CONTI', 10, true );
		$pdf->rect( Docs::LEFT, $y + 5, Docs::RIGHT - Docs::LEFT, 17 );
		$pdf->text( $px, $y + 17, 'Iniziale', 8, true, 'R' );
		$pdf->text( $cx, $y + 17, 'Finale', 8, true, 'R' );
		$y += 36;
		foreach ( $s['accounts'] as $a ) {
			$ensure( 14 );
			$pdf->text( Docs::LEFT + 14, $y, Docs::fit( $a['name'], 320, 8 ), 8 );
			$pdf->text( $px, $y, Money::format( $a['opening'] ), 8, false, 'R' );
			$pdf->text( $cx, $y, Money::format( $a['closing'] ), 8, false, 'R' );
			$y += 12;
		}
		$pdf->line( Docs::LEFT, $y - 6, Docs::RIGHT, $y - 6 );
		$pdf->text( Docs::LEFT + 3, $y + 5, 'Totale', 9, true );
		$pdf->text( $px, $y + 5, Money::format( $s['opening_total'] ), 9, true, 'R' );
		$pdf->text( $cx, $y + 5, Money::format( $s['closing_total'] ), 9, true, 'R' );
		$y += 20;
		if ( 0 !== $s['funds_total'] ) {
			$ensure( 30 );
			$pdf->text( Docs::LEFT + 14, $y, 'di cui accantonato nei fondi (rimborsi ai volontari)', 8 );
			$pdf->text( $cx, $y, Money::format( $s['funds_total'] ), 8, false, 'R' );
			$y += 12;
			$pdf->text( Docs::LEFT + 14, $y, 'Disponibilità effettiva', 8, true );
			$pdf->text( $cx, $y, Money::format( $s['available'] ), 8, true, 'R' );
			$y += 12;
		}
		$y += 10;
		if ( '' !== $s['notes'] ) {
			$ensure( 40 );
			$pdf->text( Docs::LEFT + 3, $y, 'RELAZIONE SULL\'ANDAMENTO DELLA GESTIONE', 10, true );
			$y += 16;
			foreach ( preg_split( "/\r\n|\n|\r/", $s['notes'] ) ?: array() as $para ) {
				foreach ( '' === trim( $para ) ? array( '' ) : Pdf::wrap( $para, Docs::RIGHT - Docs::LEFT, 9 ) as $line ) {
					$ensure( 14 );
					$pdf->text( Docs::LEFT + 3, $y, $line, 9 );
					$y += 12;
				}
			}
			$y += 8;
		}
		$ensure( 70 );
		$y += 24;
		$pdf->line( Docs::LEFT, $y + 24, Docs::LEFT + 170, $y + 24 );
		$pdf->text( Docs::LEFT, $y + 37, 'Il Tesoriere', 9 );
		$pdf->line( Docs::RIGHT - 170, $y + 24, Docs::RIGHT, $y + 24 );
		$pres = Docs::president();
		$pdf->text( Docs::RIGHT, $y + 37, 'Il Presidente' . ( '' !== $pres ? ' ' . $pres : '' ), 9, false, 'R' );
		$out         = $pdf->output();
		Pdf::$filter = null;
		return array( 'body' => $out, 'filename' => 'rendiconto-' . $year . '.pdf', 'mime' => 'application/pdf' );
	}
}
