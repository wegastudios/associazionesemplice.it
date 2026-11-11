<section class="page"><div class="wrap page-narrow">
	<div class="panel">
		<h1>Password dimenticata</h1>
		<p class="muted">Scrivi la tua email: ti mandiamo un link per scegliere una nuova password. Vale un'ora.</p>
		<form method="post" action="/accedi/password-dimenticata">
			<input type="hidden" name="csrf" value="<?php echo e( Portale\App::csrf() ); ?>">
			<label for="email">Email</label>
			<input id="email" type="email" name="email" autocomplete="username" required>
			<button class="btn big block" type="submit">Mandami il link</button>
		</form>
	</div>
</div></section>
