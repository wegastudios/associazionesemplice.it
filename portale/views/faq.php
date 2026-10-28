<?php
$faq = array(
	array( 'Devo pagare per usare Associazione Semplice?', 'No. Il plugin è gratuito e resta tale. Serve solo una licenza gratuita, legata al dominio del tuo sito, che ricevi in due minuti.' ),
	array( 'Cosa significa «licenza legata a un dominio»?', 'La chiave funziona sul sito per cui l\'hai richiesta. Se cambi indirizzo, lo aggiorni tu dall\'area riservata (fino a 3 volte ogni 30 giorni), senza scrivere a nessuno.' ),
	array( 'Posso passare dal gratuito al Pro più avanti?', 'Sì, in qualsiasi momento. I dati restano dove sono: si attivano soltanto le funzioni in più.' ),
	array( 'Come funziona il rinnovo?', 'Il rinnovo è automatico, mensile o annuale a scelta. Puoi disdire quando vuoi dall\'area riservata: la licenza resta valida fino alla scadenza già pagata.' ),
	array( 'Cosa succede se un pagamento non va a buon fine?', 'Hai alcuni giorni di tolleranza. I tuoi dati non vengono mai cancellati: se la licenza scade, tornano disponibili le funzioni di base.' ),
	array( 'Come pago?', 'Con carta tramite Stripe oppure con PayPal. Non vediamo né conserviamo i dati della tua carta.' ),
);
?>
<section class="section alt" id="domande"><div class="wrap">
	<div class="section-head"><h1 style="font-size:clamp(1.9rem,4vw,2.8rem)">Domande frequenti</h1></div>
	<div class="faq">
	<?php foreach ( $faq as $q ) : ?>
		<details><summary><?php echo e( $q[0] ); ?></summary><p><?php echo e( $q[1] ); ?></p></details>
	<?php endforeach; ?>
	</div>
</div></section>
