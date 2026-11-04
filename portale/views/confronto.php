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
// Nomi dei piani sempre quelli del pannello (Prezzi → «Nome sul sito»); i nomi scritti a mano nel testo sono segnaposto.
$planName = function ( string $code, string $fallback ): string {
	$n = trim( (string) Portale\Config::get( 'plans.' . $code . '.label', '' ) );
	return '' !== $n ? $n : $fallback;
};
// Dati dei piani per l'intestazione fissa del confronto (nome, prezzo, pulsante): li legge confronto.js dall'attributo data-cmp.
$cmpBase = rtrim( (string) Portale\Config::get( 'base_url', '' ), '/' );
$cmpPlans = array();
foreach ( (array) Portale\Config::get( 'plans', array() ) as $code => $p ) {
	if ( false === ( $p['visible'] ?? true ) ) {
		continue;
	}
	$y = (int) ( $p['prices']['year']['cents'] ?? 0 );
	$m = (int) ( $p['prices']['month']['cents'] ?? 0 );
	$ly = $y ? (int) Portale\Settings::listCents( $code, 'year' ) : 0;
	$row = array( 'code' => $code, 'name' => $planName( $code, (string) $code ), 'hot' => 'lite' === $code, 'blurb' => Portale\Site::blurb( $code ) );
	if ( 'free' === $code || ! $y ) {
		$row += array( 'price' => '0 €', 'per' => 'senza scadenza', 'alt' => 'Serve solo la licenza gratuita', 'url' => $cmpBase . '/free', 'cta' => 'Inizia gratis' );
	} else {
		$row += array( 'price' => money( $y ), 'per' => 'all\'anno', 'alt' => $m ? 'oppure ' . money( $m ) . ' al mese' : '', 'url' => $cmpBase . '/acquista?plan=' . Portale\Site::slug( $code ) . '&period=year', 'cta' => 'Scegli' );
		if ( $ly && $ly > $y ) {
			$row['was'] = money( $ly );
		}
	}
	$cmpPlans[] = $row;
}
$cmpData = e( json_encode( array( 'plans' => $cmpPlans ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) );
$md = strtr( $md, array(
	'{free_name}' => $planName( 'free', 'Free' ), '{lite_name}' => $planName( 'lite', 'Pro Contabile' ), '{full_name}' => $planName( 'full', 'Pro Fiscale' ),
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
$fragment = ! empty( $fragment );
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
<?php if ( $fragment ) : // frammento per la demo: solo titoli e tabelle, senza intestazione né pulsanti ?>
<div class="cmp-page" data-cmp="<?php echo $cmpData; ?>"><?php echo $html; ?></div>
<?php return; endif; ?>
<section class="page"><div class="wrap cmp-page" style="max-width:920px">
	<span class="eyebrow">Cosa cambia tra i piani</span>
	<h1>Confronto tra le versioni</h1>
	<?php if ( $promoName ) : ?><p><span class="promo-tag" style="margin:0"><?php echo e( $promoName ); ?></span> <b>Prezzi in promozione fino al <?php echo e( date( 'd/m/Y', strtotime( $promoEnd ) ) ); ?></b>: nell'intestazione di ogni piano trovi il prezzo in vigore e il listino barrato.</p><?php endif; ?>
	<div data-confronto-inline data-cmp="<?php echo $cmpData; ?>"><?php echo $html; ?></div>
	<script src="/assets/confronto.js" defer></script>
	
</div></section>
