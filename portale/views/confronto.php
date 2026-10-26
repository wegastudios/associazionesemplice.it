<?php
// Confronto Free / Pro Contabile / Pro Fiscale: il testo sta in content/confronto.md (tabelle, titoli, elenchi, un solo livello di grassetto e di link).
$inline = function ( string $t ): string {
	$t = e( $t );
	$t = preg_replace( '/\*\*(.+?)\*\*/', '<strong>$1</strong>', $t );
	$t = preg_replace_callback( '/\[([^\]]+)\]\((https:\/\/[^)\s]+)\)/', function ( $m ) {
		return '<a href="' . $m[2] . '" target="_blank" rel="noopener">' . $m[1] . '</a>';
	}, $t );
	return $t;
};
// Prezzi sempre quelli in vigore (con il listino tra parentesi durante una promozione): nel testo i segnaposto {lite_month}, {lite_year}, {full_month}, {full_year}.
$priceText = function ( string $code, string $period ): string {
	$c = (int) Portale\Config::get( 'plans.' . $code . '.prices.' . $period . '.cents', 0 );
	if ( ! $c ) {
		return '—';
	}
	$l = Portale\Settings::listCents( $code, $period );
	return money( $c ) . ( $l ? ' (listino ' . money( $l ) . ')' : '' );
};
$md = (string) file_get_contents( __DIR__ . '/../content/confronto.md' );
$md = strtr( $md, array(
	'{lite_month}' => $priceText( 'lite', 'month' ), '{lite_year}' => $priceText( 'lite', 'year' ),
	'{full_month}' => $priceText( 'full', 'month' ), '{full_year}' => $priceText( 'full', 'year' ),
) );
$promoName = Portale\Settings::promoNow();
$promoEnd  = (string) Portale\Config::get( 'promo.end', '' );
$lines     = preg_split( '/\r?\n/', $md );
$html  = '';
$mode  = '';
$close = function () use ( &$html, &$mode ) {
	if ( 'table' === $mode ) {
		$html .= "</tbody></table></div>\n";
	} elseif ( 'ul' === $mode ) {
		$html .= "</ul>\n";
	}
	$mode = '';
};
foreach ( $lines as $line ) {
	$line = rtrim( $line );
	if ( '' === $line ) {
		$close();
		continue;
	}
	if ( 0 === strpos( $line, '## ' ) ) {
		$close();
		$html .= '<h2>' . $inline( substr( $line, 3 ) ) . "</h2>\n";
	} elseif ( 0 === strpos( $line, '|' ) ) {
		$cells = array_map( 'trim', explode( '|', trim( $line, '|' ) ) );
		if ( preg_match( '/^[\s\-|:]+$/', $line ) ) {
			continue;
		}
		if ( 'table' !== $mode ) {
			$close();
			$html .= '<div class="cmp-wrap"><table class="cmp"><thead><tr>';
			foreach ( $cells as $c ) {
				$html .= '<th>' . $inline( $c ) . '</th>';
			}
			$html .= '</tr></thead><tbody>';
			$mode = 'table';
			continue;
		}
		$html .= '<tr>';
		foreach ( $cells as $i => $c ) {
			$mark = '✓' === $c ? ' class="yes"' : ( '—' === $c ? ' class="no"' : '' );
			$html .= ( 0 === $i ? '<th scope="row">' : '<td' . $mark . '>' ) . $inline( $c ) . ( 0 === $i ? '</th>' : '</td>' );
		}
		$html .= '</tr>';
	} elseif ( 0 === strpos( $line, '- ' ) ) {
		if ( 'ul' !== $mode ) {
			$close();
			$html .= '<ul>';
			$mode = 'ul';
		}
		$html .= '<li>' . $inline( substr( $line, 2 ) ) . '</li>';
	} elseif ( 0 === strpos( $line, '> ' ) ) {
		$close();
		$html .= '<p class="hint">' . $inline( substr( $line, 2 ) ) . "</p>\n";
	} else {
		$close();
		$html .= '<p>' . $inline( $line ) . "</p>\n";
	}
}
$close();
?>
<style>
.cmp-wrap{overflow-x:auto;margin:8px 0 28px}
.cmp{width:100%;border-collapse:collapse;font-size:15px}
.cmp th,.cmp td{padding:9px 12px;border-bottom:1px solid var(--line,#e3e6ee);text-align:center;vertical-align:top}
.cmp thead th{font-weight:700;white-space:nowrap}
.cmp tbody th{text-align:left;font-weight:500}
.cmp thead th:first-child{text-align:left}
.cmp td.yes{color:#2f7d4f;font-weight:700}
.cmp td.no{color:#8b90a0}
.cmp-page h2{margin-top:36px}
</style>
<section class="page"><div class="wrap cmp-page" style="max-width:920px">
	<span class="eyebrow">Free, Pro Contabile e Pro Fiscale</span>
	<h1>Confronto tra le versioni</h1>
	<?php if ( $promoName ) : ?><p><span class="promo-tag" style="margin:0"><?php echo e( $promoName ); ?></span> <b>Prezzi in promozione fino al <?php echo e( date( 'd/m/Y', strtotime( $promoEnd ) ) ); ?></b>: nell'ultima riga della tabella trovi il prezzo in vigore e, tra parentesi, il listino.</p><?php endif; ?>
	<?php echo $html; ?>
	<p class="actions"><a class="btn" href="/#prezzi">Vedi i piani e i prezzi</a> <a class="btn alt" href="/free">Ottieni la licenza gratuita</a></p>
</div></section>
