<?php /** @var string $action @var string $token @var string $name */ ?>
<section class="page"><div class="wrap page-narrow">
	<h1>Apertura: <?php echo e( $name ); ?></h1>
	<p class="muted">Ti porto lì senza un altro accesso.</p>
	<form id="vai" method="post" action="<?php echo e( $action ); ?>">
		<input type="hidden" name="token" value="<?php echo e( $token ); ?>">
		<button class="btn" type="submit">Continua</button>
	</form>
	<script>document.getElementById('vai').submit();</script>
</div></section>
