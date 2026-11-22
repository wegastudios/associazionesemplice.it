<?php
/**
 * Solo per la CI (job del workflow «WordPress plugin»): se la richiesta porta l'intestazione X-Asem-Costo, alla fine scrive in un commento
 * quanti file PHP del plugin (gratuito e Pro) ha letto, quanti byte pesano e quali classi del plugin sono state caricate.
 * Non fa nient'altro e non esiste su nessun sito vero: si monta come mu-plugin solo nel WordPress di prova del job.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
if ( empty( $_SERVER['HTTP_X_ASEM_COSTO'] ) ) {
	return;
}
register_shutdown_function(
	function () {
		$files = 0;
		$bytes = 0;
		$list  = array();
		foreach ( get_included_files() as $f ) {
			$f = str_replace( '\\', '/', $f );
			if ( ! preg_match( '#/plugins/associazionesemplice(-pro)?/#', $f ) || false !== strpos( $f, '/tests/' ) ) {
				continue;
			}
			$files++;
			$size   = (int) filesize( $f );
			$bytes += $size;
			$list[] = preg_replace( '#^.*/plugins/#', '', $f ) . ':' . $size;
		}
		$classes = array();
		foreach ( get_declared_classes() as $c ) {
			if ( 0 === strpos( $c, 'AssociazioneSemplice\\' ) ) {
				$classes[] = substr( $c, strlen( 'AssociazioneSemplice\\' ) );
			}
		}
		echo "\n<!-- asem-costo files=" . (int) $files . ' bytes=' . (int) $bytes . " -->\n";
		echo '<!-- asem-costo-classi ' . implode( ' ', $classes ) . " -->\n";
		echo '<!-- asem-costo-file ' . implode( ' ', $list ) . " -->\n";
	}
);
