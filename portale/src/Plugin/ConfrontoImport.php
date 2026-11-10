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
