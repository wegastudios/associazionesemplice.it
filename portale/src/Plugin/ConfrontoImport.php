<?php
namespace Portale\Plugin;

/**
 * Porta le righe di `content/confronto.md` nel catalogo: ogni riga di tabella diventa una caratteristica con codice stabile
 * (ricavato dal titolo breve), gruppo (il titolo «##»), eventuale chiave `compare` e, per ogni piano, stato e nota.
 * Colonne in ordine: Gratuito, Pro Contabile, Pro Fiscale → i primi tre codici piano passati (free, procontabile, profiscale).
 * Le righe con segnaposto ({lite_month}…) e quelle senza tre celle valide non entrano: i prezzi stanno nel catalogo, non nelle caratteristiche.
 */
final class ConfrontoImport {

	/** Parole del titolo → chiave comune; vince la prima che corrisponde. */
	const COMPARE_MAP = array(
		'libro soci'        => 'members',
		'schede dei soci'   => 'members',
		'area riservata dei soci' => 'member_area',
		'area soci'         => 'member_area',
		'prima nota'        => 'ledger',
		'corsi'             => 'activities',
		'eventi'            => 'activities',
		'campi e sale'      => 'resources',
		'risorse'           => 'resources',
		'incasso'           => 'online_payments',
		'pagamenti online'  => 'online_payments',
		'fattur'            => 'einvoice',
		'iva'               => 'fiscal',
		'5 per mille'       => 'fiscal',
		'rendiconto'        => 'fiscal',
		'prova gratuita'    => 'trial',
	);

	/**
	 * @param string[] $codes codici dei piani del catalogo
	 * @return array{features: array, matrix: array}
	 */
	public static function parse( string $md, array $codes ): array {
		$cols     = array();
		foreach ( array( 'free', 'procontabile', 'profiscale' ) as $c ) {
			if ( in_array( $c, $codes, true ) ) {
				$cols[] = $c;
			}
		}
		$features = array();
		$matrix   = array();
		$used     = array();
		$group    = '';
		foreach ( preg_split( '/\R/', $md ) as $line ) {
			if ( 1 === preg_match( '/^##\s+(.+)$/u', $line, $m ) ) {
				$group = trim( $m[1] );
				continue;
			}
			if ( '|' !== substr( $line, 0, 1 ) || 1 === preg_match( '/^\|\s*-/', $line ) ) {
				continue;
			}
			$cells = array_map( 'trim', explode( '|', trim( trim( $line ), '|' ) ) );
			if ( 4 !== count( $cells ) || count( $cols ) < 3 || '' === $cells[0] || 'Funzione' === $cells[0] || false !== strpos( $line, '{' ) ) {
				continue;
			}
			$title  = $cells[0];
			$detail = '';
			if ( false !== strpos( $title, ' :: ' ) ) {
				list( $title, $detail ) = array_map( 'trim', explode( ' :: ', $title, 2 ) );
			}
			$text = $title;
			if ( mb_strlen( $text ) > 160 ) {
				$detail = '' === $detail ? $title : $detail;
				$text   = rtrim( mb_substr( $text, 0, 157 ) ) . '…';
			}
			$id = self::slug( $title, $used );
			$features[] = array(
				'id'      => $id,
				'text'    => $text,
				'group'   => mb_substr( $group, 0, 60 ),
				'compare' => self::compare( $title, $group ),
				'detail'  => mb_substr( $detail, 0, 600 ),
			);
			foreach ( $cols as $i => $code ) {
				$v = $cells[ $i + 1 ];
				if ( '✓' === $v ) {
					$cell = array( 'state' => 'included', 'note' => '' );
				} elseif ( '—' === $v || '' === $v ) {
					$cell = array( 'state' => 'excluded', 'note' => '' );
				} else {
					$cell = array( 'state' => 'limit', 'note' => mb_substr( $v, 0, 120 ) );
				}
				$matrix[ $code ][ $id ] = $cell;
			}
		}
		return array( 'features' => $features, 'matrix' => $matrix );
	}

	/**
	 * Come parse(), ma tenendo i codici già nel catalogo: una riga ritrovata (stesso codice, stesso testo o titolo molto simile con le
	 * stesse celle) conserva il codice che aveva, così rinominare un titolo nel confronto non crea una caratteristica nuova.
	 * Dice anche cosa cambierebbe: nuove, rinominate (codice → vecchio e nuovo testo) e sparite (nel catalogo ma non più nel confronto).
	 *
	 * @param string[] $codes    codici dei piani del catalogo
	 * @param array    $existing caratteristiche già nella bozza ([id, text, group…])
	 * @param array    $existingMatrix tabella della bozza (piano → caratteristica → stato e nota), per riconoscere le righe rinominate
	 * @return array{features: array, matrix: array, new: array, renamed: array, removed: array, same: int, digest: string}
	 */
	public static function plan( string $md, array $codes, array $existing, array $existingMatrix = array() ): array {
		$p      = self::parse( $md, $codes );
		$exById = array();
		foreach ( $existing as $f ) {
			if ( isset( $f['id'] ) && is_string( $f['id'] ) ) {
				$exById[ $f['id'] ] = $f;
			}
		}
		$final   = array(); // codice dal titolo → codice finale
		$taken   = array(); // codici del catalogo già assegnati
		$pending = array(); // indici delle righe non ancora abbinate
		foreach ( $p['features'] as $i => $f ) {
			if ( isset( $exById[ $f['id'] ] ) && ! isset( $taken[ $f['id'] ] ) ) {
				$final[ $f['id'] ] = $f['id'];
				$taken[ $f['id'] ] = true;
				continue;
			}
			$same = '';
			foreach ( $exById as $id => $e ) {
				if ( ! isset( $taken[ $id ] ) && self::norm( (string) ( $e['text'] ?? '' ) ) === self::norm( $f['text'] ) ) {
					$same = (string) $id;
					break;
				}
			}
			if ( '' !== $same ) {
				$final[ $f['id'] ] = $same;
				$taken[ $same ]    = true;
			} else {
				$pending[] = $i;
			}
		}
		// Rinominate: titoli molto simili, o abbastanza simili con le stesse celle in tutti i piani.
		$cands = array();
		foreach ( $pending as $i ) {
			$f = $p['features'][ $i ];
			foreach ( $exById as $id => $e ) {
				if ( isset( $taken[ $id ] ) ) {
					continue;
				}
				similar_text( self::norm( (string) ( $e['text'] ?? '' ) ), self::norm( $f['text'] ), $pct );
				$cells = self::cells( $p['matrix'], $codes, $f['id'] ) === self::cells( $existingMatrix, $codes, (string) $id ) && '' !== self::cells( $p['matrix'], $codes, $f['id'] );
				if ( $pct >= 72 || ( $pct >= 50 && $cells && ( $e['group'] ?? '' ) === $f['group'] ) ) {
					$cands[] = array( $pct, $i, (string) $id );
				}
			}
		}
		usort( $cands, function ( $a, $b ) { return $b[0] <=> $a[0] ?: $a[1] <=> $b[1]; } );
		$done = array();
		foreach ( $cands as $c ) {
			if ( isset( $done[ $c[1] ] ) || isset( $taken[ $c[2] ] ) ) {
				continue;
			}
			$final[ $p['features'][ $c[1] ]['id'] ] = $c[2];
			$taken[ $c[2] ]                          = true;
			$done[ $c[1] ]                           = true;
		}
		// Le nuove tengono il codice dal titolo, ma mai uno già preso da una riga abbinata.
		$new = array();
		foreach ( $pending as $i ) {
			if ( isset( $done[ $i ] ) ) {
				continue;
			}
			$id = $p['features'][ $i ]['id'];
			for ( $n = 2; isset( $taken[ $id ] ) || isset( $exById[ $id ] ); ++$n ) {
				$id = $p['features'][ $i ]['id'] . '_' . $n;
			}
			$final[ $p['features'][ $i ]['id'] ] = $id;
			$taken[ $id ]                        = true;
			$new[]                               = array( 'id' => $id, 'text' => $p['features'][ $i ]['text'], 'group' => $p['features'][ $i ]['group'] );
		}
		$features = array();
		$matrix   = array();
		$renamed  = array();
		$same     = 0;
		foreach ( $p['features'] as $f ) {
			$old  = $f['id'];
			$f['id'] = $final[ $old ];
			$features[] = $f;
			foreach ( $p['matrix'] as $code => $row ) {
				if ( isset( $row[ $old ] ) ) {
					$matrix[ $code ][ $f['id'] ] = $row[ $old ];
				}
			}
			if ( isset( $exById[ $f['id'] ] ) ) {
				if ( $exById[ $f['id'] ]['text'] !== $f['text'] ) {
					$renamed[] = array( 'id' => $f['id'], 'old' => (string) $exById[ $f['id'] ]['text'], 'new' => $f['text'] );
				} else {
					++$same;
				}
			}
		}
		$removed = array();
		foreach ( $exById as $id => $e ) {
			if ( ! isset( $taken[ $id ] ) ) {
				$removed[] = array( 'id' => (string) $id, 'text' => (string) ( $e['text'] ?? '' ), 'group' => (string) ( $e['group'] ?? '' ) );
			}
		}
		return array(
			'features' => $features, 'matrix' => $matrix, 'new' => $new, 'renamed' => $renamed, 'removed' => $removed, 'same' => $same,
			'digest'   => hash( 'sha256', (string) json_encode( array( $features, $matrix, $removed ) ) ),
		);
	}

	/** Titolo senza maiuscole, accenti e segni, per il confronto tra vecchio e nuovo. */
	private static function norm( string $t ): string {
		$t = strtr( mb_strtolower( $t ), array( 'à' => 'a', 'è' => 'e', 'é' => 'e', 'ì' => 'i', 'ò' => 'o', 'ù' => 'u' ) );
		return trim( (string) preg_replace( '/[^a-z0-9]+/', ' ', $t ) );
	}

	/** Gli stati di una riga nei piani, come testo (per riconoscere una riga rinominata con le stesse celle). */
	private static function cells( array $matrix, array $codes, string $id ): string {
		$s = '';
		foreach ( array( 'free', 'procontabile', 'profiscale' ) as $c ) {
			if ( in_array( $c, $codes, true ) ) {
				$s .= ( $matrix[ $c ][ $id ]['state'] ?? '?' ) . ':' . ( $matrix[ $c ][ $id ]['note'] ?? '' ) . ';';
			}
		}
		return str_replace( '?::;', '', $s );
	}

	private static function compare( string $title, string $group ): string {
		$t = mb_strtolower( $title );
		foreach ( self::COMPARE_MAP as $needle => $key ) {
			if ( 1 === preg_match( '/\b' . preg_quote( $needle, '/' ) . '/u', $t ) ) {
				return $key;
			}
		}
		return 'Parte fiscale' === $group ? 'fiscal' : '';
	}

	/** Codice stabile dal titolo: minuscole, senza accenti, trattino basso, al massimo 40 caratteri; i doppioni prendono un numero. */
	private static function slug( string $title, array &$used ): string {
		$s = strtr( mb_strtolower( $title ), array( 'à' => 'a', 'è' => 'e', 'é' => 'e', 'ì' => 'i', 'ò' => 'o', 'ù' => 'u' ) );
		$s = trim( (string) preg_replace( '/[^a-z0-9]+/', '_', $s ), '_' );
		$s = rtrim( substr( $s, 0, 36 ), '_' );
		if ( strlen( $s ) < 2 ) {
			$s = 'voce';
		}
		$id = $s;
		for ( $n = 2; isset( $used[ $id ] ); ++$n ) {
			$id = $s . '_' . $n;
		}
		$used[ $id ] = true;
		return $id;
	}
}
