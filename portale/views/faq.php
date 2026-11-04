<section class="section alt" id="domande"><div class="wrap">
	<div class="section-head"><h1 style="font-size:clamp(1.9rem,4vw,2.8rem)">Domande frequenti</h1></div>
	<div class="faq">
	<?php foreach ( Portale\Site::faq() as $q ) : ?>
		<details><summary><?php echo e( $q[0] ); ?></summary><p><?php echo e( $q[1] ); ?></p></details>
	<?php endforeach; ?>
	</div>
	<p class="fine center">Non trovi la risposta? Scrivici dal <a href="<?php echo e( \Portale\SupportSso::supportUrl() ); ?>">supporto</a>.</p>
</div></section>
