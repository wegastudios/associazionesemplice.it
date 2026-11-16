<?php
/** Funzioni comuni alle prove sui dati del Pro (prima e dopo l'aggiornamento). */

use AssociazioneSemplice\Db;
use AssociazioneSemplice\FileKey;

require_once __DIR__ . '/prova-dati-comune.php';

/** Impronta dei dati di gratuito e Pro: conteggi e somme (nessuna chiave, nessun contenuto). */
function pro_impronta(): array {
	global $wpdb;
	$res = Db::t( 'reservations' );
	return dati_impronta() + array(
		'risorse'      => (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Db::t( 'resources' ) ),
		'prenotazioni' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM $res" ),
		'dovuto'       => (int) $wpdb->get_var( "SELECT COALESCE(SUM(fee_due_cents),0) FROM $res" ),
		'crediti_voci' => (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Db::t( 'credit_entries' ) ),
		'crediti_somma' => (int) $wpdb->get_var( 'SELECT COALESCE(SUM(delta),0) FROM ' . Db::t( 'credit_entries' ) ),
		'documenti'    => (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Db::t( 'documents' ) ),
	);
}

/** Cosa si può dire della chiave dei file senza mostrarla: stato, identificativo del sito e della chiave in uso, impronta della chiave. */
function pro_chiave(): array {
	$cur = FileKey::current();
	return array(
		'stato'     => FileKey::state(),
		'sito'      => hash( 'sha256', FileKey::site_id() ),
		'id_chiave' => $cur ? (int) $cur['id'] : 0,
		'impronta'  => $cur ? hash( 'sha256', $cur['key'] ) : '',
	);
}
