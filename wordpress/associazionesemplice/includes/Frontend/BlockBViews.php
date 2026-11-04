<?php
namespace AssociazioneSemplice\Frontend;

use AssociazioneSemplice\DocumentRules;
use AssociazioneSemplice\Documents;
use AssociazioneSemplice\PollRules;
use AssociazioneSemplice\Polls;

defined( 'ABSPATH' ) || exit;

/** Viste per i soci: votazioni e documenti (nell'area riservata); elenco pubblico dei documenti. */
final class BlockBViews {

	public static function register(): void {
		add_filter( 'asem_area_section', array( __CLASS__, 'area_section' ), 10, 3 );
	}

	/** Sezioni dell'area soci aggiunte da questo blocco. */
	public static function area_section( $html, $section, $person ) {
		if ( 'votazioni' === $section ) {
			return (string) $html . self::section_polls( (array) $person );
		}
		if ( 'documenti' === $section ) {
			return (string) $html . self::section_documents( (array) $person );
		}
		return $html;
	}

	// ---------- Votazioni ----------

	public static function section_polls( array $p ): string {
		$polls = Polls::for_person( $p );
		if ( ! $polls ) {
			return '';
		}
		Assets::enqueue();
		$html = '<section class="asemf-section asemf-polls"><h3>Votazioni e sondaggi</h3><ul class="asemf-list">';
		foreach ( $polls as $poll ) {
			$id    = (int) $poll['id'];
			$html .= '<li><div><strong>' . esc_html( $poll['title'] ) . '</strong> <span class="asemf-small asemf-muted">' . ( PollRules::OPEN === $poll['status']
				? 'aperta fino al ' . esc_html( mysql2date( 'd/m/Y H:i', $poll['closes_at'] ) ) : 'chiusa il ' . esc_html( mysql2date( 'd/m/Y H:i', $poll['closes_at'] ) ) )
				. ( $poll['secret'] ? ' · voto segreto' : '' ) . '</span>';
			if ( '' !== (string) $poll['question'] ) {
				$html .= '<div>' . nl2br( esc_html( $poll['question'] ) ) . '</div>';
			}
			if ( PollRules::OPEN === $poll['status'] && ! $poll['voted'] ) {
				$type    = $poll['multiple'] ? 'checkbox' : 'radio';
				$fields  = Views::hidden( 'poll_id', $id );
				$fields .= '<p class="asemf-small">' . ( $poll['multiple'] ? 'Puoi scegliere più risposte' . ( $poll['max_choices'] ? ' (al massimo ' . (int) $poll['max_choices'] . ')' : '' ) . '.' : 'Scegli una risposta.' ) . '</p>';
				foreach ( Polls::options( $id ) as $o ) {
					$fields .= '<label class="asemf-check"><input type="' . $type . '" name="choice[]" value="' . (int) $o['id'] . '"> ' . esc_html( $o['label'] ) . '</label> ';
				}
				$html .= Views::form( 'asem_front_vote', $fields, 'Vota', true );
			} elseif ( $poll['voted'] ) {
				$html .= '<div class="asemf-small"><strong>Hai già votato.</strong></div>';
			}
			if ( PollRules::results_visible( false, (bool) $poll['show_results'], (string) $poll['status'] ) ) {
				$res   = Polls::results( $id );
				$html .= '<table class="asemf-table"><tbody>';
				foreach ( $res['options'] as $o ) {
					$html .= '<tr><td>' . esc_html( $o['label'] ) . '</td><td>' . (int) $o['count'] . ' (' . (int) $o['percent'] . '%)</td></tr>';
				}
				$html .= '</tbody></table><div class="asemf-small asemf-muted">Hanno votato ' . (int) $res['voters'] . ' soci su ' . (int) $res['eligible'] . '.</div>';
			}
			$html .= '</div></li>';
		}
		return $html . '</ul></section>';
	}

	public static function polls(): string {
		return Views::with_person(
			function ( $p ) {
				$html = self::section_polls( $p );
				return '' !== $html ? $html : '<div class="asemf-notice">Nessuna votazione aperta.</div>';
			}
		);
	}

	// ---------- Documenti ----------

	/** @param array<string,array[]> $groups */
	private static function list_html( array $groups, string $title ): string {
		if ( ! $groups ) {
			return '';
		}
		Assets::enqueue();
		$html = '<section class="asemf-section asemf-documents">' . ( '' !== $title ? '<h3>' . esc_html( $title ) . '</h3>' : '' );
		foreach ( $groups as $cat => $docs ) {
			$html .= '<h4>' . esc_html( (string) $cat ) . '</h4><ul class="asemf-list">';
			foreach ( $docs as $d ) {
				$html .= '<li><div><a href="' . esc_url( Documents::url( (int) $d['id'] ) ) . '">' . esc_html( $d['title'] ) . '</a>'
					. ( $d['doc_date'] ? ' <span class="asemf-small asemf-muted">' . esc_html( ( new \DateTimeImmutable( $d['doc_date'] ) )->format( 'd/m/Y' ) ) . '</span>' : '' )
					. ( '' !== (string) $d['description'] ? '<div class="asemf-small">' . esc_html( $d['description'] ) . '</div>' : '' ) . '</div></li>';
			}
			$html .= '</ul>';
		}
		return $html . '</section>';
	}

	/** Documenti per i soci (e quelli pubblici), senza quelli riservati alla segreteria. */
	public static function section_documents( array $p ): string {
		$groups = array();
		foreach ( Documents::readable_grouped() as $cat => $docs ) {
			$docs = array_values( array_filter( $docs, function ( $d ) {
				return DocumentRules::STAFF !== $d['visibility'];
			} ) );
			if ( $docs ) {
				$groups[ $cat ] = $docs;
			}
		}
		return self::list_html( $groups, 'Documenti' );
	}

	public static function documents_member(): string {
		return Views::with_person(
			function ( $p ) {
				$html = self::section_documents( $p );
				return '' !== $html ? $html : '<div class="asemf-notice">Nessun documento disponibile.</div>';
			}
		);
	}

	/** Elenco pubblico: solo i documenti segnati «Pubblico». @param array $atts categoria */
	public static function documents_public( array $atts ): string {
		Views::no_cache();
		$groups = Documents::readable_grouped( DocumentRules::PUBLIC );
		$cat    = trim( (string) ( $atts['categoria'] ?? '' ) );
		if ( '' !== $cat ) {
			$groups = array_intersect_key( $groups, array( $cat => true ) );
		}
		$html = self::list_html( $groups, '' );
		return '' !== $html ? '<div class="asemf">' . $html . '</div>' : '<div class="asemf"><div class="asemf-notice">Nessun documento disponibile.</div></div>';
	}
}
