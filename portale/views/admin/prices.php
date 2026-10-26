<?php /** @var array $plans */ include __DIR__ . '/_nav.php';
$csrf = Portale\App::csrf();
$eur  = function ( $c ) { return null === $c ? '' : number_format( $c / 100, 2, ',', '' ); };
?>
<section class="admin"><div class="wrap" style="max-width:900px">
	<p class="muted">I prezzi mostrati sul sito e i dati per incassare. Il <b>pagamento singolo</b> usa l'importo qui scritto e non richiede nulla su Stripe o PayPal; il <b>rinnovo automatico</b> richiede invece il prezzo ricorrente creato su Stripe (<code>price_…</code>) e/o il piano creato su PayPal (<code>P-…</code>), che devono avere lo stesso importo.</p>
	<?php $promo = (array) Portale\Config::get( 'promo', array() ); $promoOn = Portale\Settings::promoNow(); ?>
	<form class="card2" method="post" action="/admin/prezzi/promo">
		<input type="hidden" name="csrf" value="<?php echo e( $csrf ); ?>">
		<h2>Promozione in vetrina <span class="badge <?php echo $promoOn ? 'ok' : ''; ?>"><?php echo $promoOn ? 'in corso' : ( ! empty( $promo['label'] ) ? 'non in corso' : 'spenta' ); ?></span></h2>
		<p class="hint" style="margin-top:0">Durante la promozione, nella vetrina, nell'acquisto e nel confronto il <b>prezzo di listino</b> compare barrato accanto al prezzo in vigore (quello che si incassa davvero), con il nome della promozione. Il prezzo di listino si scrive piano per piano qui sotto. Finita la promozione la vetrina mostra solo il prezzo in vigore: <b>se il listino deve diventare il prezzo di vendita</b>, cambia importo e prezzi Stripe/PayPal del piano. I clienti già iscritti restano al prezzo con cui hanno acquistato.</p>
		<div class="form-grid">
			<div><label for="pl" style="margin-top:0">Nome della promozione <?php echo tip( "Il nome che compare accanto ai prezzi in vetrina, per esempio «Halloween» o «Black Friday».\n\nLascialo vuoto per spegnere la promozione. Per far comparire il prezzo barrato, scrivi anche il «prezzo di listino» nei piani qui sotto.", 'Nome della promozione' ); ?></label><input id="pl" type="text" name="promo_label" value="<?php echo e( $promo['label'] ?? '' ); ?>" maxlength="40" placeholder="Black Friday"></div>
			<div><label for="ps" style="margin-top:0">Dal giorno <?php echo tip( "Il primo giorno in cui la promozione si vede. Se non scegli nessuna data parte subito, appena la salvi.\n\nClicca sul campo e scegli dal calendario: la data compare nel formato del tuo browser (giorno/mese/anno).", 'Inizio promozione' ); ?></label><input id="ps" type="date" name="promo_start" value="<?php echo e( $promo['start'] ?? '' ); ?>"></div>
			<div><label for="pe" style="margin-top:0">Fino al giorno <?php echo tip( "L'ultimo giorno della promozione, compreso: finisce a mezzanotte (ora italiana). Dopo questa data il listino barrato sparisce e restano solo i prezzi in vigore.\n\nPer Halloween puoi scegliere il 31 ottobre oppure il 1° novembre.", 'Fine promozione' ); ?></label><input id="pe" type="date" name="promo_end" value="<?php echo e( $promo['end'] ?? '' ); ?>"></div>
		</div>
		<div class="row-actions"><button class="btn" type="submit">Salva promozione</button></div>
	</form>
	<?php foreach ( $plans as $code => $p ) :
		$paid = 'free' !== $code;
		$feat = implode( "\n", (array) ( $p['features'] ?? array() ) );
	?>
	<form class="card2 plan-edit" id="<?php echo e( $code ); ?>" method="post" action="/admin/prezzi/<?php echo e( $code ); ?>">
		<input type="hidden" name="csrf" value="<?php echo e( $csrf ); ?>">
		<h2><?php echo e( $p['label'] ); ?> <span class="muted small">codice «<?php echo e( $code ); ?>»</span></h2>
		<div class="form-grid two">
			<div><label for="l<?php echo e( $code ); ?>" style="margin-top:0">Nome sul sito</label><input id="l<?php echo e( $code ); ?>" type="text" name="label" value="<?php echo e( $p['label'] ); ?>" maxlength="40"></div>
			<div><label for="n<?php echo e( $code ); ?>" style="margin-top:0">Nome inviato al server licenze <?php echo tip( "È il nome del piano scritto nella licenza. Il plugin capisce il livello dal nome: deve contenere «Free», «Lite» o «Full» (per esempio «Pro Lite» per il Contabile e «Pro Full» per il Fiscale). Cambiarlo senza questa parola toglie le funzioni ai clienti nuovi.", 'Nome per il server licenze' ); ?></label><input id="n<?php echo e( $code ); ?>" type="text" name="plan_name" value="<?php echo e( $p['plan_name'] ); ?>" maxlength="40"><p class="hint">Vale per le nuove licenze; quelle già emesse non cambiano.</p></div>
			<div><label for="t<?php echo e( $code ); ?>">Etichetta in evidenza</label><input id="t<?php echo e( $code ); ?>" type="text" name="tag" value="<?php echo e( $p['tag'] ?? '' ); ?>" maxlength="30" placeholder="Per esempio: Il più completo"></div>
			<div><label class="check" style="margin-top:44px"><input type="checkbox" name="visible" value="1"<?php echo false !== ( $p['visible'] ?? true ) ? ' checked' : ''; ?>> Mostra questo piano sul sito</label></div>
		</div>
		<label for="g<?php echo e( $code ); ?>">Descrizione breve</label><input id="g<?php echo e( $code ); ?>" type="text" name="tagline" value="<?php echo e( $p['tagline'] ?? '' ); ?>" maxlength="200" placeholder="Se vuoto si usa il testo predefinito">
		<label for="f<?php echo e( $code ); ?>">Funzioni elencate (una per riga)</label><textarea id="f<?php echo e( $code ); ?>" name="features" rows="6" placeholder="Se vuoto si usa l'elenco predefinito"><?php echo e( $feat ); ?></textarea>
		<?php if ( $paid ) : foreach ( array( 'month' => 'Mensile', 'year' => 'Annuale' ) as $per => $txt ) : $pr = $p['prices'][ $per ] ?? null; ?>
			<div class="sep"></div>
			<h3 style="margin-bottom:4px"><?php echo e( $txt ); ?></h3><p class="hint" style="margin:0 0 6px">Lascia vuoto il prezzo per non vendere questo periodo.</p>
			<div class="form-grid">
				<div><label for="p<?php echo e( $code . $per ); ?>">Prezzo in euro <?php echo tip( "L'importo che il cliente paga e che vede in vetrina, per esempio 8 oppure 49,90.\n\nCon il pagamento singolo il portale incassa esattamente questa cifra. Per il rinnovo automatico conta il prezzo creato su Stripe o PayPal: deve avere lo stesso importo, altrimenti la vetrina e l'incasso non coincidono.", 'Prezzo in euro' ); ?></label><input id="p<?php echo e( $code . $per ); ?>" type="text" inputmode="decimal" name="price_<?php echo e( $per ); ?>" value="<?php echo e( $eur( $pr['cents'] ?? null ) ); ?>" placeholder="49,90"></div>
				<div><label for="s<?php echo e( $code . $per ); ?>">Stripe · prezzo ricorrente <?php echo tip( "Serve solo per il **rinnovo automatico** con carta. Va incollato l'ID del prezzo, che inizia con **price_**.\n\nDove si trova: su Stripe apri **Catalogo prodotti**, scegli il prodotto, poi clicca sul prezzo ricorrente (mensile o annuale): in alto a destra c'è «ID API», per esempio price_1Abc…\n\nNon vanno bene l'ID del prodotto (inizia con prod_), l'importo, un link di pagamento o la chiave segreta. Il prezzo su Stripe deve avere lo stesso importo scritto qui. Se lasci vuoto, il piano si vende solo con pagamento singolo.", 'Prezzo ricorrente di Stripe' ); ?></label><input id="s<?php echo e( $code . $per ); ?>" type="text" name="stripe_<?php echo e( $per ); ?>" value="<?php echo e( $pr['stripe_price'] ?? '' ); ?>" placeholder="price_…" autocapitalize="none" spellcheck="false"></div>
				<div><label for="a<?php echo e( $code . $per ); ?>">PayPal · piano ricorrente <?php echo tip( "Serve solo per il **rinnovo automatico** con PayPal. Va incollato l'ID del piano di abbonamento, che inizia con **P-**.\n\nDove si trova: su PayPal (sviluppatori o Abbonamenti) crea un prodotto e un piano di fatturazione ricorrente con lo stesso importo; l'ID del piano compare nell'elenco dei piani, per esempio P-5ML4271244454362WXNWU5NQ.\n\nSe lasci vuoto, il piano si vende con PayPal solo come pagamento singolo.", 'Piano ricorrente di PayPal' ); ?></label><input id="a<?php echo e( $code . $per ); ?>" type="text" name="paypal_<?php echo e( $per ); ?>" value="<?php echo e( $pr['paypal_plan'] ?? '' ); ?>" placeholder="P-…" autocapitalize="none" spellcheck="false"></div>
			<div><label for="li<?php echo e( $code . $per ); ?>">Prezzo di listino (barrato in promo) <?php echo tip( "Il prezzo «pieno», che compare barrato accanto a quello in vigore solo mentre la promozione è in corso. Deve essere più alto del prezzo scritto sopra. Non cambia quello che si incassa. Lascia vuoto se non vuoi il barrato.", 'Prezzo di listino' ); ?></label><input id="li<?php echo e( $code . $per ); ?>" type="text" inputmode="decimal" name="list_<?php echo e( $per ); ?>" value="<?php echo e( ! empty( $pr['list_cents'] ) ? $eur( $pr['list_cents'] ) : '' ); ?>" placeholder="facoltativo"></div>
			</div>
			<label class="check"><input type="checkbox" name="once_<?php echo e( $per ); ?>" value="1"<?php echo false !== ( $pr['once'] ?? true ) ? ' checked' : ''; ?>> Consenti anche il pagamento singolo (senza rinnovo automatico)</label>
		<?php endforeach; else : ?>
			<p class="hint">Il piano gratuito non ha prezzo.</p>
		<?php endif; ?>
		<div class="row-actions"><button class="btn" type="submit">Salva <?php echo e( $p['label'] ); ?></button></div>
	</form>
	<?php endforeach; ?>
</div></section>
