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
