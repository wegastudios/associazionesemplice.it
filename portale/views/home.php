<?php /** @var array $plans */ ?>
<section class="hero"><div class="wrap hero-grid">
	<div>
		<span class="eyebrow">Plugin WordPress per associazioni</span>
		<h1>La segreteria della tua associazione, finalmente semplice.</h1>
		<p class="lead">Soci, eventi, prima nota e privacy in un unico plugin per il tuo sito WordPress. Si parte gratis; i pagamenti online e la contabilità arrivano quando servono.</p>
		<div class="hero-cta">
			<a class="btn big" href="/prova">Prova la demo</a>
			<a class="btn alt big" href="/free">Ottieni la licenza gratuita</a>
		</div>
		<ul class="ticks"><li>Gratis, senza scadenza</li><li>Dati sempre tuoi</li><li>In italiano</li></ul>
	</div>
	<div class="mock" aria-hidden="true">
		<div class="mock-bar"><i></i><i></i><i></i></div>
		<div class="mock-stats"><div><b>128</b><span>Soci attivi</span></div><div><b>6</b><span>Eventi aperti</span></div><div><b>14</b><span>Da rinnovare</span></div></div>
		<div class="mock-row"><span class="avatar">GR</span><div><b>Giulia Rossi</b><span>Socia ordinaria · tessera 0042</span></div><span class="pill">In regola</span></div>
		<div class="mock-row"><span class="avatar">MB</span><div><b>Marco Bianchi</b><span>Corso di fotografia</span></div><span class="pill warn">Da pagare</span></div>
		<div class="mock-row"><span class="avatar">AV</span><div><b>Anna Verdi</b><span>Socia volontaria · tessera 0057</span></div><span class="pill">In regola</span></div>
		<div class="mock-row"><span class="avatar">LN</span><div><b>Luca Neri</b><span>Quota annuale 2026</span></div><span class="pill bad">Scaduta</span></div>
	</div>
</div></section>

<section class="section alt"><div class="wrap">
	<div class="section-head"><h2>Tutto quello che serve a una segreteria</h2><p>Poche schermate, parole chiare, niente da imparare a memoria.</p></div>
	<div class="grid-3">
	<?php foreach ( Portale\Site::features() as $f ) : ?>
		<a class="feature link" href="/funzioni"><div class="ico"><?php echo icon( $f[0] ); ?></div><h3><?php echo e( $f[1] ); ?></h3><p><?php echo e( $f[2] ); ?></p><span class="more">Scopri le funzioni</span></a>
	<?php endforeach; ?>
	</div>
</div></section>

<section class="section"><div class="wrap">
	<div class="section-head"><h2>Si parte in tre passi</h2></div>
	<div class="steps">
		<div class="step"><h3>Chiedi la chiave</h3><p>Inserisci email e dominio del sito: la licenza gratuita arriva subito, nella tua area riservata.</p></div>
		<div class="step"><h3>Installa il plugin</h3><p>Lo installi da WordPress, incolli la chiave e la procedura guidata ti accompagna con poche domande.</p></div>
		<div class="step"><h3>Cresci quando vuoi</h3><p>Ti servono incassi online o contabilità? Passi al Pro dall'area riservata, senza reinstallare nulla.</p></div>
	</div>
</div></section>

<section class="section alt"><div class="wrap">
	<div class="section-head"><h2>Tre piani, parti da quello gratuito</h2><p>Il piano gratuito resta gratuito. I piani Pro si rinnovano da soli e si disdicono in autonomia.</p></div>
	<?php include __DIR__ . '/_plans.php'; ?>
</div></section>

<section class="section"><div class="wrap">
	<div class="section-head"><h2>Le domande più comuni</h2></div>
	<div class="faq">
	<?php foreach ( array_slice( Portale\Site::faq(), 0, 3 ) as $q ) : ?>
		<details><summary><?php echo e( $q[0] ); ?></summary><p><?php echo e( $q[1] ); ?></p></details>
	<?php endforeach; ?>
	</div>
	<p class="fine center"><a href="/domande">Leggi tutte le domande</a></p>
</div></section>
