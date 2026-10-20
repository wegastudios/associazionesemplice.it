<?php
namespace AssociazioneSemplice;

defined( 'ABSPATH' ) || exit;

/**
 * Documenti stampabili dei registri: libro soci, registro volontari, presenze, verbali e rendiconto, in PDF (e CSV per gli elenchi).
 * Il PDF è generato dal plugin (classe Pdf), senza librerie esterne.
 */
final class Docs {

	const LEFT   = 50.0;
	const RIGHT  = 545.0; // Pdf::W - 50
	const BOTTOM = 790.0;

	public static function register(): void {
		add_action( 'admin_post_asem_doc', array( __CLASS__, 'handle' ) );
	}

	public static function url( string $what, array $args = array(), string $format = 'pdf' ): string {
		return wp_nonce_url( add_query_arg( array_merge( array( 'action' => 'asem_doc', 'what' => $what, 'format' => $format ), $args ), admin_url( 'admin-post.php' ) ), 'asem_doc' );
	}

	public static function d( ?string $ymd ): string {
		return $ymd ? ( new \DateTimeImmutable( $ymd ) )->format( 'd/m/Y' ) : '';
	}

	public static function fit( string $s, float $w, float $size, bool $bold = false ): string {
		if ( Pdf::width( $s, $size, $bold ) <= $w ) {
			return $s;
		}
		while ( '' !== $s && Pdf::width( $s . '…', $size, $bold ) > $w ) {
			$s = function_exists( 'mb_substr' ) ? mb_substr( $s, 0, -1, 'UTF-8' ) : substr( $s, 0, -1 );
		}
		return $s . '…';
	}

	public static function csv_line( array $cells ): string {
		$out = array();
		foreach ( $cells as $c ) {
			$v     = Admin\Exports::neutralize( null === $c ? '' : (string) $c );
			$out[] = preg_match( '/[;"\r\n]/', $v ) ? '"' . str_replace( '"', '""', $v ) . '"' : $v;
		}
		return implode( ';', $out ) . "\r\n";
	}

	public static function csv( array $cols, array $rows ): string {
		$s = self::csv_line( array_column( $cols, 'label' ) );
		foreach ( $rows as $r ) {
			$s .= self::csv_line( $r );
		}
		return "\xEF\xBB\xBF" . $s;
	}

	public static function president(): string {
		foreach ( Plugin::people()->board() as $b ) {
			if ( BoardRole::PRESIDENT === $b['board_role'] ) {
				return trim( $b['first_name'] . ' ' . $b['last_name'] );
			}
		}
		return '';
	}

	/** Intestazione dell'associazione, titolo e sottotitolo. @return float la y da cui continuare */
	public static function head( Pdf $pdf, string $title, string $subtitle = '' ): float {
		$name = (string) Settings::get( 'association_name' );
		$cf   = (string) Settings::get( 'tax_code' );
		$pdf->text( self::LEFT, 56, '' !== $name ? $name : 'Associazione', 15, true );
		if ( '' !== $cf ) {
			$pdf->text( self::LEFT, 72, 'Codice fiscale: ' . $cf, 9 );
		}
		$pdf->line( self::LEFT, 82, self::RIGHT, 82, 1.0 );
		$pdf->text( self::LEFT, 106, $title, 13, true );
		$y = 106.0;
		if ( '' !== $subtitle ) {
			$y += 15;
			$pdf->text( self::LEFT, $y, $subtitle, 9 );
		}
		return $y + 20;
	}

	/**
	 * Tabella a più pagine.
	 *
	 * @param array[] $cols [label, w (punti), align L|R|C]
	 * @param array[] $rows celle di testo
	 */
	public static function table_pdf( string $title, string $subtitle, array $cols, array $rows, string $foot = '' ): string {
		Pdf::$filter = array( Texts::class, 'plain' );
		$pdf         = new Pdf();
		$y           = self::head( $pdf, $title, $subtitle );
		$header      = function ( Pdf $pdf, float $y ) use ( $cols ) {
			$pdf->rect( self::LEFT, $y - 11, self::RIGHT - self::LEFT, 17 );
			$x = self::LEFT;
			foreach ( $cols as $c ) {
				$pdf->text( 'R' === ( $c['align'] ?? 'L' ) ? $x + $c['w'] - 3 : $x + 3, $y + 1, self::fit( (string) $c['label'], $c['w'] - 6, 8, true ), 8, true, $c['align'] ?? 'L' );
				$x += $c['w'];
			}
		};
		$header( $pdf, $y );
		$y += 20;
		foreach ( $rows as $i => $r ) {
			if ( $y > self::BOTTOM ) {
				$pdf->add_page();
				$y = 60.0;
				$header( $pdf, $y );
				$y += 20;
			}
			if ( 0 === $i % 2 ) {
				$pdf->rect( self::LEFT, $y - 9, self::RIGHT - self::LEFT, 13, 0.97 );
			}
			$x = self::LEFT;
			foreach ( $cols as $k => $c ) {
				$pdf->text( 'R' === ( $c['align'] ?? 'L' ) ? $x + $c['w'] - 3 : ( 'C' === ( $c['align'] ?? 'L' ) ? $x + $c['w'] / 2 : $x + 3 ), $y, self::fit( (string) ( $r[ $k ] ?? '' ), $c['w'] - 6, 8 ), 8, false, $c['align'] ?? 'L' );
				$x += $c['w'];
			}
			$y += 13;
		}
		if ( ! $rows ) {
			$pdf->text( self::LEFT + 3, $y, 'Nessun dato.', 9 );
			$y += 13;
		}
		if ( '' !== $foot ) {
			$y += 14;
			foreach ( Pdf::wrap( $foot, self::RIGHT - self::LEFT, 8 ) as $line ) {
				$pdf->text( self::LEFT, min( $y, self::BOTTOM + 30 ), $line, 8 );
				$y += 11;
			}
		}
		$out         = $pdf->output();
		Pdf::$filter = null;
		return $out;
	}

	// ---------- Libro soci ----------

	private static function book_cols(): array {
		return array(
			array( 'label' => 'N.', 'w' => 26, 'align' => 'R' ), array( 'label' => 'Cognome e nome', 'w' => 135 ), array( 'label' => 'Codice fiscale', 'w' => 98 ),
			array( 'label' => 'Livello', 'w' => 80 ), array( 'label' => 'Ingresso', 'w' => 56 ), array( 'label' => 'Cessazione', 'w' => 100 ),
		);
	}

	private static function book_rows( array $rows ): array {
		$out = array();
		foreach ( $rows as $r ) {
			$out[] = array( (string) $r['n'], $r['name'], $r['tax_code'], $r['level'], self::d( $r['joined_on'] ), $r['left_on'] ? self::d( $r['left_on'] ) . ( '' !== $r['left_reason'] ? ' · ' . $r['left_reason'] : '' ) : '' );
		}
		return $out;
	}

	/** @return array{body:string,filename:string,mime:string} */
	public static function book( string $filter = '', string $format = 'pdf' ): array {
		$rows   = MemberBook::rows( $filter );
		$all    = MemberBook::rows();
		$active = count( MemberBook::rows( MemberBook::IN_FORCE ) );
		$cells  = self::book_rows( $rows );
		if ( 'csv' === $format ) {
			return array( 'body' => self::csv( self::book_cols(), $cells ), 'filename' => 'libro-soci.csv', 'mime' => 'text/csv; charset=utf-8' );
		}
		$sub = 'Aggiornato al ' . self::d( Db::today() ) . ' · ' . count( $all ) . ' soci iscritti, ' . $active . ' in carica';
		return array( 'body' => self::table_pdf( 'LIBRO DEI SOCI', $sub, self::book_cols(), $cells ), 'filename' => 'libro-soci.pdf', 'mime' => 'application/pdf' );
	}

	// ---------- Verbale ----------

	/** @return array{body:string,filename:string,mime:string} @throws \InvalidArgumentException */
	public static function minute( int $id ): array {
		$m = Minutes::get( $id );
		if ( ! $m ) {
			throw new \InvalidArgumentException( 'Verbale non trovato.' );
		}
		Pdf::$filter = array( Texts::class, 'plain' );
		$pdf         = new Pdf();
		$kinds       = Minutes::kinds();
		$y           = self::head( $pdf, 'VERBALE N. ' . $m['number'] . ' — ' . mb_strtoupper( $kinds[ $m['kind'] ] ), $m['title'] );
		$w           = self::RIGHT - self::LEFT;
		$put         = function ( string $text, float $size = 10, bool $bold = false ) use ( $pdf, &$y, $w ) {
			foreach ( preg_split( "/\r\n|\n|\r/", $text ) ?: array( '' ) as $para ) {
				foreach ( '' === trim( $para ) ? array( '' ) : Pdf::wrap( $para, $w, $size, $bold ) as $line ) {
					if ( $y > self::BOTTOM ) {
						$pdf->add_page();
						$y = 60.0;
					}
					$pdf->text( self::LEFT, $y, $line, $size, $bold );
					$y += $size + 4;
				}
			}
		};
		$put( 'Data: ' . self::d( $m['meeting_date'] ) . ( '' !== (string) $m['place'] ? ' · Luogo: ' . $m['place'] : '' ) );
		$y += 6;
		if ( '' !== (string) $m['attendees'] ) {
			$put( 'Presenti', 10, true );
			$put( (string) $m['attendees'] );
			$y += 6;
		}
		if ( '' !== (string) $m['agenda'] ) {
			$put( 'Ordine del giorno', 10, true );
			$put( (string) $m['agenda'] );
			$y += 6;
		}
		$put( 'Svolgimento e deliberazioni', 10, true );
		$put( (string) $m['body'] );
		$y += 10;
		if ( $m['approved_on'] ) {
			$put( 'Verbale letto e approvato il ' . self::d( $m['approved_on'] ) . '.' );
		}
		$y += 24;
		if ( $y > self::BOTTOM - 40 ) {
			$pdf->add_page();
			$y = 80.0;
		}
		$pdf->line( self::LEFT, $y + 24, self::LEFT + 170, $y + 24 );
		$pdf->text( self::LEFT, $y + 37, 'Il Segretario', 9 );
		$pdf->line( self::RIGHT - 170, $y + 24, self::RIGHT, $y + 24 );
		$pres = self::president();
		$pdf->text( self::RIGHT, $y + 37, 'Il Presidente' . ( '' !== $pres ? ' ' . $pres : '' ), 9, false, 'R' );
		$out         = $pdf->output();
		Pdf::$filter = null;
		return array( 'body' => $out, 'filename' => 'verbale-' . str_replace( '/', '-', $m['number'] ) . '-' . $m['kind'] . '.pdf', 'mime' => 'application/pdf' );
	}

	// ---------- Download ----------

	private static function send( array $doc ): void {
		while ( ob_get_level() ) {
			ob_end_clean();
		}
		nocache_headers();
		header( 'Content-Type: ' . $doc['mime'] );
		header( 'X-Content-Type-Options: nosniff' );
		header( ( 0 === strpos( $doc['mime'], 'text/csv' ) ? 'Content-Disposition: attachment' : 'Content-Disposition: inline' ) . '; filename="' . preg_replace( '/[^A-Za-z0-9._-]+/', '_', $doc['filename'] ) . '"' );
		header( 'Content-Length: ' . strlen( $doc['body'] ) );
		echo $doc['body']; // phpcs:ignore WordPress.Security.EscapeOutput -- file
		exit;
	}

	public static function handle(): void {
		if ( ! current_user_can( Plugin::CAP_OPS ) ) {
			wp_die( 'Non autorizzato.', 403 );
		}
		check_admin_referer( 'asem_doc' );
		if ( ! Edition::allows( 'export' ) ) {
			wp_die( 'L\'esportazione dei dati è sospesa perché la licenza di Associazione Semplice non risulta in regola.', 'Licenza non in regola', array( 'response' => 402, 'back_link' => true ) );
		}
		$g      = wp_unslash( $_GET ); // phpcs:ignore WordPress.Security.NonceVerification
		$what   = (string) ( $g['what'] ?? '' );
		$format = 'csv' === ( $g['format'] ?? '' ) ? 'csv' : 'pdf';
		try {
			switch ( $what ) {
				case 'book':
					$doc = self::book( (string) ( $g['filter'] ?? '' ), $format );
					break;
				case 'minute':
					$doc = self::minute( (int) ( $g['id'] ?? 0 ) );
					break;
				case 'privacy':
					$doc = PrivacyNotice::pdf();
					break;
				default:
					$doc = apply_filters( 'asem_document', null, $what, $g, $format ); // le estensioni forniscono altri documenti
					if ( ! is_array( $doc ) ) {
						wp_die( 'Documento non valido.', 400 );
					}
			}
		} catch ( \InvalidArgumentException $e ) {
			wp_die( esc_html( $e->getMessage() ), 404 );
		}
		self::send( $doc );
	}
}
