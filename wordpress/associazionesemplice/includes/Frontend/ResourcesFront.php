<?php
namespace AssociazioneSemplice\Frontend;

use AssociazioneSemplice\Access;
use AssociazioneSemplice\Edition;
use AssociazioneSemplice\PaymentItems;
use AssociazioneSemplice\MemberType;
use AssociazioneSemplice\Money;
use AssociazioneSemplice\Plugin;
use AssociazioneSemplice\Regulation;
use AssociazioneSemplice\ResourceRules;
use AssociazioneSemplice\Resources;

defined( 'ABSPATH' ) || exit;

/**
 * Risorse prenotabili nell'area dei soci: le mie prenotazioni e assegnazioni, la griglia del giorno per ogni risorsa a fasce, prenotazione e annullamento.
 * Solo i soci con la tessera valida; gli ospiti sono nomi scritti sulla prenotazione, mai persone in anagrafe.
 */
final class ResourcesFront {

	const ACTIONS = array(
		'asem_front_res_book'   => 'do_book',
		'asem_front_res_cancel' => 'do_cancel',
		'asem_front_res_wait'   => 'do_wait',
		'asem_front_res_unwait' => 'do_unwait',
	);

	public static function register(): void {
		foreach ( self::ACTIONS as $action => $method ) {
			add_action(
				'admin_post_' . $action,
				function () use ( $action, $method ) {
					check_admin_referer( $action );
					$post = wp_unslash( $_POST ); // phpcs:ignore WordPress.Security.NonceVerification
					$back = wp_validate_redirect( ! empty( $post['_back'] ) ? esc_url_raw( $post['_back'] ) : home_url( '/' ), home_url( '/' ) );
					try {
						$msg = self::$method( $post );
						if ( is_array( $msg ) ) { // prenotazione da pagare online: si va alla pagina del gateway
							wp_redirect( $msg['url'] ); // phpcs:ignore WordPress.Security.SafeRedirect -- indirizzo restituito dal gateway
							exit;
						}
						wp_safe_redirect( \AssociazioneSemplice\Flash::url( $back, 'asemf', $msg, '' ) );
					} catch ( \InvalidArgumentException $e ) {
						wp_safe_redirect( \AssociazioneSemplice\Flash::url( $back, 'asemf', '', $e->getMessage() ) );
					} catch ( \Throwable $e ) {
						wp_safe_redirect( \AssociazioneSemplice\Flash::url( $back, 'asemf', '', 'Operazione non riuscita. Riprova più tardi.' ) );
					}
					exit;
				}
			);
			add_action(
				'admin_post_nopriv_' . $action,
				function () {
					$post = wp_unslash( $_POST ); // phpcs:ignore WordPress.Security.NonceVerification
					wp_safe_redirect( wp_login_url( wp_validate_redirect( ! empty( $post['_back'] ) ? esc_url_raw( $post['_back'] ) : home_url( '/' ), home_url( '/' ) ) ) );
					exit;
				}
			);
		}
	}

	/** Il socio collegato all'utente (non un ospite). @throws \InvalidArgumentException */
	private static function actor(): array {
		$p = Access::current_person();
		if ( ! $p || ! MemberType::is_member( $p['type'] ) ) {
			throw new \InvalidArgumentException( 'Serve un accesso come socio.' );
		}
		return $p;
	}

	/** @return string|array messaggio, oppure ['url' => pagina del gateway] se si paga online */
	public static function do_book( array $post ) {
		$actor = self::actor();
		if ( Plugin::people()->profile_blocks( $actor ) ) {
			throw new \InvalidArgumentException( 'Prima di prenotare completa i tuoi dati (indirizzo e codice fiscale) nel riquadro «Il mio profilo».' );
		}
		$block = Regulation::booking_block( $actor );
		if ( '' !== $block ) {
			throw new \InvalidArgumentException( $block ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- messaggio interno, mostrato solo dopo esc_html
		}
		$guests = isset( $post['guest'] ) && is_array( $post['guest'] ) ? array_map( 'strval', $post['guest'] ) : array();
		$rid    = (int) ( $post['resource_id'] ?? 0 );
		$date   = (string) ( $post['date'] ?? '' );
		$start  = (string) ( $post['start'] ?? '' );
		$slots  = max( 1, (int) ( $post['slots'] ?? 1 ) );
		// Il costo e come si può pagare li decide il server, mai il modulo.
		list( $fee, $res ) = Resources::quote( $rid, $date, $start, $slots, $guests );
		$online  = Edition::has( 'payments' ) && Plugin::payments()->enabled();
		$options = ResourceRules::pay_options( (string) $res['pay_mode'], $online, $fee );
		if ( ! $options ) {
			throw new \InvalidArgumentException( 'Questa risorsa si paga online in anticipo, ma i pagamenti online non sono attivi: contatta la segreteria.' );
		}
		$pay = (string) ( $post['pay'] ?? '' );
		if ( array( 'free' ) === $options ) {
			$pay = 'free';
		} elseif ( 1 === count( $options ) ) {
			$pay = $options[0]; // una sola via: non serve scegliere
		}
		if ( ! in_array( $pay, $options, true ) ) {
			throw new \InvalidArgumentException( 'Scegli come pagare: ora online o in segreteria.' );
		}
		if ( 'online' !== $pay ) {
			Resources::book_slot( $rid, (int) $actor['id'], $date, $start, $slots, $guests );
			return 'desk' === $pay ? 'Prenotazione registrata: il contributo si paga in segreteria.' : 'Prenotazione registrata.';
		}
		// Pagamento online in anticipo: la fascia resta riservata pochi minuti; se il pagamento non parte si libera subito.
		$id   = Resources::book_slot( $rid, (int) $actor['id'], $date, $start, $slots, $guests, false, '', true );
		$back = remove_query_arg( array( 'asemf_ok', 'asemf_err', 'asemf_sig', 'asem_pay', 'asem_ret', 'token', 'PayerID' ), wp_validate_redirect( ! empty( $post['_back'] ) ? esc_url_raw( $post['_back'] ) : home_url( '/' ), home_url( '/' ) ) );
		try {
			$url = Plugin::payments()->create_checkout( $actor, get_current_user_id(), array( PaymentItems::key( array( 'type' => PaymentItems::RESERVATION, 'reservation_id' => $id, 'person_id' => (int) $actor['id'] ) ) ), $back );
		} catch ( \Throwable $e ) {
			Resources::cancel( $id, true ); // niente pagamento: la fascia torna libera
			throw $e;
		}
		return array( 'url' => $url );
	}

	public static function do_cancel( array $post ): string {
		$actor = self::actor();
		Resources::cancel( (int) ( $post['id'] ?? 0 ), false, (int) $actor['id'] );
		return 'Prenotazione annullata.';
	}

	public static function do_wait( array $post ): string {
		$actor = self::actor();
		Resources::waitlist_join( (int) ( $post['resource_id'] ?? 0 ), (int) $actor['id'] );
		return 'Sei in lista d\'attesa: ti avvisiamo per email quando si libera.';
	}

	public static function do_unwait( array $post ): string {
		$actor = self::actor();
		Resources::waitlist_leave( (int) ( $post['resource_id'] ?? 0 ), (int) $actor['id'] );
		return 'Sei uscito dalla lista d\'attesa.';
	}

	// ---------- Vista ----------

	private static function form( string $action, string $fields, string $button, string $class = 'asemf-inline', bool $confirm = false ): string {
		return '<form class="asemf-form ' . esc_attr( $class ) . '" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"' . ( $confirm ? ' onsubmit="return confirm(\'Confermi?\');"' : '' ) . '>'
			. '<input type="hidden" name="action" value="' . esc_attr( $action ) . '"><input type="hidden" name="_back" value="' . esc_url( Restrict::current_url() ) . '">'
			. wp_nonce_field( $action, '_wpnonce', false, false ) . $fields . '<button class="asemf-btn">' . esc_html( $button ) . '</button></form>';
	}

	private static function hidden( string $name, $value ): string {
		return '<input type="hidden" name="' . esc_attr( $name ) . '" value="' . esc_attr( (string) $value ) . '">';
	}

	private static function fee_text( array $r ): string {
		if ( (int) $r['fee_cents'] <= 0 ) {
			return 'gratuita';
		}
		return esc_html( Money::format( (int) $r['fee_cents'] ) ) . ( ResourceRules::SLOT === $r['mode'] ? ' a fascia' : ' a periodo' );
	}

	private static function when( array $r ): string {
		$a = (string) $r['starts_at'];
		$b = $r['ends_at'] ? (string) $r['ends_at'] : null;
		$d = function ( string $ymd ): string {
			return ( new \DateTimeImmutable( $ymd ) )->format( 'd/m/Y' );
		};
		if ( ResourceRules::KIND_ASSIGN === $r['kind'] ) {
			return 'dal ' . $d( substr( $a, 0, 10 ) ) . ( $b ? ' al ' . $d( gmdate( 'Y-m-d', strtotime( substr( $b, 0, 10 ) . ' -1 day' ) ) ) : '' );
		}
		return $d( substr( $a, 0, 10 ) ) . ' ' . substr( $a, 11, 5 ) . ( $b ? '–' . substr( $b, 11, 5 ) : '' );
	}

	public static function section( array $p ): string {
		$pid    = (int) $p['id'];
		$active = Plugin::people()->is_active_member( $pid );
		$html   = '<section class="asemf-section"><h3>Risorse</h3>';

		$mine = Resources::for_person( $pid );
		if ( $mine ) {
			$due   = Edition::has( 'payments' ) && Plugin::payments()->enabled() ? Resources::online_dues( $p ) : array(); // quelle che si possono pagare online
			$html .= '<h4>Le mie prenotazioni e assegnazioni</h4><ul class="asemf-list">';
			foreach ( $mine as $r ) {
				$can   = Resources::cancellation( $r );
				$html .= '<li><div><strong>' . esc_html( $r['resource_name'] ) . '</strong> · ' . esc_html( self::when( $r ) )
					. ( $r['guests'] ? '<div class="asemf-small">Ospiti: ' . esc_html( implode( ', ', $r['guests'] ) ) . '</div>' : '' ) . '</div><div>'
					. ( (int) $r['fee_due_cents'] > 0 ? ( 'paid' === $r['state'] ? '<span class="asemf-badge asemf-badge-ok">pagata</span> ' : '<span class="asemf-badge">da pagare ' . esc_html( Money::format( (int) $r['remaining'] ) ) . '</span> ' ) : '' )
					. ( ! empty( $r['hold_until'] ) ? '<span class="asemf-small asemf-muted">riservata fino alle ' . esc_html( substr( (string) $r['hold_until'], 11, 5 ) ) . ', poi si libera se non paghi</span> ' : '' )
					. ( isset( $due[ 'r:' . (int) $r['id'] . ':' . $pid ] ) ? self::form( 'asem_front_pay', self::hidden( 'items[]', 'r:' . (int) $r['id'] . ':' . $pid ), 'Paga ora' ) . ' ' : '' )
					. ( $can['allowed'] ? self::form( 'asem_front_res_cancel', self::hidden( 'id', $r['id'] ), 'Annulla', 'asemf-inline', true ) : '<span class="asemf-small asemf-muted">' . esc_html( $can['message'] ) . '</span>' )
					. '</div></li>';
			}
			$html .= '</ul>';
		}

		if ( ! $active ) {
			return $html . '<div class="asemf-notice">Rinnova la tessera per prenotare le risorse.</div></section>';
		}
		$list = Resources::all();
		if ( ! $list ) {
			return $html . ( $mine ? '' : '<p class="asemf-muted">Nessuna risorsa prenotabile.</p>' ) . '</section>';
		}
		$sel_id  = isset( $_GET['asem_res'] ) ? (int) $_GET['asem_res'] : 0; // phpcs:ignore WordPress.Security.NonceVerification
		$sel_day = isset( $_GET['asem_day'] ) ? sanitize_text_field( wp_unslash( $_GET['asem_day'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		$today   = current_time( 'Y-m-d' );
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $sel_day ) || $sel_day < $today ) {
			$sel_day = $today;
		}
		$html .= '<h4>Prenota</h4>';
		foreach ( $list as $r ) {
			$open  = (int) $r['id'] === $sel_id;
			$html .= '<details class="asemf-card"' . ( $open ? ' open' : '' ) . '><summary><strong>' . esc_html( $r['name'] ) . '</strong> · ' . esc_html( ResourceRules::categories()[ $r['category'] ] ?? '' ) . ' · ' . self::fee_text( $r ) // phpcs:ignore WordPress.Security.EscapeOutput
				. ( (int) $r['guests_max'] > 0 ? ' · ospiti fino a ' . (int) $r['guests_max'] : '' ) . '</summary>';
			if ( '' !== trim( (string) $r['description'] ) ) {
				$html .= '<p>' . esc_html( $r['description'] ) . '</p>';
			}
			if ( '' !== trim( (string) $r['rules'] ) ) {
				$html .= '<p class="asemf-small asemf-muted">' . nl2br( esc_html( $r['rules'] ) ) . '</p>';
			}
			if ( ResourceRules::ASSIGN === $r['mode'] ) {
				$html .= self::assign_info( $r, $pid ) . '</details>';
				continue;
			}
			$html .= self::day( $r, $open ? $sel_day : $today, $today ) . '</details>';
		}
		return $html . '</section>';
	}

	/** Risorsa assegnata a periodo: la assegna la segreteria; se è occupata il socio può mettersi in coda. */
	private static function assign_info( array $r, int $pid ): string {
		$rid = (int) $r['id'];
		$now = current_time( 'Y-m-d H:i:s' );
		foreach ( Resources::for_person( $pid ) as $mine ) {
			if ( (int) $mine['resource_id'] === $rid && ResourceRules::KIND_ASSIGN === $mine['kind'] ) {
				return '<p class="asemf-muted">È tua: la trovi sopra, tra le tue assegnazioni.</p>';
			}
		}
		$pos = Resources::waitlist_position( $rid, $pid );
		if ( null !== $pos ) {
			return '<p class="asemf-muted">Sei in lista d\'attesa (posizione ' . (int) $pos . ').</p>'
				. self::form( 'asem_front_res_unwait', self::hidden( 'resource_id', $rid ), 'Esci dalla lista', 'asemf-inline', true );
		}
		if ( ! Resources::between( $rid, $now, gmdate( 'Y-m-d H:i:s', strtotime( $now . ' +1 second' ) ) ) ) {
			return '<p class="asemf-muted">Al momento è libera: scrivi alla segreteria per averla.</p>';
		}
		return '<p class="asemf-muted">Al momento è occupata. Se si libera ti avvisiamo; l\'assegnazione la fa la segreteria.</p>'
			. self::form( 'asem_front_res_wait', self::hidden( 'resource_id', $rid ), 'Mettiti in lista d\'attesa' );
	}

	/** Il modulo di prenotazione di una fascia, con i pulsanti che la regola di pagamento della risorsa consente. */
	private static function book_form( array $r, string $fields, string $starts_at ): string {
		$online  = Edition::has( 'payments' ) && Plugin::payments()->enabled();
		$fee     = ResourceRules::fee( $r, max( 1, (int) $r['min_slots'] ), 0, $starts_at );
		$options = ResourceRules::pay_options( (string) $r['pay_mode'], $online, $fee );
		if ( ! $options ) {
			return '<span class="asemf-small asemf-muted">Pagamento online non attivo: prenota in segreteria</span>';
		}
		$labels  = array( 'free' => 'Prenota', 'desk' => 'Prenota (pago in segreteria)', 'online' => 'Prenota e paga ora' );
		$buttons = '';
		foreach ( $options as $o ) {
			$buttons .= '<button class="asemf-btn" name="pay" value="' . esc_attr( $o ) . '">' . esc_html( $labels[ $o ] ) . '</button> ';
		}
		return '<form class="asemf-form asemf-inline" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">'
			. '<input type="hidden" name="action" value="asem_front_res_book"><input type="hidden" name="_back" value="' . esc_url( Restrict::current_url() ) . '">'
			. wp_nonce_field( 'asem_front_res_book', '_wpnonce', false, false ) . $fields . $buttons . '</form>';
	}

	private static function day( array $r, string $day, string $today ): string {
		$rid  = (int) $r['id'];
		$base = remove_query_arg( array( 'asem_res', 'asem_day' ), Restrict::current_url() );
		$nav  = function ( string $d ) use ( $base, $rid ): string {
			return esc_url( add_query_arg( array( 'asem_res' => $rid, 'asem_day' => $d ), $base ) );
		};
		$prev = gmdate( 'Y-m-d', strtotime( $day . ' -1 day' ) );
		$next = gmdate( 'Y-m-d', strtotime( $day . ' +1 day' ) );
		$days = (int) $r['advance_days'];
		$html = '<p>' . ( $day > $today ? '<a class="asemf-btn" href="' . $nav( $prev ) . '">‹</a> ' : '' ) . '<strong>' . esc_html( ( new \DateTimeImmutable( $day ) )->format( 'd/m/Y' ) ) . '</strong> '
			. ( 0 === $days || $next <= gmdate( 'Y-m-d', strtotime( $today . ' +' . $days . ' days' ) ) ? '<a class="asemf-btn" href="' . $nav( $next ) . '">›</a>' : '' ) . '</p>';
		$now  = current_time( 'Y-m-d H:i:s' );
		$html .= '<ul class="asemf-list">';
		foreach ( Resources::day_grid( $r, $day ) as $s ) {
			$html .= '<li><div>' . esc_html( $s['start'] . '–' . $s['end'] ) . '</div><div>';
			if ( $s['reservation'] ) {
				$html .= '<span class="asemf-small asemf-muted">occupata</span>';
			} elseif ( $day . ' ' . $s['start'] . ':00' < $now ) {
				$html .= '<span class="asemf-small asemf-muted">passata</span>';
			} else {
				$fields = self::hidden( 'resource_id', $rid ) . self::hidden( 'date', $day ) . self::hidden( 'start', $s['start'] );
				if ( (int) $r['max_slots'] > 1 ) {
					$opts = '';
					for ( $n = max( 1, (int) $r['min_slots'] ); $n <= (int) $r['max_slots']; $n++ ) {
						$opts .= '<option value="' . $n . '">' . $n . ' ' . ( 1 === $n ? 'fascia' : 'fasce' ) . '</option>';
					}
					$fields .= '<select name="slots">' . $opts . '</select> ';
				} else {
					$fields .= self::hidden( 'slots', max( 1, (int) $r['min_slots'] ) );
				}
				for ( $g = 1; $g <= (int) $r['guests_max']; $g++ ) {
					$fields .= '<input type="text" name="guest[]" maxlength="120" placeholder="Ospite ' . $g . ' (nome e cognome)"> ';
				}
				$price = ResourceRules::band_fee( $r, $day . ' ' . $s['start'] . ':00' );
				$html .= ( $price > 0 && ! empty( $r['rates'] ) ? '<span class="asemf-small asemf-muted">' . esc_html( Money::format( $price ) ) . '</span> ' : '' ) . self::book_form( $r, $fields, $day . ' ' . $s['start'] . ':00' );
			}
			$html .= '</div></li>';
		}
		return $html . '</ul>';
	}
}
