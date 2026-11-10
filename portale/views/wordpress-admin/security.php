<?php /** @var bool $on @var string $secret @var string $uri */ include __DIR__ . '/_nav.php'; $csrf = Portale\App::csrf(); ?>
<section class="admin"><div class="wrap" style="max-width:560px">
	<h2>Verifica in due passaggi</h2>
	<p class="muted">È facoltativa e vale solo per te: di base è spenta. Se la attivi, a ogni nuovo accesso (e poi ogni 8 ore) ti viene chiesto un codice a 6 cifre generato da un'app di autenticazione sul telefono.</p>
	<?php if ( $on ) : ?>
		<p><strong>Stato: attiva.</strong></p>
		<form method="post" action="/wordpress/admin/sicurezza/spegni" class="inline-form">
			<input type="hidden" name="csrf" value="<?php echo e( $csrf ); ?>">
			<label>Per spegnerla inserisci un codice <input type="text" name="code" inputmode="numeric" autocomplete="one-time-code" maxlength="7" required></label>
			<button class="btn small" type="submit">Spegni</button>
		</form>
	<?php else : ?>
		<p><strong>Stato: spenta.</strong> Per attivarla:</p>
		<ol>
			<li>Nella tua app di autenticazione aggiungi un account con inserimento manuale e questa chiave: <code><?php echo e( implode( ' ', str_split( $secret, 4 ) ) ); ?></code> (tipo «basato sul tempo», 6 cifre).</li>
			<li>Scrivi qui sotto il codice che l'app mostra.</li>
		</ol>
		<p class="muted small">Indirizzo per le app che lo accettano: <code style="word-break:break-all"><?php echo e( $uri ); ?></code></p>
		<form method="post" action="/wordpress/admin/sicurezza/attiva" class="inline-form">
			<input type="hidden" name="csrf" value="<?php echo e( $csrf ); ?>">
			<label>Codice <input type="text" name="code" inputmode="numeric" autocomplete="one-time-code" maxlength="7" required></label>
			<button class="btn small" type="submit">Attiva</button>
		</form>
	<?php endif; ?>
</div></section>
