<?php
namespace AssociazioneSemplice\Frontend;

use AssociazioneSemplice\Access;
use AssociazioneSemplice\Credits;
use AssociazioneSemplice\CreditRules;
use AssociazioneSemplice\Edition;
use AssociazioneSemplice\Flash;
use AssociazioneSemplice\MemberType;
use AssociazioneSemplice\Money;
use AssociazioneSemplice\Plugin;
use AssociazioneSemplice\QrCode;
use AssociazioneSemplice\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Crediti nel sito: la sezione del socio (saldo, acquisto, movimenti), la schermata dello staff che mostra il QR di un addebito e la pagina
 * che si apre inquadrando il QR, dove il socio conferma la spesa. Specifica: docs/CREDITI.md.
 */
final class CreditsFront {

	const ACTIONS = array(
		'asem_front_credit_charge' => 'do_charge',
		'asem_front_credit_cancel' => 'do_cancel',
		'asem_front_credit_pay'    => 'do_pay',
	);

	public static function register(): void {
		foreach ( self::ACTIONS as $action => $method ) {
			add_action(
				'admin_post_' . $action,
				function () use ( $action, $method ) {
					check_admin_referer( $action );
					$post = wp_unslash( $_POST ); // phpcs:ignore WordPress.Security.NonceVerification
					$back = self::back( $post );
					try {
						$r = self::$method( $post );
						wp_safe_redirect( is_array( $r ) ? $r['url'] : Flash::url( $back, 'asemf', $r, '' ) );
					} catch ( \InvalidArgumentException $e ) {
						wp_safe_redirect( Flash::url( $back, 'asemf', '', $e->getMessage() ) );
					} catch ( \Throwable $e ) {
						wp_safe_redirect( Flash::url( $back, 'asemf', '', 'Operazione non riuscita. Riprova più tardi.' ) );
					}
					exit;
				}
			);
			add_action(
				'admin_post_nopriv_' . $action,
				function () {
					$post = wp_unslash( $_POST ); // phpcs:ignore WordPress.Security.NonceVerification
					wp_safe_redirect( wp_login_url( self::back( $post ) ) );
					exit;
				}
			);
		}
		add_action( 'template_redirect', array( __CLASS__, 'handle_scan' ), 1 );
		add_action( 'wp_ajax_asem_credit_status', array( __CLASS__, 'ajax_status' ) );
	}

	private static function back( array $post ): string {
		return wp_validate_redirect( ! empty( $post['_back'] ) ? esc_url_raw( $post['_back'] ) : home_url( '/' ), home_url( '/' ) );
	}

	/** Il socio collegato all'utente. @throws \InvalidArgumentException */
	private static function actor(): array {
		$p = Access::current_person();
		if ( ! $p || ! MemberType::is_member( $p['type'] ) ) {
			throw new \InvalidArgumentException( 'Serve un accesso come socio.' );
		}
		return $p;
	}

	// ---------- Azioni ----------

	/** Lo staff crea l'addebito: si torna alla stessa pagina con il QR. */
	public static function do_charge( array $post ): array {
		$staff = self::actor();
		if ( ! Credits::can_charge( get_current_user_id() ) ) {
			throw new \InvalidArgumentException( 'Non hai il permesso di addebitare crediti.' );
		}
		$activity = ! empty( $post['activity_id'] ) ? (int) $post['activity_id'] : null;
		$c        = Credits::create_charge( (int) $staff['id'], max( 0, (int) ( $post['credits'] ?? 0 ) ), (string) ( $post['label'] ?? '' ), $activity );
		return array( 'url' => add_query_arg( 'asem_charge', $c['id'], remove_query_arg( array( 'asem_charge', 'asemf_ok', 'asemf_err', 'asemf_sig' ), self::back( $post ) ) ) );
	}

	public static function do_cancel( array $post ): string {
		self::actor();
		Credits::cancel_charge( (int) ( $post['charge'] ?? 0 ), get_current_user_id() );
		return 'Addebito annullato.';
	}

	/** Il socio conferma la spesa dalla pagina del QR: si torna alla stessa pagina, che mostra «Pagato». */
	public static function do_pay( array $post ): array {
		$actor = self::actor();
		$code  = (string) ( $post['code'] ?? '' );
		$c     = Credits::pay_charge( $code, (int) $actor['id'] );
		return array( 'url' => Flash::url( Credits::url( $code ), 'asemf', 'Pagamento di ' . (int) $c['credits'] . ' crediti riuscito.', '' ) );
	}

	/** Stato di un addebito per lo schermo dello staff (si aggiorna ogni due secondi). */
	public static function ajax_status(): void {
		check_ajax_referer( 'asem_credit_status', 'n' );
		wp_send_json( Credits::charge_status( isset( $_GET['id'] ) ? (int) $_GET['id'] : 0, get_current_user_id() ) ); // phpcs:ignore WordPress.Security.NonceVerification -- il nonce è controllato sopra
	}

	// ---------- Pagina del QR ----------

	public static function handle_scan(): void {
		if ( ! isset( $_GET['asem_credit'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			return;
		}
		$code = sanitize_text_field( wp_unslash( $_GET['asem_credit'] ) ); // phpcs:ignore WordPress.Security.NonceVerification
		if ( ! is_user_logged_in() ) {
			wp_safe_redirect( wp_login_url( Credits::url( $code ) ) );
			exit;
		}
		CardVerify::send_headers();
		echo self::scan_page( $code, Flash::read( 'asemf' ) ); // phpcs:ignore WordPress.Security.EscapeOutput -- già escapato in scan_page()
		exit;
	}

	private static function scan_page( string $code, array $flash ): string {
		$msg = '' !== $flash['err'] ? '<div class="w" style="color:#b32d2e">' . esc_html( $flash['err'] ) . '</div>' : '';
		$c   = Credits::charge_by_code( $code );
		if ( ! $c ) {
			return CardVerify::layout( 'QR non valido', '#b32d2e', '<div class="a">Questo codice non corrisponde a nessun addebito.</div>' );
		}
		$actor = Access::current_person();
		if ( ! $actor || ! MemberType::is_member( $actor['type'] ) ) {
			return CardVerify::layout( 'Solo per i soci', '#b32d2e', '<div class="a">I crediti sono riservati ai soci: accedi con il tuo account.</div>' );
		}
		$what  = '<div class="n">' . (int) $c['credits'] . ' crediti</div><div class="t">' . esc_html( (string) $c['label'] ) . '</div>';
		$state = CreditRules::charge_state( $c, current_time( 'mysql' ) );
		if ( 'paid' === $state && (int) $c['payer_person_id'] === (int) $actor['id'] ) {
			return CardVerify::layout( 'Pagato', '#1a7f37', $what . '<div class="a">Hai pagato con i crediti. Saldo: ' . (int) Credits::balance( (int) $actor['id'] ) . ' crediti.</div>' . $msg );
		}
		if ( 'ok' !== $state ) {
			return CardVerify::layout( 'Non disponibile', '#b32d2e', $what . '<div class="a">' . esc_html( CreditRules::charge_message( $state ) ) . '</div>' . $msg );
		}
		$balance = Credits::balance( (int) $actor['id'] );
		$enough  = $balance >= (int) $c['credits'];
		$form    = '';
		if ( $enough ) {
			$form = '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="margin-top:14px"><input type="hidden" name="action" value="asem_front_credit_pay">'
				. '<input type="hidden" name="code" value="' . esc_attr( $code ) . '"><input type="hidden" name="_back" value="' . esc_url( Credits::url( $code ) ) . '">' . wp_nonce_field( 'asem_front_credit_pay', '_wpnonce', false, false )
				. '<button style="width:100%;font-size:20px;padding:14px;border:0;border-radius:10px;background:#1a7f37;color:#fff;font-weight:700">Conferma e paga</button></form>';
		} else {
			$form = '<div class="w" style="color:#b32d2e">Crediti insufficienti. <a href="' . esc_url( home_url( '/' ) ) . '">Ricarica dalla tua area</a> e inquadra di nuovo il QR.</div>';
		}
		return CardVerify::layout( 'Pagare con i crediti?', '#2271b1', $what . '<div class="a">Il tuo saldo: <strong>' . (int) $balance . '</strong> crediti.</div>' . $form . $msg );
	}

	// ---------- Sezioni dell'area soci ----------

	/** La sezione del socio: saldo, acquisto dei pacchetti, ultimi movimenti. */
	public static function section( array $p ): string {
		$pid  = (int) $p['id'];
		$html = '<section class="asemf-section"><h3>I miei crediti</h3><p><span style="font-size:2em;font-weight:700">' . (int) Credits::balance( $pid ) . '</span> crediti</p>';
		$packs = Credits::packs();
		if ( $packs && Edition::has( 'payments' ) && Plugin::payments()->enabled() ) {
			$html .= '<p><strong>Ricarica</strong></p><p>';
			foreach ( $packs as $pk ) {
				$html .= self::form( 'asem_front_pay', self::hidden( 'items[]', 'c:' . $pid . ':' . (int) $pk['credits'] ), (int) $pk['credits'] . ' crediti · ' . Money::format( (int) $pk['price_cents'] ) ) . ' ';
			}
			$html .= '</p>';
		} elseif ( $packs ) {
			$html .= '<p class="asemf-muted">Per ricaricare i crediti rivolgiti alla segreteria.</p>';
		}
		$html .= '<p class="asemf-small asemf-muted">Spendi i crediti nelle attività e nelle risorse che li accettano. Dal vivo basta inquadrare il QR che ti mostra lo staff con la fotocamera del telefono e confermare.</p>';
		$rows  = Credits::history( $pid, 8 );
		if ( $rows ) {
			$html .= '<ul class="asemf-list">';
			foreach ( $rows as $r ) {
				$html .= '<li><div>' . esc_html( ( new \DateTimeImmutable( substr( (string) $r['created_at'], 0, 10 ) ) )->format( 'd/m/Y' ) ) . ' · ' . esc_html( (string) $r['label'] ) . '</div><div><strong>' . ( (int) $r['delta'] > 0 ? '+' : '' ) . (int) $r['delta'] . '</strong></div></li>';
			}
			$html .= '</ul>';
		}
		return $html . '</section>';
	}

	/** La schermata dello staff: modulo dell'addebito e, dopo averlo creato, il QR con lo stato che si aggiorna da solo. */
	public static function staff_section( array $p ): string {
		if ( ! Credits::can_charge( get_current_user_id() ) ) {
			return '<section class="asemf-section"><h3>Incasso crediti</h3><p class="asemf-muted">Non hai il permesso di incassare crediti.</p></section>';
		}
		$html = '<section class="asemf-section"><h3>Incasso crediti</h3>';
		$cid  = isset( $_GET['asem_charge'] ) ? (int) $_GET['asem_charge'] : 0; // phpcs:ignore WordPress.Security.NonceVerification
		$c    = $cid ? Credits::charge( $cid ) : null;
		if ( $c && Credits::owns_charge( $c, get_current_user_id() ) ) {
			$state = 'paid' === $c['status'] ? 'paid' : CreditRules::charge_state( $c, current_time( 'mysql' ) );
			$poll  = add_query_arg( array( 'action' => 'asem_credit_status', 'id' => $cid, 'n' => wp_create_nonce( 'asem_credit_status' ) ), admin_url( 'admin-ajax.php' ) );
			$html .= '<div class="asemf-card" style="text-align:center" data-asemf-credit-poll="' . (int) $cid . '" data-url="' . esc_url( $poll ) . '" data-state="' . esc_attr( $state ) . '">'
				. '<div style="font-size:2em;font-weight:700">' . (int) $c['credits'] . ' crediti</div><div>' . esc_html( (string) $c['label'] ) . '</div>'
				. '<div style="max-width:320px;margin:12px auto">' . QrCode::svg( Credits::url( (string) $c['code'] ), 4, 'QR per pagare ' . (int) $c['credits'] . ' crediti' ) . '</div>' // phpcs:ignore WordPress.Security.EscapeOutput -- SVG generato dal plugin
				. '<div class="asemf-credit-status" style="font-weight:700">' . esc_html( 'paid' === $state ? 'Incassato' : ( 'ok' === $state ? 'In attesa del socio…' : CreditRules::charge_message( $state ) ) ) . '</div>'
				. '<p class="asemf-small asemf-muted">Il socio inquadra il QR con la fotocamera e conferma. Il QR vale ' . (int) CreditRules::CHARGE_MINUTES . ' minuti.</p>'
				. ( 'ok' === $state ? self::form( 'asem_front_credit_cancel', self::hidden( 'charge', $cid ), 'Annulla addebito', 'asemf-inline', true ) : '' ) . ' '
				. '<a class="asemf-btn" href="' . esc_url( remove_query_arg( array( 'asem_charge', 'asemf_ok', 'asemf_err', 'asemf_sig' ) ) ) . '">Nuovo addebito</a></div>';
			return $html . '</section>';
		}
		$opts = '<option value="">— importo libero —</option>';
		foreach ( Credits::chargeable_activities() as $a ) {
			$opts .= '<option value="' . (int) $a['id'] . '">' . esc_html( $a['name'] ) . ' (' . (int) $a['credit_price'] . ' crediti)</option>';
		}
		$fields = '<p><label>Crediti <input type="number" name="credits" min="1" max="' . (int) CreditRules::MAX_CREDITS . '" style="width:6em"></label> '
			. '<label>Attività <select name="activity_id">' . $opts . '</select></label></p>'
			. '<p><label>Per cosa <input type="text" name="label" maxlength="190" placeholder="Ad esempio: ingresso serata"></label></p>';
		$html  .= '<p class="asemf-muted">Indica i crediti (oppure scegli un’attività: l’importo si compila da solo) e mostra il QR al socio.</p>' . self::form( 'asem_front_credit_charge', $fields, 'Mostra il QR', 'asemf-stack' );
		return $html . '</section>';
	}

	// ---------- Utilità ----------

	private static function hidden( string $name, $value ): string {
		return '<input type="hidden" name="' . esc_attr( $name ) . '" value="' . esc_attr( (string) $value ) . '">';
	}

	private static function form( string $action, string $fields, string $button, string $class = 'asemf-inline', bool $confirm = false ): string {
		return '<form class="asemf-form ' . esc_attr( $class ) . '" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"' . ( $confirm ? ' onsubmit="return confirm(\'Confermi?\');"' : '' ) . '>'
			. '<input type="hidden" name="action" value="' . esc_attr( $action ) . '"><input type="hidden" name="_back" value="' . esc_url( Restrict::current_url() ) . '">'
			. wp_nonce_field( $action, '_wpnonce', false, false ) . $fields . '<button class="asemf-btn">' . esc_html( $button ) . '</button></form>';
	}
}
