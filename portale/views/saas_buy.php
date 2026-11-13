<?php
/**
 * Acquisto del gestionale. Scelte e prezzi vengono dal listino corrente del SaaS; il totale mostrato è quello che si paga.
 * @var array|null $doc @var array $v @var array|null $quote @var string $error @var string[] $providers
 */
$plans  = $doc ? Portale\Catalog::plans( $doc ) : array();
$addons = array();
foreach ( (array) ( $doc['addons'] ?? array() ) as $a ) {
	if ( is_array( $a ) && false !== ( $a['visible'] ?? true ) && Portale\Catalog::price( $a, $v['period'] ) ) {
		$addons[] = $a;
	}
}
$names  = array();
foreach ( array_merge( $plans, $addons ) as $x ) {
	$names[ (string) $x['code'] ] = (string) ( $x['name'] ?? $x['code'] );
}
$year  = 'year' === $v['period'];
$labels = array( 'stripe' => 'Carta (Stripe)', 'paypal' => 'PayPal' );
?>
<section class="page"><div class="wrap page-grid">
	<div class="panel">
		<span class="eyebrow">Gestionale online</span>
		<h1>Prendi il gestionale</h1>
		<?php if ( ! $doc || ! $plans ) : ?>
			<p class="muted">Il listino non è disponibile in questo momento: riprova tra poco o <a href="mailto:info@associazionesemplice.it">scrivici</a>.</p>
		<?php else : ?>
		<?php if ( '' !== $error ) : ?><p class="flash" role="alert"><?php echo e( $error ); ?></p><?php endif; ?>
		<form method="post" action="/saas/acquista">
			<input type="hidden" name="csrf" value="<?php echo e( Portale\App::csrf() ); ?>">
			<label for="plan">Piano</label>
			<select id="plan" name="plan">
				<?php foreach ( $plans as $p ) : ?><option value="<?php echo e( $p['code'] ); ?>"<?php echo $v['plan'] === $p['code'] ? ' selected' : ''; ?>><?php echo e( $p['name'] ?? $p['code'] ); ?></option><?php endforeach; ?>
			</select>
			<label>Fatturazione</label>
			<div class="choice">
				<label><input type="radio" name="period" value="month"<?php echo $year ? '' : ' checked'; ?>><span>Mensile</span></label>
				<label><input type="radio" name="period" value="year"<?php echo $year ? ' checked' : ''; ?>><span>Annuale</span></label>
			</div>
			<label for="members">Quanti soci avete (anche in previsione)?</label>
			<input id="members" type="number" name="members" min="1" max="100000" inputmode="numeric" value="<?php echo (int) $v['members']; ?>">
			<?php if ( $addons ) : ?>
				<label>Componenti in più</label>
				<?php foreach ( $addons as $a ) : ?>
					<div style="display:flex;justify-content:space-between;align-items:center;gap:12px;margin:6px 0"><label for="ad-<?php echo e( $a['code'] ); ?>" style="font-weight:400"><?php echo e( $a['name'] ?? $a['code'] ); ?></label>
					<input id="ad-<?php echo e( $a['code'] ); ?>" type="number" name="addons[<?php echo e( $a['code'] ); ?>]" min="0" max="1000" inputmode="numeric" value="<?php echo (int) ( $v['addons'][ $a['code'] ] ?? 0 ); ?>" style="max-width:6rem"></div>
				<?php endforeach; ?>
			<?php endif; ?>
			<label for="promo">Codice promozionale <span class="muted">(facoltativo)</span></label>
			<input id="promo" type="text" name="promo" maxlength="40" autocapitalize="characters" spellcheck="false" value="<?php echo e( $v['promo'] ); ?>">
			<button class="btn block" type="submit" name="step" value="quote">Calcola il totale</button>

			<?php if ( $quote ) : ?>
			<div class="summary" aria-live="polite" style="margin-top:1.2rem;display:block">
				<?php foreach ( $quote['lines'] as $l ) : ?>
					<div class="summary"><span><?php echo e( $names[ $l['code'] ] ?? $l['code'] ); ?><?php echo $l['qty'] > 1 ? ' × ' . (int) $l['qty'] : ''; ?></span><b><?php echo e( money( $l['net'] ) ); ?></b></div>
				<?php endforeach; ?>
				<?php if ( $quote['discount'] > 0 ) : ?><div class="summary"><span>Sconto <?php echo e( $quote['promo'] ); ?></span><b>− <?php echo e( money( $quote['discount'] ) ); ?></b></div><?php endif; ?>
				<?php if ( 'exempt' === ( $quote['vat_mode'] ?? '' ) ) : ?>
				<div class="summary"><span><strong>Totale <?php echo $year ? 'all\'anno' : 'al mese'; ?></strong> (senza IVA)</span><b><?php echo e( money( $quote['total'] ) ); ?></b></div>
				<p class="hint"><?php echo e( (string) $quote['vat_note'] ); ?></p>
				<?php else : ?>
				<div class="summary"><span>IVA <?php echo (int) $quote['vat_percent']; ?>%</span><b><?php echo e( money( $quote['vat'] ) ); ?></b></div>
				<div class="summary"><span><strong>Totale <?php echo $year ? 'all\'anno' : 'al mese'; ?></strong> (IVA inclusa)</span><b><?php echo e( money( $quote['total'] ) ); ?></b></div>
				<?php endif; ?>
				<p class="hint">Si rinnova da solo <?php echo $year ? 'ogni anno' : 'ogni mese'; ?> allo stesso prezzo: disdici quando vuoi dall'area clienti.</p>
			</div>

			<h2 style="margin-top:1.4rem">I tuoi dati</h2>
			<label for="org_name">Nome dell'associazione</label>
			<input id="org_name" type="text" name="org_name" maxlength="150" value="<?php echo e( $v['org_name'] ); ?>" required>
			<label for="slug">Indirizzo del gestionale</label>
			<input id="slug" type="text" name="slug" maxlength="40" autocapitalize="none" spellcheck="false" value="<?php echo e( $v['slug'] ); ?>" placeholder="la-mia-associazione" required>
			<p class="hint">Sarà <b>indirizzo</b>.associazionesemplice.it. Se è già preso ne aggiungiamo un pezzetto noi.</p>
			<label for="email">La tua email</label>
			<input id="email" type="email" name="email" autocomplete="email" value="<?php echo e( $v['email'] ); ?>" required>
			<label for="name">Il tuo nome <span class="muted">(facoltativo)</span></label>
			<input id="name" type="text" name="name" autocomplete="name" maxlength="150" value="<?php echo e( $v['name'] ); ?>">
			<?php if ( $providers ) : ?>
				<label>Come vuoi pagare</label>
				<div class="choice">
				<?php foreach ( $providers as $i => $pr ) : ?>
					<label><input type="radio" name="provider" value="<?php echo e( $pr ); ?>"<?php echo ( $v['provider'] === $pr || ( '' === $v['provider'] && 0 === $i ) ) ? ' checked' : ''; ?>><span><?php echo e( $labels[ $pr ] ?? $pr ); ?></span></label>
				<?php endforeach; ?>
				</div>
				<input type="hidden" name="seen_total" value="<?php echo (int) $quote['total']; ?>">
				<button class="btn big block" type="submit" name="step" value="pay">Ordina e paga <?php echo e( money( $quote['total'] ) ); ?></button>
			<?php else : ?>
				<p class="muted">Il pagamento online non è ancora attivo: <a href="mailto:info@associazionesemplice.it">scrivici</a> e ti aiutiamo noi.</p>
			<?php endif; ?>
			<?php endif; ?>
		</form>
		<?php endif; ?>
	</div>
	<div class="side">
		<h2>Cosa succede dopo</h2>
		<ul>
			<li>Paghi in modo sicuro su Stripe o PayPal: noi non vediamo i dati della carta.</li>
			<li>Appena il pagamento è confermato creiamo il gestionale della tua associazione e ti scriviamo per entrare.</li>
			<?php if ( $doc && 'exempt' === Portale\Catalog::terms( $doc )['vat_mode'] ) : ?>
			<li>I prezzi sono senza IVA: il totale che vedi è quello che paghi.</li>
			<?php else : ?>
			<li>I prezzi sono IVA esclusa nel listino; qui sopra vedi il totale con IVA.</li>
			<?php endif; ?>
		</ul>
	</div>
</div></section>
