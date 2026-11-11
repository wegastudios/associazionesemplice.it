<section class="page"><div class="wrap page-narrow">
	<div class="panel">
		<h1>Accedi</h1>
		<?php if ( ! empty( $for_support ) ) : ?><p class="flash" role="status">Entra per scrivere al supporto: dopo l'accesso torni subito al centro assistenza. Serve per sapere a quale licenza si riferisce la richiesta.</p><?php endif; ?>
		<p class="muted">Entra con la tua email e la tua password.</p>
		<form method="post" action="/accedi">
			<input type="hidden" name="csrf" value="<?php echo e( Portale\App::csrf() ); ?>">
			<label for="email">Email</label>
			<input id="email" type="email" name="email" placeholder="segreteria@miaassociazione.it" autocomplete="username" required>
			<label for="password">Password</label>
			<input id="password" type="password" name="password" autocomplete="current-password">
			<button class="btn big block" type="submit">Entra</button>
			<?php if ( ! empty( $identity ) ) : ?><p class="hint" style="margin-top:16px"><a href="/accedi/password-dimenticata">Password dimenticata?</a></p><?php endif; ?>
			<p class="hint" style="margin-top:16px">Prima volta o password dimenticata? <button class="linklike" type="submit" name="mode" value="link" formnovalidate>Mandami un link per entrare</button> (vale 15 minuti): da lì puoi scegliere la password.</p>
		</form>
	</div>
</div></section>
