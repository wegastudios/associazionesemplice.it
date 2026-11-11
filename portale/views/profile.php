<?php /** @var array $p @var string $email @var string $flash @var array $errors @var ?array $qr @var int $min */
$csrf   = Portale\App::csrf();
$hidden = '<input type="hidden" name="csrf" value="' . e( $csrf ) . '">';
?>
<section class="page"><div class="wrap" style="max-width:860px">
	<div class="account-head">
		<div><h1>Profilo e sicurezza</h1><div class="muted"><?php echo e( $email ); ?></div></div>
		<a class="btn alt small" href="/account">Torna al tuo account</a>
	</div>
	<?php if ( ! empty( $flash ) ) : ?><div class="flash" role="status"><?php echo e( $flash ); ?></div><?php endif; ?>
	<?php foreach ( $errors as $err ) : ?><div class="flash" role="alert"><?php echo e( $err ); ?></div><?php endforeach; ?>

	<div class="sub">
		<div class="sub-top"><h2>Password</h2></div>
		<details><summary class="muted small">Cambia la password</summary>
		<form method="post" action="/account/profilo/password" class="domain-form" style="display:block;margin-top:10px">
			<?php echo $hidden; ?>
			<label for="pr-cur">Password attuale</label><input id="pr-cur" type="password" name="current" autocomplete="current-password" required>
			<label for="pr-new">Nuova password</label><input id="pr-new" type="password" name="password" autocomplete="new-password" minlength="<?php echo (int) $min; ?>" required>
			<label for="pr-conf">Ripeti la nuova password</label><input id="pr-conf" type="password" name="confirm" autocomplete="new-password" minlength="<?php echo (int) $min; ?>" required>
			<button class="btn" type="submit" style="margin-top:10px">Salva la password</button>
			<p class="hint">Le altre sessioni aperte verranno chiuse.</p>
		</form></details>
	</div>

	<div class="sub">
		<div class="sub-top"><h2>Email</h2></div>
		<details><summary class="muted small">Cambia l'indirizzo email</summary>
		<form method="post" action="/account/profilo/email" class="domain-form" style="display:block;margin-top:10px">
			<?php echo $hidden; ?>
			<label for="pr-mail">Nuovo indirizzo</label><input id="pr-mail" type="email" name="new_email" autocomplete="email" required>
			<label for="pr-mailpw">Password</label><input id="pr-mailpw" type="password" name="password" autocomplete="current-password" required>
			<button class="btn" type="submit" style="margin-top:10px">Mandami il link di conferma</button>
			<p class="hint">Scriviamo al nuovo indirizzo: il cambio vale solo dopo che apri il link.</p>
		</form></details>
	</div>

	<div class="sub">
		<div class="sub-top"><h2>Secondo livello di sicurezza</h2><?php if ( $p['two_factor'] ) : ?><span class="badge">Attivo</span><?php else : ?><span class="badge warn">Non attivo</span><?php endif; ?></div>
		<?php if ( $qr ) : ?>
			<p class="muted small">Apri un'app di autenticazione (per esempio quella del telefono), inquadra il codice e scrivi qui il numero a sei cifre che ti mostra.</p>
			<?php if ( '' !== $qr['svg'] ) : ?><div style="max-width:200px;background:#fff;padding:8px;border-radius:12px" role="img" aria-label="Codice QR"><?php echo $qr['svg']; // SVG generato dal SaaS, già controllato in App::profile ?></div><?php endif; ?>
			<p class="small">Se non puoi inquadrare, scrivi questa chiave nell'app: <code><?php echo e( $qr['secret'] ); ?></code></p>
			<form method="post" action="/account/profilo/2fa-conferma" class="domain-form" style="display:block">
				<?php echo $hidden; ?>
				<label for="pr-code">Codice dell'app</label><input id="pr-code" type="text" name="code" inputmode="numeric" autocomplete="one-time-code" maxlength="12" required>
				<button class="btn" type="submit" style="margin-top:10px">Attiva</button>
			</form>
		<?php elseif ( $p['two_factor'] ) : ?>
			<details><summary class="muted small">Disattiva</summary>
			<form method="post" action="/account/profilo/2fa-disattiva" class="domain-form" style="display:block;margin-top:10px">
				<?php echo $hidden; ?>
				<label for="pr-d-pw">Password</label><input id="pr-d-pw" type="password" name="password" autocomplete="current-password" required>
				<label for="pr-d-code">Codice dell'app</label><input id="pr-d-code" type="text" name="code" inputmode="numeric" autocomplete="one-time-code" maxlength="12" required>
				<button class="btn alt" type="submit" style="margin-top:10px">Disattiva</button>
			</form></details>
		<?php else : ?>
			<p class="muted small" style="margin:0 0 6px">Dopo la password ti chiediamo anche un codice a sei cifre dall'app del telefono: se qualcuno scopre la password, non basta per entrare.</p>
			<form method="post" action="/account/profilo/2fa-avvia" class="domain-form" style="display:block">
				<?php echo $hidden; ?>
				<label for="pr-s-pw">Password</label><input id="pr-s-pw" type="password" name="password" autocomplete="current-password" required>
				<button class="btn" type="submit" style="margin-top:10px">Attiva il secondo livello</button>
			</form>
		<?php endif; ?>
	</div>

	<div class="sub">
		<div class="sub-top"><h2>Dove sei connesso</h2></div>
		<?php foreach ( $p['sessions'] as $x ) : ?>
			<div style="display:flex;gap:10px;flex-wrap:wrap;align-items:center;justify-content:space-between;padding:8px 0;border-top:1px solid var(--line)">
				<div><strong><?php echo $x['current'] ? 'Questo dispositivo' : 'Altra sessione'; ?></strong><div class="muted small">Indirizzo <?php echo e( $x['ip'] ); ?> · ultimo uso <?php echo e( $x['last'] ); ?></div></div>
				<?php if ( ! $x['current'] ) : ?><form method="post" action="/account/profilo/sessione-chiudi"><?php echo $hidden; ?><input type="hidden" name="target" value="<?php echo e( $x['id'] ); ?>"><button class="btn alt small" type="submit">Chiudi</button></form><?php endif; ?>
			</div>
		<?php endforeach; ?>
	</div>
</div></section>
