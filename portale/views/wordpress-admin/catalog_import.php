<?php /** @var array $plan @var string $nav @var string $flash @var string $admin_email */ include __DIR__ . '/_nav.php';
$csrf = Portale\App::csrf();
$n    = count( $plan['new'] );
$r    = count( $plan['renamed'] );
$x    = count( $plan['removed'] );
?>
<section class="admin"><div class="wrap" style="max-width:980px">
	<h2>Importazione dal confronto: cosa cambia</h2>
	<p class="muted">Niente è ancora stato cambiato. Se confermi, la bozza del catalogo prende le righe del confronto di oggi; non pubblica niente e puoi sempre scartare la bozza.</p>
	<ul>
		<li><strong><?php echo (int) $plan['same']; ?></strong> righe invariate (stesso codice e stesso testo)</li>
		<li><strong><?php echo $r; ?></strong> rinominate: tengono il loro codice, cambia solo il testo</li>
		<li><strong><?php echo $n; ?></strong> nuove: entrano con un codice nuovo</li>
		<li><strong><?php echo $x; ?></strong> tolte: sono nel catalogo ma non più nel confronto</li>
	</ul>

	<?php if ( $r ) : ?>
	<div class="card2"><h3>Rinominate</h3>
		<p class="muted small">Ho riconosciuto la riga dal titolo molto simile (o dalle stesse celle in tutti i piani). Se una non è davvero la stessa riga, non confermare e correggi prima il confronto.</p>
		<div class="tbl-wrap"><table class="tbl"><thead><tr><th scope="col">Codice</th><th scope="col">Prima</th><th scope="col">Dopo</th></tr></thead><tbody>
		<?php foreach ( $plan['renamed'] as $f ) : ?><tr><td><code><?php echo e( $f['id'] ); ?></code></td><td><?php echo e( $f['old'] ); ?></td><td><?php echo e( $f['new'] ); ?></td></tr><?php endforeach; ?>
		</tbody></table></div></div>
	<?php endif; ?>

	<?php if ( $n ) : ?>
	<div class="card2"><h3>Nuove</h3>
		<div class="tbl-wrap"><table class="tbl"><thead><tr><th scope="col">Codice</th><th scope="col">Testo</th><th scope="col">Gruppo</th></tr></thead><tbody>
		<?php foreach ( $plan['new'] as $f ) : ?><tr><td><code><?php echo e( $f['id'] ); ?></code></td><td><?php echo e( $f['text'] ); ?></td><td><?php echo e( $f['group'] ); ?></td></tr><?php endforeach; ?>
		</tbody></table></div></div>
	<?php endif; ?>

	<?php if ( $x ) : ?>
	<div class="card2"><h3>Tolte dalla bozza</h3>
		<p class="muted small">Se una di queste è in realtà una riga rinominata che non ho riconosciuto, non confermare: correggi il confronto e rifai l'importazione. Le righe tolte spariscono dalla bozza, non dal catalogo già pubblicato.</p>
		<div class="tbl-wrap"><table class="tbl"><thead><tr><th scope="col">Codice</th><th scope="col">Testo</th><th scope="col">Gruppo</th></tr></thead><tbody>
		<?php foreach ( $plan['removed'] as $f ) : ?><tr><td><code><?php echo e( $f['id'] ); ?></code></td><td><?php echo e( $f['text'] ); ?></td><td><?php echo e( $f['group'] ); ?></td></tr><?php endforeach; ?>
		</tbody></table></div></div>
	<?php endif; ?>

	<form class="card2" method="post" action="/wordpress/admin/catalogo/importa-confronto">
		<input type="hidden" name="csrf" value="<?php echo e( $csrf ); ?>">
		<input type="hidden" name="conferma" value="1">
		<input type="hidden" name="digest" value="<?php echo e( $plan['digest'] ); ?>">
		<div class="row-actions">
			<button class="btn small" type="submit">Conferma e aggiorna la bozza</button>
			<a class="btn alt small" href="/wordpress/admin/catalogo">Annulla</a>
		</div>
	</form>
</div></section>
