<?php
namespace AssociazioneSemplice\Frontend;

use AssociazioneSemplice\ConsentRules;
use AssociazioneSemplice\Consents;
use AssociazioneSemplice\Edition;
use AssociazioneSemplice\Settings;

defined( 'ABSPATH' ) || exit;

/** Area soci: la persona dà e revoca da sola il consenso a SMS e newsletter. */
final class ConsentFront {

	/** Canali che l'associazione offre ora: canale => funzione. @return string[] */
	private static function offered(): array {
		$out = array();
		if ( Edition::has( 'sms' ) && \AssociazioneSemplice\Sms::configured() ) {
			$out[] = ConsentRules::SMS;
		}
		if ( Edition::has( 'newsletter' ) && \AssociazioneSemplice\Newsletter::configured() ) {
			$out[] = ConsentRules::NEWSLETTER;
		}
		return $out;
	}

	/** La sezione «Le mie comunicazioni» dell'area soci (vuota se l'associazione non usa né SMS né newsletter). */
	public static function section( array $p ): string {
		$channels = self::offered();
		if ( ! $channels || ! empty( $p['anonymized_at'] ) ) {
			return '';
		}
		$assoc  = (string) Settings::get( 'association_name' );
		$fields = '';
		foreach ( $channels as $ch ) {
			$fields .= '<p><label><input type="checkbox" name="consent_' . esc_attr( $ch ) . '" value="1"' . checked( Consents::has( (int) $p['id'], $ch ), true, false ) . '> ' . esc_html( ConsentRules::notice( $ch, $assoc ) ) . '</label></p>';
		}
		return '<section class="asemf-section"><h3>Le mie comunicazioni</h3><p class="asemf-small asemf-muted">Scegli tu come vuoi essere contattato oltre alle email di servizio. Puoi cambiare idea quando vuoi.</p>'
			. Views::form( 'asem_front_consent', $fields, 'Salva le mie scelte' ) . '</section>';
	}

	/** Salva le scelte della persona collegata. */
	public static function do_save( array $post ): string {
		$actor = Actions::actor();
		$done  = 0;
		foreach ( self::offered() as $ch ) {
			$want = ! empty( $post[ 'consent_' . $ch ] );
			if ( $want !== Consents::has( (int) $actor['id'], $ch ) ) {
				Consents::set( (int) $actor['id'], $ch, $want, 'web' );
				$done++;
			}
		}
		return $done ? 'Scelte salvate.' : 'Nessuna modifica.';
	}
}
