<?php
/**
 * Pagine dei corsi con le versioni installate (workflow «Plugin update tests», job «uscita-corsi»): Bacheca, scheda di ogni corso e registro presenze
 * (per giornata e a calendario), con un corso a lezioni che salta i giorni rossi e una chiusura nel mese. Ogni avviso PHP dei plugin è un errore.
 * Uso: wp eval-file prova-uscita-pagine.php <etichetta> [crea]   («crea» aggiunge il corso a lezioni e la chiusura, una volta sola).
 */

use AssociazioneSemplice\Db;
use AssociazioneSemplice\Plugin;
use AssociazioneSemplice\Settings;

$label = (string) ( $args[0] ?? '' );
wp_set_current_user( 1 );
$errs = array();
set_error_handler( // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_set_error_handler -- solo nella prova
	function ( $no, $msg, $file, $line ) use ( &$errs ) {
		if ( false !== strpos( (string) $file, 'associazionesemplice' ) ) {
			$errs[] = basename( (string) $file ) . ':' . $line . ' ' . $msg;
		}
		return false !== strpos( (string) $file, 'associazionesemplice' );
	}
);
$ym = substr( current_time( 'Y-m-d' ), 0, 7 );
if ( in_array( 'crea', $args, true ) && ! get_option( 'asem_prova_uscita_corso' ) ) {
	$slots = array();
	for ( $d = 1; $d <= 7; $d++ ) {
		$slots[] = array( 'type' => 'weekly', 'day' => $d, 'start' => '18:00', 'end' => '19:00' );
	}
	$aid = (int) Plugin::activities()->create( array( 'name' => 'Registro a lezioni (prova)', 'social_year' => Settings::social_year()->label(), 'kind' => 'course', 'billing' => 'lessons', 'fee_cents' => 800, 'red_days' => 1, 'lesson_slots' => $slots ) );
	foreach ( Db::db()->get_col( "SELECT id FROM " . Db::t( 'people' ) . " WHERE type = 'ordinary' AND deleted_at IS NULL ORDER BY id LIMIT 3" ) as $pid ) { // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- nessun valore esterno
		Plugin::activities()->enroll( $aid, (int) $pid, $ym );
	}
	$close = ( new DateTimeImmutable( $ym . '-01' ) )->modify( '+9 days' )->format( 'd/m/Y' );
	Settings::update( array( 'closures' => $close . ' Chiusura di prova' ) );
	update_option( 'asem_prova_uscita_corso', $aid, false );
}
$render = function ( string $name, callable $fn, array $get ) use ( &$errs ) {
	$old  = $_GET;
	$_GET = $get;
	ob_start();
	try {
		$fn();
	} catch ( \Throwable $e ) {
		$errs[] = $name . ': ' . get_class( $e ) . ' ' . $e->getMessage();
	}
	$html = (string) ob_get_clean();
	$_GET = $old;
	if ( '' === trim( $html ) ) {
		$errs[] = $name . ': pagina vuota';
	}
	return $html;
};
$render( 'Bacheca', array( \AssociazioneSemplice\Admin\DashboardPage::class, 'render' ), array() );
$ids = array_map( 'intval', Db::db()->get_col( "SELECT id FROM " . Db::t( 'activities' ) . " WHERE kind = 'course' ORDER BY id DESC LIMIT 12" ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- nessun valore esterno
$reg = class_exists( '\AssociazioneSemplice\Admin\ProRegisters' ) && method_exists( '\AssociazioneSemplice\Admin\ProRegisters', 'render_attendance' );
foreach ( $ids as $id ) {
	$render( 'scheda del corso ' . $id, array( \AssociazioneSemplice\Admin\ActivitiesPage::class, 'render_detail' ), array( 'id' => $id ) );
	if ( $reg ) {
		$render( 'presenze per giornata ' . $id, array( \AssociazioneSemplice\Admin\ProRegisters::class, 'render_attendance' ), array( 'activity' => $id, 'ym' => $ym ) );
		$render( 'presenze a calendario ' . $id, array( \AssociazioneSemplice\Admin\ProRegisters::class, 'render_attendance' ), array( 'activity' => $id, 'ym' => $ym, 'view' => 'matrix' ) );
	}
}
restore_error_handler();
$pro = defined( 'ASEM_PRO_VERSION' ) ? ASEM_PRO_VERSION : 'assente';
WP_CLI::log( $label . ': gratuito ' . ( defined( 'ASEM_VERSION' ) ? ASEM_VERSION : '?' ) . ', Pro ' . $pro . ', schema ' . get_option( 'asem_db_version' ) . ', ' . count( $ids ) . ' corsi, registro presenze ' . ( $reg ? 'sì' : 'no' ) . '.' );
if ( $errs ) {
	foreach ( array_slice( $errs, 0, 30 ) as $e ) {
		WP_CLI::warning( $e );
	}
	WP_CLI::error( $label . ': ' . count( $errs ) . ' errori nelle pagine.' );
}
WP_CLI::success( $label . ': pagine dei corsi senza errori.' );
