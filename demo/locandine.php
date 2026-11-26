<?php
/**
 * Assegna le locandine vere (WebP) agli eventi e ai corsi già presenti nella demo, SENZA azzerare niente. Idempotente: si può rilanciare quando si vuole.
 * Uso: wp eval-file ~/demo-provision/locandine.php [--user=...]   (prima: wp eval-file ... con la variabile d'ambiente ASEM_LOCANDINE_SECCO=1 = solo elenco, nessuna modifica)
 * I file stanno in <cartella degli script>/locandine/<slug del titolo>.webp (vedi demo/assets/locandine/LEGGIMI.md). Un evento senza file resta com'è.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
if ( ! class_exists( '\AssociazioneSemplice\PublicEvents' ) ) {
	WP_CLI::error( 'Il plugin non è attivo.' );
}
// Le funzioni di caricamento stanno in extra-data.php, che però esegue anche i dati di prova: qui servono solo queste due, ricopiate con lo stesso comportamento.
if ( ! function_exists( 'extra_poster_file' ) ) {
	function extra_poster_file( string $title ): string {
		foreach ( array( __DIR__ . '/locandine', __DIR__ . '/assets/locandine' ) as $dir ) {
			$f = $dir . '/' . sanitize_title( $title ) . '.webp';
			if ( is_readable( $f ) && filesize( $f ) > 0 ) {
				return $f;
			}
		}
		return '';
	}
}
if ( ! function_exists( 'extra_poster_import' ) ) {
	function extra_poster_import( string $file, string $title ) {
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';
		$up = wp_upload_bits( sanitize_title( $title ) . '.webp', null, (string) file_get_contents( $file ) );
		if ( ! empty( $up['error'] ) ) {
			return 0;
		}
		$id = wp_insert_attachment( array( 'post_mime_type' => 'image/webp', 'post_title' => $title, 'post_status' => 'inherit' ), $up['file'] );
		if ( ! $id || is_wp_error( $id ) ) {
			return 0;
		}
		wp_update_attachment_metadata( (int) $id, wp_generate_attachment_metadata( (int) $id, $up['file'] ) );
		update_post_meta( (int) $id, '_wp_attachment_image_alt', 'Immagine illustrativa per «' . $title . '»' );
		update_post_meta( (int) $id, '_asem_locandina_sha1', sha1_file( $file ) );
		return (int) $id;
	}
}
$dry = '' !== (string) getenv( 'ASEM_LOCANDINE_SECCO' );
$done = 0; $same = 0; $none = 0; $fail = 0;
foreach ( get_posts( array( 'post_type' => \AssociazioneSemplice\PublicEvents::POST_TYPE, 'post_status' => array( 'publish', 'future', 'draft', 'private' ), 'numberposts' => -1 ) ) as $p ) {
	$title = get_the_title( $p );
	$file  = extra_poster_file( $title );
	if ( '' === $file ) {
		++$none;
		continue;
	}
	$thumb = (int) get_post_thumbnail_id( $p );
	if ( $thumb && sha1_file( $file ) === (string) get_post_meta( $thumb, '_asem_locandina_sha1', true ) ) {
		++$same; // già questa immagine
		continue;
	}
	if ( $dry ) {
		WP_CLI::log( 'assegnerei: ' . $title . ' ← ' . basename( $file ) );
		++$done;
		continue;
	}
	$id = extra_poster_import( $file, $title );
	if ( ! $id ) {
		WP_CLI::warning( 'non riuscita: ' . $title );
		++$fail;
		continue;
	}
	set_post_thumbnail( $p, $id );
	if ( $thumb ) {
		wp_delete_attachment( $thumb, true ); // la locandina inventata che c'era prima (non resta in libreria)
	}
	WP_CLI::log( 'assegnata: ' . $title );
	++$done;
}
WP_CLI::success( sprintf( '%s%d assegnate, %d già a posto, %d senza file, %d non riuscite.', $dry ? '[a secco] ' : '', $done, $same, $none, $fail ) );
if ( ! $dry ) {
	WP_CLI::runcommand( 'litespeed-purge all', array( 'launch' => false, 'exit_error' => false ) );
}
