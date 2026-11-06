<?php /** @var array $orgs @var int $total @var string $error @var array $f @var bool $saas_ready */ include __DIR__ . '/_nav.php'; ?>
<section class="admin"><div class="wrap">
	<p class="muted">Le associazioni che usano il gestionale SaaS, lette direttamente dal SaaS: solo numeri e stato, mai i dati dei soci. Gli operatori si disattivano dalla scheda dell'associazione.</p>
	<?php if ( ! $saas_ready ) : ?><p class="flash" role="status">Collegamento con il SaaS non ancora attivo: manca la chiave di firma verso il SaaS (<code>php bin/saas-key.php</code>) e la chiave pubblica nel SaaS.</p><?php endif; ?>
	<?php if ( $error ) : ?><p class="flash" role="alert"><?php echo e( $error ); ?></p><?php endif; ?>
	<form method="get" action="/saas" class="inline-form">
		<label>Cerca <input type="search" name="q" value="<?php echo e( $f['q'] ); ?>" maxlength="120" placeholder="nome o indirizzo"></label>
		<label>Stato <select name="status"><option value="">Tutti</option><?php foreach ( array( 'trial' => 'In prova', 'active' => 'Attiva', 'past_due' => 'Pagamento in ritardo', 'suspended' => 'Sospesa', 'cancelled' => 'Chiusa' ) as $k => $l ) : ?><option value="<?php echo e( $k ); ?>"<?php echo $k === $f['status'] ? ' selected' : ''; ?>><?php echo e( $l ); ?></option><?php endforeach; ?></select></label>
		<button class="btn small" type="submit">Filtra</button>
	</form>
	<div class="tbl-wrap"><table class="tbl"><thead><tr><th>Associazione</th><th>Stato</th><th>Operatori</th><th>Soci</th><th>Spazio</th><th>Creata il</th></tr></thead><tbody>
	<?php foreach ( $orgs as $o ) : $op = (array) ( $o['operators'] ?? array() ); ?>
		<tr>
			<td><a href="/saas/<?php echo e( (string) ( $o['org_id'] ?? '' ) ); ?>"><?php echo e( (string) ( $o['name'] ?? '' ) ); ?></a><br><span class="muted small"><?php echo e( (string) ( $o['slug'] ?? '' ) ); ?></span></td>
			<td><?php echo e( (string) ( $o['status'] ?? '' ) ); ?></td>
			<td><?php echo (int) ( $op['active'] ?? 0 ); ?> attivi<?php echo ! empty( $op['disabled'] ) ? ' · ' . (int) $op['disabled'] . ' disattivati' : ''; ?> (limite <?php echo (int) ( $op['limit'] ?? 0 ); ?>)</td>
			<td><?php echo (int) ( $o['members'] ?? 0 ); ?> / <?php echo (int) ( $o['members_limit'] ?? 0 ); ?></td>
			<td><?php echo isset( $o['storage_used_mb'] ) ? (int) $o['storage_used_mb'] . ' MB su ' : 'fino a '; ?><?php echo (int) ( $o['storage_limit_gb'] ?? 0 ); ?> GB</td>
			<td><?php echo e( (string) ( $o['created_at'] ?? '' ) ); ?></td>
		</tr>
	<?php endforeach; ?>
	<?php if ( ! $orgs && ! $error ) : ?><tr><td colspan="6" class="muted">Nessuna associazione trovata.</td></tr><?php endif; ?>
	</tbody></table></div>
	<p class="muted small"><?php echo (int) $total; ?> in tutto.</p>
</div></section>
