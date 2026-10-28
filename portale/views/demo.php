<?php /** @var array $accounts @var array $links @var array $matrix @var array $notes @var string $base */
$url = function ( string $path ) use ( $base ): string { return $base . $path; };
?>
<section class="section"><div class="wrap" style="max-width:960px">
	<div class="section-head" style="text-align:left;margin-bottom:28px">
		<span class="eyebrow">Demo pubblica</span>
		<h1 style="font-size:clamp(1.9rem,4vw,2.8rem)">Prova Associazione Semplice</h1>
		<p>Un sito WordPress vero con il plugin gratuito e il Pro già installati e pieno di dati inventati. Clicca, inserisci, cancella: non rompi niente. Non scrivere dati veri, perché tutto si azzera ogni notte alle 4, non partono email e le password non si possono cambiare.</p>
		<p><a class="btn big" href="<?php echo e( $url( '/' ) ); ?>">Apri la demo</a></p>
	</div>

	<h2>Come entrare</h2>
	<p class="muted">Due accessi di prova: scegli il ruolo che vuoi vedere. Per cambiare ruolo usa «Esci» nella barra blu in fondo alla pagina e poi entra con l'altro.</p>
	<div class="grid-3" style="grid-template-columns:repeat(auto-fit,minmax(280px,1fr));margin-bottom:12px">
	<?php foreach ( $accounts as $a ) : ?>
		<div class="feature">
			<h3>Come <?php echo e( strtolower( $a['role'] ) ); ?></h3>
			<p style="margin-bottom:12px"><?php echo e( $a['note'] ); ?></p>
			<div class="keybox" style="margin-bottom:8px"><span class="muted small">Utente</span> <code><?php echo e( $a['user'] ); ?></code><button class="btn alt small" type="button" data-copy="<?php echo e( $a['user'] ); ?>">Copia</button></div>
			<div class="keybox" style="margin-bottom:14px"><span class="muted small">Password</span> <code><?php echo e( $a['password'] ); ?></code><button class="btn alt small" type="button" data-copy="<?php echo e( $a['password'] ); ?>">Copia</button></div>
			<ul class="small" style="margin:0 0 16px;padding-left:18px"><?php foreach ( $a['what'] as $w ) : ?><li><?php echo e( $w ); ?></li><?php endforeach; ?></ul>
			<a class="btn block" href="<?php echo e( $url( '/wp-login.php?redirect_to=' . rawurlencode( $base . $a['start'] ) ) ); ?>">Entra come <?php echo e( strtolower( $a['role'] ) ); ?></a>
		</div>
	<?php endforeach; ?>
	</div>
	<p class="fine">Nella demo non c'è nessun accesso da amministratore del sito: impostazioni, pagamenti online e licenza non sono visibili.</p>
</div></section>

<section class="section alt"><div class="wrap" style="max-width:960px">
	<h2>Dove andare</h2>
	<p class="muted">I collegamenti sono tutti nella demo. Quelli «come socio» e «come segreteria» chiedono di aver fatto l'accesso con il ruolo giusto.</p>
	<?php foreach ( $links as $group => $rows ) : ?>
		<h3 style="margin-top:22px"><?php echo e( $group ); ?></h3>
		<div class="tbl-wrap"><table class="tbl"><tbody>
		<?php foreach ( $rows as $r ) : ?>
			<tr><td style="width:34%"><a href="<?php echo e( $url( $r[1] ) ); ?>"><?php echo e( $r[0] ); ?></a></td><td class="muted"><?php echo e( $r[2] ); ?></td></tr>
		<?php endforeach; ?>
		</tbody></table></div>
	<?php endforeach; ?>
</div></section>

<section class="section"><div class="wrap" style="max-width:960px">
	<h2>I ruoli del plugin</h2>
	<p class="muted">I permessi non dipendono dai ruoli di WordPress ma dai dati dei soci: una carica o un incarico dà accesso solo a ciò che serve. Nella demo trovi la segreteria e il socio; gli altri ruoli ci sono nel plugin e si assegnano dalla scheda del socio.</p>
	<div class="tbl-wrap"><table class="tbl"><thead><tr><th>Ruolo</th><th>Cosa fa</th><th>Nella demo</th></tr></thead><tbody>
	<?php foreach ( $notes as $n ) : ?>
		<tr><td style="white-space:nowrap"><b><?php echo e( $n[0] ); ?></b></td><td class="muted"><?php echo e( $n[1] ); ?></td><td><?php echo $n[2] ? '✓' : '—'; ?></td></tr>
	<?php endforeach; ?>
	</tbody></table></div>

	<h3 style="margin-top:32px">Chi può fare cosa</h3>
	<div class="tbl-wrap"><table class="tbl"><thead><tr><th></th>
	<?php foreach ( $matrix['columns'] as $c ) : ?><th><?php echo e( $c ); ?></th><?php endforeach; ?>
	</tr></thead><tbody>
	<?php foreach ( $matrix['rows'] as $label => $cells ) : ?>
		<tr><td><b><?php echo e( $label ); ?></b></td><?php foreach ( $cells as $c ) : ?><td style="text-align:center"><?php echo e( $c ); ?></td><?php endforeach; ?></tr>
	<?php endforeach; ?>
	</tbody></table></div>
	<p class="fine">Il presidente e il vicepresidente, con la tessera in regola, agiscono come la segreteria su tutto. Il tesoriere lavora dall'area riservata. Il volontario gestisce solo le sue attività. Lo staff verifica gli ingressi.</p>
	<p class="actions"><a class="btn" href="/free">Ottieni la licenza gratuita</a> <a class="btn alt" href="/confronto">Confronto tra le versioni</a> <a class="btn alt" href="/prezzi">Vedi i piani</a></p>
</div></section>
