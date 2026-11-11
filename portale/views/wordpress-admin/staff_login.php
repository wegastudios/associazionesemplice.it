<?php /** @var string $error */ $csrf = Portale\App::csrf(); ?>
<section class="admin"><div class="wrap" style="max-width:420px">
	<h1>Accesso alla gestione</h1>
	<p class="muted">Entra con l'email e la password dello staff. Il codice a due passaggi serve solo se lo hai attivato per te.</p>
	<?php if ( $error ) : ?><div class="flash" role="alert"><?php echo e( $error ); ?></div><?php endif; ?>
	<form method="post" action="/wordpress/admin/accedi" autocomplete="on">
		<input type="hidden" name="csrf" value="<?php echo e( $csrf ); ?>">
		<label>Email <input type="email" name="email" autocomplete="username" required autofocus></label>
		<label>Password <input type="password" name="password" autocomplete="current-password" required></label>
		<label>Codice a due passaggi (se attivo) <input type="text" name="code" inputmode="numeric" autocomplete="one-time-code" pattern="[0-9 ]{0,7}" maxlength="7"></label>
		<button class="btn" type="submit">Entra</button>
	</form>
</div></section>
