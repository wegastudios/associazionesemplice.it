<?php
namespace AssociazioneSemplice\Frontend;

use AssociazioneSemplice\Certificates;
use AssociazioneSemplice\Edition;
use AssociazioneSemplice\ExpiryRules;
use AssociazioneSemplice\MemberType;
use AssociazioneSemplice\Plugin;
use AssociazioneSemplice\Settings;

defined( 'ABSPATH' ) || exit;

/** Area soci: la scadenza del proprio certificato medico (facoltativa, si attiva nelle impostazioni dei certificati). Mai il file né il medico. */
final class CertificatesFront {

	/** La sezione dell'area soci per la persona. Vuota se non è attiva o non riguarda questa persona. */
	public static function section( array $p ): string {
		if ( ! Edition::has( 'medical' ) || empty( Settings::get( 'cert_show_member' ) ) || ! MemberType::is_member( (string) $p['type'] ) ) {
			return '';
		}
		$pid = (int) $p['id'];
		$st  = Certificates::status_of( $pid );
		$req = array_intersect( Plugin::activities()->active_activity_ids( $pid ), Certificates::required_ids() );
		if ( ! $st['cert'] && ! $req ) {
			return ''; // nessuna attività glielo chiede e non ne ha uno: non c'è niente da dire
		}
		$labels = array(
			ExpiryRules::VALID    => 'valido',
			ExpiryRules::EXPIRING => 'in scadenza',
			ExpiryRules::EXPIRED  => 'scaduto',
			ExpiryRules::NONE     => 'non registrato',
		);
		$html = '<section class="asemf-section"><h3>Certificato medico</h3><p>Stato: <strong>' . esc_html( $labels[ $st['status'] ] ) . '</strong>';
		if ( $st['cert'] ) {
			$html .= ' · scade il ' . esc_html( ExpiryRules::it_date( (string) $st['cert']['expires_on'] ) );
		}
		$html .= '</p>';
		if ( in_array( $st['status'], array( ExpiryRules::NONE, ExpiryRules::EXPIRED, ExpiryRules::EXPIRING ), true ) ) {
			$html .= '<p class="asemf-small asemf-muted">Porta il certificato aggiornato alla segreteria.</p>';
		}
		return $html . '</section>';
	}
}
