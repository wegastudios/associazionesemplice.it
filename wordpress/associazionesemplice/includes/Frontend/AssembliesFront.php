<?php
namespace AssociazioneSemplice\Frontend;

use AssociazioneSemplice\Assemblies;
use AssociazioneSemplice\AssemblyRules;

defined( 'ABSPATH' ) || exit;

/** Area soci: le assemblee convocate, con la possibilità di delegare un altro socio e di revocare la delega. */
final class AssembliesFront {

	public static function register(): void {
		add_filter( 'asem_area_section', array( __CLASS__, 'area_section' ), 10, 3 );
	}

	public static function area_section( $html, $section, $person ) {
		return 'assemblee' === $section ? (string) $html . self::section( (array) $person ) : $html;
	}

	public static function section( array $p ): string {
		$list = Assemblies::for_person( $p );
		if ( ! $list ) {
			return '';
		}
		Assets::enqueue();
		$pid    = (int) $p['id'];
		$others = '';
		foreach ( Assemblies::eligible_people() as $o ) {
			if ( (int) $o['id'] !== $pid ) {
				$others .= '<option value="' . (int) $o['id'] . '">' . esc_html( trim( $o['last_name'] . ' ' . $o['first_name'] ) ) . '</option>';
			}
		}
		$html = '<section class="asemf-section asemf-assemblies"><h3>Assemblee</h3><ul class="asemf-list">';
		foreach ( $list as $a ) {
			$id    = (int) $a['id'];
			$html .= '<li><div><strong>' . esc_html( $a['title'] ) . '</strong> <span class="asemf-small asemf-muted">' . esc_html( AssemblyRules::kinds()[ $a['kind'] ] ?? '' ) . '</span>'
				. '<div>Prima convocazione: ' . esc_html( mysql2date( 'd/m/Y \a\l\l\e H:i', $a['first_call_at'] ) ) . ( '' !== (string) $a['place'] ? ', ' . esc_html( $a['place'] ) : '' ) . '</div>';
			if ( ! empty( $a['second_call_at'] ) ) {
				$html .= '<div>Seconda convocazione: ' . esc_html( mysql2date( 'd/m/Y \a\l\l\e H:i', $a['second_call_at'] ) ) . '</div>';
			}
			$html .= '<div>' . nl2br( esc_html( (string) $a['agenda'] ) ) . '</div>';
			if ( $a['gotten'] ) {
				$html .= '<div class="asemf-small"><strong>Hai ricevuto la delega di:</strong> ' . esc_html( implode( ', ', $a['gotten'] ) ) . '.</div>';
			}
			if ( $a['given'] ) {
				$html .= '<div class="asemf-small"><strong>Hai delegato:</strong> ' . esc_html( Assemblies::proxy_name( $a['given'], 'b' ) ) . '.</div>'
					. Views::form( 'asem_front_proxy_revoke', Views::hidden( 'assembly_id', $id ), 'Revoca la delega', true );
			} elseif ( ! $a['gotten'] && '' !== $others ) {
				$html .= Views::form( 'asem_front_proxy', Views::hidden( 'assembly_id', $id ) . '<label class="asemf-field">Se non puoi venire, delega un altro socio <select name="delegate_id" required><option value="">— scegli —</option>' . $others . '</select></label>', 'Delega', true );
			}
			$html .= '</div></li>';
		}
		return $html . '</ul></section>';
	}

	/** La vista da shortcode: la stessa sezione, o un avviso se non ci sono assemblee. */
	public static function view(): string {
		return Views::with_person(
			function ( $p ) {
				$html = self::section( $p );
				return '' !== $html ? $html : '<div class="asemf-notice">Nessuna assemblea convocata.</div>';
			}
		);
	}

	public static function do_proxy( array $post ): string {
		$actor = Actions::actor();
		Assemblies::add_proxy( (int) ( $post['assembly_id'] ?? 0 ), (int) $actor['id'], (int) ( $post['delegate_id'] ?? 0 ), false );
		return 'Delega registrata. Il socio scelto la porterà in assemblea.';
	}

	public static function do_revoke( array $post ): string {
		$actor = Actions::actor();
		Assemblies::revoke_proxy( (int) ( $post['assembly_id'] ?? 0 ), (int) $actor['id'] );
		return 'Delega revocata.';
	}
}
