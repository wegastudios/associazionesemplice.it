<?php
namespace AssociazioneSemplice;

defined( 'ABSPATH' ) || exit;

/**
 * Avviso «da invitare ad associarsi» al check-in di un ospite non socio. Lo staff che segna l'ingresso lo vede subito nella pagina; resta tra i «Da fare» della Bacheca
 * (con il link alla scheda) finché qualcuno non preme «Fatto». Nessuna email: l'avviso è solo per chi gestisce, e ogni passaggio finisce nel registro azioni.
 * Le regole (quando scatta, come si compone il messaggio) sono in {@see GuestInviteRules}.
 */
final class GuestInvite {

	const OPTION = 'asem_invite_notices';

	/** @return array<string,array> id => voce (person_id, session_id, reasons, date, at) */
	public static function all(): array {
		$v = get_option( self::OPTION, array() );
		return is_array( $v ) ? $v : array();
	}

	/**
	 * Chiamata da {@see ActivityService::check_in()} quando l'ingresso è stato registrato.
	 *
	 * @param array $session riga della data (con activity_id)
	 * @return string il testo dell'avviso per chi registra l'ingresso (vuoto se l'ospite non è da invitare, o se non è un ospite)
	 */
	public static function on_check_in( array $session, int $person_id ): string {
		$person = Plugin::people()->get( $person_id );
		if ( ! $person || MemberType::GUEST !== $person['type'] ) {
			return '';
		}
		$activity = Plugin::activities()->get( (int) $session['activity_id'] );
		$overview = Plugin::people()->guest_overview( array( $person_id ) )[ $person_id ] ?? array();
		$host     = '';
		if ( ! empty( $person['host_person_id'] ) ) {
			$h    = Plugin::people()->get( (int) $person['host_person_id'] );
			$host = $h ? trim( $h['first_name'] . ' ' . $h['last_name'] ) : '';
		}
		$reasons = GuestInviteRules::reasons( true, (int) ( $overview['total'] ?? 0 ), Plugin::activities()->guest_limit(), $host, (string) ( $activity['name'] ?? '' ) );
		if ( ! $reasons ) {
			return '';
		}
		$session_id = (int) ( $session['id'] ?? 0 );
		$all        = self::all();
		$all[ GuestInviteRules::notice_id( $person_id ) ] = array(
			'person_id' => $person_id, 'session_id' => $session_id, 'reasons' => $reasons, 'date' => current_time( 'Y-m-d' ), 'at' => time(),
		);
		update_option( self::OPTION, GuestInviteRules::trim( $all, GuestInviteRules::MAX ), false );
		Audit::log( 'guest.invite_notice', 'person', $person_id, array( 'session' => $session_id, 'reasons' => array_column( $reasons, 'code' ) ) );
		return self::text( trim( $person['first_name'] . ' ' . $person['last_name'] ), $reasons );
	}

	/** Le frasi dell'avviso, una per motivo, una dopo l'altra. */
	public static function text( string $name, array $reasons ): string {
		$out = array();
		foreach ( $reasons as $r ) {
			switch ( $r['code'] ) {
				case 'threshold':
					$out[] = Texts::n( (int) $r['n'], '{nome} è da invitare ad associarsi: ha già {n} ingresso.', '{nome} è da invitare ad associarsi: ha già {n} ingressi.', array( 'nome' => $name ) );
					break;
				case 'host':
					$out[] = Texts::t( '{nome} è qui su invito di {chi}: puoi proporre l\'adesione.', array( 'nome' => $name, 'chi' => (string) $r['who'] ) );
					break;
				case 'event':
					$out[] = Texts::t( '{nome} è qui per «{evento}»: puoi proporre l\'adesione.', array( 'nome' => $name, 'evento' => (string) $r['name'] ) );
					break;
			}
		}
		return implode( ' ', $out );
	}

	/** I motivi in breve, per la riga della Bacheca. */
	public static function short( array $reasons ): string {
		$out = array();
		foreach ( $reasons as $r ) {
			switch ( $r['code'] ) {
				case 'threshold':
					$out[] = Texts::n( (int) $r['n'], 'soglia raggiunta: {n} ingresso', 'soglia raggiunta: {n} ingressi' );
					break;
				case 'host':
					$out[] = Texts::t( 'su invito di {chi}', array( 'chi' => (string) $r['who'] ) );
					break;
				case 'event':
					$out[] = Texts::t( 'evento «{evento}»', array( 'evento' => (string) $r['name'] ) );
					break;
			}
		}
		return implode( '; ', $out );
	}

	/** Avvisi ancora da evadere, i più recenti per primi, con la persona (le voci di persone non più in anagrafica si tolgono). @return array[] voce + 'id' + 'person' */
	public static function pending(): array {
		$all = self::all();
		$out = array();
		$gone = false;
		foreach ( $all as $id => $it ) {
			$person = Plugin::people()->get( (int) $it['person_id'] );
			if ( ! $person ) {
				unset( $all[ $id ] );
				$gone = true;
				continue;
			}
			$it['id']     = (string) $id;
			$it['person'] = $person;
			$out[]        = $it;
		}
		if ( $gone ) {
			update_option( self::OPTION, $all, false );
		}
		usort(
			$out,
			function ( $a, $b ) {
				return (int) $b['at'] <=> (int) $a['at'];
			}
		);
		return $out;
	}

	/** Toglie gli avvisi di una persona (è diventata socia: l'invito è riuscito). */
	public static function forget_person( int $person_id ): void {
		$all  = self::all();
		$left = array_filter(
			$all,
			function ( $it ) use ( $person_id ) {
				return (int) ( $it['person_id'] ?? 0 ) !== $person_id;
			}
		);
		if ( count( $left ) !== count( $all ) ) {
			update_option( self::OPTION, $left, false );
		}
	}

	/** «Fatto»: toglie l'avviso dalla Bacheca e lo segna nel registro azioni. */
	public static function done( string $id ): bool {
		$all = self::all();
		if ( ! isset( $all[ $id ] ) ) {
			return false;
		}
		$it = $all[ $id ];
		unset( $all[ $id ] );
		update_option( self::OPTION, $all, false );
		Audit::log( 'guest.invite_done', 'person', (int) $it['person_id'], array( 'session' => (int) $it['session_id'] ) );
		return true;
	}
}
