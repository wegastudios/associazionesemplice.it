<?php /** @var array $people @var string $error */ include __DIR__ . '/_nav.php'; $csrf = Portale\App::csrf();
$levels = array( 'admin' => 'Amministratore', 'vendita' => 'Vendita', 'supporto' => 'Supporto' );
?>
<section class="admin"><div class="wrap">
	<p class="muted">Chi può entrare nella gestione del plugin. L'elenco degli utenti è unico (WordPress, SaaS e Supporto): qui vedi e cambi solo i ruoli di quest'area. <strong>Amministratore</strong> fa tutto, compresi gli utenti; <strong>Vendita</strong> gestisce catalogo, prezzi, promo, licenze e clienti; <strong>Supporto</strong> vede licenze e clienti per assistere. Nessuno assegna un livello più alto del proprio e l'ultimo amministratore non si toglie.</p>
	<?php if ( $error ) : ?><div class="flash" role="alert"><?php echo e( $error ); ?></div><?php endif; ?>
	<form method="post" action="/wordpress/admin/utenti/invita" class="inline-form">
		<input type="hidden" name="csrf" value="<?php echo e( $csrf ); ?>">
		<label>Email <input type="email" name="email" required maxlength="190"></label>
		<label>Livello <select name="level"><?php foreach ( $levels as $k => $l ) : ?><option value="<?php echo e( $k ); ?>"><?php echo e( $l ); ?></option><?php endforeach; ?></select></label>
		<button class="btn small" type="submit">Invita per email</button>
	</form>
	<div class="tbl-wrap"><table class="tbl"><thead><tr><th>Email</th><th>Livello</th><th>Stato</th><th></th></tr></thead><tbody>
	<?php foreach ( $people as $p ) : $locked = ! empty( $p['locked'] ); ?>
		<tr><td><?php echo e( (string) $p['email'] ); ?><?php echo $locked ? ' <span class="muted small">(titolare, non modificabile)</span>' : ''; ?></td><td><?php echo e( $levels[ $p['level'] ] ?? (string) $p['level'] ); ?></td><td><?php echo ! empty( $p['active'] ) ? 'Attivo' : 'Disattivato'; ?></td>
		<td><?php if ( ! $locked ) : ?>
			<form method="post" action="/wordpress/admin/utenti/assegna" class="inline-form" style="display:inline"><input type="hidden" name="csrf" value="<?php echo e( $csrf ); ?>"><input type="hidden" name="email" value="<?php echo e( (string) $p['email'] ); ?>"><select name="level" aria-label="Livello"><?php foreach ( $levels as $k => $l ) : ?><option value="<?php echo e( $k ); ?>"<?php echo ( $p['level'] ?? '' ) === $k ? ' selected' : ''; ?>><?php echo e( $l ); ?></option><?php endforeach; ?></select> <button class="btn small" type="submit">Cambia</button></form>
			<form method="post" action="/wordpress/admin/utenti/revoca" style="display:inline" onsubmit="<?php echo js_confirm( 'Togliere il ruolo a ' . $p['email'] . ' in quest\'area?' ); ?>"><input type="hidden" name="csrf" value="<?php echo e( $csrf ); ?>"><input type="hidden" name="email" value="<?php echo e( (string) $p['email'] ); ?>"><button class="btn small ghost" type="submit">Togli ruolo</button></form>
			<form method="post" action="/wordpress/admin/utenti/disattiva" style="display:inline" onsubmit="<?php echo js_confirm( 'Disattivare ' . $p['email'] . '? Perde subito l\'accesso a tutte le aree.' ); ?>"><input type="hidden" name="csrf" value="<?php echo e( $csrf ); ?>"><input type="hidden" name="email" value="<?php echo e( (string) $p['email'] ); ?>"><button class="btn danger small" type="submit">Disattiva</button></form>
		<?php endif; ?></td></tr>
	<?php endforeach; ?>
	<?php if ( ! $people && ! $error ) : ?><tr><td colspan="4" class="muted">Nessuno per ora.</td></tr><?php endif; ?>
	</tbody></table></div>
</div></section>
