<section class="section" id="funzioni"><div class="wrap">
	<div class="section-head"><h1 style="font-size:clamp(1.9rem,4vw,2.8rem)">Tutto quello che serve a una segreteria</h1><p>Pensato per chi gestisce un'associazione nel tempo libero: poche schermate, parole chiare, niente da imparare a memoria.</p></div>
	<div class="grid-3">
	<?php foreach ( Portale\Site::features() as $f ) : ?>
		<div class="feature"><div class="ico"><?php echo icon( $f[0] ); ?></div><h3><?php echo e( $f[1] ); ?></h3><p><?php echo e( $f[2] ); ?></p></div>
	<?php endforeach; ?>
	</div>
	<p class="fine center">Ogni funzione, una per una, nel <a href="/confronto" data-confronto>confronto tra le versioni</a>.</p>
</div></section>
<script src="/assets/confronto.js" defer></script>
