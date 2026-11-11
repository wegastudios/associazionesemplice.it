<section class="page"><div class="wrap page-narrow">
	<div class="panel">
		<h1>Vuoi uscire?</h1>
		<p class="muted">Esci da tutte le aree: portale, supporto e gestionale.</p>
		<form method="post" action="/account/esci">
			<input type="hidden" name="csrf" value="<?php echo e( Portale\App::csrf() ); ?>">
			<button class="btn big block" type="submit">Esci</button>
		</form>
		<p class="hint" style="margin-top:16px"><a href="/account">Torna al tuo account</a></p>
	</div>
</div></section>
