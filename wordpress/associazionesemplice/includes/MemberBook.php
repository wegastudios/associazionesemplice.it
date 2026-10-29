<?php
namespace AssociazioneSemplice;

defined( 'ABSPATH' ) || exit;

/**
 * Libro soci: l'elenco progressivo di tutti i soci con la data di ingresso e, se c'è stato, di cessazione (recesso, esclusione, decesso).
 * Gli ospiti non sono soci e non compaiono.
 */
final class MemberBook {

	const IN_FORCE = 'in_force';
	const LEFT     = 'left';

	private static function db(): \wpdb {
		return Db::db();
	}

	/** Registra (o toglie, con data vuota) la cessazione di un socio. @throws \InvalidArgumentException */
	public static function set_left( int $person_id, string $date, string $reason = '' ): void {
		$p = Plugin::people()->get( $person_id );
		if ( ! $p || ! MemberType::is_member( $p['type'] ) ) {
			throw new \InvalidArgumentException( 'La cessazione si registra per un socio.' );
		}
		$date = trim( $date );
		if ( '' === $date ) {
			self::db()->update( Db::t( 'people' ), array( 'left_on' => null, 'left_reason' => null, 'suspended_at' => null, 'updated_at' => Db::now() ), array( 'id' => $person_id ) );
			if ( MemberType::is_auto_renewed( $p['type'] ) ) {
				Plugin::people()->refresh_founder_membership( $person_id ); // il fondatore torna con la tessera sempre rinnovata
			}
			Audit::log( 'member.left_cleared', 'person', $person_id, array() );
			return;
		}
		$dt = \DateTime::createFromFormat( 'Y-m-d', $date );
		if ( ! $dt || $dt->format( 'Y-m-d' ) !== $date ) {
			throw new \InvalidArgumentException( 'Data di cessazione non valida.' );
		}
		if ( $date > Db::today() ) {
			throw new \InvalidArgumentException( 'La cessazione non può essere nel futuro.' );
		}
		if ( $date < (string) $p['joined_on'] ) {
			throw new \InvalidArgumentException( 'La cessazione non può essere prima dell\'ingresso (' . $p['joined_on'] . ').' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- messaggio interno, mostrato solo dopo esc_html
		}
		// Chi ha lasciato l'associazione non è più un socio attivo: non prenota, non riceve promemoria né comunicazioni ai soci, non compare tra quelli da rinnovare.
		// Si usa lo stato «sospeso»; per il fondatore (sempre in regola) si ferma la tessera alla data di cessazione.
		self::db()->update( Db::t( 'people' ), array( 'left_on' => $date, 'left_reason' => mb_substr( trim( sanitize_text_field( $reason ) ), 0, 190 ), 'suspended_at' => ! empty( $p['suspended_at'] ) ? $p['suspended_at'] : Db::now(), 'updated_at' => Db::now() ), array( 'id' => $person_id ) );
		if ( MemberType::is_auto_renewed( $p['type'] ) ) {
			self::db()->query( self::db()->prepare( 'UPDATE ' . Db::t( 'memberships' ) . " SET valid_to = %s WHERE person_id = %d AND social_year = 'FOUNDER' AND deleted_at IS NULL", $date, $person_id ) );
		}
		Audit::log( 'member.left', 'person', $person_id, array( 'date' => $date ) );
	}

	/** Residenza su una riga: via, CAP, comune (provincia). */
	private static function residence( array $p ): string {
		$town = trim( trim( (string) $p['zip'] . ' ' . (string) $p['city'] ) . ( '' !== (string) $p['province'] ? ' (' . $p['province'] . ')' : '' ) );
		return implode( ', ', array_filter( array( trim( (string) $p['address'] ), $town ) ) );
	}

	/** Per ogni persona la quota associativa dell'anno più recente per cui risulta incassata: person_id => [year, cents]. */
	private static function last_fees(): array {
		$rows = self::db()->get_results(
			'SELECT t.person_id, t.social_year, SUM(t.amount_cents + t.discount_cents) AS cents FROM ' . Db::t( 'transactions' ) . ' t JOIN ' . Db::t( 'categories' ) . " c ON c.id = t.category_id WHERE t.person_id IS NOT NULL AND t.type = 'income' AND t.voided_at IS NULL AND c.kind = 'membership' GROUP BY t.person_id, t.social_year ORDER BY t.social_year", // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- nomi tabella interni
			ARRAY_A
		) ?: array();
		$out = array();
		foreach ( $rows as $r ) {
			if ( (int) $r['cents'] > 0 ) {
				$out[ (int) $r['person_id'] ] = array( 'year' => (string) $r['social_year'], 'cents' => (int) $r['cents'] ); // l'ultimo anno vince
			}
		}
		return $out;
	}

	/**
	 * Righe del libro soci in ordine di ingresso, con numero progressivo.
	 *
	 * @param string $filter '' tutti, in_force (in carica), left (cessati)
	 * @return array[]
	 */
	public static function rows( string $filter = '' ): array {
		$rows = self::db()->get_results(
			'SELECT * FROM ' . Db::t( 'people' ) . " WHERE deleted_at IS NULL AND application_status IS NULL AND type NOT IN ('guest','guardian') ORDER BY joined_on, id",
			ARRAY_A
		) ?: array();
		$tutori = Guardians::names_by_person();
		$fees   = self::last_fees();
		$out = array();
		$n   = 0;
		foreach ( $rows as $p ) {
			$n++;
			$gone = ! empty( $p['left_on'] );
			if ( self::IN_FORCE === $filter && $gone ) {
				continue;
			}
			if ( self::LEFT === $filter && ! $gone ) {
				continue;
			}
			$anon = ! empty( $p['anonymized_at'] );
			$out[] = array(
				'n'           => $n,
				'id'          => (int) $p['id'],
				'name'        => $anon ? '(dati anonimizzati)' : trim( $p['last_name'] . ' ' . $p['first_name'] ),
				'tax_code'    => $anon ? '' : (string) $p['tax_code'],
				'birth_date'  => $anon ? '' : (string) $p['birth_date'],
				'birth_place' => $anon ? '' : (string) $p['birth_place'],
				'residence'   => $anon ? '' : self::residence( $p ),
				'email'       => $anon ? '' : (string) $p['email'],
				'phone'       => $anon ? '' : (string) $p['phone'],
				'applied_on'  => substr( (string) ( $p['application_at'] ?: $p['joined_on'] ), 0, 10 ),
				'approved_on' => (string) ( $p['approved_on'] ?: $p['joined_on'] ),
				'fee_cents'   => (int) ( $fees[ (int) $p['id'] ]['cents'] ?? 0 ),
				'fee_year'    => (string) ( $fees[ (int) $p['id'] ]['year'] ?? '' ),
				'guardian'    => $anon ? '' : (string) ( $tutori[ (int) $p['id'] ] ?? '' ),
				'card_number' => (string) $p['card_number'],
				'level'       => Levels::label( $p ),
				'joined_on'   => (string) $p['joined_on'],
				'left_on'     => $gone ? (string) $p['left_on'] : '',
				'left_reason' => (string) $p['left_reason'],
				'status'      => $gone ? self::LEFT : self::IN_FORCE,
			);
		}
		return $out;
	}
}
