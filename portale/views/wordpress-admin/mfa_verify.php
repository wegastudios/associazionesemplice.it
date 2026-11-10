<?php /** @var string $flash @var string $admin_email */ $csrf = Portale\App::csrf(); ?>
<section class="admin"><div class="wrap" style="max-width:420px">
	<h1>Verifica in due passaggi</h1>
	<p class="muted">Hai attivato la verifica per <?php echo e( $admin_email ); ?>. Inserisci il codice a 6 cifre della tua app di autenticazione.</p>
	<?php if ( $flash ) : ?><div class="flash" role="status"><?php echo e( $flash ); ?></div><?php endif; ?>
	<form method="post" action="/wordpress/admin/verifica">
		<input type="hidden" name="csrf" value="<?php echo e( $csrf ); ?>">
		<label>Codice <input type="text" name="code" inputmode="numeric" autocomplete="one-time-code" pattern="[0-9 ]{6,7}" maxlength="7" required autofocus></label>
		<button class="btn" type="submit">Conferma</button>
	</form>
</div></section>
