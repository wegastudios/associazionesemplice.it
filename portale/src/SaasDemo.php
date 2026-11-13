<?php
namespace Portale;

/**
 * Demo pubblica del gestionale online (sottodominio a parte, si azzera ogni notte). Il portale la mostra solo se in config c'è l'indirizzo
 * `saas_demo_url`: finché è vuoto non compare nessun collegamento. Gli accessi sono ruolo@dominio-della-demo con la password `saas_demo_password`
 * (la demo è pubblica e con dati inventati, come quella del plugin).
 */
final class SaasDemo {

	const ROLES = array(
		'titolare'       => array( 'Titolare', 'Vede e gestisce tutto: soci, cassa, assemblee, impostazioni.' ),
		'amministratore' => array( 'Amministratore', 'Gestisce la gestione dell\'associazione e gli altri utenti.' ),
		'segreteria'     => array( 'Segreteria', 'Soci, tessere, quote e attività di ogni giorno.' ),
		'dipendente'     => array( 'Dipendente', 'Vede solo ciò che serve al suo lavoro, senza i dati riservati.' ),
		'commercialista' => array( 'Commercialista', 'Consulta cassa e rendiconto, senza modificare i soci.' ),
	);

	/** Indirizzo della demo senza barra finale, oppure '' se non c'è ancora. */
	public static function url(): string {
		$u = rtrim( trim( (string) Config::get( 'saas_demo_url', '' ) ), '/' );
		return 0 === strpos( $u, 'https://' ) ? $u : '';
	}

	/** Gli accessi di prova: ruolo, nome, descrizione, email, password. Vuoto se la demo non è configurata. */
	public static function accounts(): array {
		$url = self::url();
		if ( '' === $url ) {
			return array();
		}
		$host = (string) parse_url( $url, PHP_URL_HOST );
		$pass = (string) Config::get( 'saas_demo_password', '' );
		$out  = array();
		foreach ( self::ROLES as $slug => $r ) {
			$out[] = array( 'role' => $r[0], 'note' => $r[1], 'user' => $slug . '@' . $host, 'password' => $pass );
		}
		return $out;
	}
}
