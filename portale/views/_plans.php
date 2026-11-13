<?php /** Riepilogo dei piani (home e /prova). @var array $plans */
$euro = function ( $c ) { return number_format( $c / 100, ( $c % 100 ) ? 2 : 0, ',', '.' ) . ' €'; };
?>
<div class="plans mini">
<?php foreach ( $plans as $code => $p ) :
	if ( false === ( $p['visible'] ?? true ) ) { continue; }
	$m = $p['prices']['month']['cents'] ?? null; $y = $p['prices']['year']['cents'] ?? null;
	$hot = 'lite' === $code; $tag = $p['tag'] ?? ( $hot ? 'Per gli incassi online' : '' );
?>
	<div class="plan<?php echo $hot ? ' hot' : ''; ?>">
		<?php if ( $tag ) : ?><span class="tag"><?php echo e( $tag ); ?></span><?php endif; ?>
		<h3><?php echo e( $p['label'] ); ?></h3>
		<?php if ( 'free' === $code || ! $y ) : ?>
			<div class="price"><b>0 €</b><span>senza scadenza</span></div>
			<p class="price-note">La licenza gratuita è facoltativa</p>
		<?php else : ?>
			<div class="price"><b><?php echo e( $euro( $y ) ); ?></b><span>all'anno</span></div>
			<p class="price-note"><?php echo $m ? 'oppure ' . e( $euro( $m ) ) . ' al mese' : '&nbsp;'; ?></p>
		<?php endif; ?>
		<p class="for"><?php echo e( \Portale\Site::blurb( $code ) ); ?></p>
		<?php if ( 'free' === $code ) : ?>
			<a class="btn alt block" href="/free">Inizia gratis</a>
		<?php elseif ( ! \Portale\Billing::paymentsOn() ) : ?>
			<a class="btn alt block" href="/free">Avvisami quando si può acquistare</a>
		<?php else : ?>
			<a class="btn<?php echo $hot ? '' : ' alt'; ?> block" href="/acquista?plan=<?php echo e( \Portale\Site::slug( $code ) ); ?>&amp;period=year">Scegli questo piano</a>
		<?php endif; ?>
	</div>
<?php endforeach; ?>
</div>
<p class="fine center"><a href="/prezzi">Tutti i dettagli dei prezzi</a> · <a href="/confronto" data-confronto>Confronta le funzioni tra le versioni</a></p>
<script src="/assets/confronto.js" defer></script>
