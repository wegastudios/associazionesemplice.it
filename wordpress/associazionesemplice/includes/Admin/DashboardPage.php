<?php
namespace AssociazioneSemplice\Admin;

use AssociazioneSemplice\MemberType;
use AssociazioneSemplice\Money;
use AssociazioneSemplice\Plugin;
use AssociazioneSemplice\Settings;
use AssociazioneSemplice\Html;

defined( 'ABSPATH' ) || exit;

final class DashboardPage {

	public static function render(): void {
		$balances = Plugin::ledger()->balances();
		$funds    = Plugin::funds()->all();
		$avail    = Plugin::funds()->available();
		$year     = Settings::social_year();
		$r        = Plugin::reports()->social_year( $year );
		$name     = (string) Settings::get( 'association_name' );

		Ui::header( 'Associazione Semplice' . ( $name ? ' — ' . $name : '' ) );
		echo '<img class="asem-logo" src="' . esc_url( ASEM_URL . 'assets/brand/logo-orizzontale.png' ) . '" width="150" height="55" alt="Associazione Semplice">';
		echo '<p>'
			. '<a class="button button-primary" href="' . esc_url( Ui::url( 'asem-income' ) ) . '">Nuovo incasso</a> '
			. ( \AssociazioneSemplice\Edition::has( 'funds' ) ? '<a class="button" href="' . esc_url( Ui::url( 'asem-group' ) ) . '">Cassa per più persone</a> ' : '' )
			. '<a class="button" href="' . esc_url( Ui::url( 'asem-expense' ) ) . '">Nuova spesa</a> '
			. '<a class="button" href="' . esc_url( Ui::url( 'asem-person', array( 'type' => 'ordinary' ) ) ) . '">Nuovo socio</a>' . ( \AssociazioneSemplice\Modules::on( 'activities' ) ? ' <a class="button" href="' . esc_url( Ui::url( 'asem-calendar' ) ) . '">Calendario</a>' : '' ) . '</p>';
		echo '<p class="description">Il tuo account su associazionesemplice.it: <a href="' . esc_url( (string) apply_filters( 'asem_portal_account_url', 'https://associazionesemplice.it/account' ) ) . '" target="_blank" rel="noopener">Area riservata</a> (licenza, dominio, pagamenti, download) · <a href="' . esc_url( (string) apply_filters( 'asem_portal_support_url', 'https://associazionesemplice.it/supporto/accedi' ) ) . '" target="_blank" rel="noopener">Supporto</a></p>';

		echo '<div class="asem-grid"><div class="asem-card"><h2>Disponibilità reale</h2>';
		echo wp_kses( '<p class="asem-big">' . Ui::money( $avail['available'] ) . '</p><p class="description">Saldi dei conti meno i fondi accantonati per i rimborsi.</p><table class="asem-kv">', Html::allowed() );
		foreach ( $balances as $b ) {
			echo wp_kses( '<tr><td>' . esc_html( $b['name'] ) . '</td><td>' . Ui::money( $b['balance'] ) . '</td></tr>', Html::allowed() );
		}
		echo wp_kses( '<tr><td><strong>Totale saldi</strong></td><td><strong>' . Ui::money( $avail['accounts'] ) . '</strong></td></tr>', Html::allowed() );
		foreach ( $funds as $f ) {
			echo wp_kses( '<tr><td>− ' . esc_html( $f['name'] ) . '</td><td>' . Ui::money( $f['balance'] ) . '</td></tr>', Html::allowed() );
		}
		echo wp_kses( '</table>' . ( \AssociazioneSemplice\Edition::has( 'funds' ) ? '<p><a href="' . esc_url( Ui::url( 'asem-accounts' ) ) . '">Conti, fondi e verifica saldi →</a></p>' : '' ) . '</div>', Html::allowed() );

		echo '<div class="asem-card"><h2>Anno sociale ' . esc_html( $year->label() ) . '</h2><table class="asem-kv">';
		foreach ( MemberType::member_types() as $t ) {
			echo '<tr><td>' . esc_html( MemberType::label( $t ) ) . '</td><td>' . (int) ( $r['members_by_type'][ $t ] ?? 0 ) . '</td></tr>';
		}
		echo wp_kses( '<tr><td>Entrate</td><td>' . Ui::money( $r['total_income'] ) . '</td></tr><tr><td>Uscite</td><td>' . Ui::money( $r['total_expense'] ) . '</td></tr>'
			. '<tr><td><strong>Resta all\'associazione</strong></td><td><strong>' . Ui::money( $r['result'] ) . '</strong></td></tr></table></div></div>', Html::allowed() );

		if ( current_user_can( \AssociazioneSemplice\Plugin::CAP ) ) { // promemoria della copia di sicurezza (solo amministratori)
			$bk = \AssociazioneSemplice\Backup::last();
			if ( ! $bk || time() - $bk > 30 * DAY_IN_SECONDS ) {
				echo wp_kses( Dismiss::html( 'backup', 'warning', ( $bk ? 'L\'ultima copia di sicurezza dei dati risale a più di 30 giorni fa.' : 'Non hai ancora scaricato una copia di sicurezza dei dati.' ) . ' <a href="' . esc_url( Ui::url( 'asem-backup' ) ) . '">Scaricala ora</a>.', 14 ), Html::allowed() );
			}
		}
		$mail_fail = current_user_can( \AssociazioneSemplice\Plugin::CAP ) ? \AssociazioneSemplice\MailLog::failures() : 0;
		if ( $mail_fail > 0 ) {
			echo wp_kses( Dismiss::html( 'mail-fail-' . $mail_fail, 'warning', '<strong>Alcune email non sono partite</strong> negli ultimi giorni (' . (int) $mail_fail . '). <a href="' . esc_url( Ui::url( 'asem-tech' ) ) . '">Vedi perché e prova l\'invio</a>.', 3 ), Html::allowed() );
		}
		if ( current_user_can( \AssociazioneSemplice\Plugin::CAP ) && '' === trim( (string) \AssociazioneSemplice\Settings::get( 'association_name' ) ) ) {
			echo wp_kses( Dismiss::html( 'entity-name', 'info', '<strong>Manca il nome dell\'ente.</strong> Compare su ricevute, tessere e messaggi ai soci: inseriscilo in <a href="' . esc_url( Ui::url( 'asem-entity' ) ) . '">Impostazioni → Dati e fiscalità</a>.', 7 ), Html::allowed() );
		}
		if ( current_user_can( \AssociazioneSemplice\Plugin::CAP ) && \AssociazioneSemplice\Wizard::pending() ) {
			echo wp_kses( Dismiss::html( 'wizard', 'info', '<strong>Benvenuto.</strong> Configura il plugin in pochi minuti: ente, quote, pagamenti e pagine del sito. <a class="button button-primary" href="' . esc_url( Ui::url( 'asem-wizard' ) ) . '">Avvia la configurazione guidata</a>', 3 ), Html::allowed() );
		}
		if ( \AssociazioneSemplice\Guide::show_banner( get_current_user_id() ) ) {
			$gp = \AssociazioneSemplice\Guide::progress();
			echo wp_kses( Dismiss::html( 'guide', 'info', 'Configurazione iniziale: ' . (int) $gp['done'] . ' passi su ' . (int) $gp['total'] . '. <a href="' . esc_url( Ui::url( 'asem-guide' ) ) . '">Apri la guida</a>.', 7 ), Html::allowed() );
		}
		do_action( 'asem_dashboard_alerts' ); // le estensioni aggiungono i loro avvisi
		self::quick_cash();
		self::quick_enroll();
		self::payments_due();
		self::renewals();
		self::expected_guests();

		self::applications();
		self::adults_to_invite();
		self::overdue_loans();
		ConventionsPage::dashboard_card();

		$requests = \AssociazioneSemplice\AccessRequests::pending();
		if ( $requests ) {
			echo '<div class="asem-card"><h2>Richieste di accesso (' . count( $requests ) . ')</h2><p class="description">Dal "Primo accesso" del sito: chi non è stato riconosciuto, chi chiede di cambiare email e chi si è attivato col solo cellulare.</p><ul>';
			foreach ( $requests as $rq ) {
				$wa     = \AssociazioneSemplice\Phone::whatsapp( (string) $rq['phone'] );
				$who    = $rq['person'] ? $rq['person']['first_name'] . ' ' . $rq['person']['last_name'] : (string) $rq['name'];
				$label  = array( 'unknown' => 'non riconosciuto', 'change' => 'chiede di cambiare email', 'review' => 'attivato col cellulare: controlla' );
				if ( 'change' === $rq['kind'] && $rq['person'] && empty( $rq['person']['wp_user_id'] ) ) {
					$label['change'] = 'chiede l\'accesso col cellulare: verifica la persona prima di approvare';
				}
				echo '<li>' . ( $rq['person'] ? '<a href="' . esc_url( Ui::url( 'asem-person', array( 'id' => $rq['person']['id'] ) ) ) . '">' . esc_html( $who ) . '</a>' : '<strong>' . esc_html( $who ) . '</strong>' )
					. ' <span class="description">' . esc_html( $label[ $rq['kind'] ] ) . ' · ' . esc_html( (string) $rq['email'] ) . ' · ' . esc_html( (string) $rq['phone'] ) . ' · ' . esc_html( mysql2date( 'd/m H:i', gmdate( 'Y-m-d H:i:s', (int) $rq['at'] ) ) ) . '</span> ';
				if ( 'unknown' === $rq['kind'] && '' !== $wa ) {
					$text = 'Ciao ' . $who . ', abbiamo ricevuto la tua richiesta di primo accesso. Per attivare l\'accesso confermaci nome, cognome ed email con cui sei iscritto/a.';
					echo '<a class="button button-small" target="_blank" rel="noopener" href="' . esc_url( 'https://wa.me/' . $wa . '?text=' . rawurlencode( $text ) ) . '">💬 Scrivi su WhatsApp</a> ';
				}
				if ( 'change' === $rq['kind'] ) {
					Ui::form_open( 'asem_access_approve', Ui::url( 'asem' ), false, 'asem-inline' );
					echo wp_kses( Ui::hidden( 'id', $rq['id'] ) . '<button class="button button-small">Approva e manda il link</button>', Html::allowed() );
					Ui::form_close();
					echo ' ';
				}
				Ui::form_open( 'asem_access_done', Ui::url( 'asem' ), false, 'asem-inline' );
				echo wp_kses( Ui::hidden( 'id', $rq['id'] ) . '<button class="button-link">' . ( 'change' === $rq['kind'] ? 'rifiuta' : 'fatto' ) . '</button>', Html::allowed() );
				Ui::form_close();
				echo '</li>';
			}
			echo '</ul></div>';
		}

		$at_limit = \AssociazioneSemplice\Plugin::people()->guests_to_invite();
		if ( $at_limit ) {
			echo '<div class="asem-card"><h2>Ospiti da invitare a iscriversi (' . count( $at_limit ) . ')</h2><p class="description">Hanno raggiunto la soglia di partecipazioni per i non soci (sommando le registrazioni dello stesso cellulare, email o nome). Nessun blocco: decidi tu.</p><ul>';
			foreach ( array_slice( $at_limit, 0, 10 ) as $g ) {
				$ov = $g['overview'];
				echo '<li><a href="' . esc_url( Ui::url( 'asem-person', array( 'id' => $g['id'] ) ) ) . '">' . esc_html( $g['first_name'] . ' ' . $g['last_name'] ) . '</a> <span class="description">ospite di ' . esc_html( (string) $g['host_name'] ) . ' · ' . (int) $ov['total'] . ' partecipazioni'
					. ( $ov['twins'] ? ' (registrato più volte)' : '' ) . '</span></li>';
			}
			echo '</ul><p><a href="' . esc_url( Ui::url( 'asem-people', array( 'type' => 'guest', 'at_limit' => 1 ) ) ) . '">Vedi tutti →</a></p></div>';
		}

		if ( ! empty( $r['activities'] ) ) {
			echo '<h2>Attività</h2><div class="asem-grid">';
			foreach ( $r['activities'] as $a ) {
				echo wp_kses( '<div class="asem-card"><h3><a href="' . esc_url( Ui::url( 'asem-activity', array( 'id' => $a['activity']['id'] ) ) ) . '">' . esc_html( $a['activity']['name'] ) . '</a></h3>'
					. '<p>' . (int) $a['participants'] . ' iscritti · resta ' . Ui::money( $a['margin'] ) . '</p></div>', Html::allowed() );
			}
			echo '</div>';
		}
		Ui::footer();
	}

	/** Domande di adesione dal sito: Approva (si assegna la tessera, si propone la successiva) o Rifiuta. */
	private static function applications(): void {
		$apps = \AssociazioneSemplice\Applications::pending();
		if ( ! $apps ) {
			return;
		}
		echo '<div class="asem-card" id="asem-applications"><h2>Domande di adesione (' . count( $apps ) . ')</h2><p class="description">Dal modulo «Diventa socio» del sito. Approvando assegni il numero di tessera: il nuovo socio riceve il link per scegliere la password (per un minore, il genitore). Rifiutando, la domanda sparisce; una quota pagata online resta «da rimborsare».</p><ul>';
		foreach ( $apps as $a ) {
			$age   = \AssociazioneSemplice\Age::years( $a['birth_date'] ?? null, \AssociazioneSemplice\Db::today() );
			$minor = ! empty( $a['guardian'] );
			echo '<li style="margin-bottom:14px"><strong>' . esc_html( $a['first_name'] . ' ' . $a['last_name'] ) . '</strong> <span class="description">' . esc_html( MemberType::label( $a['type'] ) )
				. ' · nato/a il ' . esc_html( \AssociazioneSemplice\Age::display( $a['birth_date'] ?? null ) ) . ( null !== $age ? ' (' . (int) $age . ' anni)' : '' )
				. ( ! empty( $a['email'] ) ? ' · ' . esc_html( (string) $a['email'] ) : '' ) . ( ! empty( $a['phone'] ) ? ' · ' . esc_html( (string) $a['phone'] ) : '' )
				. ' · ' . esc_html( mysql2date( 'd/m H:i', (string) $a['application_at'] ) ) . '</span>';
			if ( $minor ) {
				$g = $a['guardian'];
				echo '<br><span class="description">Genitore/tutore: <strong>' . esc_html( trim( $g['first_name'] . ' ' . $g['last_name'] ) ) . '</strong> · ' . esc_html( (string) $g['email'] ) . ' · ' . esc_html( (string) $g['phone'] ) . ' · ha accettato regolamento e privacy a nome del minore</span>';
			}
			if ( $a['fee_cents'] > 0 ) {
				echo wp_kses( '<br><span class="description">Quota ' . Ui::money( (int) $a['fee_cents'] ) . ' — ' . ( $a['paid'] ? '<strong>pagata online</strong> (' . Ui::money( (int) $a['paid']['cents'] ) . ')' : 'da pagare in sede' ) . '</span>', Html::allowed() );
			}
			echo '<br>';
			Ui::form_open( 'asem_application_approve', Ui::url( 'asem' ), false, 'asem-inline' );
			echo wp_kses( Ui::hidden( 'id', $a['id'] ) . '<label>Tessera n. <input type="text" name="card_number" value="' . esc_attr( (string) $a['suggested_card'] ) . '" size="8"></label> <button class="button button-primary button-small">Approva</button>', Html::allowed() );
			Ui::form_close();
			echo ' ';
			Ui::form_open( 'asem_application_reject', Ui::url( 'asem' ), false, 'asem-inline' );
			echo Ui::hidden( 'id', $a['id'] ) . '<button class="button button-small" data-confirm="Rifiutare la domanda?">Rifiuta</button>';
			Ui::form_close();
			echo '</li>';
		}
		echo '</ul></div>';
	}

	/** Beni in prestito il cui rientro è scaduto. */
	private static function overdue_loans(): void {
		$late = \AssociazioneSemplice\Inventory::overdue();
		if ( ! $late ) {
			return;
		}
		echo '<div class="asem-card" id="asem-loans-late"><h2>Prestiti da recuperare (' . count( $late ) . ')</h2><p class="description">Il rientro previsto è passato. Quando il bene torna, registra il rientro dall\'Inventario.</p><ul>';
		foreach ( array_slice( $late, 0, 10 ) as $l ) {
			echo '<li><a href="' . esc_url( Ui::url( 'asem-inventory', array( 'view' => (int) $l['item_id'] ) ) ) . '">' . esc_html( $l['item_name'] ) . '</a> <span class="description">a ' . esc_html( $l['person_name'] ) . ' · previsto il '
				. esc_html( ( new \DateTimeImmutable( $l['due_on'] ) )->format( 'd/m/Y' ) ) . ' · ' . (int) $l['days_late'] . ' giorni di ritardo</span></li>';
		}
		echo '</ul><p><a href="' . esc_url( Ui::url( 'asem-inventory', array( 'tab' => 'loans' ) ) ) . '">Tutti i prestiti →</a></p></div>';
	}

	/** Ragazzi ancora collegati a un tutore che hanno compiuto 18 anni: da invitare a creare il loro accesso. */
	private static function adults_to_invite(): void {
		$list = \AssociazioneSemplice\Guardians::adults_to_invite();
		if ( ! $list ) {
			return;
		}
		echo '<div class="asem-card"><h2>Maggiorenni da invitare (' . count( $list ) . ')</h2><p class="description">Hanno compiuto 18 anni e hanno ancora il tutore al loro posto. Invitali a creare il loro accesso (serve la loro email). Il collegamento col tutore resta finché non lo togli dalla scheda.</p><ul>';
		foreach ( array_slice( $list, 0, 20 ) as $p ) {
			echo '<li><a href="' . esc_url( Ui::url( 'asem-person', array( 'id' => $p['id'] ) ) ) . '">' . esc_html( $p['first_name'] . ' ' . $p['last_name'] ) . '</a> <span class="description">maggiorenne dal ' . esc_html( \AssociazioneSemplice\Age::display( (string) $p['adult_on'] ) ) . '</span> ';
			if ( ! empty( $p['email'] ) ) {
				Ui::form_open( 'asem_adult_invite', Ui::url( 'asem' ), false, 'asem-inline' );
				echo wp_kses( Ui::hidden( 'id', $p['id'] ) . '<button class="button button-small">Invita a creare l\'accesso</button>', Html::allowed() );
				Ui::form_close();
			} else {
				echo '<span class="description">manca l\'email: <a href="' . esc_url( Ui::url( 'asem-person', array( 'id' => $p['id'] ) ) ) . '">aggiungila nella scheda</a></span>';
			}
			echo '</li>';
		}
		echo '</ul></div>';
	}

	/** Cassa rapida: un incasso o una spesa semplici in pochi campi (per il resto c'è la Contabilità). */
	private static function quick_cash(): void {
		$ledger   = Plugin::ledger();
		$accounts = array();
		foreach ( $ledger->accounts() as $a ) {
			$accounts[ $a['id'] ] = $a['name'];
		}
		$cats         = array();
		$income_ids   = array();
		$account_types = array();
		foreach ( $ledger->accounts() as $a ) {
			$account_types[ (int) $a['id'] ] = $a['type'];
		}
		foreach ( array( 'donation', 'other_income', 'general_cost' ) as $k ) {
			$id = $ledger->category_id_of_kind( $k );
			if ( $id && ! \AssociazioneSemplice\Labels::category_kinds()[ $k ][2] ) {
				$income_ids[] = (int) $id;
			}
			if ( $id ) {
				$cats[ $id ] = ( \AssociazioneSemplice\Labels::category_kinds()[ $k ][2] ? 'Spesa: ' : 'Incasso: ' ) . \AssociazioneSemplice\Labels::category_kinds()[ $k ][0];
			}
		}
		$default = $ledger->default_account_for( 'cash' );
		echo '<div class="asem-card"><h2>Cassa rapida</h2>';
		Ui::form_open( 'asem_quick_cash', Ui::url( 'asem' ) );
		echo '<div data-asem-change data-types="' . esc_attr( wp_json_encode( $account_types ) ) . '" data-income="' . esc_attr( wp_json_encode( $income_ids ) ) . '">';
		echo wp_kses( '<p><select name="category_id" required>' . Ui::options( $cats, null ) . '</select> '
			. '<input type="text" name="amount" inputmode="decimal" placeholder="0,00" size="8" required> € </p>'
			. '<p><input type="text" name="description" class="regular-text" placeholder="Descrizione" required></p>'
			. '<p>Sul conto <select name="account_id">' . Ui::options( $accounts, $default ? $default['id'] : null ) . '</select></p>'
			. '<div class="asem-cashbox" style="display:none"><p>Contanti ricevuti <input type="text" class="asem-tendered" inputmode="decimal" placeholder="importo esatto" size="8"> € <span class="asem-quick"></span></p><p class="asem-change-out"></p></div></div>'
			. '<p><button class="button button-primary">Registra</button> <a href="' . esc_url( Ui::url( 'asem-income' ) ) . '">Incasso completo (quote, attività)</a> · <a href="' . esc_url( Ui::url( 'asem-expense' ) ) . '">Spesa completa</a></p>', Html::allowed() );
		Ui::form_close();
		echo '</div>';
	}

	/** Iscrizione rapida a un corso (dal mese in corso) o prenotazione a una data di un evento. */
	private static function quick_enroll(): void {
		$acts    = Plugin::activities();
		$targets = array();
		foreach ( $acts->for_year( Settings::social_year()->label() ) as $a ) {
			if ( 'course' === $a['kind'] ) {
				$targets[ 'a:' . $a['id'] ] = 'Corso: ' . $a['name'];
			}
		}
		foreach ( $acts->upcoming_sessions( 30 ) as $s ) {
			$targets[ 's:' . $s['id'] ] = 'Evento: ' . $s['activity_name'] . ' — ' . Ui::date( $s['session_date'] );
		}
		echo '<div class="asem-card"><h2>Iscrizione a corsi ed eventi</h2>';
		if ( ! $targets ) {
			echo '<p>Nessun corso né evento in programma. <a href="' . esc_url( Ui::url( 'asem-activities' ) ) . '">Creane uno</a>.</p></div>';
			return;
		}
		Ui::form_open( 'asem_quick_enroll', Ui::url( 'asem' ) );
		echo wp_kses( '<p>' . Ui::person_select( 'person_id', Plugin::people()->search(), null, '— chi si iscrive —' ) . '</p>'
			. '<p><select name="target" required>' . Ui::options( $targets, null, '— a cosa —' ) . '</select></p>'
			. '<p><button class="button button-primary">Iscrivi</button> <a href="' . esc_url( Ui::url( 'asem-income' ) ) . '">Poi incassa il contributo →</a></p>', Html::allowed() );
		Ui::form_close();
		echo '</div>';
	}

	/** Pagamenti da incassare: per ogni persona, la quota associativa (se la tessera non è valida), le mensilità dei corsi già dovute e i contributi non versati. */
	private static function payments_due(): void {
		$acts   = Plugin::activities();
		$people = Plugin::people();
		$today  = current_time( 'Y-m-d' );
		$by     = array(); // person_id => [person, what[], cents]
		$member_due = function ( array $p ) use ( $people, $today ) {
			$fee = \AssociazioneSemplice\Levels::fee_for( $p );
			if ( $fee <= 0 ||! MemberType::is_member( $p['type'] ) || MemberType::is_auto_renewed( $p['type'] ) || ! empty( $p['suspended_at'] ) ) {
				return null;
			}
			$until = $people->active_until( (int) $p['id'], $today );
			if ( $until && $until >= $today ) {
				return null; // tessera valida
			}
			$plan    = $people->membership_plan( (int) $p['id'], $today );
			$missing = $people->membership_missing( $p, $plan['year'] ); // quota a rate: manca solo il residuo
			if ( $missing > 0 ) {
				return array( 'Quota associativa ' . $plan['year'] . ' (versati ' . \AssociazioneSemplice\Money::format( $fee - $missing ) . ' su ' . \AssociazioneSemplice\Money::format( $fee ) . ')', $missing );
			}
			return array( 'Quota associativa ' . $plan['year'] . ( $plan['free'] ? ' (' . $plan['free'] . ' in omaggio)' : '' ), $fee );
		};
		$add = function ( array $p, string $what, int $cents ) use ( &$by ) {
			$pid = (int) $p['id'];
			if ( ! isset( $by[ $pid ] ) ) {
				$by[ $pid ] = array( 'person' => $p, 'what' => array(), 'cents' => 0 );
			}
			$by[ $pid ]['what'][] = $what;
			$by[ $pid ]['cents'] += $cents;
		};
		// nuovi soci che non hanno mai pagato la quota
		foreach ( $people->search() as $p ) {
			if ( empty( $p['active_until'] ) && ( $m = $member_due( $p ) ) ) {
				$add( $p, $m[0], $m[1] );
			}
		}
		// iscritti ai corsi con mensilità dovute: se la tessera non è valida, si aggiunge anche la quota associativa
		foreach ( $acts->for_year( \AssociazioneSemplice\Settings::social_year()->label() ) as $a ) {
			if ( 'course' !== $a['kind'] ) {
				continue;
			}
			foreach ( $acts->status_for_activity( (int) $a['id'] ) as $s ) {
				if ( null !== $s['enrollment']['end_month'] || ! $s['summary']['unpaid_months'] ) {
					continue;
				}
				$p = $people->get( (int) $s['enrollment']['person_id'] );
				if ( ! $p ) {
					continue;
				}
				if ( ! isset( $by[ (int) $p['id'] ] ) && ( $m = $member_due( $p ) ) ) {
					$add( $p, $m[0], $m[1] );
				}
				$months = implode( ', ', array_map( function ( $mm ) {
					return Ui::month( $mm['month'] );
				}, $s['summary']['unpaid_months'] ) );
				$add( $p, $a['name'] . ' · ' . $months, max( 0, -$s['summary']['balance'] ) );
			}
		}
		if ( ! $by ) {
			return;
		}
		$rows = array_values( $by );
		echo '<div class="asem-card"><h2>Pagamenti da incassare (' . count( $rows ) . ')</h2><p class="description">Quote dei nuovi soci o con la tessera non valida, mensilità dei corsi (dovute dalla prima lezione del mese) e contributi non ancora versati.</p><ul>';
		foreach ( array_slice( $rows, 0, 12 ) as $r ) {
			$p = $r['person'];
			echo wp_kses( '<li><a href="' . esc_url( Ui::url( 'asem-person', array( 'id' => $p['id'] ) ) ) . '">' . esc_html( $p['first_name'] . ' ' . $p['last_name'] ) . '</a> <span class="description">' . esc_html( implode( ' + ', $r['what'] ) ) . '</span> <strong>' . esc_html( Money::format( (int) $r['cents'] ) ) . '</strong> '
				. '<a class="button button-small" href="' . esc_url( Ui::url( 'asem-income', array( 'person_id' => $p['id'], 'due' => 1 ) ) ) . '">Incassa</a> ' . Ui::contact_links( $p ) . '</li>', Html::allowed() );
		}
		echo wp_kses( '</ul>' . ( count( $rows ) > 12 ? '<p class="description">… e altri ' . ( count( $rows ) - 12 ) . '.</p>' : '' ) . '</div>', Html::allowed() );
	}

	/** Soci che non hanno rinnovato (tessera scaduta) o stanno per scadere (30 giorni): si incassa il rinnovo o si sospende il socio. */
	private static function renewals(): void {
		$today = current_time( 'Y-m-d' );
		$soon  = gmdate( 'Y-m-d', strtotime( $today . ' +30 days' ) );
		$list  = array();
		foreach ( Plugin::people()->search() as $p ) {
			if ( MemberType::GUEST === $p['type'] || empty( $p['active_until'] ) || MemberType::is_auto_renewed( $p['type'] ) || ! empty( $p['suspended_at'] ) ) {
				continue;
			}
			if ( $p['active_until'] <= $soon ) {
				$list[] = $p;
			}
		}
		if ( ! $list ) {
			return;
		}
		usort( $list, function ( $a, $b ) {
			return strcmp( $a['active_until'], $b['active_until'] );
		} );
		echo '<div class="asem-card"><h2>Soci da rinnovare (' . count( $list ) . ')</h2><p class="description">Tessera scaduta o in scadenza entro 30 giorni. Un socio con la tessera scaduta non può prenotare: incassa il rinnovo oppure sospendilo (diventa inattivo, finché non rinnova).</p><ul>';
		foreach ( array_slice( $list, 0, 10 ) as $p ) {
			$expired = $p['active_until'] < $today;
			echo wp_kses( '<li><a href="' . esc_url( Ui::url( 'asem-person', array( 'id' => $p['id'] ) ) ) . '">' . esc_html( $p['first_name'] . ' ' . $p['last_name'] ) . '</a> <span class="description">'
				. ( $expired ? 'scaduta il ' : 'scade il ' ) . esc_html( Ui::date( $p['active_until'] ) ) . '</span> '
				. '<a class="button button-small" href="' . esc_url( Ui::url( 'asem-income', array( 'person_id' => $p['id'], 'due' => 1 ) ) ) . '">Incassa</a> ', Html::allowed() );
			if ( $expired ) {
				Ui::form_open( 'asem_suspend_member', Ui::url( 'asem' ), false, 'asem-inline' );
				echo wp_kses( Ui::hidden( 'id', $p['id'] ) . '<button class="button button-small" data-confirm="Sospendere ' . esc_attr( $p['first_name'] . ' ' . $p['last_name'] ) . '? Diventa inattivo finché non rinnova.">Sospendi</button>', Html::allowed() );
				Ui::form_close();
				echo ' ';
			}
			echo wp_kses( Ui::contact_links( $p ) . '</li>', Html::allowed() );
		}
		echo '</ul>' . ( count( $list ) > 10 ? '<p><a href="' . esc_url( Ui::url( 'asem-people', array( 'status' => 'inactive' ) ) ) . '">Vedi tutti →</a></p>' : '' ) . '</div>';
	}

	/** Ospiti prenotati ai prossimi eventi che hanno già raggiunto la soglia: da invitare a iscriversi. */
	private static function expected_guests(): void {
		$acts = Plugin::activities();
		$ov   = Plugin::people()->guest_overview();
		$rows = array();
		foreach ( $acts->upcoming_sessions( 5 ) as $s ) {
			foreach ( $acts->bookings_for_session( (int) $s['id'] ) as $b ) {
				if ( MemberType::GUEST === $b['type'] && 'booked' === $b['status'] && ! empty( $ov[ (int) $b['person_id'] ]['flag'] ) ) {
					$rows[] = array( 'session' => $s, 'booking' => $b, 'overview' => $ov[ (int) $b['person_id'] ] );
				}
			}
		}
		if ( ! $rows ) {
			return;
		}
		echo '<div class="asem-card"><h2>Ospiti attesi che dovrebbero iscriversi (' . count( $rows ) . ')</h2><p class="description">Prenotati ai prossimi eventi, hanno già raggiunto la soglia di partecipazioni per i non soci.</p><ul>';
		foreach ( $rows as $r ) {
			$b = $r['booking'];
			echo wp_kses( '<li><a href="' . esc_url( Ui::url( 'asem-person', array( 'id' => $b['person_id'] ) ) ) . '">' . esc_html( $b['first_name'] . ' ' . $b['last_name'] ) . '</a> <span class="description">a '
				. esc_html( $r['session']['activity_name'] ) . ' il ' . esc_html( Ui::date( $r['session']['session_date'] ) ) . ' · ' . (int) $r['overview']['total'] . ' partecipazioni</span> ' . Ui::contact_links( $b ) . '</li>', Html::allowed() );
		}
		echo '</ul></div>';
	}
}
