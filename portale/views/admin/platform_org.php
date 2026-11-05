<?php /** @var array $org @var array $ops @var string $org_id @var string $error @var bool $can_write */ include __DIR__ . '/_nav.php'; $csrf = Portale\App::csrf(); ?>
<section class="admin"><div class="wrap">
	<p><a href="/admin/piattaforma">← Tutte le associazioni</a></p>
	<?php if ( $error ) : ?><p class="flash" role="alert"><?php echo e( $error ); ?></p><?php endif; ?>
	<?php if ( $org ) : ?>
		<h2><?php echo e( (string) ( $org['name'] ?? '' ) ); ?></h2>
		<p class="muted"><?php echo e( (string) ( $org['slug'] ?? '' ) ); ?> · stato <strong><?php echo e( (string) ( $org['status'] ?? '' ) ); ?></strong><?php echo ! empty( $org['until'] ) ? ' · pagata fino al ' . e( (string) $org['until'] ) : ''; ?><?php echo ! empty( $org['partner'] ) ? ' · partner ' . e( (string) $org['partner'] ) : ''; ?></p>
		<ul>
			<li>Soci: <?php echo (int) ( $org['members'] ?? 0 ); ?> su <?php echo (int) ( $org['members_limit'] ?? 0 ); ?></li>
			<li>Spazio: <?php echo (int) ( $org['storage_used_mb'] ?? 0 ); ?> MB su <?php echo (int) ( $org['storage_limit_gb'] ?? 0 ); ?> GB</li>
			<li>Pacchetti attivi: <?php echo e( implode( ', ', array_keys( (array) ( $org['packages'] ?? array() ) ) ) ?: 'nessuno' ); ?></li>
			<li>Ultimi diritti applicati: revisione <?php echo (int) ( $org['rev'] ?? 0 ); ?></li>
		</ul>
	<?php endif; ?>
	<h3>Operatori</h3>
	<div class="tbl-wrap"><table class="tbl"><thead><tr><th>Nome</th><th>Email</th><th>Ruolo</th><th>Accesso</th><th>Verifica in due passaggi</th><th></th></tr></thead><tbody>
	<?php foreach ( $ops as $u ) : $off = ! empty( $u['disabled'] ); ?>
		<tr>
			<td><?php echo e( (string) ( $u['name'] ?? '' ) ); ?></td>
			<td><?php echo e( (string) ( $u['email'] ?? '' ) ); ?></td>
			<td><?php echo e( (string) ( $u['role'] ?? '' ) ); ?></td>
			<td><?php echo $off ? 'Disattivato' : 'Attivo'; ?></td>
			<td><?php echo ! empty( $u['two_factor'] ) ? 'Sì' : 'No'; ?></td>
			<td><?php if ( $can_write ) : ?>
				<form method="post" action="/admin/piattaforma/utenti/<?php echo e( (string) ( $u['user_id'] ?? '' ) ); ?>" onsubmit="<?php echo js_confirm( ( $off ? 'Riattivare ' : 'Disattivare ' ) . (string) ( $u['email'] ?? '' ) . '? Vale in tutte le associazioni in cui è operatore.' ); ?>">
					<input type="hidden" name="csrf" value="<?php echo e( $csrf ); ?>">
					<input type="hidden" name="org" value="<?php echo e( $org_id ); ?>">
					<input type="hidden" name="disabled" value="<?php echo $off ? '0' : '1'; ?>">
					<button class="btn small alt" type="submit"><?php echo $off ? 'Riattiva' : 'Disattiva'; ?></button>
				</form>
			<?php endif; ?></td>
		</tr>
	<?php endforeach; ?>
	<?php if ( ! $ops && ! $error ) : ?><tr><td colspan="6" class="muted">Nessun operatore.</td></tr><?php endif; ?>
	</tbody></table></div>
	<?php if ( ! $can_write ) : ?><p class="muted small">Con il ruolo «solo supporto» la scheda è in sola lettura.</p><?php endif; ?>
</div></section>
