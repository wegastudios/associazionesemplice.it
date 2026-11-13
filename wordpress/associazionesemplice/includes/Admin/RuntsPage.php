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

	const CAUTION = 'Le regole qui sotto arrivano da commenti di professionisti e centri di servizio, non dal testo del decreto: controlla sempre sul portale del RUNTS e con chi ti segue prima di comunicare i dati.';

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
			$msg = '<strong>RUNTS: scadenza del ' . esc_html( self::dmy( RuntsRules::june_deadline( $ry ) ) ) . '.</strong> Entro questa data si aggiornano i dati riferiti al 31 dicembre ' . (int) $ry . ', se sono cambiati. Controlla sul portale del RUNTS.' . $link;
			echo wp_kses( Dismiss::html( 'runts-june-' . $ry, 'info', $msg, 14 ), Html::allowed() );
		}
	}

	/** Il testo dell'avviso sul numero minimo di soci (senza tag da proteggere: i valori sono numeri e date). */
	public static function minimum_text( array $min ): string {
		$short = RuntsRules::section_short( (string) $min['section'] );
		$t     = '<strong>Soci sotto il minimo per le ' . esc_html( $short ) . ':</strong> ' . (int) $min['persons'] . ' persone fisiche e ' . (int) $min['entities'] . ' enti della stessa sezione '
			. '(servono almeno ' . RuntsRules::MIN_PERSONS . ' persone fisiche oppure ' . RuntsRules::MIN_ENTITIES . ' enti). ';
		if ( ! empty( $min['since'] ) ) {
			$t .= 'Si è scesi sotto il minimo il ' . esc_html( self::dmy( (string) $min['since'] ) ) . ': secondo i commenti professionali la variazione va comunicata al RUNTS entro ' . RuntsRules::NOTICE_DAYS . ' giorni, cioè entro il ' . esc_html( self::dmy( (string) $min['deadline'] ) ) . '. ';
		} else {
			$t .= 'Non è possibile dire da quando (mancano le date di ingresso e di uscita): secondo i commenti professionali la variazione va comunicata al RUNTS entro ' . RuntsRules::NOTICE_DAYS . ' giorni. ';
		}
		return $t . 'Controlla sul portale del RUNTS.';
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
		echo '<p class="description">Numeri pronti da copiare nel portale del RUNTS, riferiti a una data (di norma il <strong>31 dicembre</strong>, qualunque sia il mese in cui inizia il tuo esercizio). Sono soci a quella data le persone con una tessera valida quel giorno (come nel libro soci), il fondatore ammesso entro quel giorno, e chi non aveva già lasciato l\'associazione.</p>';
		echo wp_kses( '<form method="get" class="asem-filters"><input type="hidden" name="page" value="asem-runts"><select name="year">' . Ui::options( $years, $year ) . '</select> <button class="button">Mostra</button></form>', Html::allowed() );

		echo '<h2>Al ' . esc_html( self::dmy( $date ) ) . '</h2>';
		echo '<table class="widefat striped" style="max-width:820px"><tbody>';
		echo wp_kses( '<tr><th>Soci persone fisiche con diritto di voto</th><td><strong style="font-size:1.4em">' . (int) $c['persons_vote'] . '</strong></td><td class="description">Solo il numero. Votano i tipi di socio spuntati in Impostazioni → Soci e quote, se maggiorenni alla data. Senza voto: ' . (int) $c['persons_no_vote'] . ' (su ' . (int) $c['persons'] . ' persone fisiche). Il tipo che vota è quello di oggi: per una data passata controlla.</td></tr>', Html::allowed() );
		echo wp_kses( '<tr><th>Soci che sono enti</th><td><strong style="font-size:1.4em">' . count( $c['entities'] ) . '</strong></td><td class="description">Denominazione e codice fiscale sotto. Quelli della stessa sezione del RUNTS: ' . (int) $c['entities_same_section'] . '.</td></tr>', Html::allowed() );
		$vol = '<tr><th>Volontari iscritti nel registro dei volontari</th><td><strong style="font-size:1.4em">' . (int) $man['volunteers'] . '</strong></td><td class="description">';
		if ( $man['year'] !== $year ) {
			$vol .= '<strong class="asem-warn">' . ( $man['year'] ? 'Il numero inserito è riferito al 31 dicembre ' . (int) $man['year'] . ': aggiornalo.' : 'Non hai indicato l\'anno di riferimento.' ) . '</strong> ';
		}
		$vol .= 'Il programma non ha il registro dei volontari: il numero lo scrivi tu in <a href="' . esc_url( Ui::url( 'asem-entity' ) ) . '">Dati dell\'ente</a>. Come promemoria, i soci di tipo «Socio e volontario» a quella data sono ' . (int) $c['volunteer_members'] . ' (non è il registro dei volontari).</td></tr>';
		echo wp_kses( $vol, Html::allowed() );
		echo wp_kses( '<tr><th>Lavoratori (dipendenti a qualsiasi titolo)</th><td><strong style="font-size:1.4em">' . Runts::workers_count( $date ) . '</strong></td><td class="description">Lavoratori: n. (utenti «Dipendente»). Si contano dagli utenti «Dipendente», un tipo di utente che sarà aggiunto in seguito: finché non c\'è, il numero è 0 e non è un dato vero. Controlla sul portale del RUNTS.</td></tr>', Html::allowed() );
		echo '</tbody></table>';

		if ( $c['entities'] ) {
			echo '<h3>Soci che sono enti</h3><table class="widefat striped" style="max-width:820px"><thead><tr><th>Denominazione</th><th>Codice fiscale</th><th>Stessa sezione del RUNTS</th></tr></thead><tbody>';
			foreach ( $c['entities'] as $e ) {
				echo '<tr><td><a href="' . esc_url( Ui::url( 'asem-person', array( 'id' => (int) $e['id'] ) ) ) . '">' . esc_html( $e['name'] ) . '</a></td><td>' . esc_html( $e['tax_code'] ) . '</td><td>' . ( true === $e['same'] ? 'Sì' : ( false === $e['same'] ? 'No' : '<span class="description">da indicare</span>' ) ) . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- valori protetti sopra
			}
			echo '</tbody></table>';
		}

		echo '<h2>Scadenze e minimo di soci</h2>';
		echo '<p>I dati riferiti al <strong>31 dicembre ' . (int) $year . '</strong> vanno aggiornati nel RUNTS entro il <strong>' . esc_html( self::dmy( RuntsRules::june_deadline( $year ) ) ) . '</strong>, solo se sono cambiati. Controlla sul portale del RUNTS.</p>';
		$min = Runts::minimum_state( $today );
		if ( ! $min['applies'] ) {
			echo '<p class="description">Il controllo del numero minimo di soci vale per le ODV e le APS: indica la sezione in <a href="' . esc_url( Ui::url( 'asem-entity' ) ) . '">Dati dell\'ente</a> per attivarlo.</p>';
		} elseif ( $min['below'] ) {
			echo wp_kses( '<div class="notice notice-warning inline"><p>' . self::minimum_text( $min ) . '</p></div>', Html::allowed() );
		} else {
			echo '<p class="asem-ok">Oggi i soci sono sopra il minimo: ' . (int) $min['persons'] . ' persone fisiche e ' . (int) $min['entities'] . ' enti della stessa sezione (servono almeno ' . (int) RuntsRules::MIN_PERSONS . ' persone fisiche oppure ' . (int) RuntsRules::MIN_ENTITIES . ' enti). Per questo controllo si contano i soci non cessati, anche con la tessera ancora da rinnovare.</p>';
		}
		Ui::footer();
	}
}
