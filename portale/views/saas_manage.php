<?php /** @var array $o @var ?array $st @var string $flash @var string $error @var ?array $dns @var ?array $check @var array $plans */
$csrf = Portale\App::csrf();
$base = '/account/gestionale/' . e( $o['id'] );
$pk   = (array) ( $st['packages'] ?? array() );
$dom  = is_array( $st['domain'] ?? null ) ? $st['domain'] : null;
$stateName = array( 'pending' => 'In verifica', 'active' => 'Attivo', 'error' => 'Da sistemare' );
?>
<section class="page"><div class="wrap" style="max-width:860px">
	<div class="account-head">
		<div><h1>Abbonamento · <?php echo e( $o['org_name'] ); ?></h1></div>
		<a class="btn alt small" href="/account#gestionale">Torna al tuo account</a>
	</div>
	<?php if ( '' !== $flash ) : ?><div class="flash" role="status"><?php echo e( $flash ); ?></div><?php endif; ?>
	<?php if ( '' !== $error ) : ?><div class="flash" role="alert"><?php echo e( $error ); ?></div><?php endif; ?>

	<div class="sub">
		<div class="sub-top"><h2>Quanto stai usando</h2></div>
		<?php if ( $st ) : ?>
		<div class="facts">
			<div><span>Soci</span><b><?php echo (int) ( $st['members'] ?? 0 ); ?><?php echo isset( $st['members_limit'] ) && null !== $st['members_limit'] ? ' di ' . (int) $st['members_limit'] : ''; ?></b></div>
			<div><span>Operatori</span><b><?php echo (int) ( $st['operators']['active'] ?? 0 ); ?><?php echo isset( $st['operators']['limit'] ) ? ' di ' . (int) $st['operators']['limit'] : ''; ?></b></div>
			<?php if ( isset( $st['storage_limit_gb'] ) ) : ?><div><span>Spazio disponibile</span><b><?php echo (int) $st['storage_limit_gb']; ?> GB</b></div><?php endif; ?>
		</div>
		<?php else : ?><p class="muted">Il gestionale non risponde ora: riprova tra poco.</p><?php endif; ?>
	</div>

	<?php if ( $st && Portale\SaasAccount::domainEnabled() && in_array( 'custom_domain', $pk, true ) ) : ?>
	<div class="sub">
		<div class="sub-top"><h2>Dominio personalizzato</h2><?php if ( $dom ) : ?><span class="badge <?php echo 'active' === ( $dom['state'] ?? '' ) ? 'ok' : 'warn'; ?>"><?php echo e( $stateName[ $dom['state'] ?? '' ] ?? '' ); ?></span><?php endif; ?></div>
		<?php if ( $dom ) : ?><p>Oggi: <b><?php echo e( (string) ( $dom['name'] ?? '' ) ); ?></b></p><?php endif; ?>
		<form method="post" action="<?php echo $base; ?>/dominio" class="domain-form" style="display:block">
			<input type="hidden" name="csrf" value="<?php echo e( $csrf ); ?>">
			<label for="dom">Il tuo dominio (per esempio tuaassociazione.it)</label>
			<input id="dom" type="text" name="domain" autocomplete="off" inputmode="url" required>
			<button class="btn" type="submit" style="margin-top:10px">Salva il dominio</button>
		</form>
		<?php if ( $dns ) : ?>
			<p class="hint" style="margin-top:12px"><b>Cosa fare ora.</b> Entra nel sito dove hai registrato il dominio e sostituisci i <b>nameserver</b> con quelli qui sotto; se non sai come, chiedilo a chi te lo ha registrato. Al resto pensiamo noi: il dominio si attiva da solo, entro <b>72 ore</b> al massimo.</p>
			<p class="hint"><b>Attenzione alla posta.</b> Se usi caselle email su questo dominio (per esempio info@tuaassociazione.it), cambiando i nameserver smettono di ricevere. <b>Prima</b> di cambiarli scrivici dal supporto: le sistemiamo insieme.</p>
			<?php foreach ( (array) ( $dns['dns'] ?? array() ) as $r ) : if ( ! is_array( $r ) ) { continue; } ?>
				<p class="small"><code><?php echo e( (string) ( $r['type'] ?? '' ) ); ?> · <?php echo e( (string) ( $r['name'] ?? '' ) ); ?> → <?php echo e( (string) ( $r['value'] ?? '' ) ); ?></code></p>
			<?php endforeach; ?>
			<?php if ( ! empty( $dns['message'] ) ) : ?><p class="small muted"><?php echo e( (string) $dns['message'] ); ?></p><?php endif; ?>
		<?php endif; ?>
		<?php if ( $dom ) : ?>
		<form method="post" action="<?php echo $base; ?>/dominio-togli" style="margin-top:10px" onsubmit="return confirm('Togliere il dominio personalizzato? Resta l\'indirizzo su associazionesemplice.it.')"><input type="hidden" name="csrf" value="<?php echo e( $csrf ); ?>"><button class="btn alt small" type="submit">Togli il dominio personalizzato</button></form>
		<?php endif; ?>
	</div>
	<?php endif; ?>

	<?php if ( $plans ) : ?>
	<div class="sub">
		<div class="sub-top"><h2>Cambiare piano</h2></div>
		<p class="muted small" style="margin:0 0 8px">Controlla prima se i tuoi dati ci stanno nel piano che ti interessa. Non cambia niente: è solo una verifica.</p>
		<form method="post" action="<?php echo $base; ?>/piano" class="domain-form" style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end">
			<input type="hidden" name="csrf" value="<?php echo e( $csrf ); ?>">
			<div style="flex:1;min-width:220px"><label for="to-plan" style="margin-top:0">Piano</label>
			<select id="to-plan" name="to_plan"><?php foreach ( $plans as $code => $p ) : ?><option value="<?php echo e( $code ); ?>"><?php echo e( $p['name'] ); ?></option><?php endforeach; ?></select></div>
			<button class="btn alt" type="submit">Controlla</button>
		</form>
		<?php if ( $check ) : ?>
			<div class="flash" role="status" style="margin-top:12px"><b><?php echo $check['allowed'] ? 'Puoi passare a ' . e( $check['to'] ) . ': i tuoi dati rientrano nei suoi limiti.' : 'Per ora non è possibile passare a ' . e( $check['to'] ) . '.'; ?></b>
			<?php foreach ( $check['blockers'] as $b ) : ?><br><?php echo e( $b ); ?><?php endforeach; ?>
			<?php foreach ( $check['effects'] as $f ) : ?><br><span class="muted"><?php echo e( $f ); ?></span><?php endforeach; ?></div>
			<p class="hint">Per cambiare piano scrivici dal supporto: il passaggio vale dal prossimo rinnovo e non elimina nessun dato.</p>
		<?php endif; ?>
	</div>
	<?php endif; ?>
</div></section>
