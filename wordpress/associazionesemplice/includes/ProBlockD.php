<?php
namespace AssociazioneSemplice;

defined( 'ABSPATH' ) || exit;

/**
 * Bilancio preventivo e rimborsi ai volontari (funzioni di Associazione Semplice Pro) nei punti di estensione di Associazione Semplice:
 * impostazioni, privacy, Bacheca, area soci, azioni dei moduli del sito e shortcode. Si avvia da {@see ProBoot::init()}.
 */
final class ProBlockD {

	public static function register(): void {
		add_filter( 'asem_settings_defaults', array( __CLASS__, 'defaults' ) );
		add_filter( 'asem_settings_clean', array( __CLASS__, 'clean' ) );
		add_filter( 'asem_privacy_export', array( __CLASS__, 'privacy_export' ), 30, 2 );
		add_action( 'asem_person_anonymized', array( __CLASS__, 'anonymize' ), 30, 3 );
		add_filter( 'asem_admin_post_classes', array( __CLASS__, 'admin_post_classes' ) );
		add_action( 'asem_dashboard_alerts', array( __CLASS__, 'alerts' ) );
		add_filter( 'asem_my_sections', array( __CLASS__, 'my_sections' ), 30 );
		add_filter( 'asem_area_section', array( __CLASS__, 'area_section' ), 30, 3 );
		add_filter( 'asem_shortcode_views', array( __CLASS__, 'shortcode_views' ) );
		add_filter( 'asem_render_view', array( __CLASS__, 'render_view' ), 20, 3 );
		add_filter( 'asem_view_available', array( __CLASS__, 'view_available' ), 20, 2 );
		add_filter( 'asem_areas', array( __CLASS__, 'areas' ), 20 );
		Frontend\Actions::add_handler( 'asem_front_refund', array( Frontend\RefundsFront::class, 'do_request' ) );
		Frontend\Actions::add_handler( 'asem_front_refund_cancel', array( Frontend\RefundsFront::class, 'do_cancel' ) );
	}

	// ---------- Impostazioni ----------

	public static function defaults( array $d ): array {
		return $d + array(
			'refund_km_rate_cents' => 0, // tariffa al chilometro in centesimi (0 = niente rimborso chilometrico)
			'refund_self_approve'  => 0, // chi chiede il rimborso può anche approvarlo e pagarlo
		);
	}

	public static function clean( array $clean ): array {
		$clean['refund_km_rate_cents'] = max( 0, min( 100000, (int) ( $clean['refund_km_rate_cents'] ?? 0 ) ) );
		$clean['refund_self_approve']  = empty( $clean['refund_self_approve'] ) ? 0 : 1;
		return $clean;
	}

	// ---------- Privacy ----------

	public static function privacy_export( array $data, int $person_id ): array {
		return Edition::installed( 'refunds' ) ? Refunds::privacy_export( $data, $person_id ) : $data;
	}

	public static function anonymize( int $person_id ): void {
		if ( Edition::installed( 'refunds' ) ) {
			Refunds::anonymize( $person_id );
		}
	}

	/** Download e file gestiti da classi caricate al bisogno. */
	public static function admin_post_classes( array $map ): array {
		return $map + array(
			'asem_refund_file'  => 'Refunds',
			'asem_budget_csv'   => 'Admin\BlockDExports',
			'asem_refunds_csv'  => 'Admin\BlockDExports',
		);
	}

	public static function alerts(): void {
		Admin\RefundsPage::alert();
		Admin\BudgetPage::alert();
	}

	// ---------- Area soci e shortcode ----------

	/** I rimborsi compaiono dopo i pagamenti. */
	public static function my_sections( array $sections ): array {
		if ( ! Edition::has( 'refunds' ) ) {
			return $sections;
		}
		$out = array();
		foreach ( $sections as $s ) {
			$out[] = $s;
			if ( 'pagamenti' === $s ) {
				$out[] = 'rimborsi';
			}
		}
		return in_array( 'rimborsi', $out, true ) ? $out : array_merge( $out, array( 'rimborsi' ) );
	}

	public static function area_section( $html, string $key, array $p ) {
		return 'rimborsi' === $key && Edition::has( 'refunds' ) ? (string) $html . Frontend\RefundsFront::section( $p ) : $html;
	}

	public static function shortcode_views( array $views ): array {
		$out = array();
		foreach ( $views as $k => $label ) {
			$out[ $k ] = $label;
			if ( 'pagamenti' === $k ) {
				$out['rimborsi'] = 'Rimborsi spese del volontario (richiesta e stato)';
			}
		}
		return $out;
	}

	public static function render_view( $html, string $view, array $atts ) {
		if ( 'rimborsi' === $view ) {
			Frontend\Views::no_cache();
			return Frontend\RefundsFront::view();
		}
		return $html;
	}

	public static function view_available( $ok, string $view ) {
		return 'rimborsi' === $view ? Edition::has( 'refunds' ) : $ok;
	}

	public static function areas( array $groups ): array {
		if ( isset( $groups['soci'] ) && ! in_array( 'rimborsi', $groups['soci'][1], true ) ) {
			$out = array();
			foreach ( $groups['soci'][1] as $v ) {
				$out[] = $v;
				if ( 'pagamenti' === $v ) {
					$out[] = 'rimborsi';
				}
			}
			$groups['soci'][1] = $out;
		}
		return $groups;
	}
}
