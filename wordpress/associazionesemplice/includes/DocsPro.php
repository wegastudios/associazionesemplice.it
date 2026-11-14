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
				return self::statement( (int) ( $g['year'] ?? 0 ), ! empty( $g['version'] ) ? (int) $g['version'] : null );
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
	public static function statement( int $year, ?int $version = null ): array {
		if ( $year < 2000 || $year > (int) substr( Db::today(), 0, 4 ) + 1 ) {
			throw new \InvalidArgumentException( 'Anno non valido.' );
		}
		$row = StatementSnapshot::row_for( $year, $version );
		if ( $row ) { // anno fiscale chiuso e fotografato: il PDF è quello emesso alla chiusura, con il suo numero e la sua data
			return StatementSnapshot::document( $row );
		}
		if ( null !== $version ) {
			throw new \InvalidArgumentException( 'Di questa chiusura non c\'è un rendiconto definitivo.' );
		}
		$span = FiscalYears::range( $year ); // anno fiscale aperto (o chiuso prima della fotografia): PDF dal vivo, non emesso
		$prev = FiscalYears::range( $year - 1 );
		return self::live_statement_pdf( $year, $span, $prev );
	}

	/** @return array{body:string,filename:string,mime:string} */
	private static function live_statement_pdf( int $year, array $span, array $prev ): array {
		$meta = array(
			'entity'     => array( 'name' => (string) Settings::get( 'association_name' ), 'tax_code' => (string) Settings::get( 'tax_code' ) ),
			'president'  => Docs::president(),
			'label'      => FiscalYears::label( $year ),
			'prev_label' => FiscalYears::label( $year - 1 ),
			'from'       => $span[0],
			'to'         => $span[1],
			'emission'   => null,
		);
		return array( 'body' => self::render_statement( Statement::live( $year ), $meta ), 'filename' => 'rendiconto-' . $year . '.pdf', 'mime' => 'application/pdf' );
	}

	/**
	 * Disegna il PDF del rendiconto da dei dati già pronti (dal vivo o fotografati): non legge nulla dal database né dalle impostazioni, tutto
	 * ciò che serve (ente, presidente, intervallo, numero e data di emissione) sta in $meta.
	 *
	 * @param array $s    dati del rendiconto ({@see Statement::live()})
	 * @param array $meta entity, president, label, prev_label, from, to, emission (null, oppure number, year, issued_at, retroactive, version)
	 * @return string i byte del PDF
	 */
	public static function render_statement( array $s, array $meta ): string {
		$year        = (int) $s['year'];
		$label       = (string) $meta['label'];
		$prev_label  = (string) $meta['prev_label'];
		Pdf::$filter = array( Texts::class, 'plain' );
		$pdf         = new Pdf();
		$range       = '01' === substr( (string) $meta['from'], 5, 2 )
			? 'dal 1° gennaio al 31 dicembre ' . $year
			: 'dal ' . Docs::d( (string) $meta['from'] ) . ' al ' . Docs::d( (string) $meta['to'] );
		$y           = Docs::head( $pdf, 'RENDICONTO PER CASSA (MOD. D) — ANNO ' . $label, 'Entrate e uscite ' . $range . ', con il confronto con l\'anno ' . $prev_label, (array) $meta['entity'] );
		$em          = $meta['emission'];
		if ( is_array( $em ) ) { // documento emesso alla chiusura: numero, data, versione e, se ricostruito, la dicitura
			$pdf->text( Docs::LEFT, $y - 5, 'Documento emesso ' . StatementSnapshot::number_text( (int) $em['number'], (int) $em['year'] ) . ' il ' . StatementSnapshot::dmy( (string) $em['issued_at'] ) . ' · chiusura n. ' . (int) $em['version'] . ' dell\'anno fiscale', 9, true );
			$y += 12;
			if ( ! empty( $em['retroactive'] ) ) {
				$pdf->text( Docs::LEFT, $y - 5, 'Emesso il ' . StatementSnapshot::dmy( (string) $em['issued_at'] ) . ' per un anno già chiuso: i dati sono quelli di quel giorno, non quelli della chiusura.', 8 );
				$y += 12;
			}
			$y += 4;
		}
		$cx          = Docs::RIGHT - 3;
		$px          = Docs::RIGHT - 3 - 85;
		$ensure      = function ( float $need ) use ( $pdf, &$y ) {
			if ( $y + $need > Docs::BOTTOM ) {
				$pdf->add_page();
				$y = 60.0;
			}
		};
		$colhead     = function () use ( $pdf, &$y, $cx, $px, $label, $prev_label ) {
			$pdf->rect( Docs::LEFT, $y - 11, Docs::RIGHT - Docs::LEFT, 17 );
			$pdf->text( $px, $y + 1, $prev_label, 8, true, 'R' );
			$pdf->text( $cx, $y + 1, $label, 8, true, 'R' );
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
		$pres = (string) $meta['president'];
		$pdf->text( Docs::RIGHT, $y + 37, 'Il Presidente' . ( '' !== $pres ? ' ' . $pres : '' ), 9, false, 'R' );
		$out         = $pdf->output();
		Pdf::$filter = null;
		return $out;
	}
}
