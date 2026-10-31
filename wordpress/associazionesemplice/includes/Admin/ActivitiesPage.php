<?php
namespace AssociazioneSemplice\Admin;

use AssociazioneSemplice\ActivityKind;
use AssociazioneSemplice\ActivityService;
use AssociazioneSemplice\CancelPolicy;
use AssociazioneSemplice\MemberType;
use AssociazioneSemplice\Money;
use AssociazioneSemplice\Plugin;
use AssociazioneSemplice\PriceLists;
use AssociazioneSemplice\Settings;
use AssociazioneSemplice\SocialYear;
use AssociazioneSemplice\Html;

defined( 'ABSPATH' ) || exit;

final class ActivitiesPage {


	/** Righe del modulo: cancellabile e termine (solo per eventi ed eventi ricorrenti). */
	private static function cancel_rows( ?array $a, string $row_class ): string {
		$on      = $a && ! empty( $a['cancellable'] );
		$pol     = $a ? (string) $a['cancel_policy'] : '';
		$default = CancelPolicy::labels()[ Settings::get( 'cancel_policy_default' ) ] ?? '';
		return '<tr class="' . esc_attr( $row_class ) . '"><th>Cancellazione</th><td><label><input type="checkbox" name="cancellable" value="1"' . checked( $on, true, false ) . '> Cancellabile anche se a pagamento</label>'
			. '<p>Termine: <select name="cancel_policy">' . Ui::options( array( '' => 'Predefinito (' . $default . ')' ) + CancelPolicy::labels(), $pol ) . '</select></p>'
			. '<p class="description">Gratuito: si può sempre annullare. A pagamento: non si annulla mai, ma si può cambiare nominativo (se il nuovo partecipante è un ospite con contributo maggiore si integra la differenza), a meno che l\'evento sia cancellabile entro il termine scelto.</p></td></tr>';
	}
	/**
	 * Programma a righe dinamiche: ogni riga ha giorno e orario e, con la spunta "ricorrente", si ripete ogni settimana
	 * (o tutti i giorni) fino a una data di fine; senza spunta resta una data unica. Le righe si aggiungono senza limiti.
	 *
	 * @param array[] $rows righe già presenti (date, from, to, recurring, end, repeat); vuoto = una riga con i valori di default
	 */
	private static function when_builder( array $rows = array() ): string {
		$def = array( 'date' => current_time( 'Y-m-d' ), 'from' => '18:00', 'to' => '19:00' );
		return '<div class="asem-when" data-default="' . esc_attr( wp_json_encode( $def ) ) . '" data-rows="' . esc_attr( wp_json_encode( $rows ) ) . '"><div class="asem-when-rows"></div>'
			. '<p><button type="button" class="button" data-add="1">+ Aggiungi data</button></p>'
			. '<p class="description">Ogni riga è un giorno con il suo orario. Senza la spunta <em>ricorrente</em> è una data unica; con la spunta si ripete ogni settimana (o tutti i giorni) fino alla data di fine. '
			. 'Per giorni e orari diversi aggiungi altre righe: ad esempio i martedì e giovedì alle 15 (due righe ricorrenti), i lunedì, mercoledì e venerdì alle 16 (tre righe) e un solo venerdì alle 15 (una riga senza spunta).</p></div>';
	}

	/** Righe del modulo: come si paga e quando si tiene il corso (solo corsi). */
	private static function weekday_row( ?array $a, string $row_class, bool $with_when = true ): string {
		$billing = $a ? (string) $a['billing'] : 'once';
		$rows    = $a ? ActivityService::schedule_rows( $a, current_time( 'Y-m-d' ) ) : array();
		$val     = function ( string $k ) use ( $a ) {
			return $a && ! empty( $a[ $k ] ) ? esc_attr( (string) $a[ $k ] ) : '';
		};
		$tr = '<tr class="' . esc_attr( $row_class ) . '">';
		return $tr . '<th>Come si paga</th><td><select name="billing" class="asem-billing">' . Ui::options( array( 'once' => 'Una tantum (anche annuale)', 'monthly' => 'Rinnovo mensile', 'quarterly' => 'Rinnovo trimestrale' ), $billing ) . '</select>'
			. '<p class="description"><strong>Una tantum:</strong> la quota indicata è il totale (es. 10 incontri a 120 €), dovuta subito all\'iscrizione. <strong>Rinnovo trimestrale:</strong> la quota indicata è quella di un trimestre, dovuta all\'inizio di ogni periodo di tre mesi dal primo mese dell\'iscrizione. <strong>Rinnovo mensile:</strong> il corso si rinnova da solo ogni mese finché l\'iscritto non lo cancella, e ogni mensilità è dovuta <strong>dalla prima lezione del mese</strong>.</p></td></tr>'
			. ( $with_when ? $tr . '<th>Quando</th><td>' . self::when_builder( $rows ) . '</td></tr>' : '' )
			. $tr . '<th>Luogo</th><td><input type="text" name="location" class="regular-text" value="' . $val( 'location' ) . '"></td></tr>'
			. $tr . '<th>Dal / al</th><td><input type="date" name="starts_on" value="' . $val( 'starts_on' ) . '"> <input type="date" name="ends_on" value="' . $val( 'ends_on' ) . '"> <span class="description">facoltative: se il corso ha una fine, dopo quella data le mensilità non sono più dovute</span></td></tr>';
	}

	/** Gli sport del menu: l'amministratore li aggiunge, li toglie, ne importa un elenco o torna a quello di base del CONI. */
	private static function sports_card(): void {
		if ( ! current_user_can( Plugin::CAP ) ) {
			return;
		}
		$back = Ui::url( 'asem-activities' );
		$list = \AssociazioneSemplice\SportCatalog::sports();
		echo '<details class="asem-card" id="asem-sports"><summary><strong>Sport disponibili (' . count( $list ) . ')</strong> · ' . ( \AssociazioneSemplice\SportCatalog::is_default() ? 'elenco del CONI' : 'elenco personalizzato' ) . '</summary>';
		echo '<p class="description">Sono gli sport tra cui scegli nelle attività e che finiscono nel file per le tessere degli enti. Togliere uno sport dal menu non lo cancella dalle attività che già lo usano.</p>';
		Ui::form_open( 'asem_sports', $back, false, 'asem-inline' );
		echo wp_kses( Ui::hidden( 'op', 'add' ) . '<p><input type="text" name="sport" maxlength="60" placeholder="Nuovo sport (es. Zumba)"> <button class="button">Aggiungi</button></p>', Html::allowed() );
		Ui::form_close();
		Ui::form_open( 'asem_sports', $back );
		echo wp_kses( Ui::hidden( 'op', 'add' ) . '<p><textarea name="sport" rows="3" class="large-text" placeholder="Importa un elenco: uno sport per riga (o separati da punto e virgola)"></textarea> <button class="button">Importa</button></p>', Html::allowed() );
		Ui::form_close();
		echo '<p>';
		foreach ( $list as $s ) {
			Ui::form_open( 'asem_sports', $back, false, 'asem-inline' );
			echo wp_kses( Ui::hidden( 'op', 'remove' ) . Ui::hidden( 'sport', $s ) . '<span class="asem-chip">' . esc_html( $s ) . ' <button class="button-link-delete" title="Togli dal menu" aria-label="' . esc_attr( 'Togli ' . $s ) . '">×</button></span> ', Html::allowed() );
			Ui::form_close();
		}
		echo '</p>';
		if ( ! \AssociazioneSemplice\SportCatalog::is_default() ) {
			Ui::form_open( 'asem_sports', $back, false, 'asem-inline' );
			echo wp_kses( Ui::hidden( 'op', 'reset' ) . '<button class="button" data-confirm="Tornare all\'elenco di base del CONI? Gli sport che hai aggiunto spariscono dal menu.">Ripristina l\'elenco del CONI</button>', Html::allowed() );
			Ui::form_close();
		}
		echo '</details>';
	}

	/** Riga del modulo: sport (elenco CONI) e disciplina secondo l'ente di promozione, per l'affiliazione e le tessere degli enti. */
	private static function sport_row( ?array $a ): string {
		$list   = \AssociazioneSemplice\SportCatalog::sports();
		$now    = $a ? (string) ( $a['sport'] ?? '' ) : '';
		if ( '' !== $now && ! in_array( $now, $list, true ) ) {
			$list[] = $now; // uno sport tolto dal menu resta a chi già lo usa
		}
		$sports = array_combine( $list, $list );
		return '<tr><th>Sport e disciplina</th><td><select name="sport">' . Ui::options( $sports, $a ? (string) ( $a['sport'] ?? '' ) : '', '— nessuno —' ) . '</select> '
			. '<input type="text" name="discipline" class="regular-text" maxlength="120" value="' . esc_attr( $a ? (string) ( $a['discipline'] ?? '' ) : '' ) . '" placeholder="Disciplina secondo l\'ente (es. Ginnastica dolce)">'
			. '<p class="description">Lo sport segue l\'elenco delle discipline riconosciute dal CONI (l\'amministratore lo modifica dall\'elenco delle attività); la disciplina la scrivi come la chiama il tuo ente di promozione (CSEN, UISP, ASI…). Compaiono nel file per le tessere degli enti.</p></td></tr>';
	}

	/** Riga del modulo: quota di ogni pagamento accantonata nel fondo per rimborsare il volontario. */
	private static function fund_row( ?array $a ): string {
		if ( ! \AssociazioneSemplice\Edition::has( 'funds' ) ) { // il rimborso al volontario con accantonamento è delle funzioni avanzate
			return '';
		}
		$mode  = $a ? (string) $a['fund_mode'] : '';
		$value = $a ? (int) $a['fund_value'] : 0;
		$shown = \AssociazioneSemplice\FundShare::PERCENT === $mode ? rtrim( rtrim( number_format( $value / 100, 2, ',', '' ), '0' ), ',' ) : ( $value ? Money::plain( $value ) : '' );
		return '<tr><th>Quota per il rimborso</th><td><select name="fund_mode">' . Ui::options( \AssociazioneSemplice\FundShare::modes(), $mode ) . '</select> '
			. '<input type="text" name="fund_value" class="small-text" inputmode="decimal" value="' . esc_attr( $shown ) . '"> <span class="description">€ se importo fisso, % se percentuale</span>'
			. '<p class="description">Una parte di ogni pagamento ricevuto va nel fondo "Rimborso (volontario) — (attività)". Il pagamento entra comunque nella cassa o nel conto usato: la quota è accantonata e si sottrae dalla disponibilità reale, finché non estingui il fondo registrando il rimborso. Serve indicare il referente.</p></td></tr>';
	}

	/** Riga del modulo: tolleranza per il pagamento (solo eventi ed eventi ricorrenti; ha effetto se i posti sono limitati). */
	private static function hold_row( ?array $a, string $row_class ): string {
		$h = $a ? (int) ( $a['hold_hours'] ?? 0 ) : 0;
		return '<tr class="' . esc_attr( $row_class ) . '"><th>Tolleranza per il pagamento</th><td><input type="number" name="hold_hours" min="0" max="720" class="small-text" value="' . (int) $h . '"> ore'
			. '<p class="description">Per chi paga con bonifico o contanti: se il contributo non arriva entro queste ore dalla prenotazione il posto si libera e passa a chi è in lista d\'attesa. Vale solo se i posti sono limitati e c\'è un contributo; non si libera mai il posto di chi ha già versato (anche in parte) o sta pagando online. 0 = nessun limite.</p></td></tr>';
	}

	/** Riga del modulo: biglietto QR per le prenotazioni (solo eventi ed eventi ricorrenti, spento di default). */
	private static function qr_row( ?array $a, string $row_class ): string {
		if ( ! \AssociazioneSemplice\Settings::tickets_enabled() ) {
			return ''; // i biglietti QR sono spenti: Impostazioni > Tessera e QR
		}
		$on = $a && ! empty( $a['booking_qr'] );
		return '<tr class="' . esc_attr( $row_class ) . '"><th>Biglietto QR</th><td><label><input type="checkbox" name="booking_qr" value="1"' . checked( $on, true, false ) . '> Genera un QR per ogni prenotazione</label>'
			. '<p class="description">Chi prenota (e ogni suo ospite) trova nell\'area riservata un QR: scansionandolo all\'ingresso si vede subito se la prenotazione è valida, per quale data e se il contributo è stato versato. Facoltativo, scelta per singolo evento.</p></td></tr>';
	}

	/** "Contributo 5,00 € a evento · ospiti 8,00 €" oppure "Gratuito". */
	private static function fee_text( array $a ): string {
		$fee   = (int) $a['fee_cents'];
		$guest = null === $a['guest_fee_cents'] || '' === $a['guest_fee_cents'] ? null : (int) $a['guest_fee_cents'];
		$unit  = ActivityKind::fee_unit( $a['kind'], (string) ( $a['billing'] ?? 'monthly' ) );
		$txt   = 0 === $fee ? 'Gratuito per i soci' : 'Soci ' . Money::format( $fee ) . rtrim( ' ' . $unit );
		if ( null !== $guest ) {
			$txt .= ' · ' . ( 0 === $guest ? 'ospiti: gratuito' : 'ospiti ' . Money::format( $guest ) . rtrim( ' ' . $unit ) );
		} elseif ( $fee > 0 ) {
			$txt .= ' · ospiti: uguale';
		}
		return $txt;
	}

	public static function render_list(): void {
		$sy_start = Ui::get_int( 'year', Settings::social_year()->start_year );
		$year     = new SocialYear( $sy_start, Settings::start_month() );
		$report   = Plugin::reports()->social_year( $year );
		$back     = Ui::url( 'asem-activities', array( 'year' => $sy_start ) );
		$today    = current_time( 'Y-m-d' );

		Ui::header( 'Attività — anno sociale ' . $year->label() );
		echo '<p><a class="button" href="' . esc_url( Ui::url( 'asem-activities', array( 'year' => $sy_start - 1 ) ) ) . '">‹ ' . esc_html( $year->previous()->label() ) . '</a> '
			. '<a class="button" href="' . esc_url( Ui::url( 'asem-activities', array( 'year' => $sy_start + 1 ) ) ) . '">' . esc_html( $year->next()->label() ) . ' ›</a></p>';

		if ( ! $report['activities'] ) {
			echo '<p>Nessuna attività in questo anno sociale. Creane una qui sotto.</p>';
		}
		echo '<div class="asem-grid">';
		foreach ( $report['activities'] as $s ) {
			$a = $s['activity'];
			echo '<div class="asem-card"><p class="description" style="margin-bottom:0">' . esc_html( ActivityKind::short_label( $a['kind'] ) ) . '</p>';
			echo '<h2 style="margin-top:2px"><a href="' . esc_url( Ui::url( 'asem-activity', array( 'id' => $a['id'] ) ) ) . '">' . esc_html( $a['name'] ) . '</a></h2>';
			echo '<p class="description">' . esc_html( self::fee_text( $a ) ) . '<br>' . (int) $s['participants'] . ' partecipanti · '
				. ( $a['instructor_name'] ? 'tenuta da ' . esc_html( $a['instructor_name'] ) : 'senza referente' ) . '</p>';
			if ( ActivityKind::uses_sessions( $a['kind'] ) ) {
				$sessions = Plugin::activities()->sessions( (int) $a['id'] );
				$next     = null;
				foreach ( $sessions as $x ) {
					if ( empty( $x['cancelled_at'] ) && $x['session_date'] >= $today ) {
						$next = $x;
						break;
					}
				}
				echo wp_kses( '<p class="description">' . count( $sessions ) . ( 1 === count( $sessions ) ? ' data' : ' date' ) . ( $next ? ' · prossima: ' . Ui::date( $next['session_date'] ) . ( $next['start_time'] ? ' ore ' . esc_html( $next['start_time'] ) : '' ) : '' ) . '</p>', Html::allowed() );
			}
			echo wp_kses( self::participants_html( $a, 1, false )
				. '<table class="asem-kv"><tr><td>Incassi</td><td>' . Ui::money( $s['income'] ) . '</td></tr><tr><td>Costi</td><td>' . Ui::money( $s['cost'] ) . '</td></tr>'
				. '<tr><td><strong>Resta all\'associazione</strong></td><td><strong>' . Ui::money( $s['margin'] ) . '</strong></td></tr></table>'
				. '<p><a class="button button-small" href="' . esc_url( Ui::url( 'asem-activity', array( 'id' => $a['id'] ) ) . '#asem-riproponi' ) . '">Riproponi</a></p></div>', Html::allowed() );
		}
		echo '</div>';

		self::sports_card();
		$volunteers = Plugin::people()->search( array( 'type' => MemberType::VOLUNTEER ) );
		echo '<div class="asem-card"><h2>Nuova attività</h2>';
		Ui::form_open( 'asem_save_activity', $back );
		echo wp_kses( Ui::hidden( 'social_year', $year->label() ), Html::allowed() );
		echo '<table class="form-table asem-form"><tbody>';
		echo wp_kses( '<tr><th>Tipo *</th><td><select name="kind" id="asem-kind">' . Ui::options( ActivityKind::labels(), ActivityKind::COURSE ) . '</select>', Html::allowed() );
		echo '<p class="description" id="asem-kind-hint"></p></td></tr>';
		echo '<tr><th>Nome *</th><td><input type="text" name="name" class="regular-text" required placeholder="es. Yoga, Serata di giochi"></td></tr>';
		echo wp_kses( '<tr><th>Quando *</th><td>' . self::when_builder() . '</td></tr>', Html::allowed() );
		echo '<tr class="asem-row-sessions"><th>Luogo</th><td><input type="text" name="location" class="regular-text"></td></tr>';
		echo '<tr class="asem-row-sessions"><th>Posti disponibili</th><td><input type="number" min="1" name="capacity" class="small-text"> <span class="description">vuoto = nessun limite</span></td></tr>';
		echo wp_kses( self::cancel_rows( null, 'asem-row-sessions' ), Html::allowed() );
		echo wp_kses( self::qr_row( null, 'asem-row-sessions' ), Html::allowed() );
		echo wp_kses( self::hold_row( null, 'asem-row-sessions' ), Html::allowed() );
		echo wp_kses( self::weekday_row( null, 'asem-row-course', false ), Html::allowed() );
		echo '<tr><th><span class="asem-fee-label">Contributo soci</span></th><td><input type="text" name="fee" inputmode="decimal" placeholder="0,00"> € <span class="description">0 o vuoto = gratuito</span></td></tr>';
		echo '<tr><th>Contributo ospiti</th><td><input type="text" name="guest_fee" inputmode="decimal" placeholder="uguale ai soci"> € <span class="description">vuoto = come i soci · 0 = gratuito per gli ospiti</span></td></tr>';
		echo wp_kses( Ui::vat_row( \AssociazioneSemplice\Fiscal::default_rate(), \AssociazioneSemplice\Fiscal::default_mode(), 'I contributi' ), Html::allowed() );
		echo wp_kses( '<tr><th>Referente</th><td>' . Ui::person_select( 'instructor_person_id', $volunteers, null, '— nessuno —', 'asem-instructor' )
			. '<p class="description">Solo soci e volontari possono essere referenti di un\'attività.</p></td></tr>', Html::allowed() );
		echo wp_kses( implode( '', (array) apply_filters( 'asem_activity_form_rows', array(), null ) ), Html::allowed() );
		echo wp_kses( self::fund_row( null ), Html::allowed() );
		echo wp_kses( self::sport_row( null ), Html::allowed() );
		echo wp_kses( '</tbody></table>', Html::allowed() );
		submit_button( 'Crea attività' );
		Ui::form_close();
		echo '</div>';
		Ui::footer();
	}

	public static function render_detail(): void {
		$id       = Ui::get_int( 'id' );
		$svc      = Plugin::activities();
		$activity = $svc->get( $id );
		if ( ! $activity ) {
			wp_die( 'Attività non trovata.' );
		}
		$year = SocialYear::from_label( $activity['social_year'], Settings::start_month() );
		$back = Ui::url( 'asem-activity', array( 'id' => $id ) );

		Ui::header( $activity['name'], '<a class="page-title-action" href="' . esc_url( Ui::url( 'asem-activities', array( 'year' => $year->start_year ) ) ) . '">← Attività</a>' );
		echo '<p class="description">' . esc_html( ActivityKind::labels()[ $activity['kind'] ] ?? $activity['kind'] ) . ' · ' . esc_html( self::fee_text( $activity ) ) . '</p>';
		echo '<div class="asem-cols"><div class="asem-col">';
		if ( ActivityKind::uses_sessions( $activity['kind'] ) ) {
			self::sessions_left( $activity, $back );
		} else {
			self::course_left( $activity, $year, $back );
			self::course_enrolled( $activity, $year, $back ); // gli iscritti restano nella prima colonna, sotto il modulo di iscrizione
		}
		echo '</div><div class="asem-col">';
		self::edit_card( $activity, $back );
		if ( ActivityKind::COURSE === $activity['kind'] ) {
			self::lessons_card( $activity, $back );
		}
		self::calendar_card( $activity );
		self::notices_card( $activity, $back );
		self::participants_card( $activity );
		self::copy_card( $activity );
		self::delete_card( $activity );
		echo '</div></div>';

		if ( ActivityKind::uses_sessions( $activity['kind'] ) ) {
			self::sessions_list( $activity, $back );
		}
		Ui::footer();
	}

	/** Collegamenti al calendario di questa attività (e a quello di tutte). */
	/** Corsi: annulla o sposta una singola lezione (festività, maltempo, cambio di giorno) e ripristina le eccezioni. */
	private static function lessons_card( array $activity, string $back ): void {
		$id    = (int) $activity['id'];
		$today = current_time( 'Y-m-d' );
		$next  = array();
		foreach ( \AssociazioneSemplice\Calendar::occurrences( $today, gmdate( 'Y-m-d', strtotime( $today . ' +60 days' ) ) ) as $o ) {
			if ( (int) $o['activity_id'] === $id ) {
				$next[] = $o;
			}
		}
		$ex = \AssociazioneSemplice\LessonExceptions::for_activity( $id );
		echo '<div class="asem-card"><h2>Date delle lezioni</h2><p class="description">Annulla una lezione (festività, maltempo) o spostala a un altro giorno: il calendario, le presenze e i promemoria seguono. Le quote non cambiano.</p>';
		if ( ! $next ) {
			echo '<p class="description">Nessuna lezione nei prossimi 60 giorni.</p>';
		}
		foreach ( $next as $o ) {
			Ui::form_open( 'asem_lesson_exception', $back, false, 'asem-inline' );
			echo wp_kses( Ui::hidden( 'activity_id', $id ) . Ui::hidden( 'date', $o['date'] ), Html::allowed() );
			echo '<p><strong>' . esc_html( Ui::date( $o['date'] ) ) . '</strong>' . ( $o['start'] ? ' ore ' . esc_html( (string) $o['start'] ) : '' ) . ' · <input type="text" name="note" placeholder="Motivo (facoltativo)" size="22"> '
				. '<label><input type="checkbox" name="notify" value="1" checked> avvisa gli iscritti</label> '
				. '<button class="button" name="mode" value="cancel">Annulla la lezione</button><br>Sposta a <input type="date" name="new_date"> dalle <input type="time" name="new_start"> alle <input type="time" name="new_end"> <button class="button" name="mode" value="move">Sposta</button></p>';
			Ui::form_close();
		}
		if ( $ex ) {
			echo '<h3>Lezioni cambiate</h3><ul>';
			foreach ( $ex as $e ) {
				Ui::form_open( 'asem_lesson_restore', $back, false, 'asem-inline' );
				echo wp_kses( Ui::hidden( 'activity_id', $id ) . Ui::hidden( 'date', $e['lesson_date'] ), Html::allowed() );
				echo '<li>' . esc_html( Ui::date( $e['lesson_date'] ) ) . ( \AssociazioneSemplice\LessonExceptions::MOVE === $e['action'] ? ' → spostata al ' . esc_html( Ui::date( $e['new_date'] ) ) : ' — annullata' ) . ( $e['note'] ? ' <span class="description">(' . esc_html( $e['note'] ) . ')</span>' : '' ) . ' <button class="button button-small">Ripristina</button></li>';
				Ui::form_close();
			}
			echo '</ul>';
		}
		echo '</div>';
	}

	private static function calendar_card( array $activity ): void {
		echo '<div class="asem-card"><h2>Calendario</h2>';
		if ( ! \AssociazioneSemplice\Calendar::enabled() ) {
			echo '<p class="description">Il collegamento ai calendari (Google Calendar e simili) è spento: lo accendi dalla <a href="' . esc_url( Ui::url( 'asem-calendar' ) ) . '">pagina del calendario</a>.</p></div>';
			return;
		}
		$one = \AssociazioneSemplice\Calendar::feed_url( (int) $activity['id'] );
		$all = \AssociazioneSemplice\Calendar::feed_url();
		echo '<p><strong>Solo questa attività</strong><br><a class="button" target="_blank" rel="noopener" href="' . esc_url( \AssociazioneSemplice\Calendar::google_add_url( $one ) ) . '">Aggiungi a Google Calendar</a> '
			. '<a class="button" href="' . esc_url( \AssociazioneSemplice\Calendar::webcal_url( $one ) ) . '">Apple / Outlook</a><br><input type="text" readonly class="large-text" value="' . esc_attr( $one ) . '" data-asem-select></p>'
			. '<p><strong>Tutte le attività</strong><br><a class="button" target="_blank" rel="noopener" href="' . esc_url( \AssociazioneSemplice\Calendar::google_add_url( $all ) ) . '">Aggiungi a Google Calendar</a> '
			. '<a class="button" href="' . esc_url( \AssociazioneSemplice\Calendar::webcal_url( $all ) ) . '">Apple / Outlook</a><br><input type="text" readonly class="large-text" value="' . esc_attr( $all ) . '" data-asem-select></p>';
		echo '</div>';
	}

	/**
	 * Comando per cancellare un'iscrizione: se non c'è nessun incasso collegato (la prima nota non cambia) è un pulsante con una sola conferma;
	 * altrimenti è il collegamento alla procedura con la scelta sulle somme e la doppia conferma.
	 */
	private static function cancel_control( int $activity_id, array $person, int $session_id, string $label ): string {
		$name = trim( $person['first_name'] . ' ' . $person['last_name'] );
		if ( \AssociazioneSemplice\ActivityReset::registration_is_free( $activity_id, (int) $person['id'], $session_id ) ) {
			return Ui::confirm_button( 'asem_delete_booking', Ui::url( 'asem-activity', array( 'id' => $activity_id ) ), array( 'activity' => $activity_id, 'person' => (int) $person['id'], 'session' => $session_id ), $label, 'Cancellare l\'iscrizione di ' . $name . '? Non ci sono incassi collegati: la prima nota non cambia.', 'color:#b32d2e' );
		}
		return '<a class="asem-neg" href="' . esc_url( DeleteBookingPage::url( $activity_id, (int) $person['id'], $session_id ) ) . '">' . esc_html( $label ) . '</a>';
	}

	/** Riga di un partecipante: nome, collegamento WhatsApp (se ha lasciato un cellulare valido) e, per gli amministratori, la cancellazione dell'iscrizione. */
	private static function participant_row( array $activity, array $person, int $session_id, string $extra = '', bool $with_delete = true ): string {
		$name  = trim( $person['first_name'] . ' ' . $person['last_name'] );
		$phone = (string) ( $person['phone'] ?? '' );
		$wa    = '' !== $phone ? \AssociazioneSemplice\Phone::whatsapp( $phone ) : '';
		$text  = rawurlencode( 'Ciao ' . $person['first_name'] . ', ti scrivo per «' . $activity['name'] . '».' );
		$html  = '<li>' . esc_html( $name ) . ( '' !== $extra ? ' <span class="description">' . esc_html( $extra ) . '</span>' : '' );
		$html .= '' !== $wa ? ' · <a href="' . esc_url( 'https://wa.me/' . $wa . '?text=' . $text ) . '" target="_blank" rel="noopener noreferrer">WhatsApp</a>' : ' · <span class="description">nessun cellulare</span>';
		if ( $with_delete && current_user_can( Plugin::CAP ) ) {
			$html .= ' · ' . self::cancel_control( (int) $activity['id'], $person, $session_id, 'Cancella l\'iscrizione' );
		}
		return $html . '</li>';
	}

	/** Partecipanti attesi, a scomparsa: per gli eventi una voce per data (le prossime), per i corsi gli iscritti in corso. */
	public static function participants_html( array $activity, int $max_sessions = 8, bool $with_delete = true ): string {
		$people = Plugin::people();
		$svc    = Plugin::activities();
		$html   = '';
		if ( ActivityKind::uses_sessions( $activity['kind'] ) ) {
			$n = 0;
			foreach ( $svc->sessions( (int) $activity['id'] ) as $s ) {
				if ( ! empty( $s['cancelled_at'] ) || $s['session_date'] < current_time( 'Y-m-d' ) || $n >= $max_sessions ) {
					continue;
				}
				$n++;
				$rows = '';
				$cnt  = 0;
				foreach ( $svc->bookings_for_session( (int) $s['id'] ) as $b ) {
					if ( 'booked' !== $b['status'] ) {
						continue;
					}
					$person = $people->get( (int) $b['person_id'] );
					if ( $person ) {
						$cnt++;
						$rows .= self::participant_row( $activity, $person, (int) $s['id'], trim( ( 'paid' === ( $b['state'] ?? '' ) ? 'pagato' : ( ! empty( $b['fee_due_cents'] ) ? 'da pagare' : '' ) ) . ( '' !== PriceLists::booking_label( $activity, $b ) ? ' · ' . PriceLists::booking_label( $activity, $b ) : '' ), ' ·' ), $with_delete );
					}
				}
				$html .= '<details style="margin:6px 0"><summary><strong>' . esc_html( Ui::date( $s['session_date'] ) ) . ( $s['start_time'] ? ' · ore ' . esc_html( $s['start_time'] ) : '' ) . '</strong> — ' . (int) $cnt . ( 1 === $cnt ? ' partecipante atteso' : ' partecipanti attesi' ) . '</summary>'
					. ( $rows ? '<ul style="list-style:none;margin:6px 0 6px 12px">' . $rows . '</ul>' : '<p class="description">Nessuno ancora.</p>' ) . '</details>';
			}
			if ( '' === $html ) {
				$html = '<p class="description">Nessuna data futura.</p>';
			}
		} else {
			$ym   = current_time( 'Y-m' );
			$rows = '';
			$cnt  = 0;
			foreach ( \AssociazioneSemplice\Db::db()->get_col( \AssociazioneSemplice\Db::db()->prepare( 'SELECT person_id FROM ' . \AssociazioneSemplice\Db::t( 'enrollments' ) .' WHERE activity_id = %d AND (end_month IS NULL OR end_month >= %s)', (int) $activity['id'], $ym ) ) as $pid ) {
				$person = $people->get( (int) $pid );
				if ( $person ) {
					$cnt++;
					$rows .= self::participant_row( $activity, $person, 0, '', $with_delete );
				}
			}
			$html = '<details style="margin:6px 0"><summary><strong>' . (int) $cnt . ( 1 === $cnt ? ' iscritto' : ' iscritti' ) . '</strong></summary>' . ( $rows ? '<ul style="list-style:none;margin:6px 0 6px 12px">' . $rows . '</ul>' : '<p class="description">Nessuno ancora.</p>' ) . '</details>';
		}
		return $html;
	}

	private static function participants_card( array $activity ): void {
		echo wp_kses( '<div class="asem-card"><h2>Partecipanti attesi</h2>' . self::participants_html( $activity ) . '<p class="description">Il collegamento WhatsApp compare solo per chi ha lasciato un cellulare.</p></div>', Html::allowed() );
	}

	/** Riproporre l'attività in una nuova data (eventi) o in un nuovo anno sociale (corsi), senza rifare tutto. */
	private static function copy_card( array $activity ): void {
		$id   = (int) $activity['id'];
		$kind = (string) $activity['kind'];
		echo '<div class="asem-card" id="asem-riproponi"><h2>Riproponi</h2><p class="description">Crea una nuova edizione con gli stessi dati (contributi, referente, regole, note, luogo, staff). Iscrizioni, prenotazioni e incassi non si copiano: la nuova attività parte vuota.</p>';
		Ui::form_open( 'asem_duplicate_activity', Ui::url( 'asem-activity', array( 'id' => $id ) ) );
		echo wp_kses( Ui::hidden( 'id', $id ) . '<table class="form-table"><tr><th>Nome</th><td><input type="text" name="name" class="regular-text" value="' . esc_attr( (string) $activity['name'] ) . '"></td></tr>', Html::allowed() );
		if ( ActivityKind::COURSE === $kind ) {
			$year = SocialYear::from_label( (string) $activity['social_year'], Settings::start_month() );
			$opts = array();
			$y    = $year;
			for ( $i = 0; $i < 3; $i++ ) {
				$opts[ $y->label() ] = $y->label() . ( 0 === $i ? ' (stesso anno)' : '' );
				$y                   = $y->next();
			}
			echo wp_kses( '<tr><th>Anno sociale</th><td><select name="social_year">' . Ui::options( $opts, $year->next()->label() ) . '</select></td></tr>'
				. '<tr><th>Dal / al</th><td><input type="date" name="starts_on"> <input type="date" name="ends_on"> <span class="description">vuoti = le date di prima, spostate di settimane intere (stessi giorni della settimana)</span></td></tr>', Html::allowed() );
		} else {
			$last = null;
			foreach ( Plugin::activities()->sessions( $id ) as $s ) {
				if ( empty( $s['cancelled_at'] ) ) {
					$last = $last ?: $s; // la prima data (per i ricorrenti si sposta tutto da lì)
					if ( ActivityKind::EVENT === $kind ) {
						$last = $s;
					}
				}
			}
			$suggest = $last ? \AssociazioneSemplice\ActivityCopy::suggest_date( (string) $last['session_date'], current_time( 'Y-m-d' ) ) : current_time( 'Y-m-d' );
			echo '<tr><th>' . ( ActivityKind::EVENT === $kind ? 'Nuova data' : 'Nuova prima data' ) . '</th><td><input type="date" name="date" value="' . esc_attr( $suggest ) . '" required>'
				. ( ActivityKind::RECURRING === $kind ? ' <span class="description">le altre date si spostano dello stesso numero di giorni</span>' : '' ) . '</td></tr>';
			if ( ActivityKind::EVENT === $kind ) {
				echo '<tr><th>Orario</th><td>dalle <input type="time" name="start_time" value="' . esc_attr( (string) ( $last['start_time'] ?? '' ) ) . '"> alle <input type="time" name="end_time" value="' . esc_attr( (string) ( $last['end_time'] ?? '' ) ) . '"></td></tr>'
					. '<tr><th>Luogo e posti</th><td><input type="text" name="location" class="regular-text" value="' . esc_attr( (string) ( $last['location'] ?? '' ) ) . '"> posti <input type="number" name="capacity" min="1" class="small-text" value="' . esc_attr( (string) ( $last['capacity'] ?? '' ) ) . '"></td></tr>';
			}
		}
		echo '</table><p><button class="button button-primary">Riproponi</button></p>';
		Ui::form_close();
		echo '</div>';
	}

	/** Eliminazione completa dell'evento (solo amministratori). */
	private static function delete_card( array $activity ): void {
		if ( ! current_user_can( Plugin::CAP ) ) {
			return;
		}
		echo '<div class="asem-card"><h2>Elimina l\'evento</h2><p class="description">Cancella l\'evento con tutte le sue date, prenotazioni e iscrizioni, e decide cosa fare delle somme incassate (restituirle o annullarle). Con doppia conferma.</p>'
			. '<p><a class="button" style="color:#b32d2e;border-color:#b32d2e" href="' . esc_url( Ui::url( 'asem-activity-delete', array( 'id' => (int) $activity['id'] ) ) ) . '">Elimina l\'evento…</a></p></div>';
	}

	/** Scelte della segreteria in prenotazione: listino, ospite dell'associazione o del volontario, pacchetto già pagato. */
	public static function booking_fields( array $activity, int $person_id ): string {
		if ( ! ActivityKind::uses_sessions( $activity['kind'] ) ) {
			return '';
		}
		$lists = PriceLists::for_activity( $activity );
		$html  = '';
		if ( $lists ) {
			$html .= '<select name="price_list" title="Listino"><option value="">Contributo base</option>';
			foreach ( $lists as $l ) {
				$html .= '<option value="' . esc_attr( $l['key'] ) . '">' . esc_html( $l['label'] . ' — ' . Money::format( $l['cents'] ) ) . '</option>';
			}
			$html .= '</select> ';
			$pk = $person_id ? Plugin::activities()->open_packages( $person_id, (int) $activity['id'] ) : array();
			if ( $pk ) {
				$html .= '<label><input type="checkbox" name="use_package" value="1"> usa ' . esc_html( $pk[0]['label'] ) . ' (' . (int) $pk[0]['left'] . ' rimasti)</label> ';
			} elseif ( ! $person_id ) {
				$html .= '<label><input type="checkbox" name="use_package" value="1"> dal pacchetto già pagato</label> ';
			}
		}
		if ( ! \AssociazioneSemplice\Edition::has( 'funds' ) ) {
			return $html; // gli ospiti dell'associazione e del volontario si calcolano sulla quota del volontario (funzione avanzata)
		}
		$html .= '<select name="guest_of" title="Ospite">';
		foreach ( PriceLists::guest_kinds() as $k => $label ) {
			$html .= '<option value="' . esc_attr( $k ) . '">' . esc_html( $label ) . '</option>';
		}
		return $html . '</select>';
	}

	/** Righe del modulo con i listini dell'attività (nome, prezzo, ingressi). */
	private static function price_list_row( array $activity ): string {
		if ( ActivityKind::COURSE === $activity['kind'] ) {
			return self::course_variants_row( $activity );
		}
		if ( ! ActivityKind::uses_sessions( $activity['kind'] ) ) {
			return '';
		}
		$lists = PriceLists::for_activity( $activity );
		$lists = array_pad( $lists, max( 4, count( $lists ) + 1 ), array( 'label' => '', 'cents' => 0, 'entries' => 1 ) );
		$rows  = '<div class="asem-pl-row asem-pl-head"><span>Nome</span><span>Prezzo</span><span>Ingressi</span></div>';
		foreach ( array_slice( $lists, 0, PriceLists::MAX ) as $i => $l ) {
			$rows .= '<div class="asem-pl-row"><input type="hidden" name="pl_key[' . (int) $i . ']" value="' . esc_attr( (string) ( $l['key'] ?? '' ) ) . '">'
				. '<input type="text" class="asem-pl-name" name="pl_label[' . (int) $i . ']" value="' . esc_attr( $l['label'] ) . '" placeholder="es. Individuale" maxlength="60" aria-label="Nome del listino">'
				. '<span class="asem-pl-price"><input type="text" name="pl_price[' . (int) $i . ']" size="7" value="' . esc_attr( '' === $l['label'] ? '' : Money::plain( (int) $l['cents'] ) ) . '" aria-label="Prezzo in euro"> €</span>'
				. '<input type="number" name="pl_entries[' . (int) $i . ']" min="1" max="50" value="' . (int) $l['entries'] . '" aria-label="Ingressi"></div>';
		}
		return '<tr><th>Listini</th><td><div class="asem-pl">' . $rows . '</div>'
			. '<p class="description">Facoltativi: ad esempio Individuale, Di gruppo, Pacchetto 4 individuale, Pacchetto 4 di gruppo. In prenotazione la segreteria sceglie il listino; con 4 ingressi il prezzo del pacchetto si paga una volta e le altre date si prenotano «dal pacchetto» senza altro da pagare. Righe vuote ignorate; senza listini vale il contributo sopra.</p></td></tr>';
	}

	/** Il menu delle varianti del contributo di un corso (la quota base è la prima voce). */
	private static function variant_select( array $activity, string $selected ): string {
		$html = '<select name="price_list"><option value="">Quota base — ' . esc_html( Money::format( (int) $activity['fee_cents'] ) ) . '</option>';
		foreach ( PriceLists::for_activity( $activity ) as $l ) {
			$html .= '<option value="' . esc_attr( $l['key'] ) . '"' . selected( $selected, $l['key'], false ) . '>' . esc_html( $l['label'] . ' — ' . Money::format( (int) $l['cents'] ) ) . '</option>';
		}
		return $html . '</select>';
	}

	/** Variante e nota di un'iscrizione, in breve, per l'elenco degli iscritti. */
	private static function variant_text( array $activity, array $enrollment ): string {
		$out = '';
		if ( ! empty( $enrollment['price_list'] ) ) {
			$l = PriceLists::find( $activity, (string) $enrollment['price_list'] );
			if ( $l ) {
				$out .= ' <span class="description">· ' . esc_html( $l['label'] ) . '</span>';
			}
		}
		if ( ! empty( $enrollment['fee_note'] ) ) {
			$out .= ' <span class="description">(' . esc_html( (string) $enrollment['fee_note'] ) . ')</span>';
		}
		return $out;
	}

	/** Corsi: varianti del contributo (nome libero e prezzo per ogni periodo di pagamento) che la segreteria assegna all'iscrizione. */
	private static function course_variants_row( array $activity ): string {
		$lists = PriceLists::for_activity( $activity );
		$lists = array_pad( $lists, max( 3, count( $lists ) + 1 ), array( 'label' => '', 'cents' => 0, 'entries' => 1 ) );
		$rows  = '<div class="asem-pl-row asem-pl-head"><span>Variante</span><span>Prezzo</span></div>';
		foreach ( array_slice( $lists, 0, PriceLists::MAX ) as $i => $l ) {
			$rows .= '<div class="asem-pl-row"><input type="hidden" name="pl_key[' . (int) $i . ']" value="' . esc_attr( (string) ( $l['key'] ?? '' ) ) . '"><input type="hidden" name="pl_entries[' . (int) $i . ']" value="1">'
				. '<input type="text" class="asem-pl-name" name="pl_label[' . (int) $i . ']" value="' . esc_attr( $l['label'] ) . '" placeholder="es. Secondo corso -10%, Famiglia, Solo il sabato" maxlength="60" aria-label="Nome della variante">'
				. '<span class="asem-pl-price"><input type="text" name="pl_price[' . (int) $i . ']" size="7" value="' . esc_attr( '' === $l['label'] ? '' : Money::plain( (int) $l['cents'] ) ) . '" aria-label="Prezzo in euro"> €</span></div>';
		}
		return '<tr><th>Varianti del contributo</th><td><div class="asem-pl">' . $rows . '</div>'
			. '<p class="description">Facoltative e descritte come vuoi: sconto per più corsi, attività parziale, più familiari iscritti. Il prezzo è quello di ogni periodo di pagamento del corso. Le assegna la segreteria all\'iscrizione di una persona; chi si iscrive da solo paga il contributo sopra.</p></td></tr>';
	}

	private static function edit_card( array $activity, string $back ): void {
		$volunteers = Plugin::people()->search( array( 'type' => MemberType::VOLUNTEER ) );
		echo '<div class="asem-card"><h2>Dati dell\'attività</h2>';
		Ui::form_open( 'asem_save_activity', $back );
		echo wp_kses( Ui::hidden( 'id', $activity['id'] ) . Ui::hidden( 'social_year', $activity['social_year'] ), Html::allowed() );
		echo '<table class="form-table"><tbody><tr><th>Nome</th><td><input type="text" name="name" value="' . esc_attr( $activity['name'] ) . '" class="regular-text" required></td></tr>';
		echo '<tr><th>Contributo soci' . ( ActivityKind::COURSE === $activity['kind'] ? '' : ' (' . esc_html( ActivityKind::fee_unit( $activity['kind'] ) ) . ')' ) . '</th><td><input type="text" name="fee" value="' . esc_attr( Money::plain( (int) $activity['fee_cents'] ) ) . '"> €</td></tr>';
		$guest = null === $activity['guest_fee_cents'] ? '' : Money::plain( (int) $activity['guest_fee_cents'] );
		echo '<tr><th>Contributo ospiti</th><td><input type="text" name="guest_fee" value="' . esc_attr( $guest ) . '" placeholder="uguale ai soci"> €<p class="description">Vuoto = come i soci · 0 = gratuito. Vale per le nuove prenotazioni/mensilità: quelle già fatte tengono l\'importo di allora (eventi) o seguono la nuova quota (corsi).</p></td></tr>';
		echo wp_kses( self::price_list_row( $activity ), Html::allowed() );
		if ( ActivityKind::uses_sessions( $activity['kind'] ) ) {
			echo wp_kses( self::cancel_rows( $activity, '' ), Html::allowed() );
			echo wp_kses( self::qr_row( $activity, '' ), Html::allowed() );
			echo wp_kses( self::hold_row( $activity, '' ), Html::allowed() );
		} else {
			echo wp_kses( self::weekday_row( $activity, '' ), Html::allowed() );
		}
		echo wp_kses( Ui::vat_row( \AssociazioneSemplice\Fiscal::activity_rate( $activity ), \AssociazioneSemplice\Fiscal::INCLUDED, 'I contributi' ), Html::allowed() );
		echo wp_kses( '<tr><th>Referente</th><td>' . Ui::person_select( 'instructor_person_id', $volunteers, $activity['instructor_person_id'], '— nessuno —', 'asem-instructor' ) . '</td></tr>', Html::allowed() );
		echo wp_kses( implode( '', (array) apply_filters( 'asem_activity_form_rows', array(), (int) $activity['id'] ) ), Html::allowed() );
		echo wp_kses( self::fund_row( $activity ), Html::allowed() );
		echo wp_kses( self::sport_row( $activity ), Html::allowed() );
		echo '</tbody></table>';
		submit_button( 'Salva', 'secondary' );
		Ui::form_close();
		echo '</div>';
	}

	// ---------- Corsi ----------

	private static function course_left( array $activity, SocialYear $year, string $back ): void {
		$id       = (int) $activity['id'];
		$statuses = Plugin::activities()->status_for_activity( $id );
		$due      = array_sum( array_map( function ( $s ) {
			return $s['summary']['total_due'];
		}, $statuses ) );
		$paid     = array_sum( array_map( function ( $s ) {
			return $s['summary']['total_paid'];
		}, $statuses ) );
		$to_collect = array_sum( array_map( function ( $s ) {
			return max( 0, -$s['summary']['balance'] );
		}, $statuses ) );
		$default_month = $year->clamp( substr( current_time( 'Y-m-d' ), 0, 7 ) );

		echo wp_kses( '<div class="asem-card"><h2>Riepilogo · anno sociale ' . esc_html( $activity['social_year'] ) . '</h2><table class="asem-kv">'
			. '<tr><td>Dovuto finora</td><td>' . Ui::money( $due ) . '</td></tr><tr><td>Incassato</td><td>' . Ui::money( $paid ) . '</td></tr>'
			. '<tr><td><strong>Ancora da incassare</strong></td><td><strong>' . Ui::money( $to_collect ) . '</strong></td></tr></table></div>', Html::allowed() );

		$enrolled_ids = array();
		foreach ( $statuses as $s ) {
			if ( null === $s['enrollment']['end_month'] ) {
				$enrolled_ids[] = (int) $s['enrollment']['person_id'];
			}
		}
		$candidates = array_values( array_filter( Plugin::people()->search(), function ( $p ) use ( $enrolled_ids ) {
			return ! in_array( (int) $p['id'], $enrolled_ids, true );
		} ) );
		echo '<div class="asem-card"><h2>Iscrivi un socio o un ospite</h2>' . ( 'once' === $activity['billing']
			? '<p class="description">Corso a <strong>pagamento unico</strong>: la quota è dovuta per intero all\'iscrizione (si può versare anche a rate).</p>'
			: ( 'quarterly' === $activity['billing'] ? '<p class="description">L\'iscrizione si <strong>rinnova da sola ogni trimestre</strong> (tre mesi dal primo mese dovuto) finché non viene cancellata.</p>' : '<p class="description">L\'iscrizione si <strong>rinnova da sola ogni mese</strong> finché non viene cancellata' . ( $activity['ends_on'] ? ' (o fino alla fine del corso)' : '' ) . '. Ogni mensilità si paga alla prima lezione del mese' . ( (int) $activity['lesson_weekday'] ? '' : ' (indica il giorno della lezione nei dati del corso per saperlo con precisione)' ) . '.</p>' ) );
		Ui::form_open( 'asem_enroll', $back );
		echo wp_kses( Ui::hidden( 'activity_id', $id ), Html::allowed() );
		echo wp_kses( '<p>' . Ui::person_select( 'person_id', $candidates, null, '— scegli —', 'asem-enroll-person' ) . '</p>', Html::allowed() );
		echo wp_kses( '<p>Primo mese dovuto: <select name="start_month">' . Ui::month_options( $year->months(), $default_month ) . '</select></p>', Html::allowed() );
		if ( PriceLists::for_activity( $activity ) ) {
			echo wp_kses( '<p>Contributo: ' . self::variant_select( $activity, '' ) . ' <input type="text" name="fee_note" placeholder="Nota (facoltativa)" size="28" maxlength="190"></p>', Html::allowed() );
		}
		echo '<p>';
		submit_button( 'Iscrivi e vai alla cassa', 'primary', 'go_cash', false );
		echo ' ';
		submit_button( 'Salva e prossimo inserimento', 'secondary', 'next', false );
		echo '</p><p class="description">«Vai alla cassa» apre l\'incasso già compilato se c\'è qualcosa da pagare; «prossimo inserimento» resta qui, pronto per iscrivere la persona dopo.</p>';
		Ui::form_close();
		echo '</div>';
	}

	private static function course_enrolled( array $activity, SocialYear $year, string $back ): void {
		$id            = (int) $activity['id'];
		$statuses      = Plugin::activities()->status_for_activity( $id );
		$default_month = $year->clamp( substr( current_time( 'Y-m-d' ), 0, 7 ) );
		echo '<h2>Iscritti e pagamenti</h2>';
		if ( ! $statuses ) {
			echo '<p>Nessun iscritto.</p>';
		}
		$ended = \AssociazioneSemplice\ActivityReset::ended_enrollments( $id );
		if ( $ended && current_user_can( Plugin::CAP ) ) { // chi non ha confermato: si toglie dall'elenco in un colpo solo
			Ui::form_open( 'asem_purge_enrollments', $back, false, 'asem-inline' );
			echo wp_kses( Ui::hidden( 'activity_id', $id )
				. '<div class="notice notice-warning inline"><p><strong>' . count( $ended ) . ( 1 === count( $ended ) ? ' iscritto con l\'iscrizione già finita' : ' iscritti con l\'iscrizione già finita' ) . '</strong> (non confermati o disdetti). '
				. '<label><input type="checkbox" name="confirm" value="1"> Togli dall\'elenco chi non ha incassi registrati</label> <button class="button">Togli dall\'elenco</button></p>'
				. '<p class="description">Si tolgono soltanto gli iscritti senza incassi: la prima nota non cambia. Chi ha incassi resta e si cancella uno per uno con «Cancella», scegliendo cosa fare delle somme.</p></div>', Html::allowed() );
			Ui::form_close();
		}
		foreach ( $statuses as $s ) {
			$e      = $s['enrollment'];
			$active = null === $e['end_month'];
			$label  = trim( ( $e['card_number'] ? 'n.' . $e['card_number'] . ' · ' : '' ) . $e['first_name'] . ' ' . $e['last_name'] );
			echo wp_kses( '<details class="asem-detail"><summary><a href="' . esc_url( Ui::url( 'asem-person', array( 'id' => $e['person_id'] ) ) ) . '"><strong>' . esc_html( $label ) . '</strong></a> '
				. '<em>(' . esc_html( MemberType::label( $e['type'] ) ) . ')</em> — ' . Ui::pay_status( $s['summary'] )
				. ( $active ? '' : ' <span class="asem-warn">· cancellato dopo ' . esc_html( Ui::month( $e['end_month'] ) ) . '</span>' )
				. ( self::variant_text( $activity, $e ) )
				. ( (int) $s['summary']['balance'] < 0 ? ' <a class="button button-small button-primary" href="' . esc_url( Ui::url( 'asem-income', array( 'person_id' => (int) $e['person_id'], 'due' => 1 ) ) ) . '">Paga</a>' : '' )
				. ( current_user_can( Plugin::CAP ) ? ' ' . self::cancel_control( $id, array( 'id' => (int) $e['person_id'], 'first_name' => (string) $e['first_name'], 'last_name' => (string) $e['last_name'] ), 0, 'Cancella' ) : '' )
				. '</summary>', Html::allowed() );
			echo wp_kses( Ui::months_table( $s['summary'] ), Html::allowed() );
			if ( PriceLists::for_activity( $activity ) ) { // varianti del contributo: le assegna la segreteria
				Ui::form_open( 'asem_enroll_variant', $back, false, 'asem-inline' );
				echo wp_kses( Ui::hidden( 'activity_id', $id ) . Ui::hidden( 'person_id', $e['person_id'] ) . '<p>Contributo: ' . self::variant_select( $activity, (string) ( $e['price_list'] ?? '' ) )
					. ' <input type="text" name="fee_note" value="' . esc_attr( (string) ( $e['fee_note'] ?? '' ) ) . '" placeholder="Nota (es. sorella iscritta a Danza)" size="28" maxlength="190"> <button class="button">Salva</button></p>', Html::allowed() );
				Ui::form_close();
			}
			Ui::form_open( $active ? 'asem_cancel_enrollment' : 'asem_enroll', $back );
			echo wp_kses( Ui::hidden( 'activity_id', $id ) . Ui::hidden( 'person_id', $e['person_id'] ), Html::allowed() );
			if ( $active ) {
				echo wp_kses( '<p>Ultimo mese dovuto: <select name="last_month">' . Ui::month_options( $year->months(), $default_month ) . '</select> ', Html::allowed() );
				echo '<button class="button">Disdici il rinnovo</button></p><p class="description">L\'iscrizione smette di rinnovarsi: i mesi dopo quello indicato non saranno più dovuti. I pagamenti già fatti restano registrati.</p>';
			} else {
				echo wp_kses( '<p>Riattiva dal mese: <select name="start_month">' . Ui::month_options( $year->months(), $default_month ) . '</select> <button class="button">Riattiva iscrizione</button></p>', Html::allowed() );
			}
			Ui::form_close();
			echo '</details>';
		}
	}

	// ---------- Eventi (una tantum e ricorrenti) ----------

	private static function sessions_left( array $activity, string $back ): void {
		$id       = (int) $activity['id'];
		$sessions = Plugin::activities()->sessions( $id );
		$due      = 0;
		$paid     = 0;
		foreach ( $sessions as $s ) {
			if ( ! empty( $s['cancelled_at'] ) ) {
				continue;
			}
			foreach ( Plugin::activities()->bookings_for_session( (int) $s['id'] ) as $b ) {
				if ( $b['active'] ) {
					$due  += (int) $b['fee_due_cents'];
					$paid += min( $b['paid'], (int) $b['fee_due_cents'] );
				}
			}
		}
		echo wp_kses( '<div class="asem-card"><h2>Riepilogo</h2><table class="asem-kv">'
			. '<tr><td>Date</td><td>' . count( $sessions ) . '</td></tr><tr><td>Partecipanti (persone distinte)</td><td>' . (int) Plugin::activities()->active_participants( $id ) . '</td></tr>'
			. '<tr><td>Contributi dovuti dalle prenotazioni</td><td>' . Ui::money( $due ) . '</td></tr><tr><td>Incassati</td><td>' . Ui::money( $paid ) . '</td></tr>'
			. '<tr><td><strong>Ancora da incassare</strong></td><td><strong>' . Ui::money( $due - $paid ) . '</strong></td></tr></table></div>', Html::allowed() );

		self::staff_card( $activity, $back );

		if ( ActivityKind::RECURRING === $activity['kind'] ) {
			echo '<div class="asem-card"><h2>Aggiungi date</h2>';
			Ui::form_open( 'asem_add_dates', $back );
			echo wp_kses( Ui::hidden( 'activity_id', $id ) . self::when_builder(), Html::allowed() );
			echo '<p>Luogo <input type="text" name="location"> posti <input type="number" min="1" name="capacity" class="small-text"> <span class="description">(per le date che aggiungi; posti vuoti = nessun limite)</span></p>'
				. '<p><button class="button button-primary">Aggiungi le date</button> <span class="description">Le date già presenti non vengono duplicate (massimo 400 alla volta).</span></p>';
			Ui::form_close();
			echo '</div>';
		}
	}

	/** Prenotazione sul posto: chi si presenta senza aver prenotato. Prenota, incassa il contributo e registra l'ingresso in un colpo solo. */
	private static function walk_in_form( array $activity, array $s, array $candidates, string $back ): void {
		$ledger   = Plugin::ledger();
		$accounts = array();
		foreach ( $ledger->balances() as $a ) {
			$accounts[ (int) $a['id'] ] = $a['name'];
		}
		$default = $ledger->default_account_for( 'cash' );
		$members = array_values( array_filter( Plugin::people()->search(), function ( $p ) {
			return MemberType::is_member( $p['type'] );
		} ) );
		$sid   = (int) $s['id'];
		$fee   = (int) $activity['fee_cents'];
		$guest = null === $activity['guest_fee_cents'] || '' === $activity['guest_fee_cents'] ? $fee : (int) $activity['guest_fee_cents'];
		echo '<details class="asem-detail" style="margin-top:8px"><summary><strong>Ingresso senza prenotazione (sul posto)</strong></summary>';
		$types     = array();
		foreach ( $ledger->accounts() as $acc ) {
			$types[ (int) $acc['id'] ] = $acc['type'];
		}
		$guest_ids = array();
		foreach ( $candidates as $c ) {
			if ( MemberType::GUEST === $c['type'] ) {
				$guest_ids[] = (int) $c['id'];
			}
		}
		Ui::form_open( 'asem_walk_in', $back );
		echo wp_kses( Ui::hidden( 'activity_id', $activity['id'] ) . Ui::hidden( 'session_id', $sid ), Html::allowed() );
		echo '<div data-asem-change data-fee="' . (int) $fee . '" data-guest-fee="' . (int) $guest . '" data-guest-ids="' . esc_attr( wp_json_encode( $guest_ids ) ) . '" data-types="' . esc_attr( wp_json_encode( $types ) ) . '">';
		echo wp_kses( '<p>Persona già in anagrafica: ' . Ui::person_select( 'person_id', $candidates, null, '— scegli socio o ospite —', 'asem-walk-' . $sid ) . '</p>'
			. '<p><strong>oppure</strong> nuovo ospite: nome <input type="text" name="new_first_name"> cognome <input type="text" name="new_last_name"> cellulare <input type="text" name="new_phone" placeholder="333 1234567"> del socio '
			. Ui::person_select( 'host_person_id', $members, null, '— socio che lo ospita —', 'asem-walkhost-' . $sid ) . '</p>'
			. '<p><label><input type="checkbox" name="pay" value="1" checked> Incassa ora il contributo</label> (soci ' . esc_html( Money::format( $fee ) ) . ', ospiti ' . esc_html( Money::format( $guest ) ) . ') — '
			. 'sul conto '
			. '<select name="account_id">' . Ui::options( $accounts, $default ? (int) $default['id'] : null ) . '</select></p>'
			. '<div class="asem-cashbox" style="display:none"><p>Contanti ricevuti <input type="text" class="asem-tendered" inputmode="decimal" placeholder="importo esatto" size="8"> € <span class="asem-quick"></span></p><p class="asem-change-out"></p></div></div>'
			. '<p><label><input type="checkbox" name="checkin" value="1" checked> Registra subito l\'ingresso</label> <button class="button button-primary">Prenota sul posto</button></p>'
			. '<p class="description">Il nuovo ospite viene creato (il cellulare è obbligatorio: serve a riconoscerlo) e collegato al socio che lo ospita. L\'incasso entra in prima nota, sul conto scelto, con la data di oggi.</p>', Html::allowed() );
		Ui::form_close();
		echo '</details>';
	}

	/** Cella "Ingresso" di una prenotazione: ora di ingresso e pulsante per registrarlo o annullarlo. */
	private static function checkin_cell( array $b, int $session_id, int $activity_id, string $back, bool $session_cancelled ): void {
		if ( ! $b['active'] || $session_cancelled ) {
			echo '—';
			return;
		}
		$in = ! empty( $b['checked_in_at'] );
		echo $in ? '<strong class="asem-ok">✔ ' . esc_html( mysql2date( 'H:i', $b['checked_in_at'] ) ) . '</strong> ' : '';
		Ui::form_open( 'asem_checkin', $back, false, 'asem-inline' );
		echo wp_kses( Ui::hidden( 'activity_id', $activity_id ) . Ui::hidden( 'session_id', $session_id ) . Ui::hidden( 'person_id', $b['person_id'] ) . ( $in ? Ui::hidden( 'undo', 1 ) : '' )
			. '<button class="button button-small">' . ( $in ? 'Annulla' : 'Registra ingresso' ) . '</button>', Html::allowed() );
		Ui::form_close();
	}

	/** Avvisi agli iscritti: modulo di invio e ultimi avvisi (i volontari li inviano dall'area riservata). */
	private static function notices_card( array $activity, string $back ): void {
		$id    = (int) $activity['id'];
		$count = count( \AssociazioneSemplice\Notices::recipients( $id ) );
		echo '<div class="asem-card"><h2>Avvisi agli iscritti</h2><p class="description">Arrivano per email a chi è iscritto (ora ' . (int) $count . ' persone) e restano nella bacheca dell\'area riservata. '
			. 'Il referente e i gestori dell\'evento li inviano dalla propria area riservata.</p>';
		Ui::form_open( 'asem_send_notice', $back );
		echo wp_kses( Ui::hidden( 'activity_id', $id )
			. '<p><input type="text" name="subject" class="large-text" maxlength="' . \AssociazioneSemplice\Notices::MAX_SUBJECT . '" placeholder="Titolo" required></p>'
			. '<p><textarea name="body" class="large-text" rows="3" maxlength="' . \AssociazioneSemplice\Notices::MAX_BODY . '" placeholder="Messaggio" required></textarea></p>'
			. '<p><button class="button" data-confirm="Inviare l\'avviso a tutti gli iscritti?">Invia avviso</button></p>', Html::allowed() );
		Ui::form_close();
		$recent = \AssociazioneSemplice\Notices::recent( $id, 5 );
		if ( $recent ) {
			echo '<table class="widefat striped"><thead><tr><th>Quando</th><th>Titolo</th><th>Da</th><th>Arrivato a</th></tr></thead><tbody>';
			foreach ( $recent as $n ) {
				echo '<tr><td>' . esc_html( mysql2date( 'd/m/Y H:i', $n['created_at'] ) ) . '</td><td>' . esc_html( $n['subject'] ) . '</td><td>' . esc_html( $n['author_name'] ) . '</td><td>' . (int) $n['emailed'] . ' / ' . (int) $n['recipients'] . '</td></tr>';
			}
			echo '</tbody></table>';
		}
		echo '</div>';
	}

	/** Soci abilitati a gestire l'evento (lista prenotati e registrazione ingressi dall'area riservata), oltre al referente. */
	private static function staff_card( array $activity, string $back ): void {
		$id    = (int) $activity['id'];
		$svc   = Plugin::activities();
		$staff = $svc->staff( $id );
		$in    = array();
		echo '<div class="asem-card"><h2>Staff dell\'evento (ingressi e cassa)</h2>';
		echo '<p class="description">Vedono i prenotati e registrano gli ingressi (anche scansionando il QR) dall\'area riservata, solo per questo evento. Se abiliti l\'incasso, possono anche far pagare il biglietto sul posto a chi si presenta senza aver prenotato (se c\'è posto) e registrare subito l\'ingresso. Il referente e gli amministratori possono fare tutto questo già di default.</p>';
		if ( $staff ) {
			echo '<ul>';
			foreach ( $staff as $m ) {
				$in[] = (int) $m['person_id'];
				echo '<li>' . esc_html( $m['first_name'] . ' ' . $m['last_name'] ) . ' <span class="description">' . esc_html( MemberType::label( $m['type'] ) ) . '</span> ';
				Ui::form_open( 'asem_event_staff_cash', $back, false, 'asem-inline' );
				echo wp_kses( Ui::hidden( 'activity_id', $id ) . Ui::hidden( 'person_id', $m['person_id'] ) . '<label><input type="checkbox" name="can_cash" value="1"' . checked( ! empty( $m['can_cash'] ), true, false ) . ' data-asem-autosubmit> può incassare sul posto</label>', Html::allowed() );
				Ui::form_close();
				echo ' ';
				Ui::form_open( 'asem_event_staff_remove', $back, false, 'asem-inline' );
				echo wp_kses( Ui::hidden( 'activity_id', $id ) . Ui::hidden( 'person_id', $m['person_id'] ) . '<button class="button-link" data-confirm="Togliere questo gestore?">togli</button>', Html::allowed() );
				Ui::form_close();
				echo '</li>';
			}
			echo '</ul>';
		}
		$candidates = array_values( array_filter( Plugin::people()->search(), function ( $p ) use ( $in, $activity ) {
			return MemberType::is_member( $p['type'] ) && ! in_array( (int) $p['id'], $in, true ) && (int) $p['id'] !== (int) $activity['instructor_person_id'];
		} ) );
		Ui::form_open( 'asem_event_staff_add', $back );
		echo wp_kses( Ui::hidden( 'activity_id', $id ) . Ui::person_select( 'person_id', $candidates, null, '— scegli un socio —', 'asem-staff-' . $id ) . ' <label><input type="checkbox" name="can_cash" value="1"> può incassare sul posto</label> <button class="button">Aggiungi</button>', Html::allowed() );
		Ui::form_close();
		echo '</div>';
	}

	private static function sessions_list( array $activity, string $back ): void {
		$id       = (int) $activity['id'];
		$sessions = Plugin::activities()->sessions( $id );
		$all      = Plugin::people()->search();
		$gov      = Plugin::people()->guest_overview();
		echo '<h2>' . ( ActivityKind::EVENT === $activity['kind'] ? 'Data e prenotazioni' : 'Date e prenotazioni' ) . '</h2>';
		if ( ! $sessions ) {
			echo '<p>Nessuna data. ' . ( ActivityKind::RECURRING === $activity['kind'] ? 'Aggiungine qui sopra.' : '' ) . '</p>';
		}
		foreach ( $sessions as $s ) {
			$cancelled = ! empty( $s['cancelled_at'] );
			$bookings  = Plugin::activities()->bookings_for_session( (int) $s['id'] );
			$booked    = array();
			foreach ( $bookings as $b ) {
				if ( $b['active'] ) {
					$booked[] = (int) $b['person_id'];
				}
			}
			$cap   = null === $s['capacity'] ? null : (int) $s['capacity'];
			$title = Ui::date( $s['session_date'] ) . ( $s['start_time'] ? ' · ore ' . esc_html( $s['start_time'] ) : '' ) . ( $s['location'] ? ' · ' . esc_html( $s['location'] ) : '' );
			echo wp_kses( '<details class="asem-detail"' . ( ActivityKind::EVENT === $activity['kind'] ? ' open' : '' ) . '><summary><strong>' . $title . '</strong> — '
				. count( $booked ) . ( null === $cap ? ' prenotati' : ' / ' . $cap . ' posti (' . max( 0, $cap - count( $booked ) ) . ' liberi)' ) . ( $cancelled ? ' <span class="asem-neg">· ANNULLATA</span>' : '' ) . '</summary>', Html::allowed() );

			$queue = \AssociazioneSemplice\Waitlist::waiting( (int) $s['id'] );
			if ( $queue ) {
				echo '<p><strong>Lista d\'attesa (' . count( $queue ) . ')</strong> <span class="description">entrano da soli, nell\'ordine, se si libera un posto.</span></p><ol>';
				foreach ( $queue as $w ) {
					echo '<li>' . esc_html( trim( $w['first_name'] . ' ' . $w['last_name'] ) ) . ' <span class="description">dal ' . esc_html( mysql2date( 'd/m H:i', $w['since'] ) ) . '</span> ';
					Ui::form_open( 'asem_waitlist_remove', $back, false, 'asem-inline' );
					echo wp_kses( Ui::hidden( 'session_id', $s['id'] ) . Ui::hidden( 'person_id', $w['person_id'] ) . Ui::hidden( 'activity_id', $id ) . '<button class="button-link">togli</button>', Html::allowed() );
					Ui::form_close();
					echo '</li>';
				}
				echo '</ol>';
			}
			if ( $bookings ) {
				echo '<table class="widefat striped"><thead><tr><th>Persona</th><th>Tipo</th><th>Contributo</th><th>Stato</th><th>Ingresso</th><th></th></tr></thead><tbody>';
				foreach ( $bookings as $b ) {
					$label = trim( ( $b['card_number'] ? 'n.' . $b['card_number'] . ' · ' : '' ) . $b['first_name'] . ' ' . $b['last_name'] );
					echo wp_kses( '<tr><td><a href="' . esc_url( Ui::url( 'asem-person', array( 'id' => $b['person_id'] ) ) ) . '">' . esc_html( $label ) . '</a></td><td>' . esc_html( MemberType::label( $b['type'] ) ) . ( MemberType::GUEST === $b['type'] && $b['active'] ? '<br>' . PeoplePage::guest_badge( $gov[ (int) $b['person_id'] ] ?? null ) : '' ) . '</td>'
						. '<td>' . esc_html( Money::format( (int) $b['fee_due_cents'] ) ) . '</td><td>' . ( $b['active'] ? Ui::booking_state( $b ) : ( 'transferred' === $b['status'] ? '<span class="asem-warn">trasferita ad altra persona' : '<span class="asem-warn">prenotazione annullata' ) . ( $b['paid'] > 0 ? ' · versati ' . esc_html( Money::format( $b['paid'] ) ) . ' da rimborsare' : '' ) . '</span>' ) . '</td><td>', Html::allowed() );
					self::checkin_cell( $b, (int) $s['id'], $id, $back, $cancelled );
					echo '</td><td>';
					if ( $b['active'] ) {
						Ui::form_open( 'asem_cancel_booking', $back, false, 'asem-confirm' );
						echo wp_kses( Ui::hidden( 'activity_id', $id ) . Ui::hidden( 'session_id', $s['id'] ) . Ui::hidden( 'person_id', $b['person_id'] ), Html::allowed() );
						echo '<button class="button button-small" data-confirm="Annullare la prenotazione? Gli eventuali pagamenti restano registrati.">Annulla prenotazione</button>';
						Ui::form_close();
						$others = array_values( array_filter( $all, function ( $x ) use ( $b, $booked ) {
							return (int) $x['id'] !== (int) $b['person_id'] && ! in_array( (int) $x['id'], $booked, true );
						} ) );
						echo '<details style="margin-top:6px"><summary>Cambia nominativo</summary>';
						Ui::form_open( 'asem_transfer_booking', $back );
						echo wp_kses( Ui::hidden( 'activity_id', $id ) . Ui::hidden( 'session_id', $s['id'] ) . Ui::hidden( 'person_id', $b['person_id'] ), Html::allowed() );
						echo wp_kses( Ui::person_select( 'to_person_id', $others, null, '— scegli la nuova persona —', 'asem-tr-' . (int) $s['id'] . '-' . (int) $b['person_id'] ) . ' <button class="button button-small">Cambia</button>', Html::allowed() );
						echo '<p class="description">Il pagamento già fatto passa alla nuova persona; se il suo contributo è maggiore (es. ospite) resta da pagare la differenza.</p>';
						Ui::form_close();
						echo '</details>';
					}
					echo '</td></tr>';
				}
				echo '</tbody></table>';
			}

			if ( ! $cancelled ) {
				$candidates = array_values( array_filter( $all, function ( $p ) use ( $booked ) {
					return ! in_array( (int) $p['id'], $booked, true );
				} ) );
				Ui::form_open( 'asem_book', $back );
				echo wp_kses( Ui::hidden( 'activity_id', $id ) . Ui::hidden( 'session_id', $s['id'] ), Html::allowed() );
				echo wp_kses( '<p>Prenota: ' . Ui::person_select( 'person_id', $candidates, null, '— scegli socio o ospite —', 'asem-book-' . (int) $s['id'] ) . ' ' . self::booking_fields( $activity, 0 ) . ' <button class="button button-primary">Prenota</button></p>', Html::allowed() );
				Ui::form_close();
				if ( \AssociazioneSemplice\Edition::has( 'door_sales' ) ) {
					self::walk_in_form( $activity, $s, $candidates, $back );
				}

				Ui::form_open( 'asem_update_session', $back );
				echo wp_kses( Ui::hidden( 'activity_id', $id ) . Ui::hidden( 'session_id', $s['id'] ), Html::allowed() );
				echo '<p class="description">Modifica la data: <input type="date" name="session_date" value="' . esc_attr( $s['session_date'] ) . '"> ore <input type="time" name="start_time" value="' . esc_attr( (string) $s['start_time'] ) . '"> '
					. 'luogo <input type="text" name="location" value="' . esc_attr( (string) $s['location'] ) . '"> posti <input type="number" min="1" name="capacity" class="small-text" value="' . esc_attr( null === $cap ? '' : (string) $cap ) . '"> <button class="button button-small">Salva</button></p>';
				Ui::form_close();

				Ui::form_open( 'asem_cancel_session', $back, false, 'asem-confirm' );
				echo wp_kses( Ui::hidden( 'activity_id', $id ) . Ui::hidden( 'session_id', $s['id'] ), Html::allowed() );
				echo '<button class="button button-link-delete" data-confirm="Annullare questa data? Le prenotazioni non contano più; i pagamenti già ricevuti vanno rimborsati a mano.">Annulla questa data</button>';
				Ui::form_close();
			}
			echo '</details>';
		}
	}
}
