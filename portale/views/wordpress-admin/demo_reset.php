<?php /** @var array $rows @var bool $can_run */ include __DIR__ . '/_nav.php';
$csrf    = Portale\App::csrf();
$running = false;
foreach ( $rows as $r ) {
	$running = $running || $r['running'];
}
$when = function ( int $t ): string { return $t > 0 ? gmdate( 'd/m/Y H:i', $t ) . ' UTC' : '—'; };
?>
<section class="admin"><div class="wrap">
	<?php if ( ! empty( $flash ) ) : ?><p class="notice" role="status"><?php echo e( $flash ); ?></p><?php endif; ?>
	<p class="muted">Riporta una demo pubblica allo stato iniziale. <strong>Cancella tutti i dati inseriti nella demo (anche file e caricamenti) e non si può annullare.</strong> Succede già ogni notte alle 4: usa questi tasti solo se serve subito. Il reset gira in background e dura qualche minuto; si può farne uno alla volta per demo. Chi lo avvia e l'esito restano nel registro.</p>
	<?php if ( ! $can_run ) : ?><p class="notice err" role="alert">Il server non permette al portale di lanciare comandi: i tasti sono spenti.</p><?php endif; ?>
	<?php if ( ! $rows ) : ?><p class="muted">Nessuna demo è ancora collegata al portale (manca <code>demo_reset</code> in config.php).</p><?php endif; ?>
	<?php foreach ( $rows as $k => $r ) : ?>
	<div class="card" style="margin:16px 0;padding:18px">
		<h2 style="margin-top:0"><?php echo e( $r['label'] ); ?></h2>
		<?php if ( '' !== $r['note'] ) : ?><p class="muted small"><?php echo e( $r['note'] ); ?></p><?php endif; ?>
		<p>
		<?php if ( ! $r['installed'] ) : ?>
			<span class="badge">Non ancora installata</span> <?php echo e( $r['label'] ); ?> non c'è ancora sul server: il tasto si accende quando sarà installata.
		<?php elseif ( $r['running'] ) : ?>
			<span class="badge">In corso…</span> la pagina si aggiorna da sola.
		<?php elseif ( $r['last'] ) : ?>
			Ultimo reset: <span class="badge"><?php echo 0 === (int) $r['last']['exit'] ? 'riuscito' : 'con errore (codice ' . (int) $r['last']['exit'] . ')'; ?></span>
			avviato da <?php echo e( $r['last']['actor'] ); ?> il <?php echo e( $when( (int) $r['last']['started'] ) ); ?>, finito il <?php echo e( $when( (int) $r['last']['finished'] ) ); ?>.
		<?php else : ?>
			<span class="muted">Nessun reset lanciato da qui finora.</span>
		<?php endif; ?>
		</p>
		<?php if ( '' !== $r['log'] ) : ?><pre class="small" style="white-space:pre-wrap;max-height:220px;overflow:auto"><?php echo e( $r['log'] ); ?></pre><?php endif; ?>
		<form method="post" action="/wordpress/admin/demo/<?php echo e( $k ); ?>" class="demo-reset-form" data-name="<?php echo e( $r['label'] ); ?>">
			<input type="hidden" name="csrf" value="<?php echo e( $csrf ); ?>">
			<label style="display:block;margin:10px 0"><input type="checkbox" name="confirm" value="1" required> Ho capito: cancella tutti i dati inseriti nella demo e non si può annullare.</label>
			<button class="btn" type="submit"<?php echo ( $r['running'] || ! $can_run || ! $r['installed'] ) ? ' disabled' : ''; ?>>Azzera la <?php echo e( strtolower( $r['label'] ) ); ?></button>
		</form>
	</div>
	<?php endforeach; ?>
</div></section>
<script>
document.querySelectorAll('.demo-reset-form').forEach(function (f) {
	f.addEventListener('submit', function (ev) {
		if (!window.confirm('Azzerare davvero: ' + f.getAttribute('data-name') + '? Cancella tutti i dati inseriti e non si può annullare. Chi è dentro la demo in questo momento perde la sessione.')) { ev.preventDefault(); return; }
		var b = f.querySelector('button'); if (b) { setTimeout(function () { b.disabled = true; }, 0); } // un solo clic: il secondo non parte
	});
});
<?php if ( $running ) : ?>setTimeout(function () { location.reload(); }, 8000);<?php endif; ?>
</script>
