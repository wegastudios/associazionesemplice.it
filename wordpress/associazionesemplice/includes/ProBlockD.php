<?php
namespace AssociazioneSemplice;

defined( 'ABSPATH' ) || exit;

/**
 * Assemblee e deleghe, bilancio preventivo e rimborsi ai volontari (funzioni di Associazione Semplice Pro) nei punti di estensione di
 * Associazione Semplice: impostazioni, privacy, Bacheca, area soci, azioni dei moduli del sito e shortcode. Si avvia da {@see ProBoot::init()}.
 */
final class ProBlockD {

	/** Sezioni dell'area soci di questo blocco: chiave => [funzione, dopo quale sezione]. */
	const SECTIONS = array(
		'assemblee' => array( 'assemblies', 'votazioni' ),
		'rimborsi'  => array( 'refunds', 'pagamenti' ),
	);

	/** Viste (shortcode) di questo blocco: chiave => [funzione, titolo]. */
	const VIEWS = array(
		'assemblee' => array( 'assemblies', 'Assemblee dei soci: convocazioni e deleghe' ),
		'rimborsi'  => array( 'refunds', 'Rimborsi spese del volontario (richiesta e stato)' ),
	);

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
		Frontend\Actions::add_handler( 'asem_front_proxy', array( Frontend\AssembliesFront::class, 'do_proxy' ) );
		Frontend\Actions::add_handler( 'asem_front_proxy_revoke', array( Frontend\AssembliesFront::class, 'do_revoke' ) );
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
		if ( Edition::installed( 'assemblies' ) ) {
			$data = Assemblies::privacy_export( $data, $person_id );
		}
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
			'asem_budget_csv'   => 'Admin\\BlockDExports',
			'asem_refunds_csv'  => 'Admin\\BlockDExports',
			'asem_assembly_csv' => 'Admin\\BlockDExports',
		);
	}

	public static function alerts(): void {
		Admin\RefundsPage::alert();
		Admin\BudgetPage::alert();
	}

	// ---------- Area soci e shortcode ----------

	/** Le assemblee compaiono dopo le votazioni, i rimborsi dopo i pagamenti. */
	public static function my_sections( array $sections ): array {
		foreach ( self::SECTIONS as $key => $row ) {
			if ( ! Edition::has( $row[0] ) ) {
				continue;
			}
			$out = array();
			foreach ( $sections as $s ) {
				$out[] = $s;
				if ( $row[1] === $s ) {
					$out[] = $key;
				}
			}
			$sections = in_array( $key, $out, true ) ? $out : array_merge( $out, array( $key ) );
		}
		return $sections;
	}

	public static function area_section( $html, string $key, array $p ) {
		if ( 'rimborsi' === $key && Edition::has( 'refunds' ) ) {
			return (string) $html . Frontend\RefundsFront::section( $p );
		}
		if ( 'assemblee' === $key && Edition::has( 'assemblies' ) ) {
			return (string) $html . Frontend\AssembliesFront::section( $p );
		}
		return $html;
	}

	public static function shortcode_views( array $views ): array {
		$out = array();
		foreach ( $views as $k => $label ) {
			$out[ $k ] = $label;
			if ( 'votazioni' === $k ) {
				$out['assemblee'] = self::VIEWS['assemblee'][1];
			}
			if ( 'pagamenti' === $k ) {
				$out['rimborsi'] = self::VIEWS['rimborsi'][1];
			}
		}
		return $out;
	}

	public static function render_view( $html, string $view, array $atts ) {
		if ( 'rimborsi' === $view ) {
			Frontend\Views::no_cache();
			return Frontend\RefundsFront::view();
		}
		if ( 'assemblee' === $view ) {
			Frontend\Views::no_cache();
			return Frontend\AssembliesFront::view();
		}
		return $html;
	}

	public static function view_available( $ok, string $view ) {
		return isset( self::VIEWS[ $view ] ) ? Edition::has( self::VIEWS[ $view ][0] ) : $ok;
	}

	public static function areas( array $groups ): array {
		if ( ! isset( $groups['soci'] ) ) {
			return $groups;
		}
		$out = array();
		foreach ( $groups['soci'][1] as $v ) {
			$out[] = $v;
			foreach ( self::SECTIONS as $key => $row ) {
				if ( $row[1] === $v && ! in_array( $key, $groups['soci'][1], true ) ) {
					$out[] = $key;
				}
			}
		}
		$groups['soci'][1] = $out;
		return $groups;
	}
}
