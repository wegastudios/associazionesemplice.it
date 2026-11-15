<?php
namespace AssociazioneSemplice;

defined( 'ABSPATH' ) || exit;

/**
 * Verifiche di fine anno fiscale (Pro Fiscale): dal mese che precede l'ultimo mese dell'anno (limite `watch_yearend_lead_months`) un avviso fisso
 * per i ruoli amministrativi elenca le discrepanze da correggere prima della chiusura, con il link a ciascuna. Se non ce ne sono, non si mostra niente.
 * Facoltativo: un promemoria email all'inizio di quel mese (`watch_yearend_email`, spento di partenza).
 */
final class YearEndCheck {

	const MAIL_OPTION = 'asem_watch_yearend_mail';

	public static function window( ?string $today = null ): array {
		return BalanceWatchRules::year_end_window( $today ?: Db::today(), FiscalYears::start_month(), Limits::get( 'watch_yearend_lead_months' ), Limits::get( 'watch_yearend_notice_months' ) );
	}

	/** Le discrepanze ancora da verificare (quelle approvate a mano per il nuovo anno non contano), per tipo. @return array[] label, count, url */
	public static function discrepancies( int $year ): array {
		return Discrepancies::grouped( $year );
	}

	/** Chi vede l'avviso nell'amministrazione: chi opera sulla contabilità (amministratori, segreteria, presidente e vicepresidente). */
	private static function can_see(): bool {
		return Edition::has( 'watch' ) && current_user_can( Plugin::CAP_OPS );
	}

	/** Avviso fisso (non chiudibile) con l'elenco; vuoto fuori dalla finestra o senza discrepanze: niente messaggi «tutto a posto». */
	public static function html(): string {
		if ( ! self::can_see() ) {
			return '';
		}
		$w = self::window();
		if ( '' === $w['phase'] ) {
			return '';
		}
		$items = self::discrepancies( (int) $w['year'] );
		if ( ! $items ) {
			return '';
		}
		if ( 'notice' === $w['phase'] ) { // preavviso: chiudibile fino a lunedì (la chiusura è dell'utente, con la settimana nel nome)
			$parts = array();
			foreach ( $items as $i ) {
				$parts[] = (int) $i['count'] . ' ' . esc_html( $i['label'] ) . ' (<a href="' . esc_url( $i['url'] ) . '">verifica</a>)';
			}
			$monday = ( new \DateTimeImmutable( Db::today() ) )->modify( 'monday this week' )->format( 'Y-m-d' );
			return Admin\Dismiss::html( 'yearend-' . $monday, 'warning', '<strong>Verifiche in vista della fine dell\'anno fiscale</strong> (' . esc_html( ( new \DateTimeImmutable( $w['end'] ) )->format( 'd/m/Y' ) ) . '): ' . implode( '; ', $parts ) . '. Si può chiudere per questa settimana; da lunedì ricompare finché ci sono discrepanze.', 7 );
		}
		$li = '';
		foreach ( $items as $i ) {
			$li .= '<li>' . (int) $i['count'] . ' ' . esc_html( $i['label'] ) . ' — <a href="' . esc_url( $i['url'] ) . '">verifica</a></li>';
		}
		return '<div class="notice notice-warning inline asem-yearend"><p><strong>Verifiche di fine anno fiscale.</strong> L\'anno finisce il ' . esc_html( ( new \DateTimeImmutable( $w['end'] ) )->format( 'd/m/Y' ) ) . ': conviene sistemare ora queste discrepanze, così non restano correzioni da fare dopo la chiusura. Ogni discrepanza si può anche approvare a mano per portarla al nuovo anno (<a href="' . esc_url( Admin\Ui::url( 'asem-watch' ) ) . '#asem-disc">verifica e approva</a>).</p><ul style="list-style:disc;margin-left:2em">' . $li . '</ul></div>';
	}

	// ---------- Anni fiscali passati ancora aperti in disavanzo ----------

	/** Il risultato dell'anno fiscale come lo mostra il rendiconto (entrate meno uscite della prima nota, fondi compresi); negativo = disavanzo. */
	public static function year_result( int $year ): int {
		$span = FiscalYears::range( $year );
		return (int) ( new ReportService() )->period( $span[0], $span[1] )['result'];
	}

	/** Anni fiscali già finiti, ancora aperti e con il risultato negativo (quello in corso resta del controllo di bilancio). @return array<int,int> anno => risultato */
	public static function past_deficits(): array {
		$cur = FiscalYears::current();
		$out = array();
		foreach ( FiscalYears::all() as $y ) {
			$year = (int) $y['year'];
			if ( 'open' !== $y['status'] || $year >= $cur ) {
				continue;
			}
			$result = self::year_result( $year );
			if ( $result < 0 ) {
				$out[ $year ] = $result;
			}
		}
		ksort( $out );
		return $out;
	}

	/** La frase che la chiusura chiede di confermare (vuota se l'anno non è in disavanzo). */
	public static function deficit_gap( int $year ): string {
		$result = self::year_result( $year );
		return $result < 0 ? Texts::t( 'il risultato dell\'anno fiscale è un disavanzo di {importo}: le uscite superano le entrate', array( 'importo' => Money::format( abs( $result ) ) ) ) : '';
	}

	/** Filtro `asem_year_close_confirm`: un disavanzo non blocca la chiusura (può essere coperto da riserve), ma va confermato. */
	public static function close_confirm( $list, $year ) {
		if ( ! Edition::has( 'watch' ) ) {
			return $list;
		}
		$gap = self::deficit_gap( (int) $year );
		return '' === $gap ? $list : array_merge( (array) $list, array( $gap ) );
	}

	/** Casella di conferma nel modulo «Chiudi l'anno». */
	public static function close_form( $year ): void {
		if ( ! Edition::has( 'watch' ) ) {
			return;
		}
		$result = self::year_result( (int) $year );
		if ( $result >= 0 ) {
			return;
		}
		echo '<label class="description"><input type="checkbox" name="confirm_gaps" value="1"> ' . esc_html( Texts::t( 'chiudo anche se l\'anno è in disavanzo di {importo}', array( 'importo' => Money::format( abs( $result ) ) ) ) ) . '</label> ';
	}

	/** Avviso fisso (non chiudibile) in Bacheca e in Cassa: un anno fiscale passato ancora aperto è in disavanzo. Sparisce quando il risultato torna a zero o più, o l'anno si chiude. */
	public static function deficit_html(): string {
		if ( ! self::can_see() ) {
			return '';
		}
		$out = '';
		foreach ( self::past_deficits() as $year => $result ) {
			$vars = array( 'anno' => FiscalYears::label( $year ), 'importo' => Money::format( abs( $result ) ) );
			$out .= '<div class="notice notice-warning inline asem-yearend"><p><strong>' . esc_html( Texts::t( 'Anno fiscale {anno} in disavanzo.', $vars ) ) . '</strong> '
				. esc_html( Texts::t( 'L\'anno fiscale {anno} è finito ma è ancora aperto e ha un disavanzo di {importo}: le uscite superano le entrate. Per sistemarlo, registra in Prima nota le entrate mancanti con una data dentro quell\'anno. Se invece i conti sono giusti, controllali e poi chiudi l\'anno.', $vars ) )
				. ' <a href="' . esc_url( Admin\Ui::url( 'asem-statement', array( 'year' => $year ) ) ) . '">' . esc_html( Texts::t( 'Apri il rendiconto' ) ) . '</a> · <a href="' . esc_url( Admin\Ui::url( 'asem-years' ) ) . '">' . esc_html( Texts::t( 'Vai agli anni fiscali' ) ) . '</a></p></div>';
		}
		return $out;
	}

	public static function deficit_show(): void {
		echo self::deficit_html(); // phpcs:ignore WordPress.Security.EscapeOutput -- html già protetto
	}

	public static function show(): void {
		echo self::html(); // phpcs:ignore WordPress.Security.EscapeOutput -- html già protetto
	}

	/** Ruoli amministrativi da avvisare: amministratori, segreteria, tesorieri, presidente e vicepresidente in regola. @return string[] email */
	public static function recipients(): array {
		$users = array_merge(
			get_users( array( 'role__in' => array( 'administrator', Plugin::ROLE_SECRETARY ) ) ),
			get_users( array( 'meta_key' => Access::TREASURER_META, 'meta_value' => '1' ) ) // phpcs:ignore WordPress.DB.SlowDBQuery
		);
		foreach ( Db::db()->get_col( 'SELECT wp_user_id FROM ' . Db::t( 'people' ) . " WHERE wp_user_id IS NOT NULL AND board_role IN ('president','vice_president')" ) ?: array() as $uid ) {
			if ( Access::is_board_operator( (int) $uid ) && get_userdata( (int) $uid ) ) {
				$users[] = get_userdata( (int) $uid );
			}
		}
		$out = array();
		foreach ( $users as $u ) {
			if ( $u && is_email( $u->user_email ) ) {
				$out[ strtolower( $u->user_email ) ] = $u->user_email;
			}
		}
		return array_values( $out );
	}

	/** Lavoro giornaliero: dall'inizio della finestra, se il promemoria è attivo e ci sono discrepanze, una sola email per anno fiscale. @return int email inviate */
	public static function mail_job( ?string $today = null ): int {
		if ( ! Edition::has( 'watch' ) || ! Limits::get( 'watch_yearend_email' ) ) {
			return 0;
		}
		$w = self::window( $today );
		$mark = $w['year'] . ':' . $w['phase']; // una email all'inizio del preavviso e una all'inizio della fase fissa
		if ( '' === $w['phase'] || (string) get_option( self::MAIL_OPTION, '' ) === $mark ) {
			return 0;
		}
		$items = self::discrepancies( (int) $w['year'] );
		if ( ! $items ) {
			return 0; // niente da segnalare: si riprova nei giorni seguenti
		}
		update_option( self::MAIL_OPTION, $mark, false );
		$lines = array();
		foreach ( $items as $i ) {
			$lines[] = '- ' . $i['count'] . ' ' . $i['label'] . ': ' . $i['url'];
		}
		$body = "L'anno fiscale finisce il " . ( new \DateTimeImmutable( $w['end'] ) )->format( 'd/m/Y' ) . ". Conviene sistemare ora queste discrepanze, così non restano correzioni da fare dopo la chiusura:\n\n" . implode( "\n", $lines );
		$n    = 0;
		foreach ( self::recipients() as $to ) {
			if ( wp_mail( $to, 'Verifiche di fine anno fiscale', $body ) ) {
				$n++;
			}
		}
		return $n;
	}
}
