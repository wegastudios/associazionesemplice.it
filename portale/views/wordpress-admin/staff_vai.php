<?php /** @var string $action @var string $token @var string $name */ ?>
<section class="admin"><div class="wrap" style="max-width:420px">
	<h1>Apertura di <?php echo e( $name ); ?></h1>
	<p class="muted">Ti porto lì senza un altro accesso.</p>
	<form id="vai" method="post" action="<?php echo e( $action ); ?>">
		<input type="hidden" name="token" value="<?php echo e( $token ); ?>">
		<button class="btn" type="submit">Continua</button>
	</form>
	<script>document.getElementById('vai').submit();</script>
</div></section>
