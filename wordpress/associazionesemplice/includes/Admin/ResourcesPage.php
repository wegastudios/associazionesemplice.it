<?php
namespace AssociazioneSemplice\Admin;

use AssociazioneSemplice\CancelPolicy;
use AssociazioneSemplice\Labels;
use AssociazioneSemplice\Money;
use AssociazioneSemplice\Plugin;
use AssociazioneSemplice\ResourceRules;
use AssociazioneSemplice\Resources;

defined( 'ABSPATH' ) || exit;

/** Risorse prenotabili: elenco e scheda con calendario del giorno, prenotazioni, assegnazioni e blocchi. */
final class ResourcesPage {

	private static function when( array $r ): string {
		$a = (string) $r['starts_at'];
		$b = $r['ends_at'] ? (string) $r['ends_at'] : null;
		if ( ResourceRules::KIND_ASSIGN === $r['kind'] ) {
			return 'dal ' . Ui::date( substr( $a, 0, 10 ) ) . ( $b ? ' al ' . Ui::date( gmdate( 'Y-m-d', strtotime( substr( $b, 0, 10 ) . ' -1 day' ) ) ) : ' (senza scadenza)' );
		}
		if ( $b && substr( $a, 0, 10 ) === substr( $b, 0, 10 ) ) {
			return Ui::date( substr( $a, 0, 10 ) ) . ' ' . substr( $a, 11, 5 ) . '–' . substr( $b, 11, 5 );
		}
		return Ui::date( substr( $a, 0, 10 ) ) . ' ' . substr( $a, 11, 5 ) . ' → ' . ( $b ? Ui::date( substr( $b, 0, 10 ) ) . ' ' . substr( $b, 11, 5 ) : '' );
	}

	private static function who( array $r ): string {
		if ( ResourceRules::KIND_BLOCK === $r['kind'] ) {
			return '<em>Bloccata' . ( $r['note'] ? ': ' . esc_html( (string) $r['note'] ) : '' ) . '</em>';
		}
		if ( 'activity' === $r['kind'] ) { // un corso o un evento collegato alla risorsa
			return '<em>Attività: ' . esc_html( (string) $r['first_name'] ) . '</em>';
		}
		$out = esc_html( trim( $r['first_name'] . ' ' . $r['last_name'] ) );
		if ( ! empty( $r['guests'] ) ) {
			$out .= ' + ' . esc_html( implode( ', ', $r['guests'] ) );
		}
		return $out;
	}

	/** Riga del modulo di un corso o di un evento: la risorsa che occupa. Vuota se non ci sono risorse a fasce. Da chiamare solo se la funzione c'è. */
	public static function activity_row( ?int $activity_id ): string {
		$opts = array();
		foreach ( Resources::all() as $r ) {
			if ( ResourceRules::SLOT === $r['mode'] ) {
				$opts[ $r['id'] ] = $r['name'];
			}
		}
		if ( ! $opts ) {
			return '';
		}
		$selected = $activity_id ? Resources::activity_resource( $activity_id ) : null;
		return '<tr><th>Risorsa occupata</th><td><select name="resource_id">' . Ui::options( $opts, $selected, '— nessuna —' ) . '</select>'
			. '<p class="description">Se si tiene in un campo o in una sala delle <a href="' . esc_url( Ui::url( 'asem-resources' ) ) . '">Risorse</a>, la occupa nelle sue date e nessuno la prenota negli stessi orari.</p></td></tr>';
	}

	// ---------- Elenco ----------

	public static function render_list(): void {
		Ui::header( 'Risorse prenotabili', '<a class="page-title-action" href="' . esc_url( Ui::url( 'asem-resource' ) ) . '">Nuova risorsa</a>' );
		echo '<p class="description">Campi, sale, posti auto, armadietti, posti barca: ogni risorsa è esclusiva (o è libera o è occupata). Prenotano e ricevono risorse solo i soci con la tessera valida.</p>';
		$all = Resources::all( true );
		if ( ! $all ) {
			echo '<p>Nessuna risorsa. <a href="' . esc_url( Ui::url( 'asem-resource' ) ) . '">Crea la prima.</a></p>';
		} else {
			echo '<table class="widefat striped"><thead><tr><th>Nome</th><th>Tipo</th><th>Prenotazione</th><th>Tariffa</th><th>Ospiti</th><th>Prossime</th></tr></thead><tbody>';
			foreach ( $all as $r ) {
				$next = Resources::upcoming( (int) $r['id'], 50 );
				echo '<tr><td><a href="' . esc_url( Ui::url( 'asem-resource', array( 'id' => $r['id'] ) ) ) . '"><strong>' . esc_html( $r['name'] ) . '</strong></a>' . ( (int) $r['active'] ? '' : ' <span class="description">(non prenotabile)</span>' ) . '</td>'
					. '<td>' . esc_html( ResourceRules::categories()[ $r['category'] ] ?? $r['category'] ) . '</td>'
					. '<td>' . ( ResourceRules::SLOT === $r['mode'] ? 'A fasce di ' . (int) $r['slot_minutes'] . ' min' : 'Assegnazione' ) . '</td>'
					. '<td>' . ( (int) $r['fee_cents'] > 0 ? Ui::money( (int) $r['fee_cents'] ) . ( ResourceRules::SLOT === $r['mode'] ? ' a fascia' : ' a periodo' ) : 'gratuita' ) . '</td>' // phpcs:ignore WordPress.Security.EscapeOutput
					. '<td>' . ( (int) $r['guests_max'] > 0 ? 'fino a ' . (int) $r['guests_max'] : 'no' ) . '</td>'
					. '<td>' . count( $next ) . '</td></tr>';
			}
			echo '</tbody></table>';
		}
		$review = Resources::to_review();
		if ( $review ) {
			echo '<div class="asem-card"><h2>Assegnazioni da verificare</h2><p class="description">Il titolare non ha più la tessera valida: rinnovala oppure rilascia la risorsa.</p><ul style="list-style:disc;margin-left:20px">';
			foreach ( $review as $r ) {
				echo '<li><a href="' . esc_url( Ui::url( 'asem-resource', array( 'id' => $r['resource_id'] ) ) ) . '">' . esc_html( $r['resource_name'] ) . '</a> — ' . esc_html( trim( $r['first_name'] . ' ' . $r['last_name'] ) ) . '</li>';
			}
			echo '</ul></div>';
		}
		Ui::footer();
	}

	// ---------- Scheda ----------

	public static function render_detail(): void {
		$id  = Ui::get_int( 'id' );
		$res = $id ? Resources::get( $id ) : null;
		if ( $id && ! $res ) {
			Ui::header( 'Risorsa non trovata' );
			echo '<p><a href="' . esc_url( Ui::url( 'asem-resources' ) ) . '">← Torna all\'elenco</a></p>';
			Ui::footer();
			return;
		}
		Ui::header( $res ? (string) $res['name'] : 'Nuova risorsa' );
		echo '<p><a href="' . esc_url( Ui::url( 'asem-resources' ) ) . '">← Tutte le risorse</a></p>';
		$back = Ui::url( 'asem-resource', $id ? array( 'id' => $id ) : array() );
		if ( $res ) {
			self::operations( $res, $back );
		}
		self::form( $res, $back );
		Ui::footer();
	}

	private static function people(): array {
		return array_values(
			array_filter(
				Plugin::people()->search(),
				function ( $p ) {
					return \AssociazioneSemplice\MemberType::is_member( $p['type'] );
				}
			)
		);
	}

	private static function operations( array $res, string $back ): void {
		$rid = (int) $res['id'];
		if ( ResourceRules::SLOT === $res['mode'] ) {
			$day = Ui::get_str( 'd', current_time( 'Y-m-d' ) );
			if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $day ) ) {
				$day = current_time( 'Y-m-d' );
			}
			$prev = gmdate( 'Y-m-d', strtotime( $day . ' -1 day' ) );
			$next = gmdate( 'Y-m-d', strtotime( $day . ' +1 day' ) );
			echo '<div class="asem-card"><h2>Calendario del giorno</h2><p><a class="button" href="' . esc_url( Ui::url( 'asem-resource', array( 'id' => $rid, 'd' => $prev ) ) ) . '">‹</a> <strong style="margin:0 12px">' . esc_html( Ui::date( $day ) ) . '</strong> '
				. '<a class="button" href="' . esc_url( Ui::url( 'asem-resource', array( 'id' => $rid, 'd' => $next ) ) ) . '">›</a> <a class="button" href="' . esc_url( Ui::url( 'asem-resource', array( 'id' => $rid ) ) ) . '">Oggi</a></p>';
			echo '<table class="widefat striped" style="max-width:720px"><thead><tr><th>Fascia</th><th>Occupata da</th></tr></thead><tbody>';
			foreach ( Resources::day_grid( $res, $day ) as $s ) {
				echo '<tr><td>' . esc_html( $s['start'] . '–' . $s['end'] ) . '</td><td>' . ( $s['reservation'] ? self::who( $s['reservation'] ) : '<span class="description">libera</span>' ) . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput
			}
			echo '</tbody></table></div>';

			echo '<div class="asem-card"><h2>Prenota per un socio</h2>';
			Ui::form_open( 'asem_res_book', $back );
			echo Ui::hidden( 'resource_id', $rid ); // phpcs:ignore WordPress.Security.EscapeOutput
			echo '<table class="form-table"><tr><th>Socio</th><td>' . Ui::person_select( 'person_id', self::people(), null, '— scegli —', 'asem-res-person' ) . '</td></tr>' // phpcs:ignore WordPress.Security.EscapeOutput
				. '<tr><th>Data</th><td><input type="date" name="date" value="' . esc_attr( $day ) . '" required></td></tr>'
				. '<tr><th>Inizio</th><td><select name="start">';
			foreach ( ResourceRules::day_slots( $res ) as $s ) {
				echo '<option value="' . esc_attr( $s['start'] ) . '">' . esc_html( $s['start'] ) . '</option>';
			}
			echo '</select> per <input type="number" name="slots" min="1" max="' . (int) $res['max_slots'] . '" value="' . (int) $res['min_slots'] . '" style="width:60px"> fasce</td></tr>';
			if ( (int) $res['guests_max'] > 0 ) {
				echo '<tr><th>Ospiti</th><td><textarea name="guests" rows="3" cols="40" placeholder="Un nome e cognome per riga (al massimo ' . (int) $res['guests_max'] . ')"></textarea></td></tr>';
			}
			echo '<tr><th>Ripetizione</th><td>per <input type="number" name="weeks" min="1" max="52" value="1" style="width:60px"> settimane, alla stessa ora <span class="description">1 = una volta sola. Con più settimane si prenotano tutte le date o nessuna.</span></td></tr>'
				. '<tr><th>Nota</th><td><input type="text" name="note" class="regular-text" maxlength="255"></td></tr></table>'
				. '<p><button class="button button-primary">Prenota</button> <span class="description">La segreteria può registrare anche nel passato e oltre il limite di anticipo.</span></p>';
			Ui::form_close();
			echo '</div>';

			echo '<div class="asem-card"><h2>Blocca la risorsa</h2><p class="description">Manutenzione, chiusura, torneo: nel periodo nessuno può prenotare.</p>';
			Ui::form_open( 'asem_res_block', $back );
			echo Ui::hidden( 'resource_id', $rid ) // phpcs:ignore WordPress.Security.EscapeOutput
				. '<p>Dal <input type="datetime-local" name="starts" required> al <input type="datetime-local" name="ends" required> · nota <input type="text" name="note" maxlength="255"> <button class="button">Blocca</button></p>';
			Ui::form_close();
			echo '</div>';
		} else {
			echo '<div class="asem-card"><h2>Assegna a un socio</h2>';
			Ui::form_open( 'asem_res_assign', $back );
			echo Ui::hidden( 'resource_id', $rid ); // phpcs:ignore WordPress.Security.EscapeOutput
			echo '<table class="form-table"><tr><th>Socio</th><td>' . Ui::person_select( 'person_id', self::people(), null, '— scegli —', 'asem-res-person' ) . '</td></tr>' // phpcs:ignore WordPress.Security.EscapeOutput
				. '<tr><th>Dal</th><td><input type="date" name="from" value="' . esc_attr( current_time( 'Y-m-d' ) ) . '" required></td></tr>'
				. '<tr><th>Fino al</th><td><input type="date" name="to"> <span class="description">vuoto = senza scadenza (l\'ultimo giorno è compreso)</span></td></tr>'
				. '<tr><th>Nota</th><td><input type="text" name="note" class="regular-text" maxlength="255"></td></tr></table>'
				. '<p><button class="button button-primary">Assegna</button></p>';
			Ui::form_close();
			echo '</div>';

			$queue = Resources::waitlist( $rid );
			echo '<div class="asem-card"><h2>Lista d\'attesa</h2>';
			if ( ! $queue ) {
				echo '<p class="description">Nessuno in coda. I soci si mettono in lista dalla loro area quando la risorsa è occupata; quando si libera il primo riceve un\'email e l\'assegnazione resta alla segreteria.</p>';
			} else {
				echo '<table class="widefat striped" style="max-width:720px"><thead><tr><th>#</th><th>Socio</th><th>Dal</th><th></th></tr></thead><tbody>';
				foreach ( $queue as $i => $w ) {
					$who = trim( $w['first_name'] . ' ' . $w['last_name'] );
					echo '<tr><td>' . (int) ( $i + 1 ) . '</td><td>' . esc_html( $who ) . '</td><td>' . esc_html( Ui::date( substr( (string) $w['created_at'], 0, 10 ) ) ) . '</td><td>'
						. '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline"><input type="hidden" name="action" value="asem_res_assign"><input type="hidden" name="_back" value="' . esc_url( $back ) . '">'
						. wp_nonce_field( 'asem_res_assign', '_wpnonce', true, false ) . Ui::hidden( 'resource_id', $rid ) . Ui::hidden( 'person_id', $w['person_id'] ) . Ui::hidden( 'from', current_time( 'Y-m-d' ) ) // phpcs:ignore WordPress.Security.EscapeOutput
						. '<button class="button button-small">Assegna a ' . esc_html( (string) $w['first_name'] ) . '</button></form> '
						. Ui::confirm_button( 'asem_res_wait_remove', $back, array( 'resource_id' => $rid, 'person_id' => $w['person_id'] ), 'Togli', 'Togliere dalla lista?' ) . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput
				}
				echo '</tbody></table>';
			}
			echo '</div>';
		}

		$rows = Resources::upcoming( $rid );
		echo '<div class="asem-card"><h2>' . ( ResourceRules::SLOT === $res['mode'] ? 'Prossime prenotazioni' : 'Assegnazioni in corso' ) . '</h2>';
		if ( ! $rows ) {
			echo '<p class="description">Nessuna.</p></div>';
			return;
		}
		$accounts = Plugin::ledger()->accounts();
		$amap     = array();
		foreach ( $accounts as $a ) {
			$amap[ $a['id'] ] = $a['name'];
		}
		echo '<table class="widefat striped"><thead><tr><th>Quando</th><th>Chi</th><th>Dovuto</th><th></th></tr></thead><tbody>';
		foreach ( $rows as $r ) {
			echo '<tr><td>' . esc_html( self::when( $r ) ) . '</td><td>' . self::who( $r ) . '</td><td>'; // phpcs:ignore WordPress.Security.EscapeOutput
			if ( ResourceRules::KIND_BLOCK !== $r['kind'] && (int) $r['fee_due_cents'] > 0 ) {
				echo Ui::booking_state( $r ) . ( ! empty( $r['hold_until'] ) ? ' <span class="description">riservata fino alle ' . esc_html( substr( (string) $r['hold_until'], 11, 5 ) ) . ' in attesa del pagamento online</span>' : '' ); // phpcs:ignore WordPress.Security.EscapeOutput
				if ( $r['remaining'] > 0 && $amap ) {
					echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="margin-top:4px"><input type="hidden" name="action" value="asem_res_pay"><input type="hidden" name="_back" value="' . esc_url( $back ) . '">'
						. wp_nonce_field( 'asem_res_pay', '_wpnonce', true, false ) . Ui::hidden( 'id', $r['id'] ) // phpcs:ignore WordPress.Security.EscapeOutput
						. '<input type="text" name="amount" size="6" value="' . esc_attr( Money::plain( (int) $r['remaining'] ) ) . '"> <select name="method">' . Ui::options( Labels::methods(), 'cash' ) . '</select> '
						. '<select name="account_id">' . Ui::options( $amap ) . '</select> <button class="button button-small">Incassa</button></form>';
				}
			}
			echo '</td><td>' . Ui::confirm_button( 'asem_res_cancel', $back, array( 'id' => $r['id'] ), ResourceRules::KIND_ASSIGN === $r['kind'] ? 'Rilascia' : ( ResourceRules::KIND_BLOCK === $r['kind'] ? 'Togli blocco' : 'Annulla' ), 'Confermi?' ) // phpcs:ignore WordPress.Security.EscapeOutput
				. ( ! empty( $r['series_id'] ) ? ' ' . Ui::confirm_button( 'asem_res_cancel', $back, array( 'id' => $r['id'], 'series' => 1 ), 'Annulla anche le successive', 'Annullo questa prenotazione e tutte le successive della serie. Confermi?' ) : '' ) . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput
		}
		echo '</tbody></table></div>';
	}

	/**
	 * Riga del modulo: la quota di ogni pagamento accantonata in un fondo. Serve per le risorse date in gestione a terzi (la quota è del gestore)
	 * o i cui incassi servono per altro. Solo con la funzione dei fondi.
	 */
	private static function fund_row( ?array $res ): string {
		if ( ! \AssociazioneSemplice\Edition::has( 'funds' ) ) {
			return '';
		}
		$mode  = $res ? (string) $res['fund_mode'] : '';
		$value = $res ? (int) $res['fund_value'] : 0;
		$shown = \AssociazioneSemplice\FundShare::PERCENT === $mode ? rtrim( rtrim( number_format( $value / 100, 2, ',', '' ), '0' ), ',' ) : ( $value ? Money::plain( $value ) : '' );
		$funds = array( 0 => '— scegli il fondo —', 'new' => '+ Nuovo fondo' . ( $res ? ' «' . $res['name'] . '»' : ' con il nome della risorsa' ) );
		foreach ( Plugin::funds()->all() as $f ) {
			$funds[ $f['id'] ] = $f['name'] . ' (' . Money::format( (int) $f['balance'] ) . ')';
		}
		return '<tr><th>Fondo</th><td><select name="fund_mode">' . Ui::options( \AssociazioneSemplice\FundShare::modes(), $mode ) . '</select> '
			. '<input type="text" name="fund_value" class="small-text" inputmode="decimal" value="' . esc_attr( $shown ) . '"> <span class="description">€ se importo fisso, % se percentuale</span><br>'
			. 'nel fondo <select name="fund_id">' . Ui::options( $funds, $res && ! empty( $res['fund_id'] ) ? (int) $res['fund_id'] : 0 ) . '</select>'
			. '<p class="description">Utile per le risorse date in gestione a terzi (la quota è del gestore) o i cui incassi servono a pagare altro. Una parte di ogni pagamento ricevuto (anche in crediti) va nel fondo scelto: l\'incasso resta in cassa o sul conto usato, la quota è accantonata e si sottrae dalla disponibilità reale finché il fondo non si salda o non si libera dalla pagina <em>Conti e fondi</em>. Annullando un incasso sparisce anche la sua quota.</p></td></tr>';
	}

	private static function form( ?array $res, string $back ): void {
		$v = function ( string $k, $default = '' ) use ( $res ) {
			return $res ? $res[ $k ] : $default;
		};
		echo '<div class="asem-card"><h2>' . ( $res ? 'Dati della risorsa' : 'Nuova risorsa' ) . '</h2>';
		Ui::form_open( 'asem_resource_save', $back );
		echo Ui::hidden( 'id', $res ? (int) $res['id'] : 0 ); // phpcs:ignore WordPress.Security.EscapeOutput
		echo '<table class="form-table">'
			. '<tr><th>Nome</th><td><input type="text" name="name" class="regular-text" maxlength="160" required value="' . esc_attr( (string) $v( 'name' ) ) . '"></td></tr>'
			. '<tr><th>Tipo</th><td><select name="category">' . Ui::options( ResourceRules::categories(), $v( 'category', 'court' ) ) . '</select></td></tr>'
			. '<tr><th>Prenotazione</th><td><select name="mode">' . Ui::options( ResourceRules::modes(), $v( 'mode', ResourceRules::SLOT ) ) . '</select></td></tr>'
			. '<tr><th>Descrizione</th><td><textarea name="description" rows="2" cols="60">' . esc_textarea( (string) $v( 'description' ) ) . '</textarea></td></tr>'
			. '<tr><th>Regole d\'uso</th><td><textarea name="rules" rows="2" cols="60">' . esc_textarea( (string) $v( 'rules' ) ) . '</textarea><br><span class="description">Si mostrano ai soci prima di prenotare.</span></td></tr>'
			. '<tr><th>Tariffa</th><td><input type="text" name="fee" size="8" value="' . esc_attr( Money::plain( (int) $v( 'fee_cents', 0 ) ) ) . '"> € <span class="description">per fascia (risorse a fasce) o per periodo (assegnazioni). 0 = gratuita.</span></td></tr>'
			. '<tr><th>Tariffe a fasce</th><td><textarea name="rates_text" rows="3" cols="40" placeholder="feriali 18:00-22:00 15,00&#10;weekend 08:00-22:00 20,00">' . esc_textarea( ResourceRules::rates_text( ResourceRules::clean_rates( $v( 'rates', '' ) ) ) ) . '</textarea><br><span class="description">Facoltative, solo per le risorse a fasce: una per riga, «giorni ore importo» (giorni: <em>tutti</em>, <em>feriali</em> lun–ven, <em>weekend</em> sab–dom). Vale la prima riga che corrisponde all\'ora di inizio di ogni fascia; fuori dalle righe vale la tariffa base.</span></td></tr>'
			. '<tr><th>Pagamento</th><td><select name="pay_mode">' . Ui::options( ResourceRules::pay_modes(), $v( 'pay_mode', ResourceRules::PAY_DESK ) ) . '</select><br><span class="description">Chi prenota online con pagamento in anticipo tiene la fascia riservata solo ' . (int) ResourceRules::HOLD_MINUTES . ' minuti: se il pagamento non arriva si libera. Non ci sono tolleranze di giorni. La segreteria registra sempre le prenotazioni e gli incassi a mano. Il pagamento online richiede i pagamenti online attivi.</span></td></tr>'
			. ( \AssociazioneSemplice\Edition::has( 'credits' ) ? '<tr><th>Crediti</th><td><input type="number" name="credit_cost" min="0" style="width:80px" value="' . (int) $v( 'credit_cost', 0 ) . '"> crediti ' . ( ResourceRules::ASSIGN === $v( 'mode', ResourceRules::SLOT ) ? 'per periodo' : 'per fascia' ) . '<br><span class="description">0 = non si paga in crediti. Con un costo in crediti il socio può pagare subito con i suoi crediti, oltre alle vie scelte sopra; annullando la prenotazione i crediti tornano al socio. Vale anche per gli ospiti ammessi: non c\'è un supplemento in crediti.</span></td></tr>' : '' )
			. self::fund_row( $res )
			. '<tr><th>Ospiti</th><td>fino a <input type="number" name="guests_max" min="0" max="50" style="width:60px" value="' . (int) $v( 'guests_max', 0 ) . '"> per prenotazione, supplemento <input type="text" name="guest_fee" size="6" value="' . esc_attr( Money::plain( (int) $v( 'guest_fee_cents', 0 ) ) ) . '"> € ciascuno<br><span class="description">0 = nessun ospite (posti auto, armadietti, posti barca). Gli ospiti sono solo un nome: non entrano in anagrafe.</span></td></tr>'
			. '<tr><th>Fasce orarie</th><td>aperta dalle <input type="time" name="open_time" value="' . esc_attr( (string) $v( 'open_time', '08:00' ) ) . '"> alle <input type="time" name="close_time" value="' . esc_attr( (string) $v( 'close_time', '22:00' ) ) . '"> · fascia di <input type="number" name="slot_minutes" min="5" max="720" style="width:70px" value="' . (int) $v( 'slot_minutes', 60 ) . '"> minuti<br>'
			. 'da <input type="number" name="min_slots" min="1" style="width:60px" value="' . (int) $v( 'min_slots', 1 ) . '"> a <input type="number" name="max_slots" min="1" style="width:60px" value="' . (int) $v( 'max_slots', 1 ) . '"> fasce di seguito · si prenota al massimo <input type="number" name="advance_days" min="0" style="width:70px" value="' . (int) $v( 'advance_days', 0 ) . '"> giorni prima (0 = senza limite)<br><span class="description">Solo per le risorse a fasce.</span></td></tr>'
			. '<tr><th>Limite per socio</th><td><input type="number" name="max_per_person" min="0" style="width:60px" value="' . (int) $v( 'max_per_person', 0 ) . '"> prenotazioni future insieme (0 = senza limite)</td></tr>'
			. '<tr><th>Annullamento</th><td><label><input type="checkbox" name="cancellable" value="1"' . checked( (int) $v( 'cancellable', 0 ), 1, false ) . '> il socio può annullare una prenotazione a pagamento</label> entro <select name="cancel_policy">' . Ui::options( CancelPolicy::labels(), $v( 'cancel_policy', '' ), 'predefinito' ) . '</select><br><span class="description">Le prenotazioni gratuite si annullano finché non iniziano.</span></td></tr>'
			. '<tr><th>Stato</th><td><label><input type="checkbox" name="active" value="1"' . checked( (int) $v( 'active', 1 ), 1, false ) . '> prenotabile dai soci</label></td></tr>'
			. '</table><p><button class="button button-primary">Salva</button></p>';
		Ui::form_close();
		if ( $res ) {
			echo '<p>' . Ui::confirm_button( 'asem_resource_delete', Ui::url( 'asem-resources' ), array( 'id' => $res['id'] ), 'Elimina la risorsa', 'Con prenotazioni future la risorsa viene solo disattivata. Confermi?' ) . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput
		}
		echo '</div>';
	}
}
