<?php /** @var string $error */ ?>
<section class="page"><div class="wrap page-narrow">
	<div class="panel">
		<h1>Codice di verifica</h1>
		<p class="muted">Scrivi il codice a sei cifre che trovi nell'app di autenticazione.</p>
		<?php if ( ! empty( $error ) ) : ?><div class="flash" role="alert"><?php echo e( $error ); ?></div><?php endif; ?>
		<form method="post" action="/twofactor">
			<input type="hidden" name="csrf" value="<?php echo e( Portale\App::csrf() ); ?>">
			<label for="code">Codice</label>
			<input id="code" type="text" name="code" inputmode="numeric" autocomplete="one-time-code" maxlength="12" required autofocus>
			<button class="btn big block" type="submit">Conferma</button>
		</form>
		<p class="hint" style="margin-top:16px"><a href="/accedi">Torna all'accesso</a></p>
	</div>
</div></section>
