<?php
namespace AssociazioneSemplice;

defined( 'ABSPATH' ) || exit;

/**
 * Regole pure della tessera: segue l'anno sociale (stesso mese di inizio), non l'esercizio contabile.
 * Con inizio a gennaio la tessera è l'anno solare e l'etichetta è «2026»; con un altro mese (per esempio settembre)
 * va da quel mese a quello prima dell'anno dopo e l'etichetta è «2026/2027».
 */
final class MembershipRules {

	/**
	 * Il rinnovo per l'anno dopo si apre negli ultimi giorni della tessera in corso (gli stessi dell'avviso di scadenza).
	 *
	 * @param SocialYear $current anno della tessera di oggi
	 * @param int        $days    quanti giorni prima della fine
	 */
	public static function renewal_open( SocialYear $current, string $date, int $days ): bool {
		$left = ( new \DateTimeImmutable( $date ) )->diff( $current->end() );
		return ! $left->invert && (int) $left->days <= max( 0, $days );
	}

	/**
	 * Per quale anno paga la quota chi versa oggi, e quale anno gli è regalato.
	 *  - fuori dal periodo di rinnovo: paga l'anno in corso;
	 *  - nel periodo di rinnovo chi ha già l'anno in corso rinnova in anticipo (paga l'anno dopo);
	 *  - nel periodo di rinnovo chi è nuovo (non aveva la tessera nemmeno l'anno scorso) paga l'anno dopo e ha l'anno in corso in omaggio;
	 *  - nel periodo di rinnovo chi non ha rinnovato l'anno in corso, ma aveva la tessera l'anno scorso, paga prima l'anno in corso.
	 *
	 * @return array{year:string, free:?string}
	 */
	public static function plan( SocialYear $current, bool $renewal_open, bool $has_current, bool $has_previous ): array {
		$year = $current->label();
		$free = null;
		if ( $renewal_open ) {
			if ( $has_current ) {
				$year = $current->next()->label();
			} elseif ( ! $has_previous ) {
				$year = $current->next()->label();
				$free = $current->label();
			}
		}
		return array( 'year' => $year, 'free' => $free );
	}

	/** Anno della tessera indicato da un'etichetta: «2026» (senza barra) è sempre l'anno solare, come per le tessere già emesse; «2026/2027» inizia nel mese in vigore. */
	public static function from_label( string $label, int $start_month ): SocialYear {
		return SocialYear::from_label( $label, max( 1, min( 12, $start_month ) ) );
	}

	/** Colonna-anno di un file importato («2025»): con l'anno sociale sfalsato è la tessera che inizia in quell'anno («2025/2026»); con gennaio resta «2025». */
	public static function import_label( string $label, int $start_month ): string {
		if ( 1 !== $start_month && 1 === preg_match( '/^\d{4}$/', $label ) ) {
			return ( new SocialYear( (int) $label, max( 1, min( 12, $start_month ) ) ) )->label();
		}
		return $label;
	}

	/**
	 * Si può passare a un nuovo mese di inizio senza che due tessere diverse abbiano la stessa etichetta?
	 * Falso se esiste una tessera con la barra («2026/2027»), ancora valida, che parte in un altro mese.
	 *
	 * @param array[] $live_slash_cards valid_from e valid_to delle tessere non cancellate con la barra nell'etichetta
	 */
	public static function month_change_conflicts( array $live_slash_cards, int $new_month, string $today ): bool {
		if ( 1 === $new_month ) {
			return false; // l'anno solare ha l'etichetta senza barra: non si confonde con nessun'altra
		}
		foreach ( $live_slash_cards as $c ) {
			if ( (string) $c['valid_to'] >= $today && (int) substr( (string) $c['valid_from'], 5, 2 ) !== $new_month ) {
				return true;
			}
		}
		return false;
	}
}
