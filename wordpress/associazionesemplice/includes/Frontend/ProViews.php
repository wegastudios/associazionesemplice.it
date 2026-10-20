<?php
namespace AssociazioneSemplice\Frontend;

use AssociazioneSemplice\ActivityKind;
use AssociazioneSemplice\Edition;
use AssociazioneSemplice\MemberType;
use AssociazioneSemplice\Money;
use AssociazioneSemplice\Plugin;
use AssociazioneSemplice\Settings;

defined( 'ABSPATH' ) || exit;

/** Sezioni e viste del sito delle funzioni aggiuntive (risorse, crediti, ricevute, app, 5x1000, incasso sul posto, cassa per più persone). */
final class ProViews {

	public static function register(): void {
		add_action( 'asem_front_init', array( __CLASS__, 'front_init' ) );
		add_filter( 'asem_shortcode_views', array( __CLASS__, 'shortcode_views' ) );
		add_filter( 'asem_render_view', array( __CLASS__, 'render_view' ), 10, 3 );
		add_filter( 'asem_areas', array( __CLASS__, 'areas' ) );
		add_filter( 'asem_page_defs', array( __CLASS__, 'page_defs' ) );
		add_filter( 'asem_page_areas', array( __CLASS__, 'page_areas' ) );
		add_filter( 'asem_view_available', array( __CLASS__, 'view_available' ), 10, 2 );
		add_filter( 'asem_my_sections', array( __CLASS__, 'my_sections' ) );
		add_filter( 'asem_area_section', array( __CLASS__, 'area_section' ), 10, 3 );
		add_filter( 'asem_card_extra', array( __CLASS__, 'card_extra' ), 10, 2 );
		add_filter( 'asem_checkin_extra', array( __CLASS__, 'checkin_extra' ), 10, 3 );
		add_filter( 'asem_treasurer_sections', array( __CLASS__, 'treasurer_sections' ) );
	}

	/** Le aree del sito con le viste delle funzioni aggiuntive. */
	public static function areas( array $groups ): array {
		$add = array(
			'soci'    => array( array( 'risorse', 'mie_attivita' ), array( 'crediti', 'risorse' ), array( 'ricevute', 'pagamenti' ), array( 'app', 'calendario' ) ),
			'eventi'  => array( array( 'incasso_crediti', 'ingressi' ) ),
			'pubblico' => array( array( 'cinquepermille', 'privacy' ) ),
		);
		foreach ( $add as $area => $items ) {
			if ( ! isset( $groups[ $area ] ) ) {
				continue;
			}
			foreach ( $items as $it ) {
				$out = array();
				foreach ( $groups[ $area ][1] as $v ) {
					$out[] = $v;
					if ( $it[1] === $v ) {
						$out[] = $it[0];
					}
				}
				$groups[ $area ][1] = $out;
			}
		}
		return $groups;
	}

	/** Pagine del sito proposte: il 5x1000. */
	public static function page_defs( array $defs ): array {
		$defs['cinquemille'] = array( 'title' => '5x1000', 'content' => '[associazionesemplice_cinquepermille]', 'access' => '', 'hint' => 'Il messaggio con il codice fiscale per il 5x1000 (serve la funzione attiva).', 'default' => false );
		return $defs;
	}

	public static function page_areas( array $areas ): array {
		return $areas + array( 'cinquemille' => 'pubblico' );
	}

	/** Una vista delle funzioni aggiuntive si propone solo se la funzione c'è. */
	public static function view_available( $ok, string $view ) {
		$needs = array( 'ricevute' => 'receipts', 'app' => 'pwa', 'cinquepermille' => 'fivepm', 'risorse' => 'resources', 'crediti' => 'credits', 'incasso_crediti' => 'credits' );
		return isset( $needs[ $view ] ) ? Edition::has( $needs[ $view ] ) : $ok;
	}

	/** Avvio delle parti del sito delle funzioni aggiuntive. */
	public static function front_init(): void {
		if ( Edition::has( 'resources' ) ) {
			ResourcesFront::register();
		}
		if ( Edition::has( 'credits' ) ) {
			CreditsFront::register();
		}
		if ( Edition::installed( 'payments' ) ) {
			PayReturn::register();
		}
	}

	/** Viste (shortcode, blocchi, widget) disponibili con le funzioni presenti. */
	public static function shortcode_views( array $views ): array {
		$extra = array(
			'ricevute'        => array( 'receipts', 'Le mie ricevute e attestazioni (PDF)', 'pagamenti' ),
			'risorse'         => array( 'resources', 'Risorse prenotabili: campi, sale, posti (soci)', 'mie_attivita' ),
			'crediti'         => array( 'credits', 'I miei crediti: saldo, ricarica e movimenti (soci)', 'risorse' ),
			'incasso_crediti' => array( 'credits', 'Incasso crediti dal vivo con QR (staff)', 'crediti' ),
			'app'             => array( 'pwa', 'App e notifiche (installazione e notifiche sul telefono)', 'prossimi_eventi' ),
			'cinquepermille'  => array( 'fivepm', '5x1000 (messaggio pubblico con il codice fiscale)', 'app' ),
		);
		foreach ( $extra as $slug => $row ) {
			$out = array();
			foreach ( $views as $k => $label ) {
				$out[ $k ] = $label;
				if ( $row[2] === $k ) {
					$out[ $slug ] = $row[1];
				}
			}
			if ( ! isset( $out[ $slug ] ) ) {
				$out[ $slug ] = $row[1];
			}
			$views = $out;
		}
		return $views;
	}

	/** Disegna una vista delle funzioni aggiuntive. */
	public static function render_view( $html, string $view, array $atts ) {
		switch ( $view ) {
			case 'risorse':
				return self::resources();
			case 'crediti':
				return self::credits();
			case 'incasso_crediti':
				return self::credit_charge();
			case 'ricevute':
				return self::receipts();
			case 'app':
				return self::app();
			case 'cinquepermille':
				return self::five_per_mille();
		}
		return $html;
	}

	/** Sezioni dell'area soci in più, nell'ordine in cui compaiono. */
	public static function my_sections( array $sections ): array {
		$out = array();
		foreach ( $sections as $s ) {
			$out[] = $s;
			if ( 'attivita' === $s ) {
				$out[] = 'risorse';
				$out[] = 'crediti';
			}
		}
		$out[] = 'ricevute';
		$out[] = 'app';
		return $out;
	}

	/** Una sezione dell'area soci delle funzioni aggiuntive. */
	public static function area_section( $html, string $key, array $p ) {
		switch ( $key ) {
			case 'risorse':
				return self::section_resources( $p );
			case 'crediti':
				return self::section_credits( $p );
			case 'ricevute':
				return self::section_receipts( $p );
			case 'app':
				return self::section_app( $p );
		}
		return $html;
	}

	/** Pulsanti Wallet sotto la tessera. */
	public static function card_extra( $html, array $p ) {
		return $html . ( Edition::has( 'wallet' ) ? \AssociazioneSemplice\Wallet::buttons( $p ) : '' );
	}

	/** Incasso sul posto nella scheda di un evento. */
	public static function checkin_extra( $html, array $s, array $a ) {
		return $html . self::door_form( $s, $a );
	}

	/** Cassa per più persone nell'area del tesoriere. */
	public static function treasurer_sections( $html ) {
		return $html . self::section_group();
	}

	/** Cassa per più persone del tesoriere: chi paga salda quote, eventi e corsi per sé e per altri (anche nuovi ospiti). Importo vuoto = importo standard. */
	public static function section_group(): string {
		if ( ! \AssociazioneSemplice\Edition::has( 'funds' ) || ! current_user_can( 'asem_collect', 0 ) ) {
			return '';
		}
		$ledger = Plugin::ledger();
		$opt    = '<option value="">— scegli —</option>';
		foreach ( Plugin::people()->search() as $p ) {
			$opt .= '<option value="' . (int) $p['id'] . '">' . esc_html( trim( $p['last_name'] . ' ' . $p['first_name'] ) ) . '</option>';
		}
		$accounts = '';
		foreach ( $ledger->accounts() as $a ) {
			$accounts .= '<option value="' . (int) $a['id'] . '">' . esc_html( $a['name'] ) . '</option>';
		}
		$what = '<option value="">— niente —</option><option value="m">Quota associativa</option><optgroup label="Eventi">';
		foreach ( Plugin::activities()->upcoming_sessions( 40 ) as $s ) {
			$what .= '<option value="s:' . (int) $s['id'] . '">' . esc_html( $s['activity_name'] . ' · ' . Views::d( $s['session_date'] ) ) . '</option>';
		}
		$what .= '</optgroup><optgroup label="Corsi (mese in corso)">';
		foreach ( Plugin::activities()->for_year( Settings::social_year()->label() ) as $a ) {
			if ( ActivityKind::COURSE === $a['kind'] ) {
				$what .= '<option value="k:' . (int) $a['id'] . '">' . esc_html( $a['name'] ) . '</option>';
			}
		}
		$what .= '</optgroup>';
		$rows = '';
		for ( $i = 0; $i < 6; $i++ ) {
			$rows .= '<div class="asemf-fields"><label>Persona <select name="rows[' . $i . '][person]">' . $opt . '</select></label>'
				. '<label>oppure nuovo ospite <input type="text" name="rows[' . $i . '][first]" placeholder="Nome"> <input type="text" name="rows[' . $i . '][last]" placeholder="Cognome"> <input type="tel" name="rows[' . $i . '][phone]" placeholder="Cellulare"></label>'
				. '<label>Cosa <select name="rows[' . $i . '][what]">' . $what . '</select></label>'
				. '<label>Importo (€) <input type="text" name="rows[' . $i . '][amount]" inputmode="decimal" placeholder="standard"></label></div>';
		}
		$fields = '<div class="asemf-fields"><label>Chi paga <select name="payer_id" required>' . $opt . '</select></label><label>Sul conto <select name="account_id">' . $accounts . '</select></label></div>' . $rows;
		return '<section class="asemf-section asemf-collect"><details><summary><h3 style="display:inline">Cassa per più persone</h3></summary>'
			. '<p class="asemf-small asemf-muted">Una persona paga per sé e per altri: un solo incasso. Ogni riga è una persona (o un nuovo ospite) con una voce; la stessa persona può comparire in più righe. Gli ospiti nuovi sono ospiti di chi paga. Un evento si può pagare anche per un socio con la tessera scaduta; per un corso la tessera deve essere in regola (o rinnovata nello stesso incasso).</p>'
			. Views::form( 'asem_front_group', $fields, 'Registra l\'incasso' ) . '</details></section>'; // phpcs:ignore WordPress.Security.EscapeOutput
	}

	public static function door_form( array $s, array $a ): string {
		if ( ! \AssociazioneSemplice\Edition::has( 'door_sales' ) || ! current_user_can( 'asem_door_cash', (int) $a['id'] ) || ! empty( $s['cancelled_at'] ) ) {
			return '';
		}
		$seat = Plugin::activities()->seats( (int) $s['id'] );
		if ( 0 === $seat['free'] ) {
			return '<p class="asemf-small asemf-muted">Posti esauriti: nessun ingresso sul posto possibile.</p>';
		}
		$all = '<option value="">— scegli il socio —</option>';
		foreach ( Plugin::people()->search() as $p ) {
			if ( MemberType::is_member( $p['type'] ) ) {
				$all .= '<option value="' . (int) $p['id'] . '">' . esc_html( trim( $p['last_name'] . ' ' . $p['first_name'] ) ) . '</option>';
			}
		}
		$accs = '';
		foreach ( Plugin::ledger()->accounts() as $acc ) {
			if ( in_array( $acc['type'], array( 'cash', 'pos' ), true ) ) {
				$accs .= '<option value="' . (int) $acc['id'] . '">' . esc_html( $acc['name'] ) . '</option>';
			}
		}
		$ids = Views::hidden( 'session_id', $s['id'] );
		return '<details class="asemf-door"><summary><strong>＋ Ingresso sul posto</strong> <span class="asemf-small asemf-muted">socio non prenotato (biglietto socio: ' . esc_html( Money::format( Plugin::activities()->fee_for( $a, MemberType::ORDINARY ) ) ) . ')</span></summary>'
			. Views::form(
				'asem_front_door',
				$ids . '<label>Socio <select name="person_id" required>' . $all . '</select></label>'
				. '<label><input type="checkbox" name="pay" value="1" checked> Incassa il biglietto</label>'
				. ( '' !== $accs ? '<label>Pagamento <select name="account_id">' . $accs . '</select></label>' : '' ),
				'Prenota, incassa e registra l\'ingresso',
				true
			) . self::door_group_form( $s, $all, $accs ) . '</details>'; // phpcs:ignore WordPress.Security.EscapeOutput
	}

	/** Risorse prenotabili (campi, sale, posti): vuota se la funzione non c'è in questa edizione. */
	public static function section_resources( array $p ): string {
		return Edition::has( 'resources' ) ? ResourcesFront::section( $p ) : '';
	}

	/** I crediti del socio: vuota se la funzione non c'è in questa edizione. */
	public static function section_credits( array $p ): string {
		return Edition::has( 'credits' ) ? CreditsFront::section( $p ) : '';
	}

	public static function credits(): string {
		return Edition::has( 'credits' ) ? Views::with_person( array( __CLASS__, 'section_credits' ) ) : '';
	}

	/** Lo staff addebita crediti dal vivo mostrando un QR. */
	public static function credit_charge(): string {
		return Edition::has( 'credits' ) ? Views::with_person( array( CreditsFront::class, 'staff_section' ) ) : '';
	}

	public static function resources(): string {
		return Edition::has( 'resources' ) ? Views::with_person( array( __CLASS__, 'section_resources' ) ) : '';
	}

	/** Le mie ricevute: scarico dei PDF (anche per gli ospiti che ho pagato) e attestazione annuale. */
	public static function section_receipts( array $p ): string {
		if ( ! \AssociazioneSemplice\Edition::has( 'receipts' ) ) {
			return '';
		}
		$pid   = (int) $p['id'];
		$mine  = \AssociazioneSemplice\Receipts::list_for_payer( $pid, 15 );
		$years = \AssociazioneSemplice\Receipts::years_for_payer( $pid );
		if ( ! $mine && ! $years ) {
			return '<section class="asemf-section"><h3>Le mie ricevute</h3><p class="asemf-muted">Nessun pagamento registrato finora.</p></section>';
		}
		$html = '<section class="asemf-section"><h3>Le mie ricevute</h3>';
		if ( $years ) {
			$html .= '<p>';
			foreach ( $years as $y ) {
				$html .= '<a class="asemf-btn" target="_blank" href="' . esc_url( \AssociazioneSemplice\Receipts::statement_url( $pid, (int) $y ) ) . '">Attestazione ' . (int) $y . '</a> ';
			}
			$html .= '</p><p class="asemf-small asemf-muted">L\'attestazione riepiloga tutti i versamenti dell\'anno (utile per la dichiarazione dei redditi).</p>';
		}
		$html .= '<ul class="asemf-list">';
		foreach ( $mine as $r ) {
			$html .= '<li><div><strong>' . esc_html( Views::d( $r['date'] ) ) . '</strong> · ' . esc_html( $r['what'] ) . '</div><div>' . esc_html( Money::format( (int) $r['cents'] ) )
				. ' <a class="asemf-btn" target="_blank" href="' . esc_url( \AssociazioneSemplice\Receipts::url( $r['key'] ) ) . '">Ricevuta PDF</a></div></li>';
		}
		return $html . '</ul></section>';
	}

	/** Messaggio pubblico del 5x1000 con il codice fiscale dell'associazione (vuoto se spento o senza codice fiscale). */
	public static function five_per_mille(): string {
		if ( ! \AssociazioneSemplice\Edition::has( 'fivepm' ) || ! \AssociazioneSemplice\FivePerMille::enabled() || '' === trim( (string) Settings::get( 'tax_code' ) ) ) {
			return '';
		}
		$name = trim( (string) Settings::get( 'association_name' ) );
		return '<section class="asemf-section asemf-fivepm"><h3>5x1000' . ( '' !== $name ? ' a ' . esc_html( $name ) : '' ) . '</h3><p>' . esc_html( \AssociazioneSemplice\FivePerMille::text() ) . '</p>'
			. '<p class="asemf-small">Codice fiscale: <strong>' . esc_html( (string) Settings::get( 'tax_code' ) ) . '</strong></p></section>';
	}

	/** App installabile e notifiche: si vede solo se l'app è accesa nelle impostazioni. */
	public static function section_app( array $p ): string {
		if ( ! \AssociazioneSemplice\Edition::has( 'pwa' ) || ! \AssociazioneSemplice\Pwa::enabled() ) {
			return '';
		}
		$push = \AssociazioneSemplice\Push::enabled();
		$html = '<section class="asemf-section asemf-app"><h3>App e notifiche</h3>'
			. '<p class="asemf-small asemf-muted">Aggiungi ' . esc_html( \AssociazioneSemplice\Pwa::app_name() ) . ' alla schermata Home del telefono: si apre come un\'app, con la tessera sempre a portata di mano.</p>'
			. '<p><button type="button" class="asemf-btn" data-asem-install hidden>Installa l\'app</button></p>'
			. '<p class="asemf-small asemf-muted" data-asem-ios hidden>Su iPhone: tocca <strong>Condividi</strong> e poi <strong>Aggiungi alla schermata Home</strong>.</p>';
		if ( $push ) {
			$html .= '<div data-asem-push><p class="asemf-small asemf-muted">Ricevi sul telefono gli avvisi dei corsi e degli eventi, i promemoria e le comunicazioni dell\'associazione.</p>'
				. '<p><button type="button" class="asemf-btn" data-asem-push-on>Attiva le notifiche su questo dispositivo</button> <button type="button" class="asemf-btn asemf-btn-ghost" data-asem-push-off hidden>Disattiva le notifiche</button></p>'
				. '<p class="asemf-small asemf-muted" data-asem-push-msg></p></div>';
		}
		return $html . '</section>';
	}

	public static function app(): string {
		return Views::with_person( array( __CLASS__, 'section_app' ) );
	}

	public static function receipts(): string {
		return Views::with_person( array( __CLASS__, 'section_receipts' ) );
	}


	/** Cassa per più persone sul posto: un socio paga il biglietto per sé e per altri soci (importi calcolati dal sito). */
	public static function door_group_form( array $s, string $members_options, string $accounts_options ): string {
		if ( '' === $accounts_options ) {
			return '';
		}
		$rows = '';
		for ( $i = 0; $i < 6; $i++ ) {
			$rows .= '<label>Socio ' . ( $i + 1 ) . ' <select name="rows[' . $i . '][person]">' . $members_options . '</select></label>';
		}
		$fields = Views::hidden( 'session_id', $s['id'] )
			. '<label>Chi paga <select name="payer_id" required>' . $members_options . '</select></label>'
			. '<label>Pagamento <select name="account_id">' . $accounts_options . '</select></label>'
			. '<p class="asemf-small asemf-muted">Scegli i soci per cui paga (può includere anche sé stesso): ognuno viene prenotato, l\'importo del biglietto è calcolato automaticamente e l\'incasso è unico.</p>' . $rows;
		return '<details class="asemf-door-group"><summary><strong>＋ Un socio paga per più soci</strong></summary>'
			. Views::form( 'asem_front_door_group', $fields, 'Prenota e incassa per tutti', true ) . '</details>';
	}
}
