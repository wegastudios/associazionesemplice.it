<?php
namespace AssociazioneSemplice\Admin;

defined( 'ABSPATH' ) || exit;

/** Avvisi delle funzioni aggiuntive nella Bacheca (assicurazioni, 5x1000). */
final class ProDashboard {

	public static function register(): void {
		add_action( 'asem_dashboard_alerts', array( __CLASS__, 'alerts' ) );
		add_action( 'asem_years_page_after', array( ReportsPage::class, 'funds_by_year' ) ); // nella pagina Anni solari (gratuita) i fondi per anno
	}

	public static function alerts(): void {
		if ( \AssociazioneSemplice\Edition::has( 'insurance' ) && (bool) \AssociazioneSemplice\Settings::get( 'insurance_association' ) ) {
			$rc = \AssociazioneSemplice\AssocPolicies::rc_status();
			if ( \AssociazioneSemplice\Insurance::VALID !== $rc ) {
				$msg = array( \AssociazioneSemplice\Insurance::NONE => 'non risulta nessuna polizza di responsabilità civile dell\'associazione', \AssociazioneSemplice\Insurance::EXPIRED => 'la polizza di responsabilità civile dell\'associazione è scaduta', \AssociazioneSemplice\Insurance::EXPIRING => 'la polizza di responsabilità civile dell\'associazione sta per scadere' );
				echo Dismiss::html( 'insurance-rc-' . $rc, 'warning', 'Assicurazione: ' . esc_html( $msg[ $rc ] ) . '. <a href="' . esc_url( Ui::url( 'asem-volunteers' ) ) . '">Apri le assicurazioni</a>.', 7 ); // phpcs:ignore WordPress.Security.EscapeOutput
			}
		}
		if ( \AssociazioneSemplice\Edition::has( 'fivepm' ) && \AssociazioneSemplice\FivePerMille::enabled() ) {
			$fp = \AssociazioneSemplice\FivePerMille::alerts();
			if ( $fp ) {
				echo Dismiss::html( 'fivepm-' . count( $fp ), 'warning', '5x1000: ' . count( $fp ) . ( 1 === count( $fp ) ? ' contributo ha il rendiconto sull\'utilizzo scaduto o in scadenza' : ' contributi hanno il rendiconto sull\'utilizzo scaduto o in scadenza' ) . '. <a href="' . esc_url( Ui::url( 'asem-fivepm' ) ) . '">Apri il 5x1000</a>.', 7 ); // phpcs:ignore WordPress.Security.EscapeOutput
			}
		}
		$ins = \AssociazioneSemplice\Edition::has( 'insurance' ) && (bool) \AssociazioneSemplice\Settings::get( 'insurance_volunteers' ) ? \AssociazioneSemplice\Insurance::counts() : array( 'none' => 0, 'expired' => 0, 'expiring' => 0 );
		if ( $ins['none'] + $ins['expired'] + $ins['expiring'] > 0 ) {
			echo Dismiss::html( 'insurance-vol-' . ( $ins['none'] + $ins['expired'] ) . '-' . $ins['expiring'], 'warning', 'Assicurazione dei volontari: ' // phpcs:ignore WordPress.Security.EscapeOutput -- html già protetto
				. (int) ( $ins[ \AssociazioneSemplice\Insurance::NONE ] + $ins[ \AssociazioneSemplice\Insurance::EXPIRED ] ) . ' senza copertura valida, ' . (int) $ins[ \AssociazioneSemplice\Insurance::EXPIRING ] . ' in scadenza. '
				. '<a href="' . esc_url( Ui::url( 'asem-volunteers' ) ) . '">Apri il registro</a>.', 7 ); // phpcs:ignore WordPress.Security.EscapeOutput
		}
	}
}
