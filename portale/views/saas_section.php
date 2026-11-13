<?php /** Sezione di contenuti /saas: cosa fa, per chi è. L'amministrazione (/saas/admin) è del SaaS. */ ?>
<section class="section" id="saas"><div class="wrap">
	<div class="section-head"><h1 style="font-size:clamp(1.9rem,4vw,2.8rem)">Il gestionale online per la tua associazione</h1><p>Lo stesso lavoro del plugin, senza un sito WordPress da mantenere: la segreteria apre il gestionale dal browser e i soci hanno la loro area riservata.</p></div>
	<div class="grid-3">
		<div class="feature"><div class="ico"><?php echo icon( 'users' ); ?></div><h3>Per la segreteria</h3><p>Soci, tessere, quote, attività e prima nota in un unico gestionale, con ruoli diversi per chi lavora con te.</p></div>
		<div class="feature"><div class="ico"><?php echo icon( 'card' ); ?></div><h3>Area riservata dei soci</h3><p>Ogni associazione ha il suo indirizzo, con la pagina dei soci per tessera, prenotazioni e comunicazioni.</p></div>
		<div class="feature"><div class="ico"><?php echo icon( 'shield' ); ?></div><h3>Dati al sicuro</h3><p>I dati di ogni associazione sono separati da quelli delle altre e i campi sensibili sono cifrati.</p></div>
	</div>
	<?php if ( '' !== Portale\SaasDemo::url() ) : ?><p class="center"><a class="btn" href="/prova#gestionale">Prova la demo del gestionale</a></p><?php endif; ?>
	<p class="fine center">I piani e i prezzi sono qui sotto; per qualunque dubbio ci sono le <a href="/domande">domande frequenti</a>. Preferisci WordPress? Guarda <a href="/wordpress">il plugin</a>.</p>
</div></section>
<?php include __DIR__ . "/_catalog_saas.php"; ?>
