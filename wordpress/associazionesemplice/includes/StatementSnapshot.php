<?php
namespace AssociazioneSemplice;

defined( 'ABSPATH' ) || exit;

/**
 * Rendiconto fotografato alla chiusura di un esercizio (Pro). Alla chiusura si salvano, nella stessa operazione, le cifre del rendiconto
 * (voci per area e per categoria, avanzo o disavanzo, cassa e conti, fondi, relazione) e il PDF emesso con il suo numero e la sua data.
 * Il rendiconto di un esercizio chiuso si legge sempre da qui, mai dal vivo: «il chiuso è chiuso».
 *
 * Il PDF emesso si SALVA (non si rigenera): un documento emesso con un numero non deve cambiare nemmeno se il plugin viene aggiornato e la
 * pagina si disegna in modo diverso, o se cambiano i testi personalizzati. Resta nel database, in base64, nella stessa transazione della
 * chiusura (tutto o niente), con l'impronta SHA-256 per accorgersi di qualunque alterazione; finisce anche nel backup.
 *
 * Ogni chiusura ha la sua fotografia: dopo una riapertura e una nuova chiusura la precedente resta (con il suo numero) e nasce la successiva.
 */
final class StatementSnapshot {

	const SCHEMA = 1;

	public static function register(): void {
		add_action( 'asem_year_closing', array( __CLASS__, 'on_closing' ), 10, 3 );
		add_filter( 'asem_year_snapshot_lines', array( __CLASS__, 'lines' ), 10, 3 );
		add_action( 'asem_years_page_after', array( __CLASS__, 'backfill_card' ), 20 );
	}

	private static function db(): \wpdb {
		return Db::db();
	}

	/** Alla chiusura (o alla fotografia retroattiva) di un esercizio: se questa scrittura lancia, la chiusura non avviene. */
	public static function on_closing( $year, $closure_id, $retroactive = false ): void {
		self::create( (int) $year, (int) $closure_id, (bool) $retroactive );
	}

	/** Gg/mm/aaaa da una data o data e ora del database. */
	public static function dmy( string $mysql ): string {
		return preg_match( '/^(\d{4})-(\d{2})-(\d{2})/', $mysql, $m ) ? $m[3] . '/' . $m[2] . '/' . $m[1] : '';
	}

	/** Numero del documento emesso, nella forma «n. 1/2025». */
	public static function number_text( int $number, int $year ): string {
		return 'n. ' . $number . '/' . $year;
	}

	/**
	 * Scrive la fotografia del rendiconto di una chiusura, con il PDF emesso. Da chiamare dentro la transazione della chiusura.
	 *
	 * @return int id della riga
	 * @throws \InvalidArgumentException
	 */
	public static function create( int $year, int $closure_id, bool $retroactive ): int {
		$db = self::db();
		$cl = $db->get_row( $db->prepare( 'SELECT * FROM ' . Db::t( 'fiscal_year_closures' ) . ' WHERE id = %d', $closure_id ), ARRAY_A );
		if ( ! $cl || (int) $cl['year'] !== $year ) {
			throw new \InvalidArgumentException( 'Chiusura non trovata: il rendiconto non si può emettere.' );
		}
		if ( null !== self::row_for_closure( $closure_id ) ) {
			return 0; // già fotografato (non si rifà mai: una fotografia emessa non si sostituisce)
		}
		$span    = FiscalYears::range( $year );
		$prev    = FiscalYears::range( $year - 1 );
		$data    = Statement::live( $year );
		$payload = array(
			'schema'     => self::SCHEMA,
			'year'       => $year,
			'label'      => FiscalYears::label( $year ),
			'prev_label' => FiscalYears::label( $year - 1 ),
			'from'       => $span[0],
			'to'         => $span[1],
			'prev_from'  => $prev[0],
			'prev_to'    => $prev[1],
			'entity'     => array( 'name' => (string) Settings::get( 'association_name' ), 'tax_code' => (string) Settings::get( 'tax_code' ) ),
			'president'  => Docs::president(),
			'data'       => $data,
		);
		$json = wp_json_encode( $payload, JSON_UNESCAPED_UNICODE );
		if ( ! is_string( $json ) ) {
			throw new \InvalidArgumentException( 'Il rendiconto contiene caratteri che non si riescono a salvare: la chiusura non è avvenuta.' );
		}
		$number = 1 + (int) $db->get_var( $db->prepare( 'SELECT MAX(number) FROM ' . Db::t( 'statement_snapshot' ) . ' WHERE year = %d', $year ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- nome tabella interno
		$now    = Db::now();
		$pdf    = DocsPro::render_statement(
			$data,
			self::pdf_meta( $payload, array( 'number' => $number, 'year' => $year, 'issued_at' => $now, 'retroactive' => $retroactive ? 1 : 0, 'version' => (int) $cl['version'] ) )
		);
		$ok = $db->insert(
			Db::t( 'statement_snapshot' ),
			array(
				'closure_id'     => $closure_id,
				'year'           => $year,
				'number'         => $number,
				'issued_at'      => $now,
				'issued_by'      => get_current_user_id() ?: null,
				'retroactive'    => $retroactive ? 1 : 0,
				'payload'        => $json,
				'payload_sha256' => hash( 'sha256', $json ),
				'pdf_b64'        => base64_encode( $pdf ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- non è offuscamento: il PDF binario si salva come testo perché il database non accetta byte non UTF-8
				'pdf_sha256'     => hash( 'sha256', $pdf ),
			)
		);
		if ( false === $ok ) {
			throw new \InvalidArgumentException( $retroactive ? 'Il rendiconto definitivo non è stato emesso (errore del database): non è stato salvato nulla. Riprova; se succede ancora scrivi all\'assistenza.' : 'Il rendiconto definitivo non è stato emesso (errore del database): la chiusura non è avvenuta. Riprova; se succede ancora scrivi all\'assistenza.' );
		}
		return (int) $db->insert_id;
	}

	/** La fotografia di una chiusura precisa, oppure null. */
	public static function row_for_closure( int $closure_id ): ?array {
		$row = self::db()->get_row( self::db()->prepare( 'SELECT * FROM ' . Db::t( 'statement_snapshot' ) . ' WHERE closure_id = %d', $closure_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- nome tabella interno
		return $row ?: null;
	}

	/**
	 * La fotografia di un esercizio: quella della chiusura in vigore, oppure (con $version) di una chiusura precedente rimasta dopo una riapertura.
	 * Un esercizio aperto, o chiuso prima che esistesse la fotografia, non ne ha.
	 *
	 * @return array|null riga della fotografia con `closure` (la riga della chiusura)
	 */
	public static function row_for( int $year, ?int $version = null ): ?array {
		$cl = null === $version ? FiscalYears::current_closure( $year ) : FiscalYears::closure_version( $year, $version );
		if ( ! $cl ) {
			return null;
		}
		$row = self::row_for_closure( (int) $cl['id'] );
		return $row ? $row + array( 'closure' => $cl ) : null;
	}

	/** @return array|null il contenuto fotografato (stessa forma di {@see Statement::live()}, più `snapshot` con gli estremi) */
	public static function data( int $year, ?int $version = null ): ?array {
		$row = self::row_for( $year, $version );
		if ( ! $row ) {
			return null;
		}
		$payload = json_decode( (string) $row['payload'], true );
		if ( ! is_array( $payload ) || ! isset( $payload['data'] ) || ! is_array( $payload['data'] ) ) {
			return null;
		}
		$d             = $payload['data'];
		$d['snapshot'] = self::meta( $row, $payload );
		return $d;
	}

	/** Gli estremi di una fotografia, per le schermate. */
	public static function meta( array $row, array $payload ): array {
		$retro = ! empty( $row['retroactive'] );
		$when  = self::dmy( (string) $row['issued_at'] );
		return array(
			'closure_id'  => (int) $row['closure_id'],
			'version'     => (int) ( $row['closure']['version'] ?? 1 ),
			'superseded'  => 'superseded' === ( $row['closure']['status'] ?? 'current' ),
			'number'      => (int) $row['number'],
			'number_text' => self::number_text( (int) $row['number'], (int) $row['year'] ),
			'issued_at'   => (string) $row['issued_at'],
			'retroactive' => $retro,
			'text'        => 'emesso il ' . $when . ( $retro ? ' per un anno già chiuso: i dati sono quelli di quel giorno, non quelli della chiusura' : '' ),
			'label'       => (string) ( $payload['label'] ?? $row['year'] ),
			'prev_label'  => (string) ( $payload['prev_label'] ?? ( (int) $row['year'] - 1 ) ),
			'from'        => (string) ( $payload['from'] ?? '' ),
			'to'          => (string) ( $payload['to'] ?? '' ),
		);
	}

	/** Dati per disegnare il PDF: l'ente e il presidente di quel giorno, l'intervallo, e gli estremi dell'emissione (null per il PDF dal vivo). */
	public static function pdf_meta( array $payload, ?array $emission ): array {
		return array(
			'entity'     => (array) ( $payload['entity'] ?? array() ),
			'president'  => (string) ( $payload['president'] ?? '' ),
			'label'      => (string) $payload['label'],
			'prev_label' => (string) $payload['prev_label'],
			'from'       => (string) $payload['from'],
			'to'         => (string) $payload['to'],
			'emission'   => $emission,
		);
	}

	/**
	 * Il PDF emesso di una fotografia, così com'era alla chiusura.
	 *
	 * @return array{body:string,filename:string,mime:string}
	 * @throws \InvalidArgumentException
	 */
	public static function document( array $row ): array {
		$bin = base64_decode( (string) $row['pdf_b64'], true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- rilegge il PDF salvato come testo
		if ( false === $bin || ! hash_equals( (string) $row['pdf_sha256'], hash( 'sha256', $bin ) ) ) {
			throw new \InvalidArgumentException( 'Il PDF emesso risulta cambiato dopo l\'emissione: per sicurezza non si può scaricare. Scrivi all\'assistenza.' );
		}
		return array( 'body' => $bin, 'filename' => 'rendiconto-' . (int) $row['year'] . '-n' . (int) $row['number'] . '.pdf', 'mime' => 'application/pdf' );
	}

	/** Il contenuto fotografato è ancora quello scritto alla chiusura? */
	public static function intact( array $row ): bool {
		return hash_equals( (string) $row['payload_sha256'], hash( 'sha256', (string) $row['payload'] ) );
	}

	/**
	 * Righe di testo (HTML già protetto) per la pagina Esercizi: stato della fotografia del rendiconto di una chiusura, con i collegamenti.
	 *
	 * @param string[] $lines
	 * @return string[]
	 */
	public static function lines( $lines, $year, $closure ): array {
		$lines   = (array) $lines;
		$year    = (int) $year;
		$closure = (array) $closure;
		$row     = self::row_for_closure( (int) ( $closure['id'] ?? 0 ) );
		if ( $row ) {
			$ver     = (int) $closure['version'];
			$lines[] = 'Rendiconto definitivo ' . esc_html( 'emesso il ' . self::dmy( (string) $row['issued_at'] ) . ( ! empty( $row['retroactive'] ) ? ' per un anno già chiuso: i dati sono quelli di quel giorno, non quelli della chiusura' : '' ) . ' · ' . self::number_text( (int) $row['number'], (int) $row['year'] ) )
				. ' · <a target="_blank" rel="noopener" href="' . esc_url( Docs::url( 'statement', array( 'year' => $year, 'version' => $ver ) ) ) . '">PDF emesso</a>'
				. ' · <a href="' . esc_url( Admin\Ui::url( 'asem-statement', array( 'year' => $year, 'version' => $ver ) ) ) . '">Apri</a>';
		} elseif ( 'current' === ( $closure['status'] ?? '' ) ) {
			$lines[] = 'Rendiconto in bozza: i numeri sono quelli di oggi e possono cambiare';
		}
		return $lines;
	}

	/** Chiusure in vigore di esercizi chiusi con il libro fotografato ma senza il rendiconto (chiusi prima che esistesse la fotografia del rendiconto). @return array[] */
	public static function missing(): array {
		return self::db()->get_results(
			'SELECT c.* FROM ' . Db::t( 'fiscal_year_closures' ) . ' c JOIN ' . Db::t( 'fiscal_years' ) . ' y ON y.year = c.year AND y.status = \'closed\' LEFT JOIN ' . Db::t( 'statement_snapshot' ) . " s ON s.closure_id = c.id WHERE c.status = 'current' AND c.snapshot_state = 'complete' AND s.id IS NULL ORDER BY c.year", // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- nomi di tabella interni
			ARRAY_A
		) ?: array();
	}

	/**
	 * Fotografa ora il rendiconto degli esercizi chiusi che ne sono privi: i dati sono quelli di oggi (ricostruiti), la fotografia è segnata
	 * «ricostruito il …». Tutto o niente per ogni esercizio. Gli esercizi chiusi senza il libro soci li fotografa prima l'azione della pagina Esercizi.
	 *
	 * @return int esercizi fotografati
	 */
	public static function backfill(): int {
		$n = 0;
		foreach ( self::missing() as $c ) {
			FiscalYears::in_transaction(
				function () use ( $c ) {
					self::create( (int) $c['year'], (int) $c['id'], true );
					Audit::log( 'year.statement_snapshot_retro', 'year', (int) $c['year'], array( 'version' => (int) $c['version'] ) );
				}
			);
			$n++;
		}
		return $n;
	}

	/** Riquadro nella pagina Esercizi: fotografare il rendiconto degli anni chiusi che non ce l'hanno. */
	public static function backfill_card(): void {
		$missing = self::missing();
		if ( ! $missing || ! current_user_can( Plugin::CAP ) ) {
			return;
		}
		$names = array_map(
			function ( $c ) {
				return FiscalYears::label( (int) $c['year'] );
			},
			$missing
		);
		echo '<div class="asem-card"><h2>Emetti il rendiconto definitivo degli anni già chiusi</h2>';
		echo '<p>Il rendiconto di questi anni fiscali chiusi non è ancora definitivo: i numeri che si vedono sono quelli di oggi e possono cambiare. Anni: <strong>' . esc_html( implode( ', ', $names ) ) . '</strong>. Emetterlo ora lo <strong>ricostruisce dai dati di oggi</strong> (le cifre dei movimenti sono quelle registrate, ma le voci per area sono quelle attuali) e ne emette il PDF con numero e data. '
			. 'Porta la dicitura «Emesso il gg/mm/aaaa per un anno già chiuso: i dati sono quelli di quel giorno, non quelli della chiusura.» e l\'operazione resta scritta nello storico. Non si può annullare.</p>';
		Admin\Ui::form_open( 'asem_statement_backfill', Admin\Ui::url( 'asem-years' ), false, 'asem-inline' );
		echo wp_kses( '<label><input type="checkbox" name="confirm_retro" value="1"> Ho capito: i dati sono ricostruiti, non ripresi alla chiusura.</label> <button class="button" data-confirm="Emettere ora il rendiconto definitivo degli anni fiscali già chiusi con i dati di oggi? Non si può annullare.">Emetti il rendiconto definitivo</button>', Html::allowed() );
		Admin\Ui::form_close();
		echo '</div>';
	}
}
