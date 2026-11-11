<?php /** @var string $code @var int $min */ ?>
<section class="page"><div class="wrap page-narrow">
	<div class="panel">
		<h1>Scegli la nuova password</h1>
		<p class="muted">Almeno <?php echo (int) $min; ?> caratteri. Le altre sessioni aperte verranno chiuse.</p>
		<form method="post" action="/accedi/reimposta/<?php echo e( $code ); ?>">
			<input type="hidden" name="csrf" value="<?php echo e( Portale\App::csrf() ); ?>">
			<label for="pw-new">Nuova password</label>
			<input id="pw-new" type="password" name="password" autocomplete="new-password" minlength="<?php echo (int) $min; ?>" required>
			<label for="pw-conf">Ripeti la password</label>
			<input id="pw-conf" type="password" name="confirm" autocomplete="new-password" minlength="<?php echo (int) $min; ?>" required>
			<button class="btn big block" type="submit">Salva la password</button>
		</form>
	</div>
</div></section>
