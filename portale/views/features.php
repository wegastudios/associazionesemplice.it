<?php
$features = array(
	array( 'users', 'Libro soci in ordine', 'Soci, ospiti e figure diverse, con tessera, quote e storico. Importi l\'elenco che hai già, in pochi minuti.' ),
	array( 'calendar', 'Eventi e corsi', 'Iscrizioni, prenotazioni, lista d\'attesa e calendario. Chi si iscrive riceve avvisi e promemoria per email.' ),
	array( 'book', 'Prima nota', 'Entrate e uscite sempre al loro posto, con allegati e viste pronte per il bilancio.' ),
	array( 'shield', 'Privacy in regola', 'Consensi, informativa compilata, esportazione dei dati e anonimizzazione quando serve.' ),
	array( 'card', 'Area riservata per i soci', 'Ogni socio ha la sua pagina sul sito: tessera, pagamenti, ingressi. Nel menu del sito, non in wp-admin.' ),
	array( 'phone', 'Pagamenti e app', 'Con il Pro: quote e biglietti online con carta o PayPal, app installabile e tessera nel telefono.' ),
);
?>
<section class="section" id="funzioni"><div class="wrap">
	<div class="section-head"><h1 style="font-size:clamp(1.9rem,4vw,2.8rem)">Tutto quello che serve a una segreteria</h1><p>Pensato per chi gestisce un'associazione nel tempo libero: poche schermate, parole chiare, niente da imparare a memoria.</p></div>
	<div class="grid-3">
	<?php foreach ( $features as $f ) : ?>
		<div class="feature"><div class="ico"><?php echo icon( $f[0] ); ?></div><h3><?php echo e( $f[1] ); ?></h3><p><?php echo e( $f[2] ); ?></p></div>
	<?php endforeach; ?>
	</div>
</div></section>
<section class="section alt"><div class="wrap">
	<div class="cta-band">
		<h2>Vuoi vedere tutto, funzione per funzione?</h2>
		<p>Il confronto tra le versioni elenca ogni funzione; la demo ti lascia provarle.</p>
		<a class="btn big" href="/confronto">Confronta le versioni</a> <a class="btn alt big" href="/prova">Prova la demo</a>
	</div>
</div></section>
