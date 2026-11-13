<?php
namespace AssociazioneSemplice\Admin;

use AssociazioneSemplice\Db;
use AssociazioneSemplice\Html;
use AssociazioneSemplice\Plugin;
use AssociazioneSemplice\Runts;
use AssociazioneSemplice\RuntsRules;

defined( 'ABSPATH' ) || exit;

/**
 * Dati per il RUNTS: i numeri riferiti al 31 dicembre da riportare nel portale del Registro unico del Terzo settore, il controllo del minimo di
 * soci per ODV e APS e il promemoria del 30 giugno. I testi sono prudenti: le regole arrivano da commenti professionali, non dal decreto.
 */
final class RuntsPage {

	const CAUTION = 'Questi numeri ti aiutano a compilare il portale del RUNTS. Scadenze e regole le abbiamo prese da guide di professionisti, non dal testo di legge: prima di inviare i dati controlla sul portale del RUNTS o con il tuo commercialista.';

	public static function register(): void {
		add_action( 'asem_dashboard_alerts', array( __CLASS__, 'alerts' ) );
	}

	/** Bacheca: avviso se i soci scendono sotto il minimo (ODV e APS) e, da maggio a giugno, il promemoria dell'aggiornamento dei dati. */
	public static function alerts(): void {
		if ( ! current_user_can( Plugin::CAP ) ) {
			return;
		}
		$today = Db::today();
		$link  = ' <a href="' . esc_url( Ui::url( 'asem-runts' ) ) . '">Apri i dati per il RUNTS</a>.';
		$min   = Runts::minimum_state( $today );
		if ( $min['applies'] && $min['below'] ) {
			echo wp_kses( '<div class="notice notice-warning inline"><p>' . self::minimum_text( $min ) . $link . '</p></div>', Html::allowed() );
		}
		$ry = Runts::applies() ? RuntsRules::june_reminder_year( $today ) : null;
		if ( null !== $ry ) {
			$msg = '<strong>RUNTS:</strong> entro il ' . esc_html( self::dmy( RuntsRules::june_deadline( $ry ) ) ) . ' si aggiornano i numeri di soci, volontari e lavoratori al 31 dicembre ' . (int) $ry . ', se sono cambiati.' . $link;
			echo wp_kses( Dismiss::html( 'runts-june-' . $ry, 'info', $msg, 14 ), Html::allowed() );
		}
	}

	/** Il testo dell'avviso sul numero minimo di soci (senza tag da proteggere: i valori sono numeri e date). */
	public static function minimum_text( array $min ): string {
		$short = RuntsRules::section_short( (string) $min['section'] );
		$t     = '<strong>I soci sono sotto il minimo previsto per le ' . esc_html( $short ) . ':</strong> oggi hai ' . (int) $min['persons'] . ' persone e ' . (int) $min['entities'] . ' enti iscritti come ' . esc_html( $short )
			. ', ne servono almeno ' . RuntsRules::MIN_PERSONS . ' persone oppure ' . RuntsRules::MIN_ENTITIES . ' enti. Contiamo i soci con la tessera valida oggi: chi ha la tessera scaduta non è contato finché non rinnova. ';
		if ( ! empty( $min['recent'] ) ) {
			$t .= (int) $min['recent'] . ( 1 === (int) $min['recent'] ? ' socio ha la tessera scaduta da poco' : ' soci hanno la tessera scaduta da poco' ) . ': se rinnovano, tornano nel conteggio'
				. ( ! empty( $min['recoverable'] ) ? ' e si risale sopra il minimo' : '' ) . '. ';
		}
		if ( ! empty( $min['since'] ) ) {
			$t .= 'Sei sotto il minimo dal ' . esc_html( self::dmy( (string) $min['since'] ) ) . '. Per quanto ci risulta va comunicato al RUNTS entro ' . RuntsRules::NOTICE_DAYS . ' giorni, cioè entro il ' . esc_html( self::dmy( (string) $min['deadline'] ) ) . ': controlla sul portale del RUNTS. ';
		} else {
			$t .= 'Non sappiamo dirti da quando, perché nelle schede mancano le date di ingresso o di uscita. Per quanto ci risulta va comunicato al RUNTS entro ' . RuntsRules::NOTICE_DAYS . ' giorni: controlla sul portale del RUNTS. ';
		}
		return $t . 'Per quanto ci risulta, il numero va riportato sopra il minimo entro un anno: controlla con chi ti segue.';
	}

	private static function dmy( string $ymd ): string {
		return (string) mysql2date( 'd/m/Y', $ymd );
	}

	public static function render(): void {
		$today = Db::today();
		$last  = RuntsRules::last_reference_year( $today );
		$years = array();
		for ( $y = $last; $y > $last - 6; $y-- ) {
			$years[ $y ] = '31 dicembre ' . $y;
		}
		$year = Ui::get_int( 'year' );
		$year = isset( $years[ $year ] ) ? $year : $last;
		$date = RuntsRules::reference_date( $year );
		$c    = Runts::counts_at( $date );
		$man  = Runts::manual();
		Ui::header( 'Dati per il RUNTS' );
		echo '<p class="description"><strong>' . esc_html( self::CAUTION ) . '</strong></p>';
		echo '<p class="description">Numeri da copiare nel portale del RUNTS. Valgono al <strong>31 dicembre</strong>, anche se il tuo esercizio inizia in un altro mese. Contiamo come soci chi quel giorno aveva la tessera valida (e i fondatori già ammessi), esclusi quelli già usciti dall\'associazione. È lo stesso criterio del libro soci.</p>';
		echo wp_kses( '<form method="get" class="asem-filters"><input type="hidden" name="page" value="asem-runts"><select name="year">' . Ui::options( $years, $year ) . '</select> <button class="button">Mostra</button></form>', Html::allowed() );

		echo '<h2>Al ' . esc_html( self::dmy( $date ) ) . '</h2>';
		echo '<table class="widefat striped" style="max-width:820px"><tbody>';
		$who  = \AssociazioneSemplice\Edition::has( 'levels' ) ? 'di un livello di socio con diritto di voto' : 'socio';
		$vote = 'Al RUNTS serve solo il numero. Vota chi è ' . $who . ' ed era maggiorenne a quella data. Senza voto: ' . (int) $c['persons_no_vote'] . ' su ' . (int) $c['persons'] . '. '
			. 'Attenzione: usiamo le impostazioni di voto di oggi. Se le hai cambiate dopo quella data, ricontrolla il numero.';
		if ( ! empty( $c['persons_vote_no_birth'] ) ) {
			$vote .= ' Di questi, ' . (int) $c['persons_vote_no_birth'] . ( 1 === (int) $c['persons_vote_no_birth'] ? ' non ha' : ' non hanno' ) . ' la data di nascita: li contiamo come maggiorenni. Completa le schede per un numero sicuro.';
		}
		echo wp_kses( '<tr><th>Soci persone fisiche con diritto di voto</th><td><strong style="font-size:1.4em">' . (int) $c['persons_vote'] . '</strong></td><td class="description">' . esc_html( $vote ) . '</td></tr>', Html::allowed() );
		echo wp_kses( '<tr><th>Soci che sono enti</th><td><strong style="font-size:1.4em">' . count( $c['entities'] ) . '</strong></td><td class="description">Nome e codice fiscale sono nell\'elenco qui sotto. Iscritti al RUNTS nella tua stessa sezione: ' . (int) $c['entities_same_section'] . '.</td></tr>', Html::allowed() );
		$vol = '<tr><th>Volontari iscritti nel registro dei volontari</th><td><strong style="font-size:1.4em">' . (int) $man['volunteers'] . '</strong></td><td class="description">';
		if ( $man['year'] !== $year ) {
			$vol .= '<strong class="asem-warn">' . ( $man['year'] ? 'Il numero inserito è riferito al 31 dicembre ' . (int) $man['year'] . ': aggiornalo.' : 'Non hai indicato l\'anno di riferimento.' ) . '</strong> ';
		}
		$vol .= 'Il programma non tiene il registro dei volontari: scrivi tu il numero in <a href="' . esc_url( Ui::url( 'asem-entity' ) ) . '">Dati dell\'ente</a>. Per aiutarti: a quella data i soci di tipo «Socio e volontario» erano ' . (int) $c['volunteer_members'] . '. Nel registro possono esserci anche volontari che non sono soci.</td></tr>';
		echo wp_kses( $vol, Html::allowed() );
		$workers = Runts::workers_count( $date );
		echo wp_kses(
			'<tr><th>Lavoratori (dipendenti a qualsiasi titolo)</th><td><strong style="font-size:1.4em">' . ( null === $workers ? '—' : (int) $workers ) . '</strong></td><td class="description">'
			. ( null === $workers ? 'Il programma non li conta ancora: scrivi tu il numero nel portale del RUNTS.' : 'Utenti «Dipendente» in servizio a quella data (senza date di servizio sono sempre in servizio): si gestiscono da Rubrica soci → Dipendenti.' ) . '</td></tr>',
			Html::allowed()
		);
		echo '</tbody></table>';

		if ( $c['entities'] ) {
			echo '<h3>Soci che sono enti</h3><table class="widefat striped" style="max-width:820px"><thead><tr><th>Denominazione</th><th>Codice fiscale</th><th>Stessa sezione del RUNTS</th></tr></thead><tbody>';
			foreach ( $c['entities'] as $e ) {
				echo '<tr><td><a href="' . esc_url( Ui::url( 'asem-person', array( 'id' => (int) $e['id'] ) ) ) . '">' . esc_html( $e['name'] ) . '</a></td><td>' . esc_html( $e['tax_code'] ) . '</td><td>' . ( true === $e['same'] ? 'Sì' : ( false === $e['same'] ? 'No' : '<span class="description">da indicare</span>' ) ) . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- valori protetti sopra
			}
			echo '</tbody></table>';
		}

		echo '<h2>Scadenze e minimo di soci</h2>';
		echo '<p>I dati al <strong>31 dicembre ' . (int) $year . '</strong> si aggiornano nel portale del RUNTS entro il <strong>' . esc_html( self::dmy( RuntsRules::june_deadline( $year ) ) ) . '</strong>. Se non è cambiato nulla rispetto all\'ultima volta, per quanto ci risulta non devi fare niente.</p>';
		$min = Runts::minimum_state( $today );
		if ( ! $min['applies'] ) {
			echo '<p class="description">Il controllo del numero minimo di soci vale per le ODV e le APS: indica la sezione in <a href="' . esc_url( Ui::url( 'asem-entity' ) ) . '">Dati dell\'ente</a> per attivarlo.</p>';
		} elseif ( $min['below'] ) {
			echo wp_kses( '<div class="notice notice-warning inline"><p>' . self::minimum_text( $min ) . '</p></div>', Html::allowed() );
		} else {
			echo '<p class="asem-ok">Tutto a posto: oggi hai ' . (int) $min['persons'] . ' persone e ' . (int) $min['entities'] . ' enti iscritti nella tua stessa sezione (ne servono almeno ' . (int) RuntsRules::MIN_PERSONS . ' persone oppure ' . (int) RuntsRules::MIN_ENTITIES . ' enti). Contiamo i soci con la tessera valida oggi.</p>';
		}
		Ui::footer();
	}
}
