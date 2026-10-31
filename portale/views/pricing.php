<?php /** @var array $plans @var int $trial */
$euro = function ( $c ) { return number_format( $c / 100, ( $c % 100 ) ? 2 : 0, ',', '.' ) . ' €'; };

// Testi di vetrina: si possono sostituire da config.php (plans.<codice>.tagline / features).
$copy = array(
	'free' => array( 'tagline' => 'Per partire subito, senza costi e senza scadenza.', 'lead' => '', 'features' => array(
		'Libro soci con nuovi iscritti, schede e numerazione',
		'Tessera digitale e stampabile con QR',
		'Corsi ed eventi: iscrizioni, posti e lista d\'attesa',
		'Prima nota con cassa unica, spese e donazioni PayPal',
		'Anni solari: apertura e chiusura',
		'Verbali, informativa privacy e consensi',
		'Area riservata dei soci e ruoli della segreteria',
		'Importazione ed esportazione degli elenchi',
		'Copia di sicurezza dei dati',
	) ),
	'lite' => array( 'tagline' => 'Tutto il gratuito, più incassi online e comunicazione ai soci. Senza la parte fiscale.', 'tag' => 'Il più scelto', 'lead' => 'Tutto il Free, più:', 'features' => array(
		'Pagamenti online con carta, PayPal e WooCommerce',
		'Più livelli di socio, quote diverse e nucleo familiare',
		'Conti multipli, fondi e giroconti',
		'Ricevute in PDF e report di gestione',
		'Risorse prenotabili: campi, sale, posti auto',
		'Incasso sul posto agli eventi',
		'Assicurazioni dei volontari e presenze',
		'Comunicazioni a gruppi di soci, app e notifiche',
		'Tessera in Apple Wallet e Google Wallet',
		'Testi personalizzabili e lingue',
	) ),
	'full' => array( 'tagline' => 'Per chi vuole anche la parte fiscale dell\'ente.', 'lead' => 'Tutto il Pro Contabile, più:', 'features' => array(
		'IVA su quote, attività, incassi e spese',
		'5 per mille con rendiconto sull\'utilizzo',
		'Rendiconto per cassa per il commercialista',
		'Spese documentate sui fondi dei rimborsi, con prospetto per persona',
		'Bilancio provvisorio, avvisi di bilancio e storico mensile',
		'Anno fiscale con mese di inizio proprio e verifiche prima della chiusura',
	) ),
);
$save = 0;
foreach ( $plans as $p ) {
	if ( ! empty( $p['prices']['month']['cents'] ) && ! empty( $p['prices']['year']['cents'] ) ) {
		$save = max( $save, (int) round( 100 - 100 * $p['prices']['year']['cents'] / ( 12 * $p['prices']['month']['cents'] ) ) );
	}
}
?>
<section class="section pricing" id="prezzi" data-period="year"><div class="wrap">
	<div class="section-head"><h1 style="font-size:clamp(1.9rem,4vw,2.8rem)">Prezzi chiari, disdici quando vuoi</h1><p>Il piano gratuito resta gratuito. I piani Pro si rinnovano da soli e si cambiano o si disdicono in autonomia.</p></div>
	<div class="seg" role="group" aria-label="Periodo di fatturazione">
		<button type="button" data-period="month" aria-pressed="false">Mensile</button>
		<button type="button" data-period="year" aria-pressed="true">Annuale<?php echo $save > 0 ? ' <span class="save">-' . (int) $save . '%</span>' : ''; ?></button>
	</div>
	<div class="plans">
	<?php foreach ( $plans as $code => $p ) :
		if ( false === ( $p['visible'] ?? true ) ) { continue; }
		$c    = $copy[ $code ] ?? array( 'tagline' => $p['description'] ?? '', 'features' => array() );
		$tag  = $p['tag'] ?? ( $c['tag'] ?? '' );
		$feat = $c['features'];
		$lead = $c['lead'] ?? '';
		if ( '' !== $lead && isset( $prevLabel ) ) { $lead = 'Tutto il ' . $prevLabel . ', più:'; }
		$prevLabel = $p['label'];
		$m    = $p['prices']['month']['cents'] ?? null;
		$y    = $p['prices']['year']['cents'] ?? null;
		$hot  = 'lite' === $code;
	?>
		<div class="plan<?php echo $hot ? ' hot' : ''; ?>">
			<?php if ( $tag ) : ?><span class="tag"><?php echo e( $tag ); ?></span><?php endif; ?>
			<h3><?php echo e( $p['label'] ); ?></h3>
			<p class="for"><?php echo e( $p['tagline'] ?? $c['tagline'] ); ?></p>
			<?php if ( 'free' === $code ) : ?>
				<div class="price"><b>0 €</b><span>per sempre</span></div><div class="price-note">Serve solo la licenza gratuita</div>
			<?php else : ?>
				<?php $promoName = Portale\Settings::promoNow(); $lm = Portale\Settings::listCents( $code, 'month' ); $ly = Portale\Settings::listCents( $code, 'year' ); if ( $promoName && ( $lm || $ly ) ) : ?><div class="promo-tag"><?php echo e( $promoName ); ?></div><?php endif; ?>
				<?php if ( $m ) : ?><div class="only-month"><div class="price"><?php if ( $lm ) : ?><s class="was"><?php echo e( $euro( $lm ) ); ?></s> <?php endif; ?><b><?php echo e( $euro( $m ) ); ?></b><span>al mese</span></div><div class="price-note">Rinnovo mensile, senza vincoli</div></div><?php endif; ?>
				<?php if ( $y ) : ?><div class="only-year"><div class="price"><?php if ( $ly ) : ?><s class="was"><?php echo e( $euro( $ly ) ); ?></s> <?php endif; ?><b><?php echo e( $euro( $y ) ); ?></b><span>all'anno</span></div><div class="price-note"><small>pari a <?php echo e( $euro( (int) round( $y / 12 ) ) ); ?> al mese<?php echo $m && $m * 12 > $y ? ' · risparmi ' . e( $euro( $m * 12 - $y ) ) . ' rispetto al mensile' : ''; ?></small></div></div><?php endif; ?>
			<?php endif; ?>
			<ul><?php if ( '' !== $lead ) : ?><li class="lead"><?php echo e( $lead ); ?></li><?php endif; foreach ( $feat as $line ) : ?><li><?php echo e( $line ); ?></li><?php endforeach; ?></ul>
			<?php if ( 'free' === $code ) : ?>
				<a class="btn alt block" href="/free">Inizia gratis</a>
			<?php else : ?>
				<?php if ( $m ) : ?><a class="btn<?php echo $hot ? '' : ' alt'; ?> block only-month" href="/acquista?plan=<?php echo e( $code ); ?>&amp;period=month">Scegli <?php echo e( $p['label'] ); ?></a><?php endif; ?>
				<?php if ( $y ) : ?><a class="btn<?php echo $hot ? '' : ' alt'; ?> block only-year" href="/acquista?plan=<?php echo e( $code ); ?>&amp;period=year">Scegli <?php echo e( $p['label'] ); ?></a><?php endif; ?>
			<?php endif; ?>
		</div>
	<?php endforeach; ?>
	</div>
	<?php if ( ! empty( $trial ) ) : ?><p class="fine" style="font-weight:600;color:var(--fg)">Hai già la licenza gratuita? Prova il piano Pro gratis per <?php echo (int) $trial; ?> giorni dall'area riservata, una volta sola.</p><?php endif; ?>
	<p class="fine"><a href="/confronto">Confronta tutte le funzioni delle tre versioni</a>. Prezzi in euro. Pagamento sicuro con Stripe o PayPal. Ogni licenza è legata a un dominio, modificabile dall'area riservata.</p>
</div></section>
