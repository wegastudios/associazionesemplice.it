<?php
// Esporta in sola lettura gli staff di oggi, per importarli nell'elenco unico del SaaS (docs/UTENTI_E_ACCESSI.md): email e livello per area.
// NON esporta password, hash, segreti del secondo fattore né chiavi. Non scrive nulla nel database.
//   php bin/staff-export.php > staff.json     (da eseguire sul server; il file contiene indirizzi email: tienilo fuori dal repository)
if ( 'cli' !== PHP_SAPI ) {
	exit( 1 );
}
require __DIR__ . '/../src/bootstrap.php';
Portale\Config::load( require __DIR__ . '/../config.php' );
$db     = Portale\Db::fromConfig();
$people = array();
$map    = array( 'admin' => 'admin', 'support' => 'supporto' );
$put    = function ( string $email, string $area, string $level ) use ( &$people ) {
	$email = strtolower( trim( $email ) );
	if ( '' !== $email ) {
		$people[ $email ]['email']       = $email;
		$people[ $email ]['levels'][ $area ] = $level;
	}
};
foreach ( $db->all( 'SELECT email, role FROM plugin_staff' ) as $r ) {
	if ( isset( $map[ $r['role'] ] ) ) {
		$put( $r['email'], 'wordpress', $map[ $r['role'] ] );
	}
}
foreach ( $db->all( "SELECT email, saas FROM staff WHERE saas <> ''" ) as $r ) {
	if ( isset( $map[ $r['saas'] ] ) ) {
		$put( $r['email'], 'saas', $map[ $r['saas'] ] );
	}
}
foreach ( (array) Portale\Config::get( 'admins', array() ) as $a ) {
	$put( (string) $a, 'wordpress', 'admin' );
	$put( (string) $a, 'saas', 'admin' );
}
ksort( $people );
echo json_encode( array( 'version' => 1, 'people' => array_values( $people ) ), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE ), "\n";
