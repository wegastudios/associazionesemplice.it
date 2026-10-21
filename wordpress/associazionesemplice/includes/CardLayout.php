<?php
namespace AssociazioneSemplice;

defined( 'ABSPATH' ) || exit;

/**
 * Tessera personalizzata: l'associazione sceglie le dimensioni in centimetri (orizzontale, verticale, quadrata o misura propria), un'immagine
 * di sfondo facoltativa e dove posizionare logo, nome e cognome, numero di tessera, data di scadenza e (se attivo) il QR. Posizioni in
 * percentuale della larghezza e dell'altezza; dimensioni in percentuale della larghezza della tessera, così la tessera si ridimensiona da sola
 * ed è uguale a schermo e in stampa (in stampa ha le dimensioni in centimetri scelte).
 */
final class CardLayout {

	const MODE_STANDARD = 'standard';
	const MODE_IMAGE    = 'image'; // tessera personalizzata (con o senza immagine di sfondo)

	const MIN_CM = 3.0;
	const MAX_CM = 30.0;

	/** Campo => etichetta. */
	const FIELDS = array(
		'logo'   => 'Logo',
		'name'   => 'Nome e cognome',
		'number' => 'Numero di tessera',
		'valid'  => 'Data di scadenza',
		'qr'     => 'QR della tessera',
		'type'   => 'Tipo di socio',
		'roles'  => 'Incarichi (consiglio e staff)',
		'text'   => 'Testo libero',
	);

	/** Forme proposte: chiave => [etichetta, larghezza cm, altezza cm]. La misura propria si scrive a mano. */
	const SHAPES = array(
		'landscape' => array( 'Orizzontale (come una carta di credito)', 8.56, 5.4 ),
		'portrait'  => array( 'Verticale', 5.4, 8.56 ),
		'square'    => array( 'Quadrata', 6.0, 6.0 ),
	);

	/** Campi che si dimensionano in percentuale della larghezza della tessera (immagini) invece che come grandezza delle lettere. */
	const BOXED = array( 'logo', 'qr' );

	/** Posizioni e dimensioni predefinite (x e y in %, size in % della larghezza, color). */
	public static function defaults(): array {
		return array(
			'logo'   => array( 'show' => 1, 'x' => 6, 'y' => 8, 'size' => 24.0, 'color' => '#000000' ),
			'name'   => array( 'show' => 1, 'x' => 6, 'y' => 58, 'size' => 5.0, 'color' => '#222222' ),
			'number' => array( 'show' => 1, 'x' => 6, 'y' => 74, 'size' => 3.5, 'color' => '#222222' ),
			'valid'  => array( 'show' => 1, 'x' => 6, 'y' => 84, 'size' => 3.5, 'color' => '#222222' ),
			'qr'     => array( 'show' => 1, 'x' => 76, 'y' => 52, 'size' => 20.0, 'color' => '#000000' ),
			'type'   => array( 'show' => 0, 'x' => 6, 'y' => 66.5, 'size' => 3.0, 'color' => '#222222' ),
			'roles'  => array( 'show' => 0, 'x' => 6, 'y' => 91, 'size' => 2.6, 'color' => '#222222' ),
			'text'   => array( 'show' => 0, 'x' => 40, 'y' => 12, 'size' => 3.0, 'color' => '#222222' ),
		);
	}

	/** Ripulisce una disposizione ricevuta da un modulo o dalle impostazioni; ciò che manca prende il valore predefinito. */
	public static function clean( $in ): array {
		$in  = is_array( $in ) ? $in : array();
		$out = self::defaults();
		foreach ( $out as $k => $d ) {
			$r = isset( $in[ $k ] ) && is_array( $in[ $k ] ) ? $in[ $k ] : array();
			$c = Color::normalize( (string) ( $r['color'] ?? '' ) );
			$out[ $k ] = array(
				'show'  => array_key_exists( 'show', $r ) ? ( empty( $r['show'] ) ? 0 : 1 ) : $d['show'],
				'x'     => max( 0.0, min( 100.0, round( (float) ( $r['x'] ?? $d['x'] ), 1 ) ) ),
				'y'     => max( 0.0, min( 100.0, round( (float) ( $r['y'] ?? $d['y'] ), 1 ) ) ),
				'size'  => max( 1.0, min( 100.0, round( (float) ( $r['size'] ?? $d['size'] ), 1 ) ) ),
				'color' => '' !== $c ? $c : $d['color'],
			);
			if ( ! in_array( $k, self::BOXED, true ) ) {
				$out[ $k ]['size'] = min( 40.0, $out[ $k ]['size'] ); // le lettere non vanno oltre il 40% della larghezza
			}
		}
		return $out;
	}

	public static function layout(): array {
		return self::clean( Settings::get( 'card_layout' ) );
	}

	/** Una misura in centimetri dentro i limiti (con un decimale). */
	public static function clean_cm( $v, float $fallback ): float {
		$f = (float) str_replace( ',', '.', (string) $v );
		if ( $f <= 0 ) {
			$f = $fallback;
		}
		return max( self::MIN_CM, min( self::MAX_CM, round( $f, 2 ) ) );
	}

	/** @return array{w:float,h:float} larghezza e altezza della tessera in centimetri */
	public static function dims(): array {
		return array(
			'w' => self::clean_cm( Settings::get( 'card_w_cm' ), self::SHAPES['landscape'][1] ),
			'h' => self::clean_cm( Settings::get( 'card_h_cm' ), self::SHAPES['landscape'][2] ),
		);
	}

	/** Quale forma è quella scelta ('custom' se non coincide con nessuna proposta). */
	public static function shape(): string {
		$d = self::dims();
		foreach ( self::SHAPES as $key => $s ) {
			if ( abs( $s[1] - $d['w'] ) < 0.05 && abs( $s[2] - $d['h'] ) < 0.05 ) {
				return $key;
			}
		}
		return 'custom';
	}

	public static function bg_url(): string {
		$id = (int) Settings::get( 'card_bg_id' );
		$u  = $id > 0 ? wp_get_attachment_image_url( $id, 'large' ) : false;
		return $u ? (string) $u : '';
	}

	/** La tessera personalizzata è scelta? (l'immagine di sfondo è facoltativa: senza, la tessera è bianca) */
	public static function active(): bool {
		return self::MODE_IMAGE === (string) Settings::get( 'card_mode' );
	}

	/** Stile di un campo di testo posizionato. */
	private static function style( array $f, bool $with_color = true ): string {
		return 'left:' . (float) $f['x'] . '%;top:' . (float) $f['y'] . '%;font-size:' . (float) $f['size'] . 'cqw;' . ( $with_color ? 'color:' . $f['color'] . ';' : '' );
	}

	/** Stile del contenitore: proporzioni e larghezza in stampa dalle misure in centimetri. */
	public static function box_style(): string {
		$d = self::dims();
		return 'aspect-ratio:' . $d['w'] . '/' . $d['h'] . ';--asemf-card-w:' . $d['w'] . 'cm;--asemf-card-px:' . (int) round( min( 640, $d['w'] * 74.8 ) ) . ';';
	}

	/**
	 * La tessera personalizzata con i dati sopra.
	 *
	 * @param string $name   nome e cognome
	 * @param string $number numero di tessera (testo, già pronto)
	 * @param string $valid  testo della scadenza (già pronto)
	 * @param string $qr_svg QR come SVG già pronto ('' se non c'è)
	 */
	public static function html( string $name, string $number, string $valid, string $qr_svg, array $extra = array() ): string {
		$l    = self::layout();
		$bg   = self::bg_url();
		$html = '<div class="asemf-memcard asemf-memcard-img' . ( '' === $bg ? ' asemf-card-plain' : '' ) . '" style="' . esc_attr( self::box_style() ) . '">'
			. ( '' !== $bg ? '<img class="asemf-card-bg" src="' . esc_url( $bg ) . '" alt="">' : '' );
		$logo = Frontend\Assets::logo_url();
		if ( $l['logo']['show'] && '' !== $logo ) {
			$html .= '<img class="asemf-card-f asemf-card-logo" src="' . esc_url( $logo ) . '" alt="" style="left:' . (float) $l['logo']['x'] . '%;top:' . (float) $l['logo']['y'] . '%;width:' . (float) $l['logo']['size'] . '%">';
		}
		if ( $l['name']['show'] && '' !== $name ) {
			$html .= '<span class="asemf-card-f" style="' . esc_attr( self::style( $l['name'] ) ) . '">' . esc_html( $name ) . '</span>';
		}
		if ( $l['number']['show'] && '' !== $number ) {
			$html .= '<span class="asemf-card-f" style="' . esc_attr( self::style( $l['number'] ) ) . '">' . esc_html( $number ) . '</span>';
		}
		if ( $l['valid']['show'] && '' !== $valid ) {
			$html .= '<span class="asemf-card-f" style="' . esc_attr( self::style( $l['valid'] ) ) . '">' . esc_html( $valid ) . '</span>';
		}
		foreach ( array( 'type', 'roles', 'text' ) as $k ) { // tipo di socio, incarichi e testo libero
			$v = (string) ( $extra[ $k ] ?? '' );
			if ( $l[ $k ]['show'] && '' !== $v ) {
				$html .= '<span class="asemf-card-f" style="' . esc_attr( self::style( $l[ $k ] ) ) . '">' . esc_html( $v ) . '</span>';
			}
		}
		if ( $l['qr']['show'] && '' !== $qr_svg ) {
			$html .= '<span class="asemf-card-f asemf-card-qr" style="left:' . (float) $l['qr']['x'] . '%;top:' . (float) $l['qr']['y'] . '%;width:' . (float) $l['qr']['size'] . '%">' . $qr_svg . '</span>'; // phpcs:ignore WordPress.Security.EscapeOutput -- SVG generato dal plugin
		}
		return $html . '</div>';
	}

	/** Testi di esempio per l'anteprima e per il trascinamento nell'amministrazione. */
	public static function samples(): array {
		$text = trim( (string) Settings::get( 'card_text' ) );
		return array(
			'logo'   => 'Logo',
			'name'   => 'Nome Cognome',
			'number' => 'N. 123',
			'valid'  => 'Valida fino al 31/12/' . ( (int) current_time( 'Y' ) + 1 ),
			'qr'     => 'QR',
			'type'   => 'Socio ordinario',
			'roles'  => 'Consigliere · Staff: Corso di yoga',
			'text'   => '' !== $text ? $text : 'Testo libero',
		);
	}

	/** Indirizzo da cui il socio (o la segreteria) scarica la tessera in PDF: valido per chi è collegato, con un codice di sicurezza. */
	public static function pdf_url( int $person_id ): string {
		return add_query_arg( array( 'asem_card_pdf' => $person_id, '_n' => wp_create_nonce( 'asem_card_pdf_' . $person_id ) ), home_url( '/' ) );
	}

	/** File di un'immagine della libreria media (null se non c'è o non si legge). */
	private static function attachment_bytes( int $id ): ?string {
		$file = $id > 0 ? get_attached_file( $id ) : false;
		if ( ! $file || ! is_readable( $file ) ) {
			return null;
		}
		$b = file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- file della libreria media, non un indirizzo remoto
		return false === $b ? null : $b;
	}

	/**
	 * La tessera in PDF alla misura scelta (centimetri), su un foglio A4 pronto da stampare e ritagliare: sfondo, logo, testi e QR come sulla tessera
	 * personalizzata; con la tessera standard i dati stanno nelle posizioni predefinite.
	 */
	public static function pdf( array $p ): string {
		$d     = self::dims();
		$pt    = 72 / 2.54;
		$w     = $d['w'] * $pt;
		$h     = $d['h'] * $pt;
		$x0    = max( 20.0, ( Pdf::W - $w ) / 2 );
		$y0    = 50.0;
		$l     = self::layout();
		$custom = self::active();
		$until = MemberType::GUEST === $p['type'] ? null : Plugin::people()->active_until( (int) $p['id'] );
		$when  = MemberType::is_auto_renewed( $p['type'] ) ? 'Sempre rinnovata' : ( $until ? 'Valida fino al ' . ( new \DateTimeImmutable( $until ) )->format( 'd/m/Y' ) : '' );
		$ex    = self::extras( $p );
		$texts = array(
			'name'   => trim( $p['first_name'] . ' ' . $p['last_name'] ),
			'number' => '' !== (string) $p['card_number'] ? 'N. ' . $p['card_number'] : '',
			'valid'  => $when,
			'type'   => $ex['type'],
			'roles'  => $ex['roles'],
			'text'   => $ex['text'],
		);
		if ( ! $custom ) { // tessera standard: tutti i dati disponibili, nelle posizioni predefinite
			foreach ( $l as $k => $f ) {
				$l[ $k ]['show'] = 1;
			}
		}
		$pdf = new Pdf();
		$bg  = $custom ? Pdf::load_image( (string) self::attachment_bytes( (int) Settings::get( 'card_bg_id' ) ) ) : null;
		if ( $bg ) {
			$pdf->image( $bg, $x0, $y0, $w, $h );
		}
		// bordo sottile di taglio
		$pdf->line( $x0, $y0, $x0 + $w, $y0, 0.4 );
		$pdf->line( $x0 + $w, $y0, $x0 + $w, $y0 + $h, 0.4 );
		$pdf->line( $x0 + $w, $y0 + $h, $x0, $y0 + $h, 0.4 );
		$pdf->line( $x0, $y0 + $h, $x0, $y0, 0.4 );
		$logo_id = (int) Settings::get( 'logo_id' );
		$logo    = null;
		if ( $l['logo']['show'] ) {
			if ( $logo_id <= 0 ) {
				$logo_id = (int) attachment_url_to_postid( Frontend\Assets::logo_url() );
			}
			$logo = Pdf::load_image( (string) self::attachment_bytes( $logo_id ) );
		}
		if ( $logo ) {
			$lw = $l['logo']['size'] / 100 * $w;
			$pdf->image( $logo, $x0 + $l['logo']['x'] / 100 * $w, $y0 + $l['logo']['y'] / 100 * $h, $lw, $lw * $logo['h'] / $logo['w'] );
		}
		foreach ( $texts as $k => $s ) {
			if ( empty( $l[ $k ]['show'] ) || '' === $s ) {
				continue;
			}
			$size = $l[ $k ]['size'] / 100 * $w;
			$pdf->color( $l[ $k ]['color'] );
			$pdf->text( $x0 + $l[ $k ]['x'] / 100 * $w, $y0 + $l[ $k ]['y'] / 100 * $h + $size * 0.85, $s, $size );
		}
		$pdf->color( '' );
		if ( $l['qr']['show'] && Settings::card_qr_enabled() && MemberType::is_member( $p['type'] ) ) {
			try {
				$m = QrCode::matrix( Settings::card_url( (int) $p['id'] ) );
			} catch ( \InvalidArgumentException $e ) {
				$m = array();
			}
			$n = count( $m );
			if ( $n ) {
				$box = $l['qr']['size'] / 100 * $w;
				$mod = $box / ( $n + 8 ); // zona bianca di 4 moduli intorno, come sullo schermo
				$qx  = $x0 + $l['qr']['x'] / 100 * $w;
				$qy  = $y0 + $l['qr']['y'] / 100 * $h;
				$pdf->rect( $qx, $qy, $box, $box, 1.0 );
				foreach ( $m as $r => $row ) {
					$c = 0;
					while ( $c < $n ) {
						if ( empty( $row[ $c ] ) ) {
							$c++;
							continue;
						}
						$start = $c;
						while ( $c < $n && ! empty( $row[ $c ] ) ) {
							$c++;
						}
						$pdf->rect( $qx + ( 4 + $start ) * $mod, $qy + ( 4 + $r ) * $mod, ( $c - $start ) * $mod, $mod, 0.0 );
					}
				}
			}
		}
		return $pdf->output();
	}

	/** Incarichi di una persona: carica nel consiglio e attività di cui è nello staff nell'anno sociale in corso. */
	public static function roles_text( array $p ): string {
		$out = array();
		$br  = BoardRole::label( $p['board_role'] ?? null );
		if ( '' !== (string) $br ) {
			$out[] = $br;
		}
		$db   = Db::db();
		$rows = $db->get_col(
			$db->prepare(
				'SELECT a.name FROM ' . Db::t( 'activity_staff' ) . ' s JOIN ' . Db::t( 'activities' ) . ' a ON a.id = s.activity_id WHERE s.person_id = %d AND a.deleted_at IS NULL AND a.social_year = %s ORDER BY a.name',
				(int) $p['id'],
				Settings::social_year()->label()
			)
		);
		if ( $rows ) {
			$out[] = 'Staff: ' . implode( ', ', array_map( 'strval', $rows ) );
		}
		return implode( ' · ', $out );
	}

	/** Tipo di socio, incarichi e testo libero di una persona, pronti per la tessera. */
	public static function extras( array $p ): array {
		return array(
			'type'  => Levels::label( $p ),
			'roles' => self::roles_text( $p ),
			'text'  => trim( (string) Settings::get( 'card_text' ) ),
		);
	}
}
