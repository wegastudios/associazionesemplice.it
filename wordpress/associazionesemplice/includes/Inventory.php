<?php
namespace AssociazioneSemplice;

defined( 'ABSPATH' ) || exit;

/**
 * Registro dei beni dell'associazione e dei prestiti a soci e volontari. È un registro: non c'entra con le Risorse prenotabili
 * (sale e attrezzature da prenotare). La foto o l'allegato di un bene sta nella cartella privata, come i documenti.
 */
final class Inventory {

	private static function db(): \wpdb {
		return Db::db();
	}

	public static function register(): void {
		add_action( 'admin_post_asem_item_file', array( __CLASS__, 'handle_file' ) );
	}

	/** Indirizzo per aprire la foto o l'allegato di un bene (solo segreteria). */
	public static function file_url( int $item_id ): string {
		return wp_nonce_url( add_query_arg( array( 'action' => 'asem_item_file', 'id' => $item_id ), admin_url( 'admin-post.php' ) ), 'asem_item_file_' . $item_id );
	}

	public static function handle_file(): void {
		$id = isset( $_GET['id'] ) ? (int) $_GET['id'] : 0; // phpcs:ignore WordPress.Security.NonceVerification
		if ( ! current_user_can( Plugin::CAP_OPS ) ) {
			wp_die( 'Non autorizzato.', 403 );
		}
		check_admin_referer( 'asem_item_file_' . $id );
		$item = self::item( $id );
		$path = $item && ! empty( $item['file_stored'] ) ? Attachments::dir() . '/' . AttachmentRules::stored_name( (string) $item['file_stored'] ) : '';
		if ( '' === $path || '' === basename( $path ) || ! is_readable( $path ) ) {
			wp_die( 'File non trovato.', 404 );
		}
		$ext  = AttachmentRules::extension( (string) $item['file_name'] );
		$name = DocumentRules::download_name( (string) $item['name'], (string) $item['file_name'] );
		while ( ob_get_level() ) {
			ob_end_clean();
		}
		nocache_headers();
		header( 'Content-Type: ' . ( DocumentRules::SERVE_AS[ $ext ] ?? 'application/octet-stream' ) );
		header( 'X-Content-Type-Options: nosniff' );
		header( "Content-Security-Policy: default-src 'none'; img-src 'self' data:; style-src 'unsafe-inline'; sandbox" );
		header( 'Content-Disposition: ' . ( DocumentRules::is_inline( $ext ) ? 'inline' : 'attachment' ) . '; filename="' . preg_replace( '/[^A-Za-z0-9._-]+/', '_', $name ) . '"' );
		header( 'Content-Length: ' . (int) filesize( $path ) );
		readfile( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile -- lettura in streaming di un file privato
		exit;
	}

	// ---------- Beni ----------

	/** @throws \InvalidArgumentException */
	public static function save_item( int $id, array $in, ?array $file = null ): int {
		$name = mb_substr( trim( sanitize_text_field( (string) ( $in['name'] ?? '' ) ) ), 0, 190 );
		if ( '' === $name ) {
			throw new \InvalidArgumentException( 'Scrivi il nome del bene.' );
		}
		$qty = (int) ( $in['quantity'] ?? 1 );
		if ( $qty < 1 || $qty > 100000 ) {
			throw new \InvalidArgumentException( 'La quantità deve essere almeno 1.' );
		}
		$state = (string) ( $in['state'] ?? 'good' );
		if ( ! isset( LoanRules::item_states()[ $state ] ) ) {
			throw new \InvalidArgumentException( 'Scegli lo stato del bene.' );
		}
		$code = mb_substr( trim( sanitize_text_field( (string) ( $in['code'] ?? '' ) ) ), 0, 60 );
		if ( '' !== $code ) {
			$dup = self::db()->get_var( self::db()->prepare( 'SELECT id FROM ' . Db::t( 'items' ) . ' WHERE code = %s AND deleted_at IS NULL AND id <> %d', $code, $id ) );
			if ( $dup ) {
				throw new \InvalidArgumentException( 'Esiste già un bene con questo codice.' );
			}
		}
		$value = trim( (string) ( $in['value'] ?? '' ) );
		$cents = '' === $value ? null : Money::parse( $value );
		if ( '' !== $value && ( null === $cents || $cents < 0 ) ) {
			throw new \InvalidArgumentException( 'Il valore non è valido.' );
		}
		$bought = trim( (string) ( $in['purchased_on'] ?? '' ) );
		if ( '' !== $bought && ! LoanRules::is_date( $bought ) ) {
			throw new \InvalidArgumentException( 'La data d\'acquisto non è valida.' );
		}
		if ( $id ) {
			$out = self::out_quantity( $id );
			if ( $qty < $out ) {
				throw new \InvalidArgumentException( 'Ci sono ' . $out . ' pezzi in prestito: la quantità non può essere più bassa.' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- messaggio interno, mostrato solo dopo esc_html
			}
		}
		$d = array(
			'name'         => $name,
			'category'     => mb_substr( trim( sanitize_text_field( (string) ( $in['category'] ?? '' ) ) ), 0, 80 ),
			'code'         => '' === $code ? null : $code,
			'quantity'     => $qty,
			'state'        => $state,
			'location'     => mb_substr( trim( sanitize_text_field( (string) ( $in['location'] ?? '' ) ) ), 0, 190 ),
			'value_cents'  => $cents,
			'purchased_on' => '' === $bought ? null : $bought,
			'notes'        => mb_substr( trim( sanitize_textarea_field( (string) ( $in['notes'] ?? '' ) ) ), 0, 3000 ),
		);
		if ( $file ) {
			$d['file_name']   = $file['name'];
			$d['file_stored'] = Attachments::save_upload( $file );
		}
		$db = self::db();
		if ( $id ) {
			if ( ! self::item( $id ) ) {
				throw new \InvalidArgumentException( 'Bene non trovato.' );
			}
			$db->update( Db::t( 'items' ), $d + array( 'updated_at' => Db::now() ), array( 'id' => $id ) );
			Audit::log( 'item.updated', 'item', $id, array( 'state' => $state ) );
			return $id;
		}
		if ( ! $db->insert( Db::t( 'items' ), $d + array( 'created_at' => Db::now(), 'updated_at' => Db::now() ) ) ) {
			throw new \InvalidArgumentException( 'Impossibile salvare il bene (errore del database).' );
		}
		$id = (int) $db->insert_id;
		Audit::log( 'item.created', 'item', $id );
		return $id;
	}

	public static function delete_item( int $id ): void {
		if ( ! self::item( $id ) ) {
			throw new \InvalidArgumentException( 'Bene non trovato.' );
		}
		if ( self::out_quantity( $id ) > 0 ) {
			throw new \InvalidArgumentException( 'Il bene è in prestito: registra prima il rientro.' );
		}
		self::db()->update( Db::t( 'items' ), array( 'deleted_at' => Db::now() ), array( 'id' => $id ) );
		Audit::log( 'item.deleted', 'item', $id );
	}

	public static function item( int $id ): ?array {
		$r = self::db()->get_row( self::db()->prepare( 'SELECT * FROM ' . Db::t( 'items' ) . ' WHERE id = %d AND deleted_at IS NULL', $id ), ARRAY_A );
		return $r ?: null;
	}

	/** Pezzi di un bene fuori in prestito. */
	public static function out_quantity( int $item_id ): int {
		return (int) self::db()->get_var( self::db()->prepare( 'SELECT COALESCE(SUM(quantity),0) FROM ' . Db::t( 'loans' ) . ' WHERE item_id = %d AND returned_on IS NULL', $item_id ) );
	}

	/** @return array[] beni, con 'out' (pezzi fuori) e 'available' */
	public static function items( string $q = '', string $category = '' ): array {
		$where = array( 'deleted_at IS NULL' );
		$args  = array();
		if ( '' !== $q ) {
			$like    = '%' . self::db()->esc_like( $q ) . '%';
			$where[] = '(name LIKE %s OR code LIKE %s OR location LIKE %s)';
			array_push( $args, $like, $like, $like );
		}
		if ( '' !== $category ) {
			$where[] = 'category = %s';
			$args[]  = $category;
		}
		$sql  = 'SELECT i.*, (SELECT COALESCE(SUM(l.quantity),0) FROM ' . Db::t( 'loans' ) . ' l WHERE l.item_id = i.id AND l.returned_on IS NULL) AS out_qty FROM ' . Db::t( 'items' ) . ' i WHERE ' . implode( ' AND ', $where ) . ' ORDER BY category, name';
		$rows = self::db()->get_results( $args ? self::db()->prepare( $sql, $args ) : $sql, ARRAY_A ) ?: array();
		foreach ( $rows as &$r ) {
			$r['out']       = (int) $r['out_qty'];
			$r['available'] = LoanRules::available( (int) $r['quantity'], $r['out'] );
		}
		unset( $r );
		return $rows;
	}

	public static function categories(): array {
		return self::db()->get_col( 'SELECT DISTINCT category FROM ' . Db::t( 'items' ) . " WHERE deleted_at IS NULL AND category <> '' ORDER BY category" ) ?: array();
	}

	// ---------- Prestiti ----------

	/** @throws \InvalidArgumentException */
	public static function lend( int $item_id, int $person_id, int $qty, string $lent_on, string $due_on, string $notes = '' ): int {
		$item = self::item( $item_id );
		if ( ! $item ) {
			throw new \InvalidArgumentException( 'Bene non trovato.' );
		}
		$p = Plugin::people()->get( $person_id );
		if ( ! $p || ! empty( $p['anonymized_at'] ) || ! in_array( $p['type'], array_merge( MemberType::member_types() ), true ) ) {
			throw new \InvalidArgumentException( 'Scegli un socio o volontario.' );
		}
		$err = LoanRules::check_dates( $lent_on, $due_on );
		if ( $err ) {
			throw new \InvalidArgumentException( $err ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- messaggio interno, mostrato solo dopo esc_html
		}
		return Db::with_lock( 'asem_item_' . $item_id, function () use ( $item, $item_id, $person_id, $qty, $lent_on, $due_on, $notes ) {
			$err = LoanRules::check_quantity( $qty, LoanRules::available( (int) $item['quantity'], self::out_quantity( $item_id ) ), (string) $item['state'] );
			if ( $err ) {
				throw new \InvalidArgumentException( $err ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- messaggio interno, mostrato solo dopo esc_html
			}
			self::db()->insert(
				Db::t( 'loans' ),
				array(
					'item_id' => $item_id, 'person_id' => $person_id, 'quantity' => $qty, 'lent_on' => $lent_on, 'due_on' => $due_on,
					'notes_out' => mb_substr( trim( sanitize_text_field( $notes ) ), 0, 255 ), 'created_by' => get_current_user_id() ?: null, 'created_at' => Db::now(),
				)
			);
			$id = (int) self::db()->insert_id;
			Audit::log( 'loan.created', 'loan', $id, array( 'item' => $item_id, 'person' => $person_id, 'qty' => $qty ) );
			return $id;
		} );
	}

	/** @throws \InvalidArgumentException */
	public static function return_loan( int $loan_id, string $returned_on, string $notes = '', string $new_state = '' ): void {
		$loan = self::loan( $loan_id );
		if ( ! $loan ) {
			throw new \InvalidArgumentException( 'Prestito non trovato.' );
		}
		if ( ! empty( $loan['returned_on'] ) ) {
			throw new \InvalidArgumentException( 'Il rientro è già registrato.' );
		}
		$err = LoanRules::check_return( (string) $loan['lent_on'], $returned_on );
		if ( $err ) {
			throw new \InvalidArgumentException( $err ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- messaggio interno, mostrato solo dopo esc_html
		}
		self::db()->update( Db::t( 'loans' ), array( 'returned_on' => $returned_on, 'notes_in' => mb_substr( trim( sanitize_text_field( $notes ) ), 0, 255 ) ), array( 'id' => $loan_id ) );
		if ( '' !== $new_state && isset( LoanRules::item_states()[ $new_state ] ) ) { // il bene può rientrare rovinato
			self::db()->update( Db::t( 'items' ), array( 'state' => $new_state, 'updated_at' => Db::now() ), array( 'id' => (int) $loan['item_id'] ) );
		}
		Audit::log( 'loan.returned', 'loan', $loan_id, array( 'item' => (int) $loan['item_id'] ) );
	}

	public static function loan( int $id ): ?array {
		$r = self::db()->get_row( self::db()->prepare( 'SELECT * FROM ' . Db::t( 'loans' ) . ' WHERE id = %d', $id ), ARRAY_A );
		return $r ?: null;
	}

	/**
	 * Storico dei prestiti, dal più recente, con nome del bene e della persona e lo stato (out/overdue/returned).
	 *
	 * @param int  $item_id   solo questo bene (0 = tutti)
	 * @param int  $person_id solo questa persona (0 = tutte)
	 * @param bool $open_only solo quelli non rientrati
	 * @return array[]
	 */
	public static function loans( int $item_id = 0, int $person_id = 0, bool $open_only = false ): array {
		$where = array( '1=1' );
		$args  = array();
		if ( $item_id ) {
			$where[] = 'l.item_id = %d';
			$args[]  = $item_id;
		}
		if ( $person_id ) {
			$where[] = 'l.person_id = %d';
			$args[]  = $person_id;
		}
		if ( $open_only ) {
			$where[] = 'l.returned_on IS NULL';
		}
		$sql  = 'SELECT l.*, i.name AS item_name, i.code AS item_code, p.first_name, p.last_name, p.anonymized_at FROM ' . Db::t( 'loans' ) . ' l JOIN ' . Db::t( 'items' ) . ' i ON i.id = l.item_id LEFT JOIN ' . Db::t( 'people' ) . ' p ON p.id = l.person_id WHERE ' . implode( ' AND ', $where ) . ' ORDER BY l.lent_on DESC, l.id DESC';
		$rows = self::db()->get_results( $args ? self::db()->prepare( $sql, $args ) : $sql, ARRAY_A ) ?: array();
		$today = Db::today();
		foreach ( $rows as &$r ) {
			$r['person_name'] = ! empty( $r['anonymized_at'] ) ? 'Persona anonimizzata' : trim( (string) $r['first_name'] . ' ' . (string) $r['last_name'] );
			$r['status']      = LoanRules::status( (string) $r['due_on'], $r['returned_on'], $today );
			$r['days_late']   = LoanRules::days_late( (string) $r['due_on'], $r['returned_on'], $today );
		}
		unset( $r );
		return $rows;
	}

	/** Prestiti con il rientro scaduto, dal più vecchio. */
	public static function overdue(): array {
		$out = array_filter( self::loans( 0, 0, true ), function ( $l ) {
			return LoanRules::OVERDUE === $l['status'];
		} );
		usort( $out, function ( $a, $b ) {
			return strcmp( (string) $a['due_on'], (string) $b['due_on'] );
		} );
		return array_values( $out );
	}

	public static function open_loans_of( int $person_id ): int {
		return (int) self::db()->get_var( self::db()->prepare( 'SELECT COUNT(*) FROM ' . Db::t( 'loans' ) . ' WHERE person_id = %d AND returned_on IS NULL', $person_id ) );
	}

	// ---------- CSV ----------

	public static function items_csv(): string {
		$cols = array(
			array( 'label' => 'Codice' ), array( 'label' => 'Nome' ), array( 'label' => 'Categoria' ), array( 'label' => 'Quantità' ), array( 'label' => 'In prestito' ),
			array( 'label' => 'Stato' ), array( 'label' => 'Ubicazione' ), array( 'label' => 'Valore' ), array( 'label' => 'Acquistato il' ), array( 'label' => 'Note' ),
		);
		$rows = array();
		foreach ( self::items() as $i ) {
			$rows[] = array(
				$i['code'], $i['name'], $i['category'], $i['quantity'], $i['out'], LoanRules::item_states()[ $i['state'] ] ?? $i['state'], $i['location'],
				null === $i['value_cents'] ? '' : Money::plain( (int) $i['value_cents'] ), Docs::d( $i['purchased_on'] ), $i['notes'],
			);
		}
		return Docs::csv( $cols, $rows );
	}

	public static function loans_csv(): string {
		$cols = array(
			array( 'label' => 'Bene' ), array( 'label' => 'Codice' ), array( 'label' => 'Persona' ), array( 'label' => 'Pezzi' ), array( 'label' => 'Uscita' ),
			array( 'label' => 'Rientro previsto' ), array( 'label' => 'Rientrato il' ), array( 'label' => 'Situazione' ), array( 'label' => 'Note all\'uscita' ), array( 'label' => 'Note al rientro' ),
		);
		$rows = array();
		foreach ( self::loans() as $l ) {
			$rows[] = array(
				$l['item_name'], $l['item_code'], $l['person_name'], $l['quantity'], Docs::d( $l['lent_on'] ), Docs::d( $l['due_on'] ), Docs::d( $l['returned_on'] ),
				LoanRules::loan_labels()[ $l['status'] ], $l['notes_out'], $l['notes_in'],
			);
		}
		return Docs::csv( $cols, $rows );
	}
}
