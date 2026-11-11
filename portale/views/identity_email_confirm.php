<?php /** @var string $code */ ?>
<section class="page"><div class="wrap page-narrow">
	<div class="panel">
		<h1>Conferma il nuovo indirizzo</h1>
		<p class="muted">Premi il pulsante per usare questo indirizzo email per entrare.</p>
		<form method="post" action="/accedi/conferma-email/<?php echo e( $code ); ?>">
			<input type="hidden" name="csrf" value="<?php echo e( Portale\App::csrf() ); ?>">
			<button class="btn big block" type="submit">Conferma</button>
		</form>
	</div>
</div></section>
