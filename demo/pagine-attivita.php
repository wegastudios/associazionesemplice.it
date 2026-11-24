<?php
/**
 * Pubblica le pagine pubbliche (testo breve, categoria) delle attività e dei corsi della demo creati da molti-dati.php, che altrimenti restano bozze.
 * Così tutte le schede di «Attività ed eventi» sono coerenti e hanno l'immagine (la assegna locandine.php). Non cambia le regole di prenotazione né di iscrizione:
 * tocca solo la pagina pubblica (stato, testo, categoria). Testi inventati, nessun dato vero. Idempotente.
 * Uso: wp eval-file ~/demo-provision/pagine-attivita.php --user=setup_tmp
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
if ( ! class_exists( '\AssociazioneSemplice\PublicEvents' ) ) {
	WP_CLI::error( 'Il plugin non è attivo.' );
}
$pages = array(
	// Corsi
	'Yoga dolce'                => array( 'Corsi', 'Un corso di yoga lento e rispettoso del corpo, adatto a chi comincia o vuole ritrovare un po\' di mobilità. Si lavora su respirazione, postura e rilassamento. Basta un tappetino e un abbigliamento comodo.' ),
	'Acquerello'                => array( 'Corsi', 'Un corso per imparare a usare l\'acquerello partendo dalle basi: colori, trasparenze e le prime pennellate. Si dipinge dal vero e da fotografie, con materiali già pronti per chi comincia. Si esce con i propri lavori da portare a casa.' ),
	'Inglese conversazione'     => array( 'Corsi', 'Un corso di conversazione per riprendere confidenza con l\'inglese parlando di cose di tutti i giorni. Si lavora in piccoli gruppi, niente compiti, niente esami. Adatto a chi ha già studiato un po\' e vuole sciogliersi.' ),
	'Teatro amatoriale'         => array( 'Corsi', 'Un corso per scoprire il teatro giocando: voce, movimento, improvvisazione e piccole scene da costruire insieme. Non serve esperienza, solo voglia di mettersi alla prova. A fine corso è prevista una serata aperta ai soci.' ),
	'Chitarra per principianti' => array( 'Corsi', 'Un corso per chi vuole imparare i primi accordi e accompagnare le canzoni che ama. Si parte da zero, con una chitarra propria o in prestito dall\'associazione. Una lezione a settimana in piccoli gruppi.' ),
	'Ginnastica per tutti'      => array( 'Corsi', 'Una ginnastica dolce e adatta a ogni età, per tenere in movimento articolazioni e schiena. Si lavora a corpo libero con esercizi semplici, ciascuno al proprio ritmo. Porta un tappetino e una bottiglia d\'acqua.' ),
	'Cucito creativo'           => array( 'Corsi', 'Un corso per imparare a cucire a mano e a macchina piccoli lavori: borse, cuscini e riparazioni. Le macchine ci sono, il tessuto lo scegli tu con l\'aiuto delle insegnanti. Adatto a chi non ha mai cucito.' ),
	'Fotoritocco'               => array( 'Corsi', 'Un corso per sistemare le proprie fotografie con programmi di uso comune: luce, colori, ritaglio e piccoli difetti. Si lavora sul proprio computer portatile o su quelli della sede. Un buon seguito del corso di fotografia.' ),
	'Cucina regionale'          => array( 'Corsi', 'Un corso di cucina per scoprire i piatti della tradizione di diverse regioni, preparati insieme e poi assaggiati a tavola. Ricette semplici, con ingredienti facili da trovare. Si finisce sempre con una cena conviviale.' ),
	'Ballo liscio'              => array( 'Corsi', 'Un corso per imparare i passi base di valzer, mazurka e polka e ballare con più sicurezza alle serate danzanti. Si può venire in coppia o da soli, ci si alterna nelle coppie durante la lezione. Scarpe comode e tanta allegria.' ),
	// Eventi
	'Serata di quiz'            => array( 'Serate', 'Una serata di domande e risposte a squadre su cultura generale, musica e curiosità. Ogni squadra ha un nome fantasioso e un punteggio da difendere. Premi simbolici e un rinfresco per tutti.' ),
	'Torneo di calcetto'        => array( 'Serate', 'Un torneo di calcetto amichevole tra squadre di soci, pensato per divertirsi più che per vincere. Si gioca a partite brevi, con arbitri volontari e un tifo molto rumoroso. Alla fine una merenda per tutti.' ),
	'Aperitivo sociale'         => array( 'Serate', 'Un aperitivo in sede per ritrovarsi, conoscere i nuovi soci e raccontarsi cosa bolle in pentola. Stuzzichini preparati dai volontari e qualcosa da bere.' ),
	'Visita al museo'           => array( 'Gite', 'Una visita guidata al museo cittadino, con ritrovo davanti all\'ingresso e un\'ora e mezza di racconti e curiosità. Adatta a tutte le età. Dopo la visita ci si ferma insieme per un caffè.' ),
	'Cena della befana'         => array( 'Serate', 'Una cena in compagnia per salutare le feste, con piatti portati dai soci e dolci per i bambini. Dopo cena giochi, musica e la calza della befana per i più piccoli. Prenota per sapere quanti saremo.' ),
	'Lezione aperta di yoga'    => array( 'Laboratori', 'Una lezione di prova aperta a tutti, per scoprire cosa si fa nel corso di yoga dolce. Insegnante e volontari accolgono chi viene per la prima volta. Porta un tappetino e abiti comodi.' ),
	'Mostra di fotografia'      => array( 'Serate', 'Una piccola mostra con le fotografie dei partecipanti ai corsi, esposte in sede con un brindisi di inaugurazione. Gli autori raccontano gli scatti a chi passa.' ),
	'Pomeriggio di giochi'      => array( 'Laboratori', 'Un pomeriggio per giocare tutti insieme: giochi da tavolo, carte e giochi di una volta, per grandi e piccoli. I volontari spiegano le regole a chi non le conosce. Merenda offerta dall\'associazione.' ),
	'Cineforum'                 => array( 'Serate', 'Una serata con la proiezione di un film scelto dai soci, seguita da una chiacchierata per dire che ne pensiamo. Poltrone comode e popcorn per tutti. Il titolo si comunica ai prenotati qualche giorno prima.' ),
	'Pranzo di quartiere'       => array( 'Solidarietà', 'Un pranzo all\'aperto con i vicini di casa, aperto a tutto il quartiere: ognuno porta qualcosa da condividere. Giochi per i bambini e musica di sottofondo. Il ricavato delle offerte libere va ai progetti dell\'associazione.' ),
	'Corsa solidale'            => array( 'Solidarietà', 'Una corsa o camminata non competitiva su un percorso di pochi chilometri, per tutte le età. I contributi sostengono un progetto dell\'associazione. Al traguardo un ristoro per tutti.' ),
	'Festa dei nonni'           => array( 'Serate', 'Una festa dedicata ai nonni e ai nipoti, con giochi insieme, una merenda speciale e un piccolo spettacolo dei bambini. Un\'occasione per stare in compagnia tra generazioni.' ),
);
$tax    = \AssociazioneSemplice\PublicEvents::TAXONOMY;
$posts  = array();
foreach ( get_posts( array( 'post_type' => \AssociazioneSemplice\PublicEvents::POST_TYPE, 'post_status' => array( 'publish', 'draft', 'future', 'private' ), 'numberposts' => -1 ) ) as $p ) {
	$posts[ get_the_title( $p ) ] = $p;
}
$done = 0; $skip = 0; $missing = 0;
foreach ( $pages as $title => $row ) {
	if ( empty( $posts[ $title ] ) ) {
		++$missing;
		continue;
	}
	$p = $posts[ $title ];
	if ( 'publish' === $p->post_status ) {
		++$skip; // già pubblicata: non si riscrive
		continue;
	}
	wp_update_post( array( 'ID' => $p->ID, 'post_status' => 'publish', 'post_content' => '<p>' . esc_html( $row[1] ) . '</p>', 'post_excerpt' => wp_trim_words( $row[1], 18 ) ) );
	$t = term_exists( $row[0], $tax );
	if ( ! $t ) {
		$t = wp_insert_term( $row[0], $tax );
	}
	if ( ! is_wp_error( $t ) ) {
		wp_set_object_terms( $p->ID, array( (int) $t['term_id'] ), $tax );
	}
	++$done;
}
WP_CLI::success( sprintf( 'Pagine delle attività: %d pubblicate, %d già pubbliche, %d non trovate.', $done, $skip, $missing ) );
