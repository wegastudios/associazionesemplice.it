<?php
namespace AssociazioneSemplice\Admin;

use AssociazioneSemplice\ActivityKind;
use AssociazioneSemplice\ActivityService;
use AssociazioneSemplice\CancelPolicy;
use AssociazioneSemplice\MemberType;
use AssociazioneSemplice\Money;
use AssociazioneSemplice\Plugin;
use AssociazioneSemplice\PriceLists;
use AssociazioneSemplice\PublicEvents;
use AssociazioneSemplice\Settings;
use AssociazioneSemplice\SocialYear;
use AssociazioneSemplice\Texts;
use AssociazioneSemplice\Trials;
use AssociazioneSemplice\Html;

defined( 'ABSPATH' ) || exit;

final class ActivitiesPage {

	/**
	 * Letture fatte una volta sola per tutte le righe di un elenco (date, prenotazioni, iscritti, persone): le righe le usano al posto di una lettura ciascuna.
	 * Vuote (null) fuori dall'elenco delle attività: le funzioni pubbliche funzionano allo stesso modo, con una lettura per riga.
	 *
	 * @var array|null
	 */
	private static $pre = null;

	/**
	 * Per ogni iscrizione, se cancellarla non tocca la prima nota (chiave «attività|data|persona»): calcolato insieme per tutte le righe di un elenco.
	 *
	 * @var array<string,bool>
	 */
	private static $free = array();

	/** Date di un'attività: dalla lettura comune dell'elenco se c'è, altrimenti dal database. */
	private static function sessions_of( int $activity_id ): array {
		return self::$pre['sessions'][ $activity_id ] ?? Plugin::activities()->sessions( $activity_id );
	}

	/** Prenotazioni di una data: dalla lettura comune dell'elenco se c'è, altrimenti dal database. */
	private static function bookings_of( int $session_id ): array {
		return self::$pre['bookings'][ $session_id ] ?? Plugin::activities()->bookings_for_session( $session_id );
	}

	/** Le letture comuni delle righe dell'elenco delle attività (date, prenotazioni della prossima data, iscritti in corso e le loro schede). @param array[] $summaries righe di ReportService::social_year */
	private static function prime_list( array $summaries ): void {
		$svc    = Plugin::activities();
		$events = array();
		$course = array();
		foreach ( $summaries as $s ) {
			if ( ActivityKind::uses_sessions( $s['activity']['kind'] ) ) {
				$events[] = (int) $s['activity']['id'];
			} else {
				$course[] = (int) $s['activity']['id'];
			}
		}
		$sessions = $svc->sessions_many( $events );
		$today    = current_time( 'Y-m-d' );
		$next     = array(); // la prima data futura non annullata di ogni evento: è quella di cui l'elenco mostra i partecipanti attesi
		foreach ( $sessions as $list ) {
			foreach ( $list as $x ) {
				if ( empty( $x['cancelled_at'] ) && \AssociazioneSemplice\ActivityService::last_day( $x ) >= $today ) {
					$next[] = (int) $x['id'];
					break;
				}
			}
		}
		$enrollees = $svc->current_enrollees_many( $course, current_time( 'Y-m' ) );
		$ids       = array();
		foreach ( $enrollees as $list ) {
			$ids = array_merge( $ids, $list );
		}
		self::$pre = array(
			'sessions'  => $sessions,
			'bookings'  => $svc->bookings_for_sessions( $next ),
			'enrollees' => $enrollees,
			'people'    => Plugin::people()->get_many( $ids ),
		);
	}

	/** Le cancellazioni delle iscrizioni di queste persone toccano la prima nota? Si calcola per tutte insieme e poi cancel_control() lo trova già pronto. @param int[] $person_ids */
	private static function prime_free( int $activity_id, int $session_id, array $person_ids ): void {
		if ( ! current_user_can( Plugin::CAP ) ) { // i comandi di cancellazione li vedono solo gli amministratori
			return;
		}
		foreach ( \AssociazioneSemplice\ActivityReset::free_registrations( $activity_id, $person_ids, $session_id ) as $pid => $free ) {
			self::$free[ $activity_id . '|' . $session_id . '|' . $pid ] = $free;
		}
	}


	/** Righe del modulo: cancellabile e termine (solo per eventi ed eventi ricorrenti). */
	private static function cancel_rows( ?array $a, string $row_class ): string {
		$on      = $a && ! empty( $a['cancellable'] );
		$pol     = $a ? (string) $a['cancel_policy'] : '';
		$default = CancelPolicy::labels()[ Settings::get( 'cancel_policy_default' ) ] ?? '';
		return '<tr class="' . esc_attr( $row_class ) . '"><th>Annullamento</th><td><label><input type="checkbox" name="cancellable" value="1"' . checked( $on, true, false ) . '> Si può annullare anche se a pagamento</label>'
			. '<p>Termine: <select name="cancel_policy">' . Ui::options( array( '' => 'Predefinito (' . $default . ')' ) + CancelPolicy::labels(), $pol ) . '</select></p>'
			. '<p class="description">Evento gratuito: la prenotazione si può sempre annullare. A pagamento: si annulla solo con questa spunta ed entro il termine scelto; altrimenti si può solo cedere il posto (se il nuovo partecipante paga un contributo più alto, versa la differenza).</p></td></tr>';
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
		return $tr . '<th>Come si paga</th><td><select name="billing" class="asem-billing">' . Ui::options( array( 'once' => 'Pagamento unico (anche annuale)', 'monthly' => 'Rinnovo mensile', 'quarterly' => 'Rinnovo trimestrale' ), $billing ) . '</select>'
			. '<p class="description"><strong>Pagamento unico:</strong> il contributo indicato è il totale (es. 10 incontri a 120 €), dovuto subito all\'iscrizione. <strong>Rinnovo trimestrale:</strong> il contributo indicato è quello di un trimestre, dovuto all\'inizio di ogni periodo di tre mesi dal primo mese dell\'iscrizione. <strong>Rinnovo mensile:</strong> il corso si rinnova da solo ogni mese finché l\'iscritto non lo disdice, e ogni mensilità è dovuta <strong>dalla prima lezione del mese</strong>.</p></td></tr>'
			. $tr . '<th>Giorni di prova gratuiti</th><td><input type="number" name="trial_days" min="0" max="' . (int) Trials::MAX_DAYS . '" step="1" value="' . (int) ( $a['trial_days'] ?? 0 ) . '" style="width:5em"><p class="description">0 = nessuna prova. La prova si registra ma è gratuita. Chi si iscrive <strong>nello stesso mese</strong> paga dal giorno della prova, in proporzione alle lezioni (rinnovo mensile; trimestre e pagamento unico restano interi); chi si iscrive più tardi non paga la prova.</p></td></tr>'
			. $tr . '<th>Giorni festivi</th><td><input type="hidden" name="red_days_present" value="1"><label><input type="checkbox" name="red_days" value="1"' . checked( $a ? ! empty( $a['red_days'] ) : true, true, false ) . '> Nessuna lezione nei giorni festivi e di chiusura</label><p class="description">Festività nazionali, patrono e chiusure (Impostazioni, Soci e quote). Vale per le lezioni settimanali: le date uniche e le lezioni con le presenze già segnate restano. La scelta vale da oggi in poi: le lezioni già passate non cambiano.</p></td></tr>'
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
		return '<tr><th>Parte per il rimborso</th><td><select name="fund_mode">' . Ui::options( \AssociazioneSemplice\FundShare::modes(), $mode ) . '</select> '
			. '<input type="text" name="fund_value" class="small-text" inputmode="decimal" value="' . esc_attr( $shown ) . '"> <span class="description">€ se importo fisso, % se percentuale</span>'
			. '<p class="description">Una parte di ogni pagamento ricevuto va nel fondo "Rimborso (volontario) — (attività)". Il pagamento entra comunque nella cassa o nel conto usato: la parte accantonata si sottrae dalla disponibilità reale, finché non chiudi il fondo registrando il rimborso. Serve indicare il referente.</p></td></tr>';
	}

	/** Riga del modulo: tolleranza per il pagamento (solo eventi ed eventi ricorrenti; ha effetto se i posti sono limitati). */
	private static function hold_row( ?array $a, string $row_class ): string {
		$h = $a ? (int) ( $a['hold_hours'] ?? 0 ) : 0;
		return '<tr class="' . esc_attr( $row_class ) . '"><th>Tempo per pagare</th><td><input type="number" name="hold_hours" min="0" max="720" class="small-text" value="' . (int) $h . '"> ore'
			. '<p class="description">Per chi paga con bonifico o contanti: se il contributo non arriva entro queste ore dalla prenotazione, il posto passa a chi è in lista d\'attesa. Vale solo con posti limitati e un contributo; chi ha già versato qualcosa o sta pagando online non perde mai il posto. 0 = nessun limite.</p></td></tr>';
	}

	/** Riga del modulo: biglietto QR per le prenotazioni (solo eventi ed eventi ricorrenti, spento di default). */
	private static function qr_row( ?array $a, string $row_class ): string {
		if ( ! \AssociazioneSemplice\Settings::tickets_enabled() ) {
			return ''; // i biglietti QR sono spenti: Impostazioni > Tessera e QR
		}
		$on = $a && ! empty( $a['booking_qr'] );
		return '<tr class="' . esc_attr( $row_class ) . '"><th>Biglietto QR</th><td><label><input type="checkbox" name="booking_qr" value="1"' . checked( $on, true, false ) . '> Genera un QR per ogni prenotazione</label>'
			. '<p class="description">Chi prenota (e ogni suo ospite) trova nell\'area soci un QR: scansionandolo all\'ingresso si vede subito se la prenotazione è valida, per quale data e se il contributo è stato versato. Facoltativo, scelta per singolo evento.</p></td></tr>';
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

		Ui::header( 'Attività · anno delle attività ' . $year->label() );
		echo '<p><a class="button" href="' . esc_url( Ui::url( 'asem-activities', array( 'year' => $sy_start - 1 ) ) ) . '">‹ ' . esc_html( $year->previous()->label() ) . '</a> '
			. '<a class="button" href="' . esc_url( Ui::url( 'asem-activities', array( 'year' => $sy_start + 1 ) ) ) . '">' . esc_html( $year->next()->label() ) . ' ›</a></p>';

		if ( ! $report['activities'] ) {
			echo '<div class="asem-empty"><p><strong>Ancora nessuna attività in questo anno</strong></p><p class="description">Crea la prima con il modulo «Nuova attività» qui sotto: un corso, un evento o un evento ricorrente.</p><a class="button button-primary" href="#asem-new-activity">Crea un\'attività</a></div>';
		}
		$can_bulk = $report['activities'] && current_user_can( Plugin::CAP );
		if ( $can_bulk ) { // scelta multipla: un modulo GET porta alla pagina di conferma (le eliminazioni sono riservate agli amministratori)
			echo '<form method="get" id="asem-bulk" action="' . esc_url( admin_url( 'admin.php' ) ) . '"><input type="hidden" name="page" value="asem-activity-delete">'
				. '<p><label><input type="checkbox" data-asem-check-all="asem-bulk-item"> <strong>Seleziona tutte</strong></label> '
				. '<button class="button" style="color:#b32d2e;border-color:#b32d2e">Elimina selezionate…</button> <span class="description">Spunta le attività da togliere: prima di eliminare vedrai l\'elenco e cosa viene cancellato.</span></p>';
		}
		echo '<div class="asem-grid asem-more">';
		self::prime_list( $report['activities'] );
		foreach ( $report['activities'] as $s ) {
			$a = $s['activity'];
			echo '<div class="asem-card">' . ( $can_bulk ? '<label class="asem-bulk-pick"><input type="checkbox" class="asem-bulk-item" name="ids[]" value="' . (int) $a['id'] . '" aria-label="' . esc_attr( 'Seleziona ' . $a['name'] ) . '"></label>' : '' ) . '<p class="description" style="margin-bottom:0">' . esc_html( ActivityKind::short_label( $a['kind'] ) ) . '</p>';
			echo '<h2 style="margin-top:2px"><a href="' . esc_url( Ui::url( 'asem-activity', array( 'id' => $a['id'] ) ) ) . '">' . esc_html( $a['name'] ) . '</a></h2>';
			echo '<p class="description">' . esc_html( self::fee_text( $a ) ) . '<br>' . (int) $s['participants'] . ( 1 === (int) $s['participants'] ? ' partecipante' : ' partecipanti' ) . ' · '
				. ( $a['instructor_name'] ? 'tenuta da ' . esc_html( $a['instructor_name'] ) : 'senza referente' ) . '</p>';
			if ( ActivityKind::uses_sessions( $a['kind'] ) ) {
				$sessions = self::sessions_of( (int) $a['id'] );
				$next     = null;
				foreach ( $sessions as $x ) {
					if ( empty( $x['cancelled_at'] ) && \AssociazioneSemplice\ActivityService::last_day( $x ) >= $today ) {
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
		self::$pre = null; // le letture comuni servono solo alle righe qui sopra
		if ( $can_bulk ) {
			echo '</form>';
		}

		$dn_denied = \AssociazioneSemplice\Employees::denied_for( get_current_user_id() ); // chi ha una sola area (corsi o eventi) vede solo le pagine della sua
		$drafts    = current_user_can( Plugin::CAP_OPS ) ? \AssociazioneSemplice\PublicEvents::draft_pages( array_map( function ( $r ) { return (int) $r['activity']['id']; }, array_filter( $report['activities'], function ( $r ) use ( $dn_denied ) { return \AssociazioneSemplice\EmployeeRules::has( $dn_denied, \AssociazioneSemplice\EmployeeRules::activity_key( (string) $r['activity']['kind'] ) ); } ) ) ) : array();
		if ( $drafts ) { // le pagine importate (per esempio da The Events Calendar) restano in bozza: si pubblicano insieme, senza aprirle una a una
			Ui::form_open( 'asem_publish_pages', $back );
			echo '<div class="asem-card"><h2>' . esc_html( \AssociazioneSemplice\Texts::t( 'Pagine in bozza: non si vedono ancora sul sito' ) ) . '</h2>'
				. '<p class="description">' . esc_html( \AssociazioneSemplice\Texts::t( 'Spunta le pagine da pubblicare: dopo il clic compaiono subito sul sito.' ) ) . '</p>'
				. '<p><label><input type="checkbox" data-asem-check-all="asem-draft-item"> <strong>' . esc_html( \AssociazioneSemplice\Texts::t( 'Seleziona tutte' ) ) . '</strong></label></p><ul>';
			foreach ( $report['activities'] as $r ) {
				$aid = (int) $r['activity']['id'];
				if ( isset( $drafts[ $aid ] ) ) {
					$ds     = self::sessions_of( $aid ); // data accanto al nome; «(annullato)» se tutte le date sono annullate
					$live   = array_values( array_filter( $ds, function ( $x ) { return empty( $x['cancelled_at'] ); } ) );
					$shown  = $live ? $live[0] : ( $ds ? $ds[0] : null );
					$when   = $shown ? ' — ' . Ui::date( (string) $shown['session_date'] ) : '';
					$cancel = $ds && ! $live ? ' ' . \AssociazioneSemplice\Texts::t( '(annullato)' ) : '';
					echo '<li><label><input type="checkbox" class="asem-draft-item" name="ids[]" value="' . (int) $aid . '"> ' . esc_html( (string) $r['activity']['name'] . $when . $cancel ) . '</label></li>';
				}
			}
			echo '</ul><p><button class="button button-primary" onclick="return confirm(\'' . esc_js( \AssociazioneSemplice\Texts::t( 'Pubblicare le pagine selezionate? Compariranno subito sul sito.' ) ) . '\')">' . esc_html( \AssociazioneSemplice\Texts::t( 'Pubblica le pagine selezionate' ) ) . '</button></p></div></form>';
		}

		self::sports_card();
		$volunteers = Plugin::people()->search_light( array( 'type' => MemberType::VOLUNTEER ) );
		echo '<div class="asem-card" id="asem-new-activity"><h2>Nuova attività</h2>';
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
			self::sessions_summary( $activity ); // il riepilogo sta prima del calendario
			self::sessions_calendar( $activity );
			self::sessions_list( $activity, $back ); // le date e le prenotazioni restano nella stessa colonna, sotto il calendario
			self::sessions_left( $activity, $back ); // staff e aggiunta di date
		} else {
			self::course_left( $activity, $year, $back );
			self::trials_card( $activity, $back );
			self::course_enrolled( $activity, $year, $back ); // gli iscritti restano nella prima colonna, sotto il modulo di iscrizione
		}
		echo '</div><div class="asem-col">';
		echo wp_kses( \AssociazioneSemplice\EventRequests::admin_box( $id, $back ) . PublicEvents::activity_box( $id ), Html::allowed() );
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
		Ui::footer();
	}

	/** Calendario visuale delle date di un evento, a mese o a settimana (a scelta). Ogni data porta alla sua riga con le prenotazioni. */
	private static function sessions_calendar( array $activity ): void {
		$id    = (int) $activity['id'];
		$today = current_time( 'Y-m-d' );
		$by    = array();
		$first = '';
		$all_s = Plugin::activities()->sessions( $id );
		$shown = array_flip( array_map( 'intval', array_column( self::session_block( $all_s, $today, self::session_limit() )['shown'], 'id' ) ) ); // le date non ancora mostrate nell'elenco portano alla pagina con tutte
		foreach ( $all_s as $s ) {
			$by[ $s['session_date'] ][] = $s;
			if ( '' === $first && empty( $s['cancelled_at'] ) && \AssociazioneSemplice\ActivityService::last_day( $s ) >= $today ) {
				$first = $s['session_date']; // di partenza si mostra il periodo della prossima data
			}
		}
		$view = 'day' === Ui::get_str( 'cal' ) ? 'day' : 'month'; // di partenza il mese
		$ref  = Ui::get_str( 'cal_date' );
		if ( ! preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $ref, $m ) || ! checkdate( (int) $m[2], (int) $m[3], (int) $m[1] ) ) {
			$ref = '' !== $first ? $first : ( $by ? array_key_last( $by ) : $today );
		}
		$d = new \DateTimeImmutable( $ref );
		if ( 'day' === $view ) {
			$from = $d;
			$to   = $d;
			$prev = $d->modify( '-1 day' );
			$next = $d->modify( '+1 day' );
			$head = wp_date( 'l j F Y', $d->getTimestamp() );
		} else {
			$m1   = $d->modify( 'first day of this month' );
			$from = $m1->modify( 'monday this week' );
			$end  = $m1->modify( 'last day of this month' );
			$to   = $end->modify( 'sunday this week' );
			$prev = $m1->modify( '-1 month' );
			$next = $m1->modify( '+1 month' );
			$head = wp_date( 'F Y', $m1->getTimestamp() );
		}
		$link = function ( string $v, string $date ) use ( $id ) {
			return Ui::url( 'asem-activity', array( 'id' => $id, 'cal' => $v, 'cal_date' => $date ) );
		};
		$html  = '<div class="asem-card asem-cal"><h2>Calendario dell\'evento</h2><p class="asem-cal-nav">'
			. '<a class="button" aria-label="Periodo precedente" href="' . esc_url( $link( $view, $prev->format( 'Y-m-d' ) ) ) . '">←</a> <strong>' . esc_html( $head ) . '</strong> '
			. '<a class="button" aria-label="Periodo successivo" href="' . esc_url( $link( $view, $next->format( 'Y-m-d' ) ) ) . '">→</a> '
			. '<span class="asem-cal-views"><a class="button' . ( 'day' === $view ? ' button-primary' : '' ) . '" href="' . esc_url( $link( 'day', $ref ) ) . '">Giorno</a> '
			. '<a class="button' . ( 'month' === $view ? ' button-primary' : '' ) . '" href="' . esc_url( $link( 'month', $ref ) ) . '">Mese</a></span></p>';
		if ( 'day' === $view ) { // per cambiare giorno si sceglie la data (selettore del calendario, scritta come gg/mm/aaaa)
			$html .= '<form method="get" action="' . esc_url( admin_url( 'admin.php' ) ) . '" class="asem-cal-pick"><input type="hidden" name="page" value="asem-activity"><input type="hidden" name="id" value="' . $id . '"><input type="hidden" name="cal" value="day">'
				. '<label>Vai al giorno <input type="date" name="cal_date" value="' . esc_attr( $ref ) . '" required></label> <button class="button">Vai</button></form>';
		}
		$html .= '<table class="asem-cal-grid"><thead><tr>';
		$names = array( 'Lun', 'Mar', 'Mer', 'Gio', 'Ven', 'Sab', 'Dom' );
		$cols  = 'day' === $view ? 1 : 7;
		foreach ( 'day' === $view ? array( $names[ (int) $from->format( 'N' ) - 1 ] ) : $names as $n ) {
			$html .= '<th>' . $n . '</th>';
		}
		$html .= '</tr></thead><tbody>';
		for ( $day = $from, $col = 0; $day <= $to; $day = $day->modify( '+1 day' ), $col++ ) {
			$key = $day->format( 'Y-m-d' );
			if ( 0 === $col % $cols ) {
				$html .= '<tr>';
			}
			$other = 'month' === $view && $day->format( 'm' ) !== $m1->format( 'm' );
			$html .= '<td class="asem-cal-day' . ( $other ? ' asem-cal-other' : '' ) . ( $key === $today ? ' asem-cal-today' : '' ) . '"><span class="asem-cal-n">' . (int) $day->format( 'j' ) . '</span>';
			foreach ( $by[ $key ] ?? array() as $s ) {
				$seat   = Plugin::activities()->seats( (int) $s['id'] );
				$count  = (int) $seat['taken'] . ( null === $seat['capacity'] ? '' : '/' . (int) $seat['capacity'] );
				$full   = null !== $seat['free'] && 0 === $seat['free'];
				$html  .= '<a class="asem-cal-s' . ( ! empty( $s['cancelled_at'] ) ? ' asem-cal-off' : ( $full ? ' asem-cal-full' : '' ) ) . '" href="' . ( isset( $shown[ (int) $s['id'] ] ) ? '' : esc_url( Ui::url( 'asem-activity', array( 'id' => $id, 'sess_n' => 5000 ) ) ) ) . '#asem-sess-' . (int) $s['id'] . '">'
					. esc_html( ( $s['start_time'] ? substr( (string) $s['start_time'], 0, 5 ) . ' ' : '' ) . $count ) . '</a>';
			}
			if ( 'day' === $view && empty( $by[ $key ] ) ) {
				$html .= '<span class="description">Nessuna data in questo giorno.</span>';
			}
			$html .= '</td>';
			if ( $cols - 1 === $col % $cols ) {
				$html .= '</tr>';
			}
		}
		$html .= '</tbody></table><p class="description">Accanto all\'orario: persone prenotate e posti totali. Tocca una data per vedere le prenotazioni.</p>';
		$pk    = Plugin::activities()->packages_overview( $id );
		if ( $pk ) {
			$html .= '<details class="asem-detail"><summary><strong>Pacchetti con ingressi ancora da usare (' . count( $pk ) . ')</strong></summary><table class="widefat striped"><thead><tr><th>Persona</th><th>Pacchetto</th><th>Ingressi rimasti</th></tr></thead><tbody>';
			foreach ( $pk as $r ) {
				$html .= '<tr><td><a href="' . esc_url( Ui::url( 'asem-person', array( 'id' => $r['person_id'] ) ) ) . '">' . esc_html( $r['name'] ) . '</a></td><td>' . esc_html( $r['label'] ) . '</td><td>' . (int) $r['left'] . ' su ' . (int) $r['total'] . '</td></tr>';
			}
			$html .= '</tbody></table></details>';
		}
		echo wp_kses( $html . '</div>', Html::allowed() );
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
		echo '<div class="asem-card"><h2>Date delle lezioni</h2><p class="description">Annulla una lezione (festività, maltempo) o spostala a un altro giorno: il calendario, le presenze e i promemoria seguono. I contributi non cambiano.</p>';
		if ( ! $next ) {
			echo '<p class="description">Nessuna lezione nei prossimi 60 giorni.</p>';
		}
		foreach ( $next as $o ) {
			Ui::form_open( 'asem_lesson_exception', $back, false, 'asem-inline' );
			echo wp_kses( Ui::hidden( 'activity_id', $id ) . Ui::hidden( 'date', $o['date'] ), Html::allowed() );
			echo '<p><strong>' . esc_html( Ui::date( $o['date'] ) ) . '</strong>' . ( $o['start'] ? ' ore ' . esc_html( (string) $o['start'] ) : '' ) . ' · <input type="text" name="note" placeholder="Motivo (facoltativo)" size="22"> '
				. '<label><input type="checkbox" name="notify" value="1" checked> avvisa gli iscritti</label> '
				. '<button class="button" name="mode" value="cancel">Annulla solo questa lezione</button> <button class="button" name="mode" value="cancel_from">Annulla questa lezione e le successive</button><br>Sposta a <input type="date" name="new_date"> dalle <input type="time" name="new_start"> alle <input type="time" name="new_end"> <button class="button" name="mode" value="move">Sposta</button></p>';
			Ui::form_close();
		}
		$red = \AssociazioneSemplice\CourseCalendar::red_lessons( $activity, \AssociazioneSemplice\Db::today(), gmdate( 'Y-m-d', strtotime( \AssociazioneSemplice\Db::today() . ' +60 days' ) ) );
		if ( $red ) {
			echo '<h3>Giorni festivi e di chiusura, senza lezione</h3><ul>';
			foreach ( $red as $d => $why ) {
				Ui::form_open( 'asem_lesson_exception', $back, false, 'asem-inline' );
				echo wp_kses( Ui::hidden( 'activity_id', $id ) . Ui::hidden( 'date', $d ), Html::allowed() );
				echo '<li>' . esc_html( Ui::date( $d ) ) . ' — ' . esc_html( $why ) . ' <button class="button button-small" name="mode" value="keep">Tieni la lezione</button></li>';
				Ui::form_close();
			}
			echo '</ul>';
		}
		if ( $ex ) {
			echo '<h3>Lezioni cambiate</h3><ul>';
			foreach ( $ex as $e ) {
				Ui::form_open( 'asem_lesson_restore', $back, false, 'asem-inline' );
				echo wp_kses( Ui::hidden( 'activity_id', $id ) . Ui::hidden( 'date', $e['lesson_date'] ), Html::allowed() );
				echo '<li>' . esc_html( Ui::date( $e['lesson_date'] ) ) . ( \AssociazioneSemplice\LessonExceptions::MOVE === $e['action'] ? ' → spostata al ' . esc_html( Ui::date( $e['new_date'] ) ) : ( \AssociazioneSemplice\LessonExceptions::KEEP === $e['action'] ? ' — si tiene anche se è un giorno festivo o di chiusura' : ' — annullata' ) ) . ( $e['note'] ? ' <span class="description">(' . esc_html( $e['note'] ) . ')</span>' : '' ) . ' <button class="button button-small">Ripristina</button></li>';
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
		$key  = $activity_id . '|' . $session_id . '|' . (int) $person['id'];
		$free = self::$free[ $key ] ?? \AssociazioneSemplice\ActivityReset::registration_is_free( $activity_id, (int) $person['id'], $session_id );
		if ( $free ) {
			return Ui::confirm_button( 'asem_delete_booking', Ui::url( 'asem-activity', array( 'id' => $activity_id ) ), array( 'activity' => $activity_id, 'person' => (int) $person['id'], 'session' => $session_id ), $label, 'Eliminare l\'iscrizione di ' . $name . '? Non ci sono incassi collegati: la prima nota non cambia.', 'color:#b32d2e' );
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
			$html .= ' · ' . self::cancel_control( (int) $activity['id'], $person, $session_id, 'Elimina l\'iscrizione' );
		}
		return $html . '</li>';
	}

	/** Partecipanti attesi, a scomparsa: per gli eventi una voce per data (le prossime), per i corsi gli iscritti in corso. */
	public static function participants_html( array $activity, int $max_sessions = 8, bool $with_delete = true ): string {
		$people = Plugin::people();
		$html   = '';
		self::$free = array(); // le risposte sulle cancellazioni valgono solo per questa chiamata: dopo una modifica si rileggono
		if ( ActivityKind::uses_sessions( $activity['kind'] ) ) {
			$n = 0;
			foreach ( self::sessions_of( (int) $activity['id'] ) as $s ) {
				if ( ! empty( $s['cancelled_at'] ) || $s['session_date'] < current_time( 'Y-m-d' ) || $n >= $max_sessions ) {
					continue;
				}
				$n++;
				$rows = '';
				$cnt  = 0;
				$booked_rows = self::bookings_of( (int) $s['id'] );
				if ( $with_delete ) { // il comando «Cancella» di ogni riga sa già se tocca la prima nota
					self::prime_free( (int) $activity['id'], (int) $s['id'], array_column( array_filter( $booked_rows, function ( $b ) {
						return 'booked' === $b['status'];
					} ), 'person_id' ) );
				}
				foreach ( $booked_rows as $b ) {
					if ( 'booked' !== $b['status'] ) {
						continue;
					}
					$person = array( 'id' => (int) $b['person_id'], 'first_name' => $b['first_name'], 'last_name' => $b['last_name'], 'phone' => $b['person_phone'] ); // i dati della persona arrivano già con le prenotazioni (la persona eliminata non è nell'elenco): nessuna query per ciascuna
					if ( $person ) {
						$cnt++;
						$rows .= self::participant_row( $activity, $person, (int) $s['id'], trim( ( 'paid' === ( $b['state'] ?? '' ) ? 'pagato' : ( ! empty( $b['fee_due_cents'] ) ? ( ActivityService::owed_by_another( $b ) ? 'da pagare, a carico di chi ha prenotato' : 'da pagare' ) : '' ) ) . ( '' !== PriceLists::booking_label( $activity, $b ) ? ' · ' . PriceLists::booking_label( $activity, $b ) : '' ), ' ·' ), $with_delete );
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
			$aid = (int) $activity['id'];
			if ( isset( self::$pre['enrollees'][ $aid ] ) ) { // dalla lettura comune dell'elenco
				$pids   = self::$pre['enrollees'][ $aid ];
				$loaded = self::$pre['people'];
			} else {
				$pids   = array_map( 'intval', \AssociazioneSemplice\Db::db()->get_col( \AssociazioneSemplice\Db::db()->prepare( 'SELECT person_id FROM ' . \AssociazioneSemplice\Db::t( 'enrollments' ) .' WHERE activity_id = %d AND (end_month IS NULL OR end_month >= %s)', $aid, $ym ) ) );
				$loaded = $people->get_many( $pids ); // le schede si leggono tutte insieme, nell'ordine degli iscritti
			}
			if ( $with_delete ) {
				self::prime_free( $aid, 0, $pids );
			}
			foreach ( $pids as $pid ) {
				$person = $loaded[ (int) $pid ] ?? null;
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
			echo wp_kses( '<tr><th>Anno delle attività</th><td><select name="social_year">' . Ui::options( $opts, $year->next()->label() ) . '</select></td></tr>'
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
		echo '<div class="asem-card"><h2>Elimina l\'evento</h2><p class="description">Elimina l\'evento con tutte le date, le prenotazioni e le iscrizioni. Prima scegli cosa fare delle somme incassate (restituirle o annullarle) e confermi due volte.</p>'
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
			$html .= '<select name="price_list" title="Tariffa"><option value="">Contributo base</option>';
			foreach ( $lists as $l ) {
				$html .= '<option value="' . esc_attr( $l['key'] ) . '">' . esc_html( $l['label'] . ' — ' . Money::format( $l['cents'] ) ) . '</option>';
			}
			$html .= '</select> ';
			$pk = $person_id ? Plugin::activities()->open_packages( $person_id, (int) $activity['id'] ) : array();
			if ( $pk ) {
				$html .= '<label><input type="checkbox" name="use_package" value="1"> usa ' . esc_html( $pk[0]['label'] ) . ' (' . (int) $pk[0]['left'] . ' rimasti)</label> ';
			} elseif ( ! $person_id ) {
				// Persona non ancora scelta: la scritta si aggiorna (admin.js) appena la scegli, con il pacchetto e gli ingressi rimasti.
				$map = array();
				foreach ( Plugin::activities()->packages_overview( (int) $activity['id'] ) as $r ) {
					if ( ! isset( $map[ $r['person_id'] ] ) ) {
						$map[ $r['person_id'] ] = $r['label'] . '|' . $r['left'];
					}
				}
				$html .= '<label class="asem-use-pk" data-packages="' . esc_attr( (string) wp_json_encode( (object) $map ) ) . '"><input type="checkbox" name="use_package" value="1"> <span>usa un pacchetto già pagato</span></label> ';
			} else {
				$html .= '<span class="description">nessun pacchetto attivo</span> ';
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

	/** Una riga del listino (nome, prezzo, ingressi per gli eventi). */
	private static function price_row( int $i, array $l, bool $course ): string {
		return '<div class="asem-pl-row"><input type="hidden" name="pl_key[' . $i . ']" value="' . esc_attr( (string) ( $l['key'] ?? '' ) ) . '">'
			. ( $course ? '<input type="hidden" name="pl_entries[' . $i . ']" value="1">' : '' )
			. '<input type="text" class="asem-pl-name" name="pl_label[' . $i . ']" value="' . esc_attr( $l['label'] ) . '" placeholder="' . ( $course ? 'es. Secondo corso -10%, Famiglia, Solo il sabato' : 'es. Intero, Ridotto, Pacchetto 4' ) . '" maxlength="60" aria-label="' . ( $course ? 'Nome della variante' : 'Nome della tariffa' ) . '">'
			. '<span class="asem-pl-price"><input type="text" name="pl_price[' . $i . ']" size="7" value="' . esc_attr( '' === $l['label'] ? '' : Money::plain( (int) $l['cents'] ) ) . '" aria-label="Importo in euro"> €</span>'
			. ( $course ? '' : '<input type="number" name="pl_entries[' . $i . ']" min="1" max="50" value="' . (int) $l['entries'] . '" aria-label="Ingressi">' )
			. '</div>';
	}

	/** Le righe dei listini: quelle già scritte più una vuota; il pulsante ne aggiunge altre, fino al limite scelto in Impostazioni. */
	private static function price_rows( array $activity, bool $course ): string {
		$lists = array_slice( PriceLists::for_activity( $activity ), 0, PriceLists::max() );
		$lists = array_merge( $lists, array( array( 'label' => '', 'cents' => 0, 'entries' => 1 ) ) );
		$rows  = $course ? '<div class="asem-pl-row asem-pl-head"><span>Variante</span><span>Importo</span></div>' : '<div class="asem-pl-row asem-pl-head"><span>Nome</span><span>Importo</span><span>Ingressi</span></div>';
		foreach ( $lists as $i => $l ) {
			$rows .= self::price_row( (int) $i, $l, $course );
		}
		return '<div class="asem-pl" data-max="' . (int) PriceLists::max() . '">' . $rows . '</div><p><button type="button" class="button asem-pl-add">+ Aggiungi una tariffa</button></p>';
	}

	/** Righe del modulo con i listini dell'attività (nome, prezzo, ingressi). */
	private static function price_list_row( array $activity ): string {
		if ( ActivityKind::COURSE === $activity['kind'] ) {
			return self::course_variants_row( $activity );
		}
		if ( ! ActivityKind::uses_sessions( $activity['kind'] ) ) {
			return '';
		}
		return '<tr><th>Listini</th><td>' . self::price_rows( $activity, false )
			. '<p class="description">Facoltativi: ad esempio Intero, Ridotto, Studenti, Pacchetto 4. Scrivi solo quelli che servono e aggiungine altri col pulsante. In prenotazione la segreteria sceglie la tariffa; con 4 ingressi l\'importo del pacchetto si paga una volta e le altre date si prenotano «dal pacchetto» senza altro da pagare. Senza tariffe vale il contributo sopra.</p></td></tr>';
	}

	/** Il menu delle varianti del contributo di un corso (la quota base è la prima voce). */
	private static function variant_select( array $activity, string $selected ): string {
		$html = '<select name="price_list"><option value="">Contributo base — ' . esc_html( Money::format( (int) $activity['fee_cents'] ) ) . '</option>';
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
		return '<tr><th>Varianti del contributo</th><td>' . self::price_rows( $activity, true )
			. '<p class="description">Facoltative e descritte come vuoi: sconto per più corsi, attività parziale, più familiari iscritti. L\'importo è quello di ogni periodo di pagamento del corso. Le assegna la segreteria all\'iscrizione di una persona; chi si iscrive da solo paga il contributo sopra.</p></td></tr>';
	}

	private static function edit_card( array $activity, string $back ): void {
		$volunteers = Plugin::people()->search_light( array( 'type' => MemberType::VOLUNTEER ) );
		echo '<div class="asem-card"><h2>Dati dell\'attività</h2>';
		Ui::form_open( 'asem_save_activity', $back );
		echo wp_kses( Ui::hidden( 'id', $activity['id'] ) . Ui::hidden( 'social_year', $activity['social_year'] ), Html::allowed() );
		echo '<table class="form-table"><tbody><tr><th>Nome</th><td><input type="text" name="name" value="' . esc_attr( $activity['name'] ) . '" class="regular-text" required></td></tr>';
		echo '<tr><th>Contributo soci' . ( ActivityKind::COURSE === $activity['kind'] ? '' : ' (' . esc_html( ActivityKind::fee_unit( $activity['kind'] ) ) . ')' ) . '</th><td><input type="text" name="fee" value="' . esc_attr( Money::plain( (int) $activity['fee_cents'] ) ) . '"> €</td></tr>';
		$guest = null === $activity['guest_fee_cents'] ? '' : Money::plain( (int) $activity['guest_fee_cents'] );
		echo '<tr><th>Contributo ospiti</th><td><input type="text" name="guest_fee" value="' . esc_attr( $guest ) . '" placeholder="uguale ai soci"> €<p class="description">Vuoto = come i soci · 0 = gratuito. Vale da ora: le prenotazioni già fatte tengono l\'importo di allora, le mensilità dei corsi seguono il nuovo contributo.</p></td></tr>';
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

		echo wp_kses( '<div class="asem-card"><h2>Riepilogo · anno delle attività ' . esc_html( $activity['social_year'] ) . '</h2><table class="asem-kv">'
			. '<tr><td>Dovuto finora</td><td>' . Ui::money( $due ) . '</td></tr><tr><td>Incassato</td><td>' . Ui::money( $paid ) . '</td></tr>'
			. '<tr><td><strong>Ancora da incassare</strong></td><td><strong>' . Ui::money( $to_collect ) . '</strong></td></tr></table></div>', Html::allowed() );

		$enrolled_ids = array();
		foreach ( $statuses as $s ) {
			if ( null === $s['enrollment']['end_month'] ) {
				$enrolled_ids[] = (int) $s['enrollment']['person_id'];
			}
		}
		$candidates = array_values( array_filter( Plugin::people()->search_light(), function ( $p ) use ( $enrolled_ids ) {
			return ! in_array( (int) $p['id'], $enrolled_ids, true );
		} ) );
		echo '<div class="asem-card"><h2>Iscrivi un socio o un ospite</h2>' . ( 'once' === $activity['billing']
			? '<p class="description">Corso a <strong>pagamento unico</strong>: il contributo è dovuto per intero all\'iscrizione (si può versare anche a rate).</p>'
			: ( 'quarterly' === $activity['billing'] ? '<p class="description">L\'iscrizione si <strong>rinnova da sola ogni trimestre</strong> (tre mesi dal primo mese dovuto) finché non viene disdetta.</p>' :'<p class="description">L\'iscrizione si <strong>rinnova da sola ogni mese</strong> finché non viene disdetta' . ( $activity['ends_on'] ? ' (o fino alla fine del corso)' : '' ) . '. Ogni mensilità si paga alla prima lezione del mese' . ( (int) $activity['lesson_weekday'] ? '' : ' (indica il giorno della lezione nei dati del corso per saperlo con precisione)' ) . '.</p>' ) );
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

	/** Giorni di prova gratuiti: si registrano qui; chi li ha fatti e chi si è poi iscritto. */
	private static function trials_card( array $activity, string $back ): void {
		$id   = (int) $activity['id'];
		$days = (int) ( $activity['trial_days'] ?? 0 );
		$rows = Trials::of_activity( $id );
		if ( $days < 1 && ! $rows ) {
			return;
		}
		echo '<div class="asem-card" id="asem-trials"><h2>Prove gratuite</h2>';
		if ( $days > 0 ) {
			echo '<p class="description">' . ( 1 === $days ? 'Un giorno di prova gratuito.' : (int) $days . ' giorni di prova gratuiti.' ) . ' Chi si iscrive nello stesso mese paga dal giorno della prova; altrimenti la prova resta gratuita.</p>';
			Ui::form_open( 'asem_trial_add', $back . '#asem-trials' );
			echo wp_kses( Ui::hidden( 'activity_id', $id ) . '<p>' . Ui::person_select( 'person_id', Plugin::people()->search_light(), null, '— scegli la persona —', 'asem-trial-person' ) . '</p>', Html::allowed() );
			if ( Settings::guests_enabled() ) {
				echo wp_kses( '<details><summary>oppure nuovo ospite</summary><p><input type="text" name="new_first_name" placeholder="Nome" aria-label="Nome"> <input type="text" name="new_last_name" placeholder="Cognome" aria-label="Cognome"> <input type="tel" name="new_phone" placeholder="Cellulare" aria-label="Cellulare"></p>'
					. '<p>Ospite di ' . Ui::person_select( 'new_host_id', Plugin::people()->search_light(), null, '— nessuno —', 'asem-trial-host', false, true ) . '</p></details>', Html::allowed() );
			}
			echo wp_kses( '<p><label>Giorno della prova <input type="date" name="trial_date" value="' . esc_attr( current_time( 'Y-m-d' ) ) . '" required></label> <button class="button">Registra la prova</button></p>', Html::allowed() );
			Ui::form_close();
		}
		if ( $rows ) {
			echo '<table class="widefat striped"><thead><tr><th>Giorno</th><th>Persona</th><th>Iscrizione</th><th></th></tr></thead><tbody>';
			foreach ( $rows as $r ) {
				$state = empty( $r['enrollment_id'] ) ? 'non iscritto' : ( $r['trial_from'] === $r['trial_date'] ? 'iscritto, paga da questo giorno' : 'iscritto da ' . Ui::month( (string) $r['start_month'] ) );
				echo '<tr><td>' . esc_html( Ui::date( (string) $r['trial_date'] ) ) . '</td><td><a href="' . esc_url( Ui::url( 'asem-person', array( 'id' => (int) $r['person_id'] ) ) ) . '">' . esc_html( trim( $r['first_name'] . ' ' . $r['last_name'] ) ) . '</a></td><td>' . esc_html( $state ) . '</td><td>';
				if ( $r['trial_from'] !== $r['trial_date'] ) {
					Ui::form_open( 'asem_trial_remove', $back . '#asem-trials', false, 'asem-inline' );
					echo wp_kses( Ui::hidden( 'trial_id', (int) $r['id'] ) . Ui::hidden( 'activity_id', $id ) . '<button class="button button-small" data-confirm="Togliere questa prova?">Togli</button>', Html::allowed() );
					Ui::form_close();
				}
				echo '</td></tr>';
			}
			echo '</tbody></table>';
		}
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
		$trials = array();
		foreach ( array_reverse( Trials::of_activity( $id ) ) as $t ) {
			$trials[ (int) $t['person_id'] ][] = (string) $t['trial_date'];
		}
		self::$free = array();
		self::prime_free( $id, 0, array_column( array_column( $statuses, 'enrollment' ), 'person_id' ) ); // il comando «Cancella» di ogni iscritto sa già se tocca la prima nota
		foreach ( $statuses as $s ) {
			$e      = $s['enrollment'];
			$active = null === $e['end_month'];
			$label  = trim( ( $e['card_number'] ? 'n.' . $e['card_number'] . ' · ' : '' ) . $e['first_name'] . ' ' . $e['last_name'] );
			echo wp_kses( '<details class="asem-detail"><summary><a href="' . esc_url( Ui::url( 'asem-person', array( 'id' => $e['person_id'] ) ) ) . '"><strong>' . esc_html( $label ) . '</strong></a> '
				. '<em>(' . esc_html( MemberType::label( $e['type'] ) ) . ')</em> — ' . Ui::pay_status( $s['summary'] )
				. ( $active ? '' : ' <span class="asem-warn">· cancellato dopo ' . esc_html( Ui::month( $e['end_month'] ) ) . '</span>' )
				. ( self::variant_text( $activity, $e ) )
				. ( (int) $s['summary']['balance'] < 0 ? ' <a class="button button-small button-primary" href="' . esc_url( Ui::url( 'asem-income', array( 'person_id' => (int) $e['person_id'], 'due' => 1 ) ) ) . '">Paga</a>' : '' )
				. ( current_user_can( Plugin::CAP ) ? ' ' . self::cancel_control( $id, array( 'id' => (int) $e['person_id'], 'first_name' => (string) $e['first_name'], 'last_name' => (string) $e['last_name'] ), 0, 'Elimina' ) : '' )
				. '</summary>', Html::allowed() );
			if ( ! empty( $e['trial_from'] ) ) {
				echo '<p class="description">' . esc_html( 'Ha fatto la prova: il primo mese si paga dal ' . Ui::date( (string) $e['trial_from'] ) . ' (' . (int) $e['trial_left'] . ' su ' . (int) $e['trial_total'] . ').' ) . '</p>';
			} else {
				$done = $trials[ (int) $e['person_id'] ] ?? array();
				echo $done ? '<p class="description">' . esc_html( 'Ha già fatto ' . Trials::days_text( $done ) . '.' ) . '</p>' : '';
			}
			echo wp_kses( Ui::months_table( $s['summary'] ), Html::allowed() );
			if ( PriceLists::for_activity( $activity ) ) { // varianti del contributo: le assegna la segreteria
				Ui::form_open( 'asem_enroll_variant', $back, false, 'asem-inline' );
				echo wp_kses( Ui::hidden( 'activity_id', $id ) . Ui::hidden( 'person_id', $e['person_id'] ) . '<p>Contributo: ' . self::variant_select( $activity, (string) ( $e['price_list'] ?? '' ) )
					. ' <input type="text" name="fee_note" value="' . esc_attr( (string) ( $e['fee_note'] ?? '' ) ) . '" placeholder="Nota (es. sorella iscritta a Danza)" size="28" maxlength="190"> <button class="button">Salva</button></p>', Html::allowed() );
				Ui::form_close();
			}
			if ( $active ) { // «Non rinnova»: finito il periodo pagato non è più dovuto nulla, senza scegliere il mese
				Ui::form_open( 'asem_stop_renewal', $back, false, 'asem-inline' );
				echo wp_kses( Ui::hidden( 'activity_id', $id ) . Ui::hidden( 'person_id', $e['person_id'] ) . '<p><button class="button button-primary">Disdici a fine periodo</button> <span class="description">Resta nel corso fino alla fine del periodo già pagato (o del mese in corso) e poi non deve più nulla. Il mese già iniziato non si rimborsa.</span></p>', Html::allowed() );
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

	private static function sessions_summary( array $activity ): void {
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
	}

	/** Eventi: staff e, per i ricorrenti, aggiunta di nuove date. */
	private static function sessions_left( array $activity, string $back ): void {
		$id = (int) $activity['id'];

		self::staff_card( $activity, $back );

		if ( ActivityKind::RECURRING === $activity['kind'] ) {
			echo '<div class="asem-card"><h2>Aggiungi o annulla date</h2>';
			$upcoming = array_values(
				array_filter(
					Plugin::activities()->sessions( $id ),
					function ( $s ) {
						return empty( $s['cancelled_at'] ) && \AssociazioneSemplice\ActivityService::last_day( $s ) >= current_time( 'Y-m-d' );
					}
				)
			);
			echo '<h3>Annulla una data</h3><p class="description">Per un giorno di festa o di pausa: la data resta in elenco con la scritta «annullata» e le prenotazioni non contano più. I pagamenti già ricevuti per quella data vanno rimborsati a mano.</p>';
			if ( ! $upcoming ) {
				echo '<p class="description">Non ci sono date future.</p>';
			} else {
				echo '<ul class="asem-dates">';
				foreach ( $upcoming as $s ) {
					echo '<li>';
					Ui::form_open( 'asem_cancel_session', $back, false, 'asem-confirm asem-inline' );
					echo wp_kses( Ui::hidden( 'activity_id', $id ) . Ui::hidden( 'session_id', $s['id'] ) . esc_html( Ui::date( $s['session_date'] ) . ( $s['start_time'] ? ' · ore ' . substr( (string) $s['start_time'], 0, 5 ) : '' ) ) . ' <button class="button button-small" data-confirm="Annullare la data del ' . esc_attr( Ui::date( $s['session_date'] ) ) . '? Le prenotazioni non contano più; i pagamenti già ricevuti vanno rimborsati a mano.">Annulla</button>', Html::allowed() );
					Ui::form_close();
					echo '</li>';
				}
				echo '</ul>';
			}
			echo '<h3>Aggiungi date</h3>';
			Ui::form_open( 'asem_add_dates', $back );
			echo wp_kses( Ui::hidden( 'activity_id', $id ) . self::when_builder(), Html::allowed() );
			echo '<p>Luogo <input type="text" name="location"> posti <input type="number" min="1" name="capacity" class="small-text"> <span class="description">(per le date che aggiungi; posti vuoti = nessun limite)</span></p>'
				. '<p><button class="button button-primary">Aggiungi le date</button> <span class="description">Le date già presenti non vengono duplicate (massimo 400 alla volta).</span></p>';
			Ui::form_close();
			echo '</div>';
		}
	}

	/** Prenotazione sul posto: chi si presenta senza aver prenotato. Prenota, incassa il contributo e registra l'ingresso in un colpo solo. */
	private static function walk_in_form( array $activity, array $s, array $candidates, string $back, ?array &$ctx = null, bool $lazy = false ): void {
		if ( null === $ctx ) { // i conti, il conto di cassa e i tipi si leggono una volta per pagina, non per ogni data
			$ledger   = Plugin::ledger();
			$accounts = array();
			$types    = array();
			foreach ( $ledger->accounts() as $a ) { // solo l'elenco dei conti: i saldi non servono qui
				$accounts[ (int) $a['id'] ] = $a['name'];
				$types[ (int) $a['id'] ]    = $a['type'];
			}
			$default = $ledger->default_account_for( 'cash' );
			$members = array();
			if ( ! $lazy ) {
				$members = array_values( array_filter( Plugin::people()->search_light(), function ( $p ) {
					return MemberType::is_member( $p['type'] );
				} ) );
			}
			$ctx = array( 'accounts' => $accounts, 'types' => $types, 'default' => $default ? (int) $default['id'] : null, 'members' => $members );
		}
		$sid   = (int) $s['id'];
		$fee   = (int) $activity['fee_cents'];
		$guest = null === $activity['guest_fee_cents'] || '' === $activity['guest_fee_cents'] ? $fee : (int) $activity['guest_fee_cents'];
		echo '<details class="asem-detail" style="margin-top:8px"><summary><strong>Ingresso senza prenotazione (sul posto)</strong></summary>';
		$guest_ids = array();
		foreach ( $candidates as $c ) { // con la ricerca non c'è l'elenco: l'ospite si riconosce dal tipo della voce scelta
			if ( MemberType::GUEST === $c['type'] ) {
				$guest_ids[] = (int) $c['id'];
			}
		}
		Ui::form_open( 'asem_walk_in', $back );
		echo wp_kses( Ui::hidden( 'activity_id', $activity['id'] ) . Ui::hidden( 'session_id', $sid ), Html::allowed() );
		echo '<div data-asem-change data-fee="' . (int) $fee . '" data-guest-fee="' . (int) $guest . '" data-guest-ids="' . esc_attr( wp_json_encode( $guest_ids ) ) . '" data-types="' . esc_attr( wp_json_encode( $ctx['types'] ) ) . '">';
		echo wp_kses( '<p>Persona già in anagrafica: ' . Ui::person_select( 'person_id', $candidates, null, '— scegli socio o ospite —', 'asem-walk-' . $sid, $lazy ) . '</p>'
			. '<p><strong>oppure</strong> nuovo ospite: nome <input type="text" name="new_first_name"> cognome <input type="text" name="new_last_name"> cellulare <input type="text" name="new_phone" placeholder="333 1234567"> del socio '
			. Ui::person_select( 'host_person_id', $ctx['members'], null, '— socio che lo ospita —', 'asem-walkhost-' . $sid, $lazy, true ) . '</p>'
			. '<p><label><input type="checkbox" name="pay" value="1" checked> Incassa ora il contributo</label> (soci ' . esc_html( Money::format( $fee ) ) . ', ospiti ' . esc_html( Money::format( $guest ) ) . ') — '
			. 'sul conto '
			. '<select name="account_id">' . Ui::options( $ctx['accounts'], $ctx['default'] ) . '</select></p>'
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
			. '<button class="button button-small">' . ( $in ? 'Annulla ingresso' : 'Registra ingresso' ) . '</button>', Html::allowed() );
		Ui::form_close();
	}

	/** Avvisi agli iscritti: modulo di invio e ultimi avvisi (i volontari li inviano dall'area soci). */
	private static function notices_card( array $activity, string $back ): void {
		$id    = (int) $activity['id'];
		$count = count( \AssociazioneSemplice\Notices::recipients( $id ) );
		echo '<div class="asem-card"><h2>Avvisi agli iscritti</h2><p class="description">Arrivano per email a chi è iscritto (ora ' . (int) $count . ' persone) e restano nella bacheca dell\'area soci. '
			. 'Il referente e lo staff dell\'evento li inviano dalla propria area soci.</p>';
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

	/** Soci abilitati a gestire l'evento (lista prenotati e registrazione ingressi dall'area soci), oltre al referente. */
	private static function staff_card( array $activity, string $back ): void {
		$id    = (int) $activity['id'];
		$svc   = Plugin::activities();
		$staff = $svc->staff( $id );
		$in    = array();
		echo '<div class="asem-card"><h2>Staff dell\'evento (ingressi e cassa)</h2>';
		echo '<p class="description">Vedono i prenotati e registrano gli ingressi (anche scansionando il QR) dall\'area soci, solo per questo evento. Con «può incassare sul posto» fanno anche pagare il contributo a chi arriva senza prenotazione (se c\'è posto) e ne registrano l\'ingresso. Il referente e gli amministratori possono già fare tutto questo.</p>';
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
				echo wp_kses( Ui::hidden( 'activity_id', $id ) . Ui::hidden( 'person_id', $m['person_id'] ) . '<button class="button-link" data-confirm="Togliere questa persona dallo staff?">togli</button>', Html::allowed() );
				Ui::form_close();
				echo '</li>';
			}
			echo '</ul>';
		}
		$lazy       = Ui::person_search_preferred( Plugin::people()->count_people() ); // con un'anagrafica grande non si porta in pagina l'elenco: si cerca
		$candidates = $lazy ? array() : array_values( array_filter( Plugin::people()->search_light(), function ( $p ) use ( $in, $activity ) {
			return MemberType::is_member( $p['type'] ) && ! in_array( (int) $p['id'], $in, true ) && (int) $p['id'] !== (int) $activity['instructor_person_id'];
		} ) );
		Ui::form_open( 'asem_event_staff_add', $back );
		echo wp_kses( Ui::hidden( 'activity_id', $id ) . Ui::person_select( 'person_id', $candidates, null, '— scegli un socio —', 'asem-staff-' . $id, $lazy, true ) . ' <label><input type="checkbox" name="can_cash" value="1"> può incassare sul posto</label> <button class="button">Aggiungi</button>', Html::allowed() );
		Ui::form_close();
		echo '</div>';
	}

	/** Quante date si mostrano per volta nella scheda di un'attività con molte date. */
	const SESSION_BLOCK = 12;

	/**
	 * Le date da mostrare per prime: quelle che non sono ancora finite, dalla più vicina, poi le passate dalla più recente. Le altre restano fuori finché non si chiedono.
	 *
	 * @param array[] $sessions date nell'ordine del calendario
	 * @return array{shown:array[],hidden:int}
	 */
	public static function session_block( array $sessions, string $today, int $limit ): array {
		$next = array();
		$past = array();
		foreach ( $sessions as $s ) {
			if ( ActivityService::last_day( $s ) >= $today ) {
				$next[] = $s;
			} else {
				array_unshift( $past, $s );
			}
		}
		$order = array_merge( $next, $past );
		$limit = max( 1, $limit );
		return array( 'shown' => array_slice( $order, 0, $limit ), 'hidden' => max( 0, count( $order ) - $limit ) );
	}

	/** Il numero di date da mostrare in questa richiesta (a blocchi: «Mostra altre date» aggiunge un blocco). */
	private static function session_limit(): int {
		return max( self::SESSION_BLOCK, min( 5000, Ui::get_int( 'sess_n', self::SESSION_BLOCK ) ) );
	}

	private static function sessions_list( array $activity, string $back ): void {
		$id       = (int) $activity['id'];
		$limit    = self::session_limit();
		$sessions = Plugin::activities()->sessions( $id );
		$block    = self::session_block( $sessions, current_time( 'Y-m-d' ), $limit );
		$lazy     = Ui::person_search_preferred( Plugin::people()->count_people() ); // anagrafica grande: ricerca con completamento al posto delle tendine con tutti i nomi
		$all      = $lazy ? array() : Plugin::people()->search_light();
		$gov      = null; // gli ospiti con più prenotazioni si leggono solo se nella pagina c'è un ospite prenotato
		$walk_ctx = null;
		$by_sess  = Plugin::activities()->bookings_for_sessions( array_column( $block['shown'], 'id' ) ); // una lettura per tutte le date mostrate
		echo '<h2>' . ( ActivityKind::EVENT === $activity['kind'] ? 'Data e prenotazioni' : 'Date e prenotazioni' ) . '</h2>';
		if ( ! $sessions ) {
			echo '<p>Nessuna data. ' . ( ActivityKind::RECURRING === $activity['kind'] ? 'Aggiungine qui sopra.' : '' ) . '</p>';
		}
		foreach ( $block['shown'] as $s ) {
			$cancelled = ! empty( $s['cancelled_at'] );
			$bookings  = $by_sess[ (int) $s['id'] ] ?? array();
			$booked    = array(); // persona => true: si cerca per chiave, non scorrendo l'elenco
			foreach ( $bookings as $b ) {
				if ( $b['active'] ) {
					$booked[ (int) $b['person_id'] ] = true;
				}
			}
			$cap   = null === $s['capacity'] ? null : (int) $s['capacity'];
			$title = Ui::date( $s['session_date'] ) . ( $s['start_time'] ? ' · ore ' . esc_html( $s['start_time'] ) : '' ) . ( $s['location'] ? ' · ' . esc_html( $s['location'] ) : '' );
			echo wp_kses( '<details class="asem-detail" id="asem-sess-' . (int) $s['id'] . '"' . ( ActivityKind::EVENT === $activity['kind'] ? ' open' : '' ) . '><summary><strong>' . $title . '</strong> — '
				. count( $booked ) . ( null === $cap ? ' prenotati' : ' / ' . $cap . ' posti (' . max( 0, $cap - count( $booked ) ) . ' liberi)' ) . ( $cancelled ? ' <span class="asem-neg">· annullata</span>' : '' ) . '</summary>', Html::allowed() );

			$queue = \AssociazioneSemplice\Waitlist::waiting( (int) $s['id'] );
			if ( $queue ) {
				$by_request = (bool) \AssociazioneSemplice\PublicEvents::options( (int) $activity['id'] )['approval']; // evento su richiesta: la coda non avanza da sola
				echo '<p><strong>Lista d\'attesa (' . count( $queue ) . ')</strong> <span class="description">' . ( $by_request ? esc_html( Texts::t( 'Evento su richiesta: la lista d\'attesa non avanza da sola. Prenota tu chi vuoi far entrare.' ) ) : 'entrano da soli, nell\'ordine, se si libera un posto.' ) . '</span></p><ol>';
				foreach ( $queue as $w ) {
					echo '<li>' . esc_html( trim( $w['first_name'] . ' ' . $w['last_name'] ) ) . ' <span class="description">dal ' . esc_html( mysql2date( 'd/m H:i', $w['since'] ) ) . '</span> ' . ( \AssociazioneSemplice\Waitlist::needs_list_choice( $activity, $w ) ? '<span class="asem-warn">non ha scelto la tariffa: non entra da solo, prenotalo a mano scegliendola tu</span> ' : '' );
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
					echo wp_kses( '<tr><td><a href="' . esc_url( Ui::url( 'asem-person', array( 'id' => $b['person_id'] ) ) ) . '">' . esc_html( $label ) . '</a></td><td>' . esc_html( MemberType::label( $b['type'] ) ) . ( MemberType::GUEST === $b['type'] && $b['active'] ? '<br>' . PeoplePage::guest_badge( ( $gov = null === $gov ? Plugin::people()->guest_overview() : $gov )[ (int) $b['person_id'] ] ?? null ) : '' ) . '</td>'
						. '<td>' . esc_html( Money::format( (int) $b['fee_due_cents'] ) ) . '</td><td>' . ( $b['active'] ? Ui::booking_state( $b ) : ( 'transferred' === $b['status'] ? '<span class="asem-warn">posto ceduto a un\'altra persona' : '<span class="asem-warn">prenotazione annullata' ) . ( $b['paid'] > 0 ? ' · versati ' . esc_html( Money::format( $b['paid'] ) ) . ' da rimborsare' : '' ) . '</span>' ) . '</td><td>', Html::allowed() );
					self::checkin_cell( $b, (int) $s['id'], $id, $back, $cancelled );
					echo '</td><td>';
					if ( $b['active'] ) {
						Ui::form_open( 'asem_cancel_booking', $back, false, 'asem-confirm' );
						echo wp_kses( Ui::hidden( 'activity_id', $id ) . Ui::hidden( 'session_id', $s['id'] ) . Ui::hidden( 'person_id', $b['person_id'] ), Html::allowed() );
						echo '<button class="button button-small" data-confirm="Annullare la prenotazione? I pagamenti già fatti restano registrati: vanno rimborsati a mano.">Annulla prenotazione</button>';
						Ui::form_close();
						echo '<details style="margin-top:6px"><summary>Cedi il posto a un\'altra persona</summary>';
						Ui::form_open( 'asem_transfer_booking', $back );
						echo wp_kses( Ui::hidden( 'activity_id', $id ) . Ui::hidden( 'session_id', $s['id'] ) . Ui::hidden( 'person_id', $b['person_id'] ), Html::allowed() );
						Ui::echo_person_select( '', 'to_person_id', array(), null, '— scegli la nuova persona —', 'asem-tr-' . (int) $s['id'] . '-' . (int) $b['person_id'], ' <button class="button button-small">Cedi il posto</button>', true ); // sempre la ricerca: una tendina per ogni prenotato pesa troppo, e chi ha già il posto lo rifiuta il server
						echo '<p class="description">Il pagamento già fatto passa alla nuova persona; se il suo contributo è maggiore (es. ospite) resta da pagare la differenza.</p>';
						Ui::form_close();
						echo '</details>';
					}
					echo '</td></tr>';
				}
				echo '</tbody></table>';
			}

			if ( ! $cancelled ) {
				$candidates = $lazy ? array() : array_values( array_filter( $all, function ( $p ) use ( $booked ) {
					return ! isset( $booked[ (int) $p['id'] ] );
				} ) );
				Ui::form_open( 'asem_book', $back );
				echo wp_kses( Ui::hidden( 'activity_id', $id ) . Ui::hidden( 'session_id', $s['id'] ), Html::allowed() );
				Ui::echo_person_select( '<p>Prenota: ', 'person_id', $candidates, null, '— scegli socio o ospite —', 'asem-book-' . (int) $s['id'], ' ' . self::booking_fields( $activity, 0 ) . ' <button class="button button-primary">Prenota</button></p>', $lazy );
				Ui::form_close();
				if ( \AssociazioneSemplice\Edition::has( 'door_sales' ) ) {
					self::walk_in_form( $activity, $s, $candidates, $back, $walk_ctx, $lazy );
				}

				Ui::form_open( 'asem_update_session', $back );
				echo wp_kses( Ui::hidden( 'activity_id', $id ) . Ui::hidden( 'session_id', $s['id'] ), Html::allowed() );
				echo '<p class="description">Modifica la data: <input type="date" name="session_date" value="' . esc_attr( $s['session_date'] ) . '"> ore <input type="time" name="start_time" value="' . esc_attr( (string) $s['start_time'] ) . '"> fino al <input type="date" name="end_date" value="' . esc_attr( (string) ( $s['end_date'] ?? '' ) ) . '"> alle <input type="time" name="end_time" value="' . esc_attr( (string) ( $s['end_time'] ?? '' ) ) . '"> <label><input type="checkbox" name="confirm_long" value="1"> Confermo la durata (oltre 7 giorni)</label> '
					. 'luogo <input type="text" name="location" value="' . esc_attr( (string) $s['location'] ) . '"> posti <input type="number" min="1" name="capacity" class="small-text" value="' . esc_attr( null === $cap ? '' : (string) $cap ) . '"> <button class="button button-small">Salva</button></p>';
				Ui::form_close();

				Ui::form_open( 'asem_cancel_session', $back, false, 'asem-confirm' );
				echo wp_kses( Ui::hidden( 'activity_id', $id ) . Ui::hidden( 'session_id', $s['id'] ), Html::allowed() );
				echo '<button class="button button-link-delete" data-confirm="Annullare questa data? Le prenotazioni non contano più; i pagamenti già ricevuti vanno rimborsati a mano.">Annulla solo questa data</button>';
				Ui::form_close();
				if ( ActivityKind::RECURRING === $activity['kind'] ) {
					Ui::form_open( 'asem_cancel_session', $back, false, 'asem-confirm' );
					echo wp_kses( Ui::hidden( 'activity_id', $id ) . Ui::hidden( 'session_id', $s['id'] ) . Ui::hidden( 'scope', 'future' ), Html::allowed() );
					echo '<button class="button button-link-delete" data-confirm="Annullare questa data e tutte le successive? Le date già passate restano; i pagamenti già ricevuti per le date annullate vanno rimborsati a mano.">Annulla questa data e le successive</button>';
					Ui::form_close();
				}
			}
			echo '</details>';
		}
		if ( $block['hidden'] > 0 ) { // le date oltre il blocco: prima le prossime, poi le passate
			echo '<p><a class="button" href="' . esc_url( Ui::url( 'asem-activity', array( 'id' => $id, 'sess_n' => $limit + self::SESSION_BLOCK ) ) ) . '">'
				. esc_html( Texts::t( 'Mostra altre {n} date', array( 'n' => min( self::SESSION_BLOCK, $block['hidden'] ) ) ) ) . '</a> <span class="description">'
				. esc_html( Texts::t( 'Prima le prossime, poi le passate. Ne restano {n} da mostrare.', array( 'n' => $block['hidden'] ) ) ) . '</span></p>';
		}
	}
}
