<?php
namespace AssociazioneSemplice\Frontend;

use AssociazioneSemplice\Access;
use AssociazioneSemplice\ActivityKind;
use AssociazioneSemplice\Bank;
use AssociazioneSemplice\Labels;
use AssociazioneSemplice\Edition;
use AssociazioneSemplice\Limits;
use AssociazioneSemplice\MemberType;
use AssociazioneSemplice\Money;
use AssociazioneSemplice\PaymentConfig;
use AssociazioneSemplice\Plugin;
use AssociazioneSemplice\Pricing;
use AssociazioneSemplice\Settings;
use AssociazioneSemplice\Text;

defined( 'ABSPATH' ) || exit;

/**
 * Viste del front-end (area riservata e pagine pubbliche). Ogni vista restituisce HTML già escapato,
 * con classi `asemf-*` stilate da assets/frontend.css che eredita font e colori del tema.
 * Le scelte di layout (colonne, spazi) sono dei builder (Gutenberg, Elementor): qui solo il contenuto.
 */
final class Views {

	const DAYS   = array( 'dom', 'lun', 'mar', 'mer', 'gio', 'ven', 'sab' );
	const MONTHS = array( 1 => 'gennaio', 'febbraio', 'marzo', 'aprile', 'maggio', 'giugno', 'luglio', 'agosto', 'settembre', 'ottobre', 'novembre', 'dicembre' );

	// ---------- Utilità ----------

	public static function date_long( string $ymd ): string {
		$t = strtotime( $ymd . ' 12:00:00 UTC' );
		return self::DAYS[ (int) gmdate( 'w', $t ) ] . ' ' . (int) gmdate( 'j', $t ) . ' ' . self::MONTHS[ (int) gmdate( 'n', $t ) ] . ' ' . gmdate( 'Y', $t );
	}

	/** Data scritta per esteso per le email («venerdì 20 novembre 2026»); da una data tecnica «2026-11-20» (un valore vuoto o non valido torna com'è). */
	public static function date_words( string $ymd ): string {
		$t = strtotime( substr( $ymd, 0, 10 ) . ' 12:00:00 UTC' );
		if ( false === $t || ! preg_match( '/^\d{4}-\d{2}-\d{2}/', $ymd ) ) {
			return $ymd;
		}
		$days = array( 'domenica', 'lunedì', 'martedì', 'mercoledì', 'giovedì', 'venerdì', 'sabato' );
		return $days[ (int) gmdate( 'w', $t ) ] . ' ' . (int) gmdate( 'j', $t ) . ' ' . self::MONTHS[ (int) gmdate( 'n', $t ) ] . ' ' . gmdate( 'Y', $t );
	}

	public static function d( ?string $ymd ): string {
		return $ymd ? esc_html( ( new \DateTimeImmutable( $ymd ) )->format( 'd/m/Y' ) ) : '—';
	}

	/** Contenitore delle viste, anche per le pagine pubbliche del plugin. */
	public static function shell( string $inner, string $class = '' ): string {
		return self::wrap( $inner, $class );
	}

	private static function wrap( string $inner, string $class = '' ): string {
		Assets::enqueue();
		return \AssociazioneSemplice\Texts::html( '<div class="asemf ' . esc_attr( $class ) . '">' . self::flash() . $inner . '</div>' ); // testi personalizzati
	}

	/** Esito dell'ultima azione (una sola volta per pagina, anche con più viste). */
	private static function flash(): string {
		static $printed = false;
		if ( $printed ) {
			return '';
		}
		$msg = \AssociazioneSemplice\Flash::read( 'asemf' ); // solo messaggi scritti dal sito (firmati)
		$ok  = $msg['ok'];
		$err = $msg['err'];
		if ( '' === $ok && '' === $err ) {
			return '';
		}
		$printed = true;
		if ( '' !== $err ) {
			$link = esc_html( Actions::RENEW_LINK );
			$safe = esc_html( $err );
			if ( false !== strpos( $safe, $link ) ) { // «Rinnova online dalla tua area soci» diventa un link alla pagina dell'area (il testo è quello firmato dal sito)
				$safe = str_replace( $link, '<a href="' . esc_url( \AssociazioneSemplice\Gatekeeper::area_url() ) . '">' . $link . '</a>', $safe );
			}
			return '<div class="asemf-notice asemf-err" role="alert">' . $safe . '</div>';
		}
		return '' !== $err ? '<div class="asemf-notice asemf-err" role="alert">' . esc_html( $err ) . '</div>' : '<div class="asemf-notice asemf-ok" role="status">' . esc_html( $ok ) . '</div>';
	}

	private static function notice( string $text, string $kind = '' ): string {
		return '<div class="asemf-notice ' . esc_attr( $kind ) . '">' . esc_html( $text ) . '</div>';
	}

	public static function fee_text( array $a ): string {
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

	/**
	 * @param array[] $buttons se indicati sostituiscono il pulsante unico: ciascuno con value (inviato come "provider") e label
	 */
	public static function form( string $action, string $fields_html, string $button, bool $confirm = false, string $class = '', bool $multipart = false, array $buttons = array() ): string {
		$html  = '<form class="asemf-form ' . esc_attr( $class ) . '" method="post"' . ( $multipart ? ' enctype="multipart/form-data"' : '' ) . ' action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		$html .= '<input type="hidden" name="action" value="' . esc_attr( $action ) . '">';
		$html .= '<input type="hidden" name="_back" value="' . esc_url( Restrict::current_url() ) . '">';
		$html .= \AssociazioneSemplice\Csrf::field( $action );
		$html .= $fields_html;
		if ( $buttons ) {
			foreach ( $buttons as $b ) {
				$html .= '<button type="submit" name="provider" value="' . esc_attr( (string) $b['value'] ) . '" class="asemf-btn wp-element-button">' . esc_html( (string) $b['label'] ) . '</button> ';
			}
			return $html . '</form>';
		}
		$html .= '<button type="submit" class="asemf-btn wp-element-button"' . ( $confirm ? ' data-confirm="Confermi?"' : '' ) . '>' . esc_html( $button ) . '</button></form>';
		return $html;
	}

	public static function hidden( string $name, $value ): string {
		return '<input type="hidden" name="' . esc_attr( $name ) . '" value="' . esc_attr( (string) $value ) . '">';
	}

	// ---------- Accesso ----------

	/** Dove mandare un socio che deve accedere e poi tornare qui: la pagina di accesso dei soci (se hanno l'accesso proprio) o quella di WordPress. */
	public static function member_login_url(): string {
		$back = Restrict::current_url();
		if ( \AssociazioneSemplice\MemberSession::enabled() && ! \AssociazioneSemplice\MemberSession::wp_users_wanted() ) {
			return add_query_arg( '_back', rawurlencode( $back ), MemberLoginPage::url() );
		}
		return wp_login_url( $back );
	}

	public static function login_prompt(): string {
		Assets::enqueue();
		if ( \AssociazioneSemplice\MemberSession::enabled() && ! \AssociazioneSemplice\MemberSession::wp_users_wanted() ) { // i soci non sono utenti WordPress: email e password propri
			$back = Restrict::current_url();
			$own  = MemberLoginPage::url();
			return self::wrap(
				'<div class="asemf-card"><h3>Area riservata ai soci</h3><p>Accedi per vedere la tessera, le tue attività e prenotarti agli eventi.</p>'
				. MemberLoginPage::inline_login( $back )
				. '<p class="asemf-small"><a href="' . esc_url( add_query_arg( array( 'asem_ml_reset' => '1', '_back' => rawurlencode( $back ) ), $own ) ) . '">Password dimenticata?</a> · <a href="' . esc_url( add_query_arg( '_back', rawurlencode( $back ), $own ) ) . '">Entra con un link via email</a> · <strong><a href="' . esc_url( FirstAccess::url() ) . '">Primo accesso</a></strong></p>'
				. '<p class="asemf-small">Segreteria e staff: <a href="' . esc_url( wp_login_url( $back ) ) . '">accedi da qui</a>.</p></div>'
			);
		}
		$form = wp_login_form( array( 'echo' => false, 'redirect' => Restrict::current_url(), 'label_username' => 'Email o nome utente', 'label_log_in' => 'Accedi' ) );
		return self::wrap(
			'<div class="asemf-card"><h3>Area riservata ai soci</h3><p>Accedi per vedere la tua tessera, le tue attività e prenotarti agli eventi.</p>'
			. $form . '<p class="asemf-small"><strong><a href="' . esc_url( FirstAccess::url() ) . '">Primo accesso</a></strong> (non hai ancora una password) · <a href="' . esc_url( wp_lostpassword_url( Restrict::current_url() ) ) . '">Password dimenticata?</a></p>'
			. ( \AssociazioneSemplice\MemberSession::enabled() ? '<p class="asemf-small"><strong><a href="' . esc_url( MemberLoginPage::url() ) . '">Entra con un link via email</a></strong>, senza password.</p>' : '' ) . '</div>'
		);
	}

	/**
	 * Questa pagina cambia con i posti, i prenotati o chi la guarda: non va nella cache di pagina (ne avrebbe dati vecchi, a volte per giorni).
	 * Vale per i plugin di cache che rispettano la costante standard e per LiteSpeed (azione apposita).
	 */
	public static function no_cache(): void {
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- costante standard dei plugin di cache
		}
		do_action( 'litespeed_control_set_nocache', 'Associazione Semplice: posti e prenotazioni' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- azione di LiteSpeed Cache
		if ( ! headers_sent() ) {
			nocache_headers();
		}
	}

	/** Esegue $fn( persona ) solo se l'utente è collegato e ha una scheda socio; altrimenti mostra il messaggio giusto. */
	public static function with_person( callable $fn, string $class = '' ): string {
		if ( ! \AssociazioneSemplice\MemberSession::logged_in() ) {
			if ( \AssociazioneSemplice\MemberSession::pending_setup() ) { // entrato ma senza password: prima la sceglie
				self::no_cache();
				return self::wrap( '<div class="asemf-card"><h3>Scegli la tua password</h3><p>Per continuare devi scegliere la tua password.</p><p><a class="asemf-btn wp-element-button" href="' . esc_url( add_query_arg( '_back', rawurlencode( Restrict::current_url() ), MemberLoginPage::url() ) ) . '">Scegli la password</a></p></div>' );
			}
			return self::login_prompt();
		}
		self::no_cache();
		$uid      = get_current_user_id(); // 0 per chi è entrato con l'accesso proprio
		$is_admin = Access::is_admin_user( $uid );
		if ( ! $is_admin && ! Edition::allows( 'member_area' ) ) {
			return self::wrap( self::notice( 'Servizio temporaneamente sospeso. Contatta l\'associazione.', 'asemf-err' ) );
		}
		$person = Access::current_person();
		if ( ! $person ) {
			return self::wrap( self::notice( $is_admin
				? 'Sei amministratore del sito e non hai una scheda socio collegata: qui i soci vedono i propri dati.'
				: 'Il tuo account non è collegato a una scheda socio. Contatta l\'associazione.' ) );
		}
		return self::wrap( (string) $fn( $person ), $class );
	}

	// ---------- Sezioni dell'area soci ----------

	public static function section_card( array $p ): string {
		if ( ! Settings::get( 'card_enabled' ) || MemberType::GUARDIAN === $p['type'] ) {
			return ''; // tessera digitale spenta nelle impostazioni
		}
		$people = Plugin::people();
		$until  = ! MemberType::is_member( $p['type'] ) ? null : $people->active_until( (int) $p['id'] );
		$active = MemberType::is_member( $p['type'] ) && $people->is_active_member( (int) $p['id'] ); // un socio sospeso o uscito non ha la tessera valida, come al controllo con il QR
		$assoc  = (string) Settings::get( 'association_name' );
		$valid  = MemberType::is_auto_renewed( $p['type'] ) ? 'Sempre rinnovata' : ( $until ? self::d( $until ) : '—' );
		if ( \AssociazioneSemplice\CardLayout::active() ) { // tessera su un'immagine dell'associazione, con i dati posizionati sopra
			$when = MemberType::is_auto_renewed( $p['type'] ) ? 'Sempre rinnovata' : ( $until ? 'Valida fino al ' . self::d( $until ) : '' );
			return '<section class="asemf-section">'
				. \AssociazioneSemplice\CardLayout::html( trim( $p['first_name'] . ' ' . $p['last_name'] ), '' !== (string) $p['card_number'] ? 'N. ' . $p['card_number'] : '', $when, self::card_qr_svg( $p ), \AssociazioneSemplice\CardLayout::extras( $p ) )
				. ( $active ? '' : '<p><span class="asemf-badge asemf-badge-bad">Tessera scaduta</span></p>' ) // sulla tessera stampabile compare solo se non è in regola
				. (string) apply_filters( 'asem_card_extra', '', $p )
				. '<p class="asemf-print-wrap"><button type="button" class="asemf-btn asemf-print-card">Stampa la tessera</button> <a class="asemf-btn" target="_blank" rel="noopener" href="' . esc_url( \AssociazioneSemplice\CardLayout::pdf_url( (int) $p['id'] ) ) . '">Scarica il PDF</a></p></section>';
		}
		$ex = \AssociazioneSemplice\CardLayout::extras( $p );
		$logo = Assets::logo_url();
		return '<section class="asemf-section"><div class="asemf-memcard">'
			. ( '' !== $logo ? '<img class="asemf-memcard-logo" src="' . esc_url( $logo ) . '" alt="' . esc_attr( $assoc ) . '">' : '' )
			. ( '' !== $assoc ? '<div class="asemf-memcard-assoc">' . esc_html( $assoc ) . '</div>' : '' )
			. '<div class="asemf-memcard-name">' . esc_html( trim( $p['first_name'] . ' ' . $p['last_name'] ) ) . '</div>'
			. '<div class="asemf-memcard-type">' . esc_html( $ex['type'] ) . '</div>'
			. ( '' !== $ex['roles'] ? '<div class="asemf-memcard-roles">' . esc_html( $ex['roles'] ) . '</div>' : '' )
			. ( '' !== $ex['text'] ? '<div class="asemf-memcard-text">' . esc_html( $ex['text'] ) . '</div>' : '' )
			. '<dl class="asemf-memcard-data"><div><dt>Tessera n.</dt><dd>' . esc_html( (string) ( $p['card_number'] ?: '—' ) ) . '</dd></div>'
			. '<div><dt>Valida fino al</dt><dd>' . $valid . '</dd></div></dl>'
			. ( $active ? '' : '<span class="asemf-badge asemf-badge-bad">Tessera scaduta</span>' )
			. self::card_qr( $p ) . (string) apply_filters( 'asem_card_extra', '', $p )
			. '</div><p class="asemf-print-wrap"><button type="button" class="asemf-btn asemf-print-card">Stampa la tessera</button> <a class="asemf-btn" target="_blank" rel="noopener" href="' . esc_url( \AssociazioneSemplice\CardLayout::pdf_url( (int) $p['id'] ) ) . '">Scarica il PDF</a></p></section>';
	}

	/** Solo il disegno del QR della tessera (vuoto se il QR è spento o il socio non è un socio). */
	private static function card_qr_svg( array $p ): string {
		if ( ! Settings::card_qr_enabled() || ! MemberType::is_member( $p['type'] ) ) {
			return '';
		}
		try {
			return \AssociazioneSemplice\QrCode::svg( Settings::card_url( (int) $p['id'] ), 4, 'QR della tessera di ' . trim( $p['first_name'] . ' ' . $p['last_name'] ) );
		} catch ( \InvalidArgumentException $e ) {
			return '';
		}
	}

	/** QR della tessera (si verifica al momento, anche se la tessera nel frattempo scade o si rinnova). */
	private static function card_qr( array $p ): string {
		if ( ! Settings::card_qr_enabled() || ! MemberType::is_member( $p['type'] ) ) {
			return '';
		}
		$url = Settings::card_url( (int) $p['id'] );
		try {
			$svg = \AssociazioneSemplice\QrCode::svg( $url, 4, 'QR della tessera di ' . trim( $p['first_name'] . ' ' . $p['last_name'] ) );
		} catch ( \InvalidArgumentException $e ) {
			return '';
		}
		return '<div class="asemf-memcard-qr">' . $svg . '<div class="asemf-small asemf-qr-note"><span>Mostra questo codice: chi lo scansiona vede subito se la tessera è valida.</span> '
			. '<a href="' . esc_url( $url ) . '" target="_blank" rel="noopener">Apri la verifica</a></div></div>';
	}

	private static function pay_text( array $summary ): string {
		if ( $summary['balance'] < 0 ) {
			return '<span class="asemf-bad">Da versare ' . esc_html( Money::format( -$summary['balance'] ) ) . '</span>';
		}
		return '<span class="asemf-good">In regola</span>' . ( $summary['balance'] > 0 ? ' <span class="asemf-small">(credito ' . esc_html( Money::format( $summary['balance'] ) ) . ')</span>' : '' );
	}

	private static function booking_pay( array $b ): string {
		switch ( $b['state'] ) {
			case Pricing::FREE:
				return '<span class="asemf-good">gratuito</span>';
			case Pricing::PAID:
				return '<span class="asemf-good">pagato</span>';
			case Pricing::PARTIAL:
				return '<span class="asemf-warn">parziale · resta ' . esc_html( Money::format( (int) $b['remaining'] ) ) . '</span>';
		}
		return '<span class="asemf-bad">da pagare ' . esc_html( Money::format( (int) $b['remaining'] ) ) . '</span>';
	}

	public static function section_activities( array $p ): string {
		$html  = '<section class="asemf-section"><h3>Le mie attività</h3>';
		$today = current_time( 'Y-m-d' );
		$stat  = Plugin::activities()->status_for_person( (int) $p['id'] );
		if ( $stat ) {
			$html .= '<div class="asemf-table-wrap"><table class="asemf-table asemf-stack"><thead><tr><th>Corso</th><th>Anno</th><th>Iscrizione</th><th>Pagamenti</th></tr></thead><tbody>';
			foreach ( $stat as $s ) {
				$e     = $s['enrollment'];
				$unpd  = $s['summary']['unpaid_months'] ? '<div class="asemf-small">Mesi da pagare: ' . esc_html( implode( ', ', array_map( function ( $m ) {
					return self::MONTHS[ (int) substr( $m['month'], 5, 2 ) ] . ' ' . substr( $m['month'], 0, 4 );
				}, $s['summary']['unpaid_months'] ) ) ) . '</div>' : '';
				$html .= '<tr><td class="asemf-cell-main">' . esc_html( $s['activity']['name'] ) . '</td><td data-label="Anno">' . esc_html( $s['activity']['social_year'] ) . '</td><td data-label="Iscrizione">'
					. ( null === $e['end_month'] ? 'Attiva' : 'Conclusa' ) . '</td><td data-label="Pagamenti">' . self::pay_text( $s['summary'] ) . $unpd . '</td></tr>';
			}
			$html .= '</tbody></table></div>';
		}

		$upcoming = array();
		$past     = array();
		foreach ( Plugin::activities()->bookings_for_person( (int) $p['id'] ) as $b ) {
			if ( $b['active'] && $b['session_date'] >= $today ) {
				$upcoming[] = $b;
			} else {
				$past[] = $b;
			}
		}
		if ( $upcoming ) {
			usort( $upcoming, function ( $a, $b ) {
				return strcmp( $a['session_date'] . $a['start_time'], $b['session_date'] . $b['start_time'] );
			} );
			$html .= '<h4>Prossime prenotazioni</h4><ul class="asemf-list">';
			foreach ( $upcoming as $b ) {
				$html .= '<li><div><strong>' . esc_html( $b['activity_name'] ) . '</strong><div class="asemf-small">' . esc_html( self::date_long( $b['session_date'] ) )
					. ( $b['start_time'] ? ' · ore ' . esc_html( $b['start_time'] ) : '' ) . ( $b['location'] ? ' · ' . esc_html( $b['location'] ) : '' ) . '</div>'
					. '<div class="asemf-small">Contributo ' . esc_html( Money::format( (int) $b['fee_due_cents'] ) ) . ' · ' . self::booking_pay( $b ) . '</div></div>'
					. self::ticket_qr( $b ) . self::booking_controls( $b, $p ) . '</li>';
			}
			$html .= '</ul>';
		}
		if ( ! $stat && ! $upcoming && ! $past ) {
			$html .= '<p class="asemf-muted">Non sei ancora iscritto a nessuna attività.</p>';
		}
		$unpaid = array_filter( array_merge( $upcoming, $past ), function ( $b ) {
			return $b['active'] && $b['remaining'] > 0;
		} );
		if ( $unpaid || array_filter( $stat, function ( $s ) {
			return $s['summary']['balance'] < 0;
		} ) ) {
			if ( ! Plugin::payments()->enabled() ) {
				$html .= '<p class="asemf-small asemf-muted">' . esc_html( Settings::payment_hint() ) . '</p>';
			}
		}
		if ( $past ) {
			$html .= '<details class="asemf-details"><summary>Storico prenotazioni</summary><ul class="asemf-list">';
			foreach ( array_slice( $past, 0, 10 ) as $b ) {
				$html .= '<li><div><strong>' . esc_html( $b['activity_name'] ) . '</strong><div class="asemf-small">' . esc_html( self::date_long( $b['session_date'] ) ) . ( $b['active'] ? '' : ' · annullata' ) . '</div></div></li>';
			}
			$html .= '</ul></details>';
		}
		return $html . '</section>';
	}

	/** Biglietto QR di una prenotazione: solo per gli eventi per cui il gestore l'ha attivato. */
	private static function ticket_qr( array $b ): string {
		if ( ! Settings::tickets_enabled() || empty( $b['booking_qr'] ) || empty( $b['active'] ) ) {
			return '';
		}
		$url = Settings::ticket_url( (int) $b['session_id'], (int) $b['person_id'] );
		try {
			$svg = \AssociazioneSemplice\QrCode::svg( $url, 4, 'Biglietto QR: ' . $b['activity_name'] );
		} catch ( \InvalidArgumentException $e ) {
			return '';
		}
		return '<details class="asemf-details asemf-ticket"><summary>Biglietto QR</summary><div class="asemf-memcard-qr">' . $svg
			. '<div class="asemf-small">Mostralo all\'ingresso: si vede se la prenotazione è valida e se il contributo è versato. <a href="' . esc_url( $url ) . '" target="_blank" rel="noopener">Apri la verifica</a></div></div></details>';
	}

	/** Annulla (se la regola lo consente) e Cambia nominativo di una prenotazione dell'area soci. */
	private static function booking_controls( array $b, array $actor ): string {
		$svc  = Plugin::activities();
		$sid  = (int) $b['session_id'];
		$pid  = (int) $b['person_id'];
		$ev   = $svc->cancellation_for( $sid, $pid );
		$html = '<div class="asemf-manage">';
		if ( $ev['allowed'] ) {
			$html .= '<div class="asemf-small asemf-muted">' . esc_html( $ev['message'] ) . '</div>'
				. self::form( 'asem_front_cancel_booking', self::hidden( 'session_id', $sid ) . self::hidden( 'person_id', $pid ), 'Annulla prenotazione', true, 'asemf-inline' );
		} else {
			$html .= '<div class="asemf-small asemf-muted">' . esc_html( $ev['message'] ) . '</div>';
		}
		if ( $ev['can_transfer'] ) {
			$pool    = array_merge( array( $actor ), Plugin::people()->managed_by( (int) $actor['id'] ) );
			$options = '';
			foreach ( $pool as $cand ) {
				if ( (int) $cand['id'] === $pid || $svc->has_active_booking( $sid, (int) $cand['id'] ) ) {
					continue;
				}
				$options .= '<option value="' . (int) $cand['id'] . '">' . esc_html( $cand['first_name'] . ' ' . $cand['last_name'] ) . '</option>';
			}
			$fields = '<div class="asemf-fields">'
				. ( '' !== $options ? '<label>Intesta a <select name="to_person_id"><option value="">— scegli —</option>' . $options . '</select></label>' : '' )
				. '<label>' . ( '' !== $options ? 'oppure nuovo ospite: nome' : 'Nuovo ospite: nome' ) . ' <input type="text" name="new_first_name"></label><label>Cognome <input type="text" name="new_last_name"></label><label>Cellulare <input type="tel" name="new_phone" placeholder="333 1234567"></label></div>';
			$html  .= '<details class="asemf-details"><summary>Cedi il posto a un\'altra persona</summary>'
				. '<p class="asemf-small asemf-muted">Se il nuovo partecipante ha un contributo diverso (ad esempio un ospite) la differenza va integrata.</p>'
				. self::form( 'asem_front_transfer_booking', self::hidden( 'session_id', $sid ) . self::hidden( 'person_id', $pid ) . $fields, 'Cedi il posto' ) . '</details>';
		}
		return $html . '</div>';
	}

	/** Stato di un pagamento online, per l'elenco dei pagamenti recenti. */
	private static function payment_status( string $status ): string {
		$m = array(
			'paid' => 'pagato', 'pending' => 'in attesa di conferma', 'created' => 'avviato', 'processing' => 'in registrazione',
			'cancelled' => 'annullato', 'failed' => 'non riuscito', 'expired' => 'scaduto',
		);
		return $m[ $status ] ?? $status;
	}

	/** Cosa c'è da pagare (quota associativa, mensilità, eventi) e, se i pagamenti online sono attivi, il pulsante per pagare. */
	public static function section_pay( array $p ): string {
		$pay  = Plugin::payments();
		$dues = $pay->dues_for( $p );
		$html = '<section class="asemf-section asemf-pay"><h3>Pagamenti</h3>';
		$bank = Bank::enabled();
		if ( ! $dues ) {
			$html .= '<p class="asemf-muted">Non hai nulla da pagare al momento.</p>';
		} elseif ( ! $pay->enabled() ) {
			$html .= '<ul class="asemf-list">';
			foreach ( $dues as $i ) {
				$html .= '<li><div><strong>' . esc_html( $i['label'] ) . '</strong><div class="asemf-small">' . esc_html( $i['person_name'] ) . '</div></div><strong>' . esc_html( Money::format( (int) $i['amount_cents'] ) ) . '</strong></li>';
			}
			$html .= '</ul>' . ( $bank ? '' : '<p class="asemf-small asemf-muted">' . esc_html( Settings::payment_hint() ) . '</p>' );
		} else {
			$total  = 0;
			$fields = '<ul class="asemf-list asemf-paylist">';
			foreach ( $dues as $i ) {
				$total  += (int) $i['amount_cents'];
				$fields .= '<li><label class="asemf-payrow"><input type="checkbox" name="items[]" value="' . esc_attr( $i['key'] ) . '" data-cents="' . (int) $i['amount_cents'] . '" checked> '
					. '<span><strong>' . esc_html( $i['label'] ) . '</strong><span class="asemf-small"> · ' . esc_html( $i['person_name'] ) . '</span></span></label>'
					. '<strong>' . esc_html( Money::format( (int) $i['amount_cents'] ) ) . '</strong></li>';
			}
			$fields .= '</ul><p class="asemf-paytotal">Totale: <strong class="asemf-pay-total">' . esc_html( Money::format( $total ) ) . '</strong></p>';
			$all      = Settings::all();
			$buttons  = array();
			$notes    = array();
			foreach ( $pay->providers() as $g ) { // un pulsante per ogni metodo attivo, con le diciture scelte dall'amministratore
				$buttons[] = array( 'value' => $g, 'label' => PaymentConfig::label( $g, $all ) );
				$notes[]   = trim( PaymentConfig::note( $g, $all ) . ' ' . PaymentConfig::fee_note( $all, $g ) ); // se il metodo ribalta la commissione, il socio lo legge prima di pagare
			}
			$html .= self::form( 'asem_front_pay', $fields, '', false, '', false, $buttons );
			foreach ( array_filter( $notes ) as $n ) {
				$html .= '<p class="asemf-small asemf-muted">' . esc_html( $n ) . '</p>';
			}
		}
		if ( $dues && $bank ) { // coordinate per il bonifico, accanto ai pagamenti online o al loro posto
			$html .= Bank::html_accounts( $p, array_values( $dues ) )
				. self::form( 'asem_front_bank_email', '', 'Mandami le coordinate per email', false, 'asemf-bank-form' );
		}
		$recent = $pay->list( array( 'payer_person_id' => (int) $p['id'] ), 5 );
		if ( $recent ) {
			$html .= '<details class="asemf-details"><summary>Ultimi pagamenti online</summary><ul class="asemf-list">';
			foreach ( $recent as $r ) {
				$html .= '<li><div>' . esc_html( ( new \DateTimeImmutable( $r['created_at'] ) )->format( 'd/m/Y' ) ) . ' · ' . esc_html( self::payment_status( $r['status'] ) ) . '</div><strong>' . esc_html( Money::format( (int) $r['amount_cents'] ) ) . '</strong></li>';
			}
			$html .= '</ul></details>';
		}
		return $html . '</section>';
	}

	public static function pay(): string {
		return self::with_person( array( __CLASS__, 'section_pay' ) );
	}

	/** Spese del tesoriere: modulo con scatto dello scontrino + le ultime spese registrate da lui (nessun saldo, nessun altro movimento). */
	/** Area segreteria: il punto d'ingresso di chi lavora con la segreteria (anche presidente e vicepresidente). Il lavoro vero resta nell'amministrazione. */
	public static function secretary(): string {
		if ( ! is_user_logged_in() ) {
			return self::login_prompt();
		}
		if ( ! current_user_can( Plugin::CAP_OPS ) ) {
			return self::notice( 'Questa pagina è riservata alla segreteria.' );
		}
		$url   = function ( string $page ) {
			return esc_url( admin_url( 'admin.php?page=' . $page ) );
		};
		$pend  = count( \AssociazioneSemplice\AccessRequests::pending() );
		$links = array( array( 'asem-people', 'Soci e ospiti', true ), array( 'asem-person', 'Nuovo socio', true ), array( 'asem-messages', 'Comunicazioni', \AssociazioneSemplice\Modules::on( 'messages' ) ), array( 'asem-activities', 'Corsi ed eventi', \AssociazioneSemplice\Modules::on( 'activities' ) ), array( 'asem-money', 'Cassa', \AssociazioneSemplice\Modules::on( 'ledger' ) ), array( 'asem-book', 'Libro soci e registri', \AssociazioneSemplice\Modules::on( 'book' ) ), array( 'asem', 'Bacheca', true ) );
		$html  = '<section class="asemf-section asemf-secretary"><h3>Segreteria</h3>';
		$apps  = \AssociazioneSemplice\Applications::pending_count();
		if ( $apps > 0 ) {
			$html .= '<p><strong>' . (int) $apps . ( 1 === $apps ? ' domanda di adesione' : ' domande di adesione' ) . '</strong> da approvare: si approvano dalla <a href="' . $url( 'asem' ) . '">Bacheca</a>.</p>';
		}
		$html .= '<p>' . ( $pend > 0 ? '<strong>' . (int) $pend . ( 1 === $pend ? ' richiesta di accesso' : ' richieste di accesso' ) . '</strong> da controllare: le trovi in <a href="' . $url( 'asem' ) . '">Bacheca</a>.' : 'Nessuna richiesta di accesso da controllare.' ) . '</p><p class="asemf-small asemf-muted">Da qui si arriva alle funzioni di gestione.</p><p class="asemf-actions">';
		foreach ( $links as $l ) {
			if ( $l[2] && \AssociazioneSemplice\Employees::page_allowed( $l[0] ) ) { // un dipendente non vede i collegamenti ai permessi che gli sono stati tolti
				$html .= '<a class="asemf-btn" href="' . $url( $l[0] ) . '">' . esc_html( $l[1] ) . '</a> ';
			}
		}
		return $html . '</p></section>';
	}

	/** Nuova iscrizione (tesoriere): nome, cognome e tipo di socio; email e cellulare facoltativi. */
	private static function section_new_member(): string {
		if ( ! current_user_can( 'asem_register_member', 0 ) ) {
			return '';
		}
		$opts = '';
		foreach ( \AssociazioneSemplice\Levels::choices( MemberType::ORDINARY ) as $lv ) {
			$opts .= '<option value="' . (int) $lv['id'] . '">' . esc_html( $lv['name'] ) . '</option>';
		}
		if ( '' === $opts ) {
			return '';
		}
		$fields = '<div class="asemf-fields"><label>Nome <input type="text" name="first_name" required autocomplete="off"></label><label>Cognome <input type="text" name="last_name" required autocomplete="off"></label></div>'
			. '<div class="asemf-fields"><label>Email (facoltativa) <input type="email" name="email" autocomplete="off"></label><label>Cellulare (facoltativo) <input type="tel" name="phone" autocomplete="off"></label>'
			. '<label>Tipo di socio <select name="level_id">' . $opts . '</select></label></div>';
		return '<section class="asemf-section asemf-new-member"><h3>Nuova iscrizione</h3><p class="asemf-small asemf-muted">Registra un nuovo socio: bastano nome e cognome, i dati restanti si completano dopo. La quota si incassa qui sotto.</p>'
			. self::form( 'asem_front_new_member', $fields, 'Iscrivi' ) . '</section>';
	}

	/** Incassi del tesoriere: una persona, un conto e fino a tre voci (quota associativa, eventi, altre entrate). Il resto lo calcola la contabilità. */
	private static function section_collect(): string {
		if ( ! current_user_can( 'asem_collect', 0 ) ) {
			return '';
		}
		$ledger = Plugin::ledger();
		$people = '<option value="">— scegli —</option>';
		foreach ( Plugin::people()->search() as $p ) {
			$people .= '<option value="' . (int) $p['id'] . '">' . esc_html( trim( $p['last_name'] . ' ' . $p['first_name'] ) ) . '</option>';
		}
		$accounts = '';
		foreach ( $ledger->accounts() as $a ) {
			$accounts .= '<option value="' . (int) $a['id'] . '">' . esc_html( $a['name'] ) . '</option>';
		}
		$what = '<option value="">— niente —</option><option value="m">Quota associativa</option><optgroup label="Eventi">';
		foreach ( Plugin::activities()->upcoming_sessions( 40 ) as $s ) {
			$what .= '<option value="s:' . (int) $s['id'] . '">' . esc_html( $s['activity_name'] . ' · ' . self::d( $s['session_date'] ) ) . '</option>';
		}
		$what .= '</optgroup><optgroup label="Corsi (mese in corso)">';
		foreach ( Plugin::activities()->for_year( Settings::social_year()->label() ) as $a ) {
			if ( ActivityKind::COURSE === $a['kind'] ) {
				$what .= '<option value="k:' . (int) $a['id'] . '">' . esc_html( $a['name'] ) . '</option>';
			}
		}
		$what .= '</optgroup><optgroup label="Altre entrate">';
		foreach ( $ledger->categories() as $c ) {
			if ( Labels::category_kinds()[ $c['kind'] ][1] && ! in_array( $c['kind'], array( 'membership', 'activity_fee', 'adjustment' ), true ) ) {
				$what .= '<option value="c:' . (int) $c['id'] . '">' . esc_html( $c['name'] ) . '</option>';
			}
		}
		$what .= '</optgroup>';
		$rows  = '';
		for ( $i = 0; $i < 3; $i++ ) {
			$rows .= '<div class="asemf-fields"><label>Voce <select name="lines[' . $i . '][what]">' . $what . '</select></label>'
				. '<label>Importo (€) <input type="text" name="lines[' . $i . '][amount]" inputmode="decimal" placeholder="0,00"></label></div>';
		}
		$fields = '<div class="asemf-fields"><label>Chi paga <select name="person_id" required>' . $people . '</select></label>'
			. '<label>Sul conto <select name="account_id">' . $accounts . '</select></label></div>' . $rows;
		return '<section class="asemf-section asemf-collect"><h3>Incassa</h3><p class="asemf-small asemf-muted">Quota associativa, eventi, corsi e altre entrate. Per le quote l\'anno viene calcolato automaticamente.</p>'
			. self::form( 'asem_front_collect', $fields, 'Registra l\'incasso' ) . '</section>';
	}


	public static function section_expenses( array $p ): string {
		if ( ! current_user_can( 'asem_add_expense', 0 ) ) {
			return '';
		}
		$ledger = Plugin::ledger();
		$cats   = array();
		foreach ( $ledger->categories() as $c ) {
			if ( Labels::category_kinds()[ $c['kind'] ][2] && 'adjustment' !== $c['kind'] ) {
				$cats[ (int) $c['id'] ] = $c['name'];
			}
		}
		$accounts = array();
		foreach ( $ledger->accounts() as $a ) {
			$accounts[ (int) $a['id'] ] = $a['name'];
		}
		$methods = array_diff_key( Labels::methods(), array_fill_keys( \AssociazioneSemplice\ActivityReset::online_methods(), 1 ) );
		$acts    = array();
		foreach ( Plugin::activities()->for_year( Settings::social_year()->label() ) as $a ) {
			$acts[ (int) $a['id'] ] = $a['name'];
		}
		$default = $ledger->default_account_for( 'cash' );
		$opts    = function ( array $items, $sel = null, string $empty = '' ) {
			$h = '' !== $empty ? '<option value="">' . esc_html( $empty ) . '</option>' : '';
			foreach ( $items as $k => $label ) {
				$h .= '<option value="' . esc_attr( (string) $k ) . '"' . ( (string) $k === (string) $sel ? ' selected' : '' ) . '>' . esc_html( $label ) . '</option>';
			}
			return $h;
		};
		$fields = '<div class="asemf-fields">'
			. '<label>Data <input type="date" name="date" value="' . esc_attr( current_time( 'Y-m-d' ) ) . '" max="' . esc_attr( current_time( 'Y-m-d' ) ) . '" required></label>'
			. '<label>Importo (€) <input type="text" name="amount" inputmode="decimal" placeholder="0,00" required></label>'
			. '<label>Voce <select name="category_id" required>' . $opts( $cats, null, '— scegli —' ) . '</select></label>'
			. '<label>Dal conto <select name="account_id">' . $opts( $accounts, $default ? $default['id'] : null ) . '</select></label>'
			. '<label>Attività <select name="activity_id">' . $opts( $acts, null, 'Nessuna (costo generale)' ) . '</select></label>'
			. '<label>Descrizione <input type="text" name="description" maxlength="255"></label>'
			. '<label>N. fattura / scontrino <input type="text" name="document_ref" maxlength="80"></label>'
			. '</div>' . self::doc_inputs();
		$html = '<section class="asemf-section asemf-expenses"><h3>Registra una spesa</h3>'
			. '<p class="asemf-small asemf-muted">Fotografa lo scontrino o allega la fattura: la spesa entra in prima nota con il documento.</p>'
			. self::form( 'asem_front_expense', $fields, 'Registra la spesa', false, '', true );
		$mine = $ledger->expenses_by_user( get_current_user_id(), 10 );
		if ( $mine ) {
			$att   = \AssociazioneSemplice\Attachments::map_for( array_column( $mine, 'id' ) );
			$html .= '<h4>Le tue ultime spese</h4><ul class="asemf-list">';
			foreach ( $mine as $t ) {
				$docs  = '';
				foreach ( $att[ (int) $t['id'] ] ?? array() as $a ) {
					$docs .= '<a href="' . esc_url( \AssociazioneSemplice\Attachments::url( (int) $a['id'] ) ) . '" target="_blank" rel="noopener">' . esc_html( $a['original_name'] ) . '</a> ';
				}
				$more  = '<details class="asemf-details"><summary>Aggiungi documenti</summary>'
					. self::form( 'asem_front_expense_docs', self::hidden( 'transaction_id', $t['id'] ) . self::doc_inputs(), 'Allega', false, '', true ) . '</details>';
				$html .= '<li><div><strong>' . esc_html( $t['category_name'] ) . '</strong> · ' . esc_html( self::d( $t['tx_date'] ) )
					. ( '' !== $t['description'] ? '<div class="asemf-small">' . esc_html( $t['description'] ) . '</div>' : '' )
					. '<div class="asemf-small">' . ( $docs ? '📎 ' . $docs : '<span class="asemf-muted">nessun documento</span>' ) . '</div>' . $more . '</div>'
					. '<strong>' . esc_html( Money::format( (int) $t['amount_cents'] ) ) . '</strong></li>';
			}
			$html .= '</ul>';
		}
		return self::section_new_member() . self::section_collect() . (string) apply_filters( 'asem_treasurer_sections', '' ) . $html . '</section>';
	}

	/** Scelta dei documenti: file dal telefono o dal computer, oppure scatto con la fotocamera. */
	private static function doc_inputs(): string {
		return '<div class="asemf-docs"><label class="asemf-file">Documenti (PDF o foto) <input type="file" name="docs[]" class="asem-doc-input" accept="image/*,application/pdf" multiple></label>'
			. '<label class="asemf-btn asemf-shot">📷 Scatta una foto<input type="file" name="shots[]" class="asem-doc-input" accept="image/*" capture="environment" hidden></label></div>';
	}

	public static function expenses(): string {
		return self::with_person(
			function ( $p ) {
				return current_user_can( 'asem_add_expense', 0 ) ? self::section_expenses( $p ) : self::notice( 'Questa pagina è riservata al tesoriere.' );
			}
		);
	}

	public static function section_guests( array $p ): string {
		if ( ! MemberType::is_member( $p['type'] ) || ( ! Settings::guests_enabled() && ! Plugin::people()->guests_of( (int) $p['id'] ) ) ) {
			return '';
		}
		$guests = Plugin::people()->guests_of( (int) $p['id'] );
		$html   = '<section class="asemf-section"><h3>I miei ospiti</h3>';
		$html  .= '<p class="asemf-muted">Gli ospiti possono partecipare alle attività senza essere soci: puoi prenotarli agli eventi.</p>';
		if ( $guests ) {
			$html .= '<ul class="asemf-list">';
			$today = current_time( 'Y-m-d' );
			foreach ( $guests as $g ) {
				$tickets = '';
				foreach ( Plugin::activities()->bookings_for_person( (int) $g['id'] ) as $b ) { // biglietti QR degli eventi che li prevedono
					if ( $b['active'] && $b['session_date'] >= $today && Settings::tickets_enabled() && ! empty( $b['booking_qr'] ) ) {
						$tickets .= '<div class="asemf-small">' . esc_html( $b['activity_name'] ) . ' · ' . esc_html( self::date_long( $b['session_date'] ) ) . '</div>' . self::ticket_qr( $b );
					}
				}
				$st      = Plugin::activities()->guest_status( (int) $g['id'] );
				$seen    = array();
				foreach ( array_slice( $st['items'], 0, 3 ) as $it ) {
					$seen[] = $it['activity_name'];
				}
				$status = '<div class="asemf-small asemf-muted">Partecipazioni: ' . (int) $st['count']
					. ( $seen ? ' (' . esc_html( implode( ', ', $seen ) ) . ')' : '' ) . '</div>';
				$html .= '<li><div><strong>' . esc_html( $g['first_name'] . ' ' . $g['last_name'] ) . '</strong>' . $status . $tickets . '</div></li>';
			}
			$html .= '</ul>';
		}
		$fields = '<div class="asemf-fields"><label>Nome <input type="text" name="first_name" required></label><label>Cognome <input type="text" name="last_name" required></label>'
			. '<label>Email (facoltativa) <input type="email" name="email"></label><label>Cellulare (obbligatorio) <input type="tel" name="phone" required placeholder="333 1234567" inputmode="tel"></label></div>';
		return $html . '<details class="asemf-details"><summary>Aggiungi un ospite</summary>' . self::form( 'asem_front_add_guest', $fields, 'Aggiungi ospite' ) . '</details></section>';
	}

	/**
	 * I minori di cui il socio è tutore: per ognuno la tessera, le attività e, finché mancano, il regolamento e la privacy da accettare per suo conto.
	 * I pagamenti da fare per loro compaiono insieme a quelli del tutore, in «Pagamenti».
	 */
	public static function section_minors( array $p ): string {
		if ( ! MemberType::can_login( $p['type'] ) ) {
			return '';
		}
		$wards = Plugin::people()->dependents_of( (int) $p['id'] );
		if ( ! $wards ) {
			return '';
		}
		$html = '<section class="asemf-section"><h3>I miei figli</h3><p class="asemf-muted">Sei il tutore di queste persone: vedi la loro tessera e le attività, le prenoti agli eventi e paghi per loro. Le email che li riguardano arrivano anche a te.</p></section>';
		foreach ( $wards as $m ) {
			$name  = trim( $m['first_name'] . ' ' . $m['last_name'] );
			$adult = ! \AssociazioneSemplice\Age::is_minor( $m['birth_date'] ?? null, current_time( 'Y-m-d' ) ) && ! empty( $m['birth_date'] );
			$html .= '<section class="asemf-section"><h3>' . esc_html( $name ) . '</h3>'
				. ( $adult ? '<p class="asemf-small asemf-muted">Ha compiuto 18 anni: può avere un suo accesso. Chiedi alla segreteria di invitarlo; finché il collegamento resta, continui a vedere i suoi dati.</p>' : '' )
				. '</section>';
			$html .= self::ward_accept_box( $p, $m );
			$html .= self::section_card( $m );
			$html .= str_replace( '<h3>Le mie attività</h3>', '<h3>Attività di ' . esc_html( $m['first_name'] ) . '</h3>', self::section_activities( $m ) );
		}
		return $html;
	}

	/** Casella per accettare, a nome del minore, il regolamento e la privacy ancora mancanti. */
	private static function ward_accept_box( array $guardian, array $m ): string {
		$fields = '';
		if ( \AssociazioneSemplice\Regulation::applies_to( $m ) && ! \AssociazioneSemplice\Regulation::accepted( $m ) ) {
			$title   = mb_strtolower( \AssociazioneSemplice\Regulation::title(), 'UTF-8' );
			$fields .= \AssociazioneSemplice\Regulation::box_html() . '<label><input type="checkbox" name="rules_ok" value="1"> Ho letto e accetto il ' . esc_html( $title ) . ' per conto di ' . esc_html( $m['first_name'] ) . '.</label>';
		}
		if ( ! \AssociazioneSemplice\Privacy::has_consent( $m ) ) {
			$fields .= '<p><label><input type="checkbox" name="privacy_ok" value="1"> Ho letto l\'<a href="' . esc_url( (string) Settings::get( 'privacy_url' ) ) . '" target="_blank" rel="noopener">informativa privacy</a> e acconsento al trattamento dei dati di ' . esc_html( $m['first_name'] ) . '.</label></p>';
		}
		if ( '' === $fields ) {
			return '';
		}
		return '<section class="asemf-section asemf-rules-box"><h3>Da accettare per conto di ' . esc_html( $m['first_name'] ) . '</h3>'
			. self::form( 'asem_front_accept_for', self::hidden( 'person_id', $m['id'] ) . $fields, 'Accetto' ) . '</section>';
	}

	/** Avviso in cima all'area: il profilo va completato (indirizzo e codice fiscale) prima di prenotare e pagare. Vuoto se non serve. */
	public static function section_complete( array $p ): string {
		if ( empty( $p['profile_due'] ) ) {
			return '';
		}
		$missing = Plugin::people()->profile_missing( $p );
		if ( ! $missing ) {
			return '';
		}
		return '<section class="asemf-section asemf-complete"><h3>Completa i tuoi dati</h3><p>Per usare l\'area riservata mancano ancora: <strong>' . esc_html( implode( ', ', $missing ) ) . '</strong>.'
			. ( Limits::flag( 'profile_gate' ) ? ' Finché non li inserisci non puoi prenotare né pagare online.' : '' ) . ' Li trovi nel riquadro «Il mio profilo» qui sotto.</p></section>';
	}

	public static function section_profile( array $p ): string {
		$fields = '<div class="asemf-fields"><label>Telefono <input type="text" name="phone" value="' . esc_attr( (string) $p['phone'] ) . '"></label>'
			. '<label>Codice fiscale <input type="text" name="tax_code" maxlength="16" autocomplete="off" value="' . esc_attr( (string) $p['tax_code'] ) . '"></label>'
			. '<label>Indirizzo (via e numero) <input type="text" name="address" maxlength="190" autocomplete="street-address" value="' . esc_attr( (string) $p['address'] ) . '"></label>'
			. '<label>CAP <input type="text" name="zip" maxlength="12" autocomplete="postal-code" value="' . esc_attr( (string) $p['zip'] ) . '"></label>'
			. '<label>Comune <input type="text" name="city" maxlength="100" autocomplete="address-level2" value="' . esc_attr( (string) $p['city'] ) . '"></label>'
			. '<label>Provincia <input type="text" name="province" maxlength="5" autocomplete="address-level1" value="' . esc_attr( (string) $p['province'] ) . '"></label></div>';
		return self::section_complete( $p ) . '<section class="asemf-section"><h3>Il mio profilo</h3><dl class="asemf-dl"><div><dt>Nome</dt><dd>' . esc_html( $p['first_name'] . ' ' . $p['last_name'] ) . '</dd></div>'
			. '<div><dt>Email</dt><dd>' . esc_html( (string) $p['email'] ) . '</dd></div></dl>'
			. '<p class="asemf-small asemf-muted">Per cambiare nome o email scrivi all\'associazione.</p>'
			. self::form( 'asem_front_profile', $fields, 'Salva' )
			. '<p class="asemf-small"><a href="' . esc_url( \AssociazioneSemplice\Privacy::export_url( (int) $p['id'] ) ) . '">Scarica i miei dati</a>'
			. ( '' !== (string) Settings::get( 'privacy_url' ) ? ' · <a href="' . esc_url( (string) Settings::get( 'privacy_url' ) ) . '" target="_blank" rel="noopener">Informativa sulla privacy</a>' : '' ) . '</p></section>';
	}

	// ---------- Gestione degli eventi: prenotati e ingressi ----------

	/** Eventi che l'utente può gestire (referente, gestori indicati, amministratori). @return array[] attività */
	private static function managed_events(): array {
		$svc = Plugin::activities();
		$out = array();
		if ( Access::is_admin_user( get_current_user_id() ) ) {
			foreach ( $svc->for_year( Settings::social_year()->label() ) as $a ) {
				if ( ActivityKind::uses_sessions( $a['kind'] ) ) {
					$out[] = $a;
				}
			}
			return $out;
		}
		$person = Access::current_person();
		foreach ( $person ? $svc->managed_activity_ids( (int) $person['id'] ) : array() as $aid ) {
			$a = $svc->get( $aid );
			if ( $a && current_user_can( 'asem_manage_event', $aid ) ) {
				$out[] = $a;
			}
		}
		return $out;
	}

	private static function session_url( int $session_id = 0 ): string {
		$base = remove_query_arg( array( 'asemf_ok', 'asemf_err', 'asemf_sig', 'asem_session' ), Restrict::current_url() );
		return $session_id ? add_query_arg( 'asem_session', $session_id, $base ) : $base;
	}

	/** Elenco delle date degli eventi gestiti e, aprendone una, lista dei prenotati con registrazione degli ingressi. */
	public static function section_checkin( array $p ): string {
		$events = self::managed_events();
		if ( ! $events ) {
			return '';
		}
		$svc = Plugin::activities();
		$sid = isset( $_GET['asem_session'] ) ? (int) $_GET['asem_session'] : 0; // phpcs:ignore WordPress.Security.NonceVerification
		if ( $sid ) {
			$s = $svc->session( $sid );
			foreach ( $events as $a ) {
				if ( $s && (int) $s['activity_id'] === (int) $a['id'] ) {
					return self::checkin_detail( $s, $a );
				}
			}
		}
		$today = current_time( 'Y-m-d' );
		$from  = gmdate( 'Y-m-d', strtotime( $today . ' -3 days' ) );
		$html  = '<section class="asemf-section asemf-checkin"><h3>Ingressi agli eventi</h3><p class="asemf-small asemf-muted">Gli eventi che gestisci: apri una data per vedere i prenotati e registrare gli ingressi.</p>';
		$any   = false;
		foreach ( $events as $a ) {
			$rows = '';
			foreach ( $svc->sessions( (int) $a['id'] ) as $s ) {
				if ( ! empty( $s['cancelled_at'] ) || $s['session_date'] < $from ) {
					continue;
				}
				$active  = 0;
				$present = 0;
				foreach ( $svc->bookings_for_session( (int) $s['id'] ) as $b ) {
					if ( $b['active'] ) {
						$active++;
						$present += ! empty( $b['checked_in_at'] ) ? 1 : 0;
					}
				}
				$rows .= '<li><div><strong>' . esc_html( self::date_long( $s['session_date'] ) ) . ( $s['start_time'] ? ' · ore ' . esc_html( $s['start_time'] ) : '' ) . '</strong>'
					. ( $s['session_date'] === $today ? ' <span class="asemf-badge asemf-badge-ok">oggi</span>' : '' )
					. '<div class="asemf-small">' . (int) $active . ' prenotati · ' . (int) $present . ' presenti</div></div>'
					. '<a class="asemf-btn" href="' . esc_url( self::session_url( (int) $s['id'] ) ) . '">Apri</a></li>';
			}
			if ( '' !== $rows ) {
				$any   = true;
				$html .= '<h4>' . esc_html( $a['name'] ) . '</h4><ul class="asemf-list">' . $rows . '</ul>';
			}
		}
		if ( ! $any ) {
			$html .= '<p class="asemf-muted">Nessuna data in programma per i tuoi eventi.</p>';
		}
		return $html . '</section>';
	}

	/** Contatore dei posti e, per chi può incassare, il modulo dell'ingresso sul posto (amico dell'ultimo minuto). */
	private static function seats_html( array $s ): string {
		$seat = Plugin::activities()->seats( (int) $s['id'] );
		if ( null === $seat['capacity'] ) {
			return '';
		}
		$cls  = 0 === $seat['free'] ? 'asemf-bad' : 'asemf-good';
		$wait = \AssociazioneSemplice\Waitlist::count( (int) $s['id'] );
		return '<p class="' . $cls . '"><strong>' . ( 0 === $seat['free'] ? 'Posti esauriti · lista d’attesa' : 'Posti liberi: ' . (int) $seat['free'] ) . '</strong> <span class="asemf-small asemf-muted">(' . (int) $seat['taken'] . ' prenotati su ' . (int) $seat['capacity'] . ')</span>' . ( $wait ? ' <span class="asemf-small asemf-muted">· in lista d\'attesa: ' . (int) $wait . '</span>' : '' ) . '</p>';
	}



	private static function checkin_detail( array $s, array $a ): string {
		$svc      = Plugin::activities();
		$today    = current_time( 'Y-m-d' );
		$list     = '';
		$booked   = 0;
		$present  = 0;
		$unpaid   = 0;
		$cancel   = 0;
		$all_bookings = $svc->bookings_for_session( (int) $s['id'] );
		$gov          = Plugin::people()->guest_overview();
		foreach ( $all_bookings as $b ) {
			if ( ! $b['active'] ) {
				$cancel++;
				continue;
			}
			$booked++;
			$in    = ! empty( $b['checked_in_at'] );
			$present += $in ? 1 : 0;
			$host  = '';
			if ( MemberType::GUEST === $b['type'] && ! empty( $b['host_person_id'] ) ) {
				$h    = Plugin::people()->get( (int) $b['host_person_id'] );
				$host = $h ? ' di ' . $h['first_name'] . ' ' . $h['last_name'] : '';
			}
			if ( (int) $b['fee_due_cents'] <= 0 ) {
				$pay = '<span class="asemf-small asemf-muted">Gratuito</span>';
			} elseif ( (int) $b['remaining'] > 0 ) {
				$unpaid++;
				$pay = '<span class="asemf-bad asemf-small">Da versare ' . esc_html( Money::format( (int) $b['remaining'] ) ) . '</span>';
			} else {
				$pay = '<span class="asemf-good asemf-small">Versato</span>';
			}
			$ids = self::hidden( 'session_id', $s['id'] ) . self::hidden( 'person_id', $b['person_id'] );
			$act = $in
				? '<span class="asemf-small asemf-good">✔ ore ' . esc_html( mysql2date( 'H:i', $b['checked_in_at'] ) ) . '</span>' . self::form( 'asem_front_checkin', $ids . self::hidden( 'undo', 1 ), 'Annulla', true, 'asemf-inline' )
				: self::form( 'asem_front_checkin', $ids, 'Registra ingresso', false, 'asemf-inline' );
			$name = trim( $b['first_name'] . ' ' . $b['last_name'] );
			$list .= '<li class="asemf-booked" data-name="' . esc_attr( Text::normalize( $name ) ) . '" data-state="' . ( $in ? 'in' : 'out' ) . '"><div><strong>' . esc_html( $name ) . '</strong>'
				. '<div class="asemf-small asemf-muted">' . esc_html( MemberType::GUEST === $b['type'] ? 'Ospite' . $host : MemberType::label( $b['type'] ) ) . '</div>'
				. ( MemberType::GUEST === $b['type'] ? self::guest_note( $gov[ (int) $b['person_id'] ] ?? null ) : '' ) . $pay . '</div><div class="asemf-checkin-act">' . $act . '</div></li>';
		}
		$can_scan = Settings::tickets_enabled() && ! empty( $a['booking_qr'] ) && $s['session_date'] === $today;
		$scan     = '';
		if ( $can_scan ) {
			$scan = '<div class="asemf-scan"><button type="button" class="asemf-btn" data-asemf-scan>📷 Scansiona il QR del biglietto</button> '
				. '<video class="asemf-scan-video" playsinline muted hidden></video><p class="asemf-small asemf-muted asemf-scan-msg">Oppure scansiona con la fotocamera del telefono: il QR apre la pagina dove registrare l\'ingresso.</p>'
				. self::form( 'asem_front_checkin_scan', '<input type="hidden" name="ticket" value="">', 'Registra', false, 'asemf-scan-form' ) . '</div>';
		}
		$html = '<section class="asemf-section asemf-checkin"><p><a href="' . esc_url( self::session_url() ) . '">← Tutti gli eventi</a></p>'
			. '<h3>' . esc_html( $a['name'] ) . '</h3><p><strong>' . esc_html( self::date_long( $s['session_date'] ) ) . ( $s['start_time'] ? ' · ore ' . esc_html( $s['start_time'] ) : '' ) . '</strong>'
			. ( $s['location'] ? ' · ' . esc_html( $s['location'] ) : '' ) . '</p>'
			. '<dl class="asemf-dl asemf-counts"><div><dt>Prenotati</dt><dd>' . (int) $booked . ( null === $s['capacity'] ? '' : ' / ' . (int) $s['capacity'] ) . '</dd></div><div><dt>Presenti</dt><dd>' . (int) $present . '</dd></div>'
			. '<div><dt>Da registrare</dt><dd>' . (int) ( $booked - $present ) . '</dd></div><div><dt>Contributo da versare</dt><dd>' . (int) $unpaid . '</dd></div></dl>'
			. ( $s['session_date'] !== $today ? '<p class="asemf-small asemf-muted">Gli ingressi si registrano nel giorno dell\'evento.</p>' : '' ) . self::seats_html( $s ) . $scan . (string) apply_filters( 'asem_checkin_extra', '', $s, $a ) . self::notice_form( $a, (int) $s['id'] );
		if ( ! $booked ) {
			return $html . '<p class="asemf-muted">Nessuna prenotazione.</p></section>';
		}
		return $html . '<div class="asemf-checkin-tools"><input type="search" class="asemf-search" placeholder="Cerca per nome" aria-label="Cerca per nome">'
			. '<span class="asemf-filters"><button type="button" class="asemf-chip is-on" data-filter="all">Tutti</button><button type="button" class="asemf-chip" data-filter="out">Da registrare</button><button type="button" class="asemf-chip" data-filter="in">Presenti</button></span></div>'
			. '<ul class="asemf-list asemf-booked-list">' . $list . '</ul>'
			. ( $cancel ? '<p class="asemf-small asemf-muted">' . (int) $cancel . ' prenotazioni annullate non sono in elenco.</p>' : '' ) . '</section>';
	}

	/**
	 * Nota su un ospite per chi accoglie all'ingresso: quante volte è venuto, se risulta registrato anche con altri nomi
	 * (stesso cellulare, email o nome) e se è da invitare a iscriversi. Nessun blocco: decide chi gestisce.
	 */
	public static function guest_note( ?array $ov ): string {
		if ( ! $ov ) {
			return '';
		}
		$txt = $ov['count'] . 'ª partecipazione come ospite';
		if ( $ov['twins'] ) {
			$names = array_map( function ( $t ) {
				return $t['name'] . ' (ospite di ' . $t['host'] . ', ' . $t['count'] . ')';
			}, $ov['twins'] );
			$txt  .= ' · risulta registrato anche come ' . implode( '; ', $names ) . ': in tutto ' . $ov['total'];
		}
		if ( $ov['flag'] ) {
			return '<div class="asemf-small asemf-bad">' . esc_html( $txt . ' — da invitare a iscriversi' ) . '</div>';
		}
		return '<div class="asemf-small ' . ( $ov['twins'] ? 'asemf-bad' : 'asemf-muted' ) . '">' . esc_html( $txt ) . '</div>';
	}

	// ---------- Avvisi agli iscritti ----------

	/** Modulo "Invia un avviso agli iscritti" (vuoto se l'utente non può inviarne per quell'attività). */
	private static function notice_form( array $a, ?int $session_id = null ): string {
		$aid = (int) $a['id'];
		if ( ! \AssociazioneSemplice\Notices::can_send( $aid ) ) {
			return '';
		}
		$count  = count( \AssociazioneSemplice\Notices::recipients( $aid, $session_id ) );
		$fields = self::hidden( 'activity_id', $aid ) . ( $session_id ? self::hidden( 'session_id', $session_id ) : '' ) . '<div class="asemf-fields">';
		if ( ! $session_id && ActivityKind::uses_sessions( $a['kind'] ) ) {
			$opts = '<option value="">Tutti i prenotati</option>';
			foreach ( Plugin::activities()->sessions( $aid ) as $s ) {
				if ( empty( $s['cancelled_at'] ) && $s['session_date'] >= current_time( 'Y-m-d' ) ) {
					$opts .= '<option value="' . (int) $s['id'] . '">Solo ' . esc_html( self::date_long( $s['session_date'] ) ) . '</option>';
				}
			}
			$fields .= '<label>A chi <select name="session_id">' . $opts . '</select></label>';
		}
		$fields .= '<label>Titolo <input type="text" name="subject" maxlength="' . \AssociazioneSemplice\Notices::MAX_SUBJECT . '" required></label>'
			. '<label>Messaggio <textarea name="body" rows="4" maxlength="' . \AssociazioneSemplice\Notices::MAX_BODY . '" required></textarea></label></div>';
		return '<details class="asemf-details"><summary>Invia un avviso agli iscritti</summary>'
			. '<p class="asemf-small asemf-muted">Arriva per email a chi è iscritto' . ( $session_id ? ' a questa data' : '' ) . ' (ora ' . (int) $count . ' persone; gli ospiti senza email lo ricevono tramite il socio che li ospita) e resta nella loro bacheca. Usalo per cambi dell\'ultimo momento.</p>'
			. self::form( 'asem_front_notice', $fields, 'Invia avviso', true ) . '</details>';
	}

	/** Bacheca degli avvisi per i soci (e per chi ha ospiti iscritti): quelli recenti delle attività a cui partecipano. */
	public static function section_notices( array $p ): string {
		$list = \AssociazioneSemplice\Notices::board( (int) $p['id'], 8 );
		if ( ! $list ) {
			return '';
		}
		$html = '<section class="asemf-section asemf-notices"><h3>Avvisi</h3><ul class="asemf-list">';
		foreach ( $list as $n ) {
			$html .= '<li><div><strong>' . esc_html( $n['subject'] ) . '</strong><div class="asemf-small asemf-muted">' . esc_html( $n['activity_name'] ) . ' · ' . esc_html( mysql2date( 'd/m/Y H:i', $n['created_at'] ) )
				. ' · ' . esc_html( $n['author_name'] ) . '</div><div>' . nl2br( esc_html( $n['body'] ) ) . '</div></div></li>';
		}
		return $html . '</ul></section>';
	}

	/** Calendario del mese: lezioni dei corsi e date degli eventi, con in evidenza le attività a cui partecipa il socio. */
	public static function section_calendar( array $p ): string {
		$month = isset( $_GET['asemf_m'] ) ? sanitize_text_field( wp_unslash( $_GET['asemf_m'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		if ( ! preg_match( '/^\d{4}-(0[1-9]|1[0-2])$/', $month ) ) {
			$month = substr( current_time( 'Y-m-d' ), 0, 7 );
		}
		$first = new \DateTimeImmutable( $month . '-01' );
		$mine  = array_map( 'intval', Plugin::activities()->person_activity_ids( (int) $p['id'] ) );
		$by    = array();
		foreach ( \AssociazioneSemplice\Calendar::occurrences( $first->format( 'Y-m-d' ), $first->modify( 'last day of this month' )->format( 'Y-m-d' ) ) as $o ) {
			$by[ $o['date'] ][] = $o;
		}
		$prev = $first->modify( '-1 month' )->format( 'Y-m' );
		$next = $first->modify( '+1 month' )->format( 'Y-m' );
		$html = '<section class="asemf-section asemf-calendar"><h3>Calendario</h3><p class="asemf-small">'
			. '<a href="' . esc_url( add_query_arg( 'asemf_m', $prev ) ) . '">&lsaquo; ' . esc_html( self::MONTHS[ (int) $first->modify( '-1 month' )->format( 'n' ) ] ) . '</a> &nbsp; <strong>'
			. esc_html( self::MONTHS[ (int) $first->format( 'n' ) ] . ' ' . $first->format( 'Y' ) ) . '</strong> &nbsp; '
			. '<a href="' . esc_url( add_query_arg( 'asemf_m', $next ) ) . '">' . esc_html( self::MONTHS[ (int) $first->modify( '+1 month' )->format( 'n' ) ] ) . ' &rsaquo;</a></p>';
		if ( ! $by ) {
			$html .= '<p class="asemf-muted">Nessuna lezione o evento in questo mese.</p>';
		} else {
			$html .= '<ul class="asemf-list">';
			foreach ( $by as $date => $items ) {
				$html .= '<li><div><strong>' . esc_html( self::date_long( $date ) ) . '</strong>';
				foreach ( $items as $o ) {
					$html .= '<div class="asemf-small">' . ( $o['start'] ? esc_html( $o['start'] . ( $o['end'] ? '-' . $o['end'] : '' ) ) . ' &middot; ' : '' ) . esc_html( $o['title'] )
						. ( $o['location'] ? ' <span class="asemf-muted">&middot; ' . esc_html( $o['location'] ) . '</span>' : '' )
						. ( in_array( (int) $o['activity_id'], $mine, true ) ? ' <strong>&middot; sei iscritto</strong>' : '' ) . '</div>';
				}
				$html .= '</div></li>';
			}
			$html .= '</ul>';
		}
		if ( \AssociazioneSemplice\Calendar::enabled() ) {
			$feed = \AssociazioneSemplice\Calendar::feed_url();
			$html .= '<p class="asemf-small"><a href="' . esc_url( \AssociazioneSemplice\Calendar::google_add_url( $feed ) ) . '" target="_blank" rel="noopener">Aggiungi a Google Calendar</a> &middot; <a href="' . esc_url( \AssociazioneSemplice\Calendar::webcal_url( $feed ) ) . '">Apple / Outlook</a></p>';
		}
		return $html . '</section>';
	}

	public static function calendar(): string {
		return self::with_person( array( __CLASS__, 'section_calendar' ) );
	}

	public static function notices(): string {
		return self::with_person(
			function ( $p ) {
				$html = self::section_notices( $p );
				return '' !== $html ? $html : self::notice( 'Nessun avviso recente.' );
			}
		);
	}

	public static function checkin(): string {
		return self::with_person(
			function ( $p ) {
				$html = self::section_checkin( $p );
				return '' !== $html ? $html : self::notice( 'Non gestisci nessun evento.' );
			}
		);
	}

	/** Le attività che il socio tiene, con prenotati e invio avvisi. $only: 'course' solo corsi, 'event' solo eventi, '' tutte. */
	public static function section_volunteer( array $p, string $only = '' ): string {
		if ( ! MemberType::can_teach( $p['type'] ) ) {
			return '';
		}
		$svc   = Plugin::activities();
		$title = array( 'course' => 'I corsi che gestisci', 'event' => 'Gli eventi che gestisci' )[ $only ] ?? 'Le attività che gestisci';
		$html  = '<section class="asemf-section"><h3>' . esc_html( $title ) . '</h3>';
		$found = false;
		foreach ( $svc->taught_activity_ids( (int) $p['id'] ) as $aid ) {
			$a = $svc->get( $aid );
			if ( ! $a || ! current_user_can( 'asem_view_participants', $aid ) || $a['social_year'] !== Settings::social_year()->label() ) {
				continue;
			}
			if ( ( 'course' === $only && ActivityKind::COURSE !== $a['kind'] ) || ( 'event' === $only && ActivityKind::COURSE === $a['kind'] ) ) {
				continue;
			}
			$found = true;
			$html .= '<div class="asemf-card"><h4>' . esc_html( $a['name'] ) . ' <span class="asemf-badge">' . esc_html( ActivityKind::short_label( $a['kind'] ) ) . '</span></h4>';
			if ( ActivityKind::uses_sessions( $a['kind'] ) ) {
				$sessions = array_filter( $svc->sessions( $aid ), function ( $s ) {
					return empty( $s['cancelled_at'] ) && $s['session_date'] >= current_time( 'Y-m-d' );
				} );
				if ( ! $sessions ) {
					$html .= '<p class="asemf-muted">Nessuna data in programma.</p>';
				}
				foreach ( array_slice( $sessions, 0, 6 ) as $s ) {
					$names = array();
					foreach ( $svc->bookings_for_session( (int) $s['id'] ) as $b ) {
						if ( $b['active'] ) {
							$names[] = $b['first_name'] . ' ' . $b['last_name'] . ( MemberType::GUEST === $b['type'] ? ' (ospite)' : '' );
						}
					}
					$html .= '<p><strong>' . esc_html( self::date_long( $s['session_date'] ) ) . ( $s['start_time'] ? ' · ore ' . esc_html( $s['start_time'] ) : '' ) . '</strong> — '
						. count( $names ) . ( null === $s['capacity'] ? ' prenotati' : ' / ' . (int) $s['capacity'] . ' posti' ) . '<br><span class="asemf-small">' . esc_html( $names ? implode( ', ', $names ) : 'Nessuna prenotazione' ) . '</span></p>';
				}
			} else {
				$names = array();
				foreach ( $svc->status_for_activity( $aid ) as $s ) {
					if ( null === $s['enrollment']['end_month'] ) {
						$names[] = $s['enrollment']['first_name'] . ' ' . $s['enrollment']['last_name'] . ( MemberType::GUEST === $s['enrollment']['type'] ? ' (ospite)' : '' );
					}
				}
				$html .= '<p>' . count( $names ) . ' iscritti<br><span class="asemf-small">' . esc_html( $names ? implode( ', ', $names ) : 'Nessun iscritto' ) . '</span></p>';
			}
			$html .= self::notice_form( $a );
			$html .= '</div>';
		}
		if ( ! $found && 'event' === $only ) {
			return ''; // chi gestisce eventi senza esserne referente (staff, gestori) trova qui sotto solo gli ingressi
		}
		if ( ! $found ) {
			$html .= '<p class="asemf-muted">Non sei referente di nessuna attività dell\'anno sociale in corso.</p>';
		}
		return $html . '</section>';
	}

	// ---------- Viste complete (usate da shortcode, blocchi, widget) ----------

	/** Le parti dell'area riservata: chiave => titolo. La prima è quella del socio; le altre compaiono solo a chi ha quel ruolo. */
	const PANES = array(
		'mio'         => 'Il mio spazio',
		'segreteria'  => 'Segreteria',
		'corsi'       => 'Gestione corsi',
		'eventi'      => 'Gestione eventi',
	);

	/** Sezioni della parte «Il mio spazio»: tutto ciò che riguarda il socio come persona. */
	const MY_SECTIONS = array( 'regolamento', 'tessera', 'attivita', 'calendario', 'avvisi', 'pagamenti', 'ospiti', 'minori', 'votazioni', 'documenti', 'convenzioni', 'profilo' );

	/** Sezioni dell'area soci, nell'ordine in cui compaiono (un'estensione aggiunge le sue con il filtro `asem_my_sections`). */
	private static function my_sections(): array {
		return (array) apply_filters( 'asem_my_sections', self::MY_SECTIONS );
	}

	/** @return array<string,string> le parti che questa persona può usare (chiave => titolo), sempre con «Il mio spazio» per prima */
	public static function panes_for( array $p ): array {
		$out = array( 'mio' => self::PANES['mio'] );
		if ( current_user_can( Plugin::CAP_OPS ) || current_user_can( 'asem_add_expense', 0 ) ) {
			$out['segreteria'] = self::PANES['segreteria'];
		}
		if ( ( MemberType::can_teach( $p['type'] ) || Access::is_admin_user( get_current_user_id() ) ) && self::has_managed( $p, 'course' ) ) {
			$out['corsi'] = self::PANES['corsi'];
		}
		if ( self::has_managed( $p, 'event' ) ) { // anche lo staff e chi gestisce un solo evento
			$out['eventi'] = self::PANES['eventi'];
		}
		return $out;
	}

	/** La persona gestisce almeno un corso (kind «course») o un evento (kind «event»: anche i ricorrenti)? */
	private static function has_managed( array $p, string $kind ): bool {
		if ( 'event' === $kind ) {
			return (bool) self::managed_events();
		}
		$svc = Plugin::activities();
		if ( Access::is_admin_user( get_current_user_id() ) ) {
			foreach ( $svc->for_year( Settings::social_year()->label() ) as $a ) {
				if ( ActivityKind::COURSE === $a['kind'] ) {
					return true;
				}
			}
			return false;
		}
		foreach ( $svc->taught_activity_ids( (int) $p['id'] ) as $aid ) {
			$a = $svc->get( $aid );
			if ( $a && ActivityKind::COURSE === $a['kind'] && current_user_can( 'asem_view_participants', $aid ) ) {
				return true;
			}
		}
		return false;
	}

	/** Contenuto di una parte dell'area. */
	private static function pane_html( string $pane, array $p ): string {
		switch ( $pane ) {
			case 'segreteria':
				$html = current_user_can( Plugin::CAP_OPS ) ? self::secretary() : '';
				return $html . self::section_expenses( $p );
			case 'corsi':
				return self::section_volunteer( $p, 'course' );
			case 'eventi':
				return self::section_volunteer( $p, 'event' ) . self::section_checkin( $p );
		}
		$html = self::section_complete( $p );
		foreach ( self::my_sections() as $s ) {
			$map = array( 'tessera' => 'section_card', 'attivita' => 'section_activities', 'calendario' => 'section_calendar', 'pagamenti' => 'section_pay', 'ospiti' => 'section_guests', 'minori' => 'section_minors', 'profilo' => 'section_profile', 'regolamento' => 'section_rules', 'avvisi' => 'section_notices' );
			$html .= isset( $map[ $s ] ) ? self::collapsible( self::{$map[ $s ]}( $p ) ) : (string) apply_filters( 'asem_area_section', '', $s, $p );
			if ( 'tessera' === $s ) {
				$html .= (string) apply_filters( 'asem_after_card', '' ); // invito all'app (Pro)
			}
		}
		return self::jump_nav( $html );
	}


	/**
	 * Rende richiudibile una sezione lunga dell'area soci: il titolo diventa il «summary» di un <details>, con tutto il contenuto uguale.
	 * Nasce aperta (senza JavaScript resta tutta aperta); da telefono frontend.js chiude le sezioni dopo la prima. Le sezioni senza titolo,
	 * quelle da accettare (regolamento) e l'avviso «Completa i tuoi dati» restano com'erano.
	 */
	private static function collapsible( string $html ): string {
		if ( 1 !== substr_count( $html, '<section' ) || false !== strpos( $html, 'asemf-rules-box' ) || false !== strpos( $html, 'asemf-complete' ) ) {
			return $html;
		}
		if ( ! preg_match( '#^<section class="([^"]*)">\s*<h3>(.*?)</h3>(.*)</section>$#s', $html, $m ) ) {
			return $html;
		}
		return '<section class="' . $m[1] . ' asemf-collapsible"><details class="asemf-details asemf-collapse" open><summary><h3>' . $m[2] . '</h3></summary>' . $m[3] . '</details></section>';
	}

	/** Icona della voce della barra: dal titolo della sezione (tessera, attività, pagamenti, profilo…). */
	private static function jump_icon( string $title ): string {
		$t = function_exists( 'mb_strtolower' ) ? mb_strtolower( $title ) : strtolower( $title );
		if ( false !== strpos( $t, 'tessera' ) ) {
			return 'id-card';
		}
		if ( preg_match( '/attivit|event|corsi|prenot/u', $t ) ) {
			return 'calendar';
		}
		if ( preg_match( '/pagam|quot|ricevut|credit|contribut/u', $t ) ) {
			return 'wallet';
		}
		if ( preg_match( '/profil|dati|ospit|famil/u', $t ) ) {
			return 'user-round';
		}
		return 'chevron-right';
	}
	/**
	 * «Il mio spazio» è una colonna lunga: da telefono una barra fissa in basso (icona e parola) porta alle sezioni (titoli h3 delle sezioni già disegnate; sotto 4 sezioni non serve).
	 */
	private static function jump_nav( string $html ): string {
		$n     = 0;
		$items = array();
		$html  = (string) preg_replace_callback(
			'#<section class="(asemf-section[^"]*)">(\s*(?:<details[^>]*>\s*<summary>\s*)?<h3>(.*?)</h3>)?#s',
			function ( $m ) use ( &$n, &$items ) {
				if ( empty( $m[3] ) || false !== strpos( $m[1], 'asemf-complete' ) ) {
					return $m[0];
				}
				$n++;
				$id      = 'asemf-s' . $n;
				$items[] = array( $id, wp_strip_all_tags( $m[3] ) );
				return '<section id="' . $id . '" class="' . $m[1] . '">' . $m[2];
			},
			$html
		);
		if ( count( $items ) < 5 ) {
			return $html;
		}
		$nav = '<nav class="asemf-jump" aria-label="Vai a una sezione"><ul>';
		foreach ( $items as $it ) {
			$nav .= '<li><a href="#' . esc_attr( $it[0] ) . '">' . \AssociazioneSemplice\Icons::svg( self::jump_icon( $it[1] ) ) . '<span>' . esc_html( $it[1] ) . '</span></a></li>';
		}
		return $nav . '</ul></nav>' . $html;
	}

	/** L'area riservata con il piccolo menu laterale: «Il mio spazio» e, per chi ha il ruolo, Segreteria, Gestione corsi e Gestione eventi. */
	private static function area_with_menu( array $p ): string {
		$panes = self::panes_for( $p );
		$pane  = isset( $_GET['asemf_vista'] ) ? sanitize_key( wp_unslash( $_GET['asemf_vista'] ) ) : 'mio'; // phpcs:ignore WordPress.Security.NonceVerification
		if ( ! isset( $panes[ $pane ] ) ) {
			$pane = 'mio';
		}
		$base = remove_query_arg( array( 'asemf_vista', 'asem_session', 'asemf_ok', 'asemf_err', 'asemf_sig' ), Restrict::current_url() );
		$nav  = '';
		if ( count( $panes ) > 1 ) {
			$nav = '<nav class="asemf-sidenav" aria-label="Area riservata"><ul>';
			foreach ( $panes as $key => $title ) {
				$nav .= '<li><a href="' . esc_url( 'mio' === $key ? $base : add_query_arg( 'asemf_vista', $key, $base ) ) . '"' . ( $key === $pane ? ' class="is-active" aria-current="page"' : '' ) . '>' . esc_html( $title ) . '</a></li>';
			}
			$nav .= '</ul></nav>';
		}
		return '<div class="asemf-hello"><span>Ciao <strong>' . esc_html( $p['first_name'] ) . '</strong></span>' . MemberLoginPage::logout_button() . '</div><div class="asemf-layout' . ( '' === $nav ? ' asemf-nonav' : '' ) . '">' . $nav
			. '<div class="asemf-area asemf-pane-' . esc_attr( $pane ) . '">' . self::pane_html( $pane, $p ) . '</div></div>';
	}

	public static function area( array $atts = array() ): string {
		$custom = isset( $atts['sezioni'] ) ? trim( (string) $atts['sezioni'] ) : '';
		if ( '' === $custom ) { // l'area standard, con il menu laterale
			return self::with_person( array( __CLASS__, 'area_with_menu' ), 'asemf-area-wrap' );
		}
		$sections = array_filter( array_map( 'trim', explode( ',', $custom ) ) );
		return self::with_person(
			function ( $p ) use ( $sections ) {
				$map  = array(
					'tessera'    => 'section_card', 'attivita' => 'section_activities', 'calendario' => 'section_calendar', 'pagamenti' => 'section_pay', 'ospiti' => 'section_guests', 'minori' => 'section_minors',
					'profilo'    => 'section_profile', 'regolamento' => 'section_rules', 'volontario' => 'section_volunteer', 'spese' => 'section_expenses', 'ingressi' => 'section_checkin', 'avvisi' => 'section_notices',
				);
				$html = '<div class="asemf-hello"><span>Ciao <strong>' . esc_html( $p['first_name'] ) . '</strong></span>' . MemberLoginPage::logout_button() . '</div><div class="asemf-area">';
				if ( ! in_array( 'profilo', $sections, true ) ) {
					$html .= self::section_complete( $p ); // l'avviso compare comunque in cima, anche se la sezione del profilo non è tra quelle mostrate
				}
				foreach ( $sections as $s ) {
					if ( isset( $map[ $s ] ) ) {
						$html .= self::{$map[ $s ]}( $p );
					} else {
						$html .= (string) apply_filters( 'asem_area_section', '', $s, $p ); // sezioni di un'estensione
					}
				}
				return $html . '</div>';
			},
			'asemf-area-wrap'
		);
	}

	public static function card(): string {
		return self::with_person( array( __CLASS__, 'section_card' ) );
	}

	public static function my_activities(): string {
		return self::with_person( array( __CLASS__, 'section_activities' ) );
	}






	public static function guests(): string {
		return self::with_person( array( __CLASS__, 'section_guests' ) );
	}

	public static function minors(): string {
		return self::with_person( array( __CLASS__, 'section_minors' ) );
	}


	/** Regolamento da accettare (compare in cima all'area finché il socio non lo accetta). */
	public static function section_rules( array $p ): string {
		if ( ! \AssociazioneSemplice\Regulation::applies_to( $p ) || \AssociazioneSemplice\Regulation::accepted( $p ) ) {
			return '';
		}
		$title = \AssociazioneSemplice\Regulation::title();
		$redo  = ! empty( $p['rules_accepted_at'] ) ? '<p class="asemf-small asemf-muted">Il ' . esc_html( mb_strtolower( $title, 'UTF-8' ) ) . ' è stato aggiornato: ti chiediamo di accettarlo di nuovo.</p>' : '';
		$fields = \AssociazioneSemplice\Regulation::box_html() . '<label><input type="checkbox" name="rules_ok" value="1" required> Ho letto e accetto il ' . esc_html( mb_strtolower( $title, 'UTF-8' ) ) . '.</label>';
		return '<section class="asemf-section asemf-rules-box"><h3>' . esc_html( $title ) . '</h3>' . $redo
			. ( ! empty( Settings::get( 'rules_block_booking' ) ) ? '<p class="asemf-small asemf-muted">Finché non lo accetti non puoi prenotare eventi e attività.</p>' : '' )
			. self::form( 'asem_front_accept_rules', $fields, 'Accetto' ) . '</section>';
	}


	/** Coordinate per il bonifico per chiunque (ad esempio una pagina di donazioni): niente causale personale. Vuoto se il bonifico non è attivo. */
	public static function bank_public(): string {
		if ( ! Bank::enabled() ) {
			return '';
		}
		Assets::enqueue();
		return '<section class="asemf-section asemf-bankpub">' . Bank::html_accounts() . '</section>';
	}

	/** Informativa sul trattamento dei dati personali, compilata con i dati dell'ente (pubblica, per chiunque). */
	public static function privacy_notice(): string {
		Assets::enqueue();
		return '<section class="asemf-section asemf-privacy-wrap">' . \AssociazioneSemplice\PrivacyNotice::html() . '</section>';
	}

	/**
	 * Modulo di donazione con il pulsante PayPal. Senza `id` è la raccolta generale; con `id` è una raccolta creata in Donazioni (titolo, importi
	 * e testo suoi). Se PayPal non è configurato ai visitatori non compare niente; chi gestisce vede un avviso che spiega perché.
	 */
	public static function donate( array $atts = array() ): string {
		$id = (int) ( $atts['id'] ?? 0 );
		$c  = $id > 0 ? \AssociazioneSemplice\Donations::campaign( $id ) : null;
		if ( ! \AssociazioneSemplice\Donations::enabled() || ( $id > 0 && ( ! $c || empty( $c['active'] ) ) ) ) {
			if ( ! current_user_can( \AssociazioneSemplice\Plugin::CAP ) ) {
				return '';
			}
			$why = ! \AssociazioneSemplice\Donations::enabled() ? \AssociazioneSemplice\Donations::disclaimer() : ( $c ? 'Questa raccolta è spenta.' : 'Questa raccolta non esiste più.' );
			return '<p class="asemf-small asemf-muted"><em>Donazioni (lo vedi solo tu): ' . esc_html( $why ) . '</em></p>';
		}
		Assets::enqueue();
		$amounts = $c ? \AssociazioneSemplice\Donations::campaign_amounts( $c ) : \AssociazioneSemplice\Donations::amounts();
		$purpose = $c ? \AssociazioneSemplice\Donations::campaign_purpose( $c ) : \AssociazioneSemplice\Donations::purpose();
		$select  = '<label>Importo <select name="amount">';
		foreach ( $amounts as $a ) {
			$select .= '<option value="' . esc_attr( \AssociazioneSemplice\Donations::paypal_amount( $a ) ) . '">' . esc_html( $a ) . ' €</option>';
		}
		$select .= '<option value="">Un altro importo (lo scegli su PayPal)</option></select></label>';
		return '<section class="asemf-section asemf-donate"><h3>' . esc_html( $purpose ) . '</h3>'
			. ( $c && '' !== $c['note'] ? '<p>' . esc_html( $c['note'] ) . '</p>' : '' )
			. '<form method="post" action="' . esc_url( \AssociazioneSemplice\Donations::URL ) . '" target="_blank" rel="noopener">'
			. '<input type="hidden" name="business" value="' . esc_attr( \AssociazioneSemplice\Donations::account() ) . '">'
			. '<input type="hidden" name="item_name" value="' . esc_attr( $purpose ) . '">'
			. '<input type="hidden" name="currency_code" value="EUR"><input type="hidden" name="no_recurring" value="1">'
			. '<p>' . $select . '</p><p><button type="submit" class="asemf-btn wp-element-button">Dona con PayPal</button></p>'
			. '<p class="asemf-small asemf-muted">Il pagamento avviene sul sito di PayPal: qui non passa nessun dato di pagamento.</p></form></section>';
	}



	public static function rules(): string {
		return self::with_person( array( __CLASS__, 'section_rules' ) );
	}


	public static function profile(): string {
		return self::with_person( array( __CLASS__, 'section_profile' ) );
	}

	public static function volunteer(): string {
		return self::with_person(
			function ( $p ) {
				return MemberType::can_teach( $p['type'] ) ? self::section_volunteer( $p ) : self::notice( 'Quest\'area è riservata ai soci e volontari.' );
			}
		);
	}

	// ---------- Pagine pubbliche: attività ed eventi ----------

	/** Chi sta guardando e chi può prenotare (sé stesso e i propri ospiti). */
	private static function booking_context(): array {
		$ctx = array( 'logged' => \AssociazioneSemplice\MemberSession::logged_in(), 'can_book' => false, 'people' => array(), 'suspended' => false );
		if ( ! $ctx['logged'] ) {
			return $ctx;
		}
		if ( ! Access::is_admin_user( get_current_user_id() ) && ! Edition::allows( 'member_area' ) ) {
			$ctx['suspended'] = true;
			return $ctx;
		}
		$p = Access::current_person();
		if ( $p && MemberType::can_login( $p['type'] ) ) {
			$ctx['actor']    = $p;
			$people = Plugin::people();
			$list   = array();
			if ( $people->is_active_member( (int) $p['id'] ) ) {
				$list = array_merge( array( $p ), $people->guests_of( (int) $p['id'] ) );
			}
			foreach ( $people->dependents_of( (int) $p['id'] ) as $m ) { // chi ha il tutore prenota con la tessera del minore, non con la propria
				if ( $people->is_active_member( (int) $m['id'] ) ) {
					$list[] = $m;
				}
			}
			$ctx['can_book'] = array() !== $list;
			$ctx['people']   = $list;
		}
		return $ctx;
	}

	private static function session_row( array $s, array $activity, array $ctx, string $prefix = '' ): string {
		$svc    = Plugin::activities();
		$cap    = null === $s['capacity'] ? null : (int) $s['capacity'];
		$booked = (int) ( $s['booked_count'] ?? 0 );
		$full   = null !== $cap && $booked >= $cap;
		$html   = '<li><div>' . $prefix . '<strong>' . esc_html( self::date_long( $s['session_date'] ) ) . ( $s['start_time'] ? ' · ore ' . esc_html( $s['start_time'] ) : '' ) . '</strong>'
			. ( $s['location'] ? '<div class="asemf-small">' . esc_html( $s['location'] ) . '</div>' : '' )
			. ( null !== $cap ? '<div class="asemf-small">' . ( $full ? 'Posti esauriti · lista d’attesa' : max( 0, $cap - $booked ) . ' posti liberi' ) . '</div>' : '' ) . '</div><div class="asemf-actions">';
		if ( ! $ctx['logged'] ) {
			if ( empty( $ctx['hide_login'] ) ) { // negli elenchi non c'è: la prenotazione si fa dalla pagina dell'evento
				$html .= '<a class="asemf-btn asemf-btn-ghost wp-element-button" href="' . esc_url( self::member_login_url() ) . '">Sei socio? Accedi per prenotarti</a>';
			}
		} elseif ( ! empty( $ctx['can_book'] ) ) {
			$free = array();
			foreach ( $ctx['people'] as $person ) {
				if ( $svc->has_active_booking( (int) $s['id'], (int) $person['id'] ) ) {
					$html .= '<span class="asemf-badge asemf-badge-ok">✓ ' . esc_html( $person['first_name'] ) . '</span> '
						. ( $svc->cancellation_for( (int) $s['id'], (int) $person['id'] )['allowed']
							? self::form( 'asem_front_cancel_booking', self::hidden( 'session_id', $s['id'] ) . self::hidden( 'person_id', $person['id'] ), 'Annulla', true, 'asemf-inline' )
							: '<span class="asemf-small asemf-muted">non annullabile (gestiscila nella tua area)</span>' );
				} else {
					$free[] = $person;
				}
			}
			if ( $free && $full ) { // posti finiti: lista d'attesa
				$queue = array();
				foreach ( $free as $person ) {
					$pos = \AssociazioneSemplice\Waitlist::position( (int) $s['id'], (int) $person['id'] );
					if ( null !== $pos ) {
						$html .= '<span class="asemf-badge">' . esc_html( $person['first_name'] ) . ': in lista d\'attesa (n. ' . (int) $pos . ')</span> '
							. self::form( 'asem_front_waitlist_leave', self::hidden( 'session_id', $s['id'] ) . self::hidden( 'person_id', $person['id'] ), 'Esci dalla lista', true, 'asemf-inline' );
					} else {
						$queue[] = $person;
					}
				}
				if ( $queue ) {
					$wf = '';
					if ( count( $queue ) > 1 ) {
						$wf .= '<select name="person_id" aria-label="Chi metti in lista">';
						foreach ( $queue as $person ) {
							$wf .= '<option value="' . (int) $person['id'] . '">' . esc_html( $person['first_name'] ) . '</option>';
						}
						$wf .= '</select>';
					} else {
						$wf .= self::hidden( 'person_id', $queue[0]['id'] );
					}
					$html .= self::form( 'asem_front_waitlist_join', self::hidden( 'session_id', $s['id'] ) . $wf, 'Lista d\'attesa', false, 'asemf-inline' );
				}
			}
			if ( $free && ! $full && \AssociazioneSemplice\PublicEvents::options( (int) $activity['id'] )['approval'] ) { // evento con richiesta di partecipazione: la segreteria approva, in ordine di arrivo
				$ask = array();
				foreach ( $free as $person ) {
					if ( \AssociazioneSemplice\EventRequests::pending_for( (int) $s['id'], (int) $person['id'] ) ) {
						$html .= '<span class="asemf-badge">' . esc_html( $person['first_name'] ) . ': richiesta inviata, in attesa della segreteria</span> ';
					} else {
						$ask[] = $person;
					}
				}
				if ( $ask ) {
					$rf = '';
					if ( count( $ask ) > 1 ) {
						$rf .= '<select name="person_id" aria-label="Per chi chiedi">';
						foreach ( $ask as $person ) {
							$rf .= '<option value="' . (int) $person['id'] . '">' . esc_html( $person['first_name'] ) . '</option>';
						}
						$rf .= '</select>';
					} else {
						$rf .= self::hidden( 'person_id', $ask[0]['id'] );
					}
					$html .= self::form( 'asem_front_request', self::hidden( 'session_id', $s['id'] ) . $rf, 'Chiedi di partecipare', false, 'asemf-inline' );
				}
				$free = array();
			}
			if ( $free && ! $full ) {
				$fields = '';
				if ( count( $free ) > 1 ) {
					$fields .= '<select name="person_id" aria-label="Chi prenoti">';
					foreach ( $free as $person ) {
						$fee     = $svc->fee_for( $activity, $person['type'] );
						$fields .= '<option value="' . (int) $person['id'] . '">' . esc_html( $person['first_name'] . ' — ' . ( 0 === $fee ? 'gratuito' : Money::format( $fee ) ) ) . '</option>';
					}
					$fields .= '</select>';
				} else {
					$fields .= self::hidden( 'person_id', $free[0]['id'] );
				}
				$lists = \AssociazioneSemplice\PriceLists::for_activity( $activity );
				if ( $lists ) {
					$fields .= '<select name="price_list" aria-label="Tariffa">';
					foreach ( $lists as $l ) {
						$fields .= '<option value="' . esc_attr( $l['key'] ) . '">' . esc_html( $l['label'] . ' — ' . Money::format( $l['cents'] ) ) . '</option>';
					}
					$fields .= '</select>';
				}
				$html .= self::form( 'asem_front_book', self::hidden( 'session_id', $s['id'] ) . $fields, 'Prenotati', false, 'asemf-inline' );
			}
		} elseif ( ! empty( $ctx['actor'] ) ) {
			$html .= '<span class="asemf-small asemf-muted">La tessera non è valida: per prenotarti rinnova la quota associativa (' . ( Plugin::payments()->enabled() ? 'apri «Pagamenti» qui sopra o ' : '' ) . 'rivolgiti alla segreteria).</span>';
		}
		return $html . '</div></li>';
	}

	/**
	 * Elenco delle attività dell'anno sociale (o una sola con id=…): corsi con quota, eventi con le prossime date
	 * e il pulsante per prenotarsi (soci con tessera valida, anche per i propri ospiti).
	 */
	public static function activities( array $atts = array() ): string {
		self::no_cache();
		$svc   = Plugin::activities();
		$label = ! empty( $atts['anno'] ) ? (string) $atts['anno'] : Settings::social_year()->label();
		$kind  = self::kind_from_text( (string) ( $atts['tipo'] ?? '' ) );
		$max   = max( 1, (int) ( $atts['date'] ?? 5 ) );
		if ( ! empty( $atts['id'] ) ) {
			$one  = $svc->get( (int) $atts['id'] );
			$list = $one ? array( array_merge( $one, array( 'instructor_name' => self::person_name( (int) $one['instructor_person_id'] ) ) ) ) : array();
		} else {
			$list = array_values(
				array_filter(
					$svc->for_year( $label ),
					function ( $a ) use ( $kind ) {
						return null === $kind || $a['kind'] === $kind;
					}
				)
			);
		}
		if ( ! $list ) {
			return self::wrap( '<p class="asemf-muted">Nessuna attività da mostrare.</p>' );
		}
		$ctx  = self::booking_context();
		$ctx['hide_login'] = true;
		$mine = ! empty( $ctx['actor'] ) ? $svc->person_activity_ids( (int) $ctx['actor']['id'] ) : array();
		$html = '<div class="asemf-grid">';
		foreach ( $list as $a ) {
			$page   = \AssociazioneSemplice\PublicEvents::page_url( (int) $a['id'] ); // la pagina pubblica dell'attività, se c'è: locandina, descrizione, altre date
			$poster = '' !== $page ? \AssociazioneSemplice\PublicEvents::poster( (int) $a['id'] ) : '';
			$html  .= '<article class="asemf-card">' . ( '' !== $poster ? '<a href="' . esc_url( $page ) . '" class="asemf-card-poster" tabindex="-1" aria-hidden="true">' . $poster . '</a>' : '' )
				. '<span class="asemf-badge">' . esc_html( ActivityKind::short_label( $a['kind'] ) ) . '</span>'
				. '<h3>' . ( '' !== $page ? '<a href="' . esc_url( $page ) . '">' . esc_html( $a['name'] ) . '</a>' : esc_html( $a['name'] ) ) . '</h3><p class="asemf-small asemf-muted">' . esc_html( self::fee_text( $a ) )
				. ( ! empty( $a['instructor_name'] ) ? '<br>Con ' . esc_html( $a['instructor_name'] ) : '' ) . '</p>';
			if ( ! empty( $a['notes'] ) ) {
				$html .= '<p>' . esc_html( $a['notes'] ) . '</p>';
			}
			if ( ActivityKind::uses_sessions( $a['kind'] ) ) {
				$sessions = array_values(
					array_filter(
						$svc->sessions( (int) $a['id'] ),
						function ( $s ) {
							return empty( $s['cancelled_at'] ) && $s['session_date'] >= current_time( 'Y-m-d' );
						}
					)
				);
				if ( ! $sessions ) {
					$html .= '<p class="asemf-muted">Nessuna data in programma.</p>';
				} else {
					$html .= '<ul class="asemf-list asemf-sessions">';
					foreach ( array_slice( $sessions, 0, $max ) as $s ) {
						$html .= self::session_row( $s, $a, $ctx );
					}
					$html .= '</ul>';
				}
			} elseif ( in_array( (int) $a['id'], $mine, true ) ) {
				$html .= '<p><span class="asemf-badge asemf-badge-ok">✓ Sei iscritto</span></p>';
			}
			if ( '' !== $page ) {
				$html .= '<p><a class="asemf-btn asemf-btn-ghost wp-element-button" href="' . esc_url( $page ) . '">Dettagli' . ( \AssociazioneSemplice\EventRequests::available( $a ) ? ' e prenotazione' : '' ) . '</a></p>';
			}
			$html .= '</article>';
		}
		return self::wrap( $html . '</div>' );
	}

	/** Le date di un evento con i posti e il modulo per prenotarsi (stesse regole dell'area soci). Per la pagina pubblica dell'evento. */
	public static function event_booking( array $activity ): string {
		self::no_cache();
		$rows = Plugin::activities()->upcoming_sessions( 50, (int) $activity['id'] );
		if ( ! $rows ) {
			return '<p class="asemf-muted">Nessuna data in programma.</p>';
		}
		$ctx  = self::booking_context();
		$html = '<h3 class="asemf-event-h">Date e prenotazione</h3><ul class="asemf-list asemf-upcoming">';
		foreach ( $rows as $s ) {
			$html .= self::session_row( $s, $activity, $ctx );
		}
		$html .= '</ul>';
		if ( empty( $ctx['can_book'] ) && \AssociazioneSemplice\EventRequests::available( $activity ) ) { // chi non è socio chiede un posto da qui
			$html .= \AssociazioneSemplice\EventRequests::form_html( $activity, $rows );
		}
		return \AssociazioneSemplice\Texts::html( self::flash() . $html );
	}

	/**
	 * Come iscriversi a un corso, per la sua pagina pubblica: il socio (o chi ne fa le veci) chiede in un clic, gli altri compilano il modulo.
	 * La segreteria approva sempre; l'iscrizione parte dal mese corrente.
	 */
	public static function course_request( array $activity ): string {
		self::no_cache();
		if ( ! \AssociazioneSemplice\EventRequests::course_open( $activity ) ) {
			return '';
		}
		$aid  = (int) $activity['id'];
		$ctx  = self::booking_context();
		$svc  = Plugin::activities();
		$html = '<h3 class="asemf-event-h">Come iscriversi</h3>';
		if ( ! empty( $ctx['can_book'] ) ) {
			$ask = array();
			foreach ( $ctx['people'] as $person ) {
				if ( in_array( $aid, $svc->active_activity_ids( (int) $person['id'] ), true ) ) {
					$html .= '<p><span class="asemf-badge asemf-badge-ok">✓ ' . esc_html( $person['first_name'] ) . ': iscritto</span></p>';
				} elseif ( \AssociazioneSemplice\EventRequests::course_pending_for( $aid, (int) $person['id'] ) ) {
					$html .= '<p><span class="asemf-badge">' . esc_html( $person['first_name'] ) . ': richiesta inviata, in attesa della segreteria</span></p>';
				} else {
					$ask[] = $person;
				}
			}
			if ( $ask ) {
				$rf = '';
				if ( count( $ask ) > 1 ) {
					$rf .= '<select name="person_id" aria-label="Per chi chiedi">';
					foreach ( $ask as $person ) {
						$rf .= '<option value="' . (int) $person['id'] . '">' . esc_html( $person['first_name'] ) . '</option>';
					}
					$rf .= '</select>';
				} else {
					$rf .= self::hidden( 'person_id', $ask[0]['id'] );
				}
				$html .= '<p class="asemf-small asemf-muted">La segreteria conferma la richiesta; l\'iscrizione parte dal mese corrente e il contributo segue le regole del corso.</p>'
					. self::form( 'asem_front_course_request', self::hidden( 'activity_id', $aid ) . $rf, 'Chiedi l\'iscrizione', false, 'asemf-inline' );
			}
		} else {
			$html .= '<p><a class="asemf-btn wp-element-button" href="' . esc_url( self::member_login_url() ) . '">Sei socio? Accedi per chiedere l\'iscrizione</a></p>';
			if ( \AssociazioneSemplice\EventRequests::course_guests_open( $activity ) ) {
				$html .= \AssociazioneSemplice\EventRequests::course_form_html( $activity );
			} else {
				$html .= '<p class="asemf-small asemf-muted">Per iscriverti rivolgiti alla segreteria con i contatti qui sotto.</p>';
			}
		}
		return \AssociazioneSemplice\Texts::html( self::flash() . $html );
	}

	/** Le prossime date di tutti gli eventi, in ordine di data. */
	public static function upcoming( array $atts = array() ): string {
		self::no_cache();
		$limit = max( 1, min( 50, (int) ( $atts['limite'] ?? 5 ) ) );
		$cat   = sanitize_title( (string) ( $atts['categoria'] ?? '' ) );
		if ( '' !== $cat ) { // solo gli eventi di una categoria: si cercano più date e poi si tengono quelle giuste
			$ids  = \AssociazioneSemplice\PublicEvents::activity_ids( $cat );
			$rows = array_slice( array_values( array_filter( Plugin::activities()->upcoming_sessions( 500 ), function ( $s ) use ( $ids ) {
				return in_array( (int) $s['activity_id'], $ids, true );
			} ) ), 0, $limit );
		} else {
			$rows = Plugin::activities()->upcoming_sessions( $limit );
		}
		$title = \AssociazioneSemplice\PublicEvents::title_html( $atts );
		if ( ! $rows ) {
			$empty = trim( (string) ( $atts['vuoto'] ?? '' ) );
			return self::wrap( $title . '<p class="asemf-muted">' . esc_html( '' !== $empty ? $empty : 'Nessun evento in programma.' ) . '</p>' );
		}
		$ctx   = self::booking_context();
		$ctx['hide_login'] = true;
		$book  = ! isset( $atts['prenotazione'] ) || 'no' !== strtolower( (string) $atts['prenotazione'] );
		$html  = '<ul class="asemf-list asemf-upcoming">';
		foreach ( $rows as $s ) {
			if ( $book ) {
				$html .= self::session_row( $s, array_merge( $s, array( 'id' => $s['activity_id'] ) ), $ctx, '<div class="asemf-evt">' . esc_html( $s['activity_name'] ) . '</div>' );
			} else {
				$html .= '<li><div><div class="asemf-evt">' . esc_html( $s['activity_name'] ) . '</div><strong>' . esc_html( self::date_long( $s['session_date'] ) ) . ( $s['start_time'] ? ' · ore ' . esc_html( $s['start_time'] ) : '' ) . '</strong>'
					. ( $s['location'] ? '<div class="asemf-small">' . esc_html( $s['location'] ) . '</div>' : '' ) . '</div></li>';
			}
		}
		return self::wrap( $title . $html . '</ul>' );
	}

	private static function kind_from_text( string $t ): ?string {
		$map = array( 'corso' => ActivityKind::COURSE, 'corsi' => ActivityKind::COURSE, 'course' => ActivityKind::COURSE, 'evento' => ActivityKind::EVENT, 'eventi' => ActivityKind::EVENT,
			'event' => ActivityKind::EVENT, 'ricorrente' => ActivityKind::RECURRING, 'ricorrenti' => ActivityKind::RECURRING, 'recurring' => ActivityKind::RECURRING );
		return $map[ strtolower( trim( $t ) ) ] ?? null;
	}

	private static function person_name( int $id ): string {
		$p = $id ? Plugin::people()->get( $id ) : null;
		return $p ? trim( $p['first_name'] . ' ' . $p['last_name'] ) : '';
	}
}
