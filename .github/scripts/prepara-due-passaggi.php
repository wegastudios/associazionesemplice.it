<?php
/**
 * Dati per la prova nel browser della verifica in due passaggi (workflow «Prove in sandbox», passo «Verifica in due passaggi nel browser»).
 * Crea in wp-env (nessun dato vero) una segreteria con la verifica accesa, un dipendente senza, un socio con l'accesso proprio con la verifica
 * accesa e uno senza; accende i due obblighi dell'ente; prepara un link di accesso via email per il socio con la verifica. Stampa una riga JSON
 * con gli accessi, i segreti di prova e il link (il passo li toglie prima di caricare l'artefatto).
 */
use AssociazioneSemplice\MemberLogin;
use AssociazioneSemplice\Plugin;
use AssociazioneSemplice\Settings;
use AssociazioneSemplice\TwoFactor;

wp_set_current_user( 1 );
\AssociazioneSemplice\Employees::ensure_role();
$now  = time();
$code = function ( string $secret ) use ( $now ): string {
	return TwoFactor::code_at( $secret, $now );
};
$pw = array(
	'sec'  => wp_generate_password( 20, false ),
	'emp'  => wp_generate_password( 20, false ),
	'mem'  => wp_generate_password( 20, false ),
	'free' => wp_generate_password( 20, false ),
);

// Segreteria con la verifica accesa
$sec = (int) wp_insert_user( array( 'user_login' => 'tfa.segreteria', 'user_email' => 'tfa.segreteria@example.com', 'user_pass' => $pw['sec'], 'first_name' => 'Segreteria', 'role' => Plugin::ROLE_SECRETARY ) );
$s1  = TwoFactor::start_setup( TwoFactor::user( $sec ) );
TwoFactor::confirm_setup( TwoFactor::user( $sec ), $code( $s1 ) );
TwoFactor::take_new_codes( TwoFactor::user( $sec ) );

// Dipendente senza verifica (con l'obbligo va all'attivazione)
$emp = (int) wp_insert_user( array( 'user_login' => 'tfa.dipendente', 'user_email' => 'tfa.dipendente@example.com', 'user_pass' => $pw['emp'], 'first_name' => 'Dipendente', 'role' => Plugin::ROLE_EMPLOYEE ) );

// Soci con l'accesso proprio (senza utente WordPress)
$peo  = Plugin::people();
$mem  = (int) $peo->create( array( 'type' => 'ordinary', 'first_name' => 'Socia', 'last_name' => 'Verificata', 'email' => 'tfa.socia@example.com', 'card_number' => 'TFA1' ) );
$free = (int) $peo->create( array( 'type' => 'ordinary', 'first_name' => 'Socio', 'last_name' => 'Daaccendere', 'email' => 'tfa.socio@example.com', 'card_number' => 'TFA2' ) );
foreach ( array( $mem, $free ) as $pid ) {
	$peo->set_membership( $pid, Settings::membership_year()->label(), true );
	\AssociazioneSemplice\Db::db()->update( \AssociazioneSemplice\Db::t( 'people' ), array( 'wp_user_id' => null ), array( 'id' => $pid ) );
}
MemberLogin::set_password( $mem, $pw['mem'] );
MemberLogin::set_password( $free, $pw['free'] );
$s2 = TwoFactor::start_setup( $mem );
TwoFactor::confirm_setup( $mem, $code( $s2 ) );
TwoFactor::take_new_codes( $mem );

// Link di accesso via email per la socia con la verifica: la mail non parte, il link si legge qui
$mail = '';
add_filter(
	'pre_wp_mail',
	function ( $null, $atts ) use ( &$mail ) {
		$mail = (string) $atts['message'];
		return true;
	},
	10,
	2
);
MemberLogin::request( 'tfa.socia@example.com' );
$link = 1 === preg_match( '#(https?://\S*asem_ml=[A-Za-z0-9_-]{43})#', $mail, $m ) ? $m[1] : '';

// Obblighi dell'ente accesi (nessuno dei due tocca chi ha già la verifica)
$l                         = (array) Settings::get( 'limits' );
$l['tfa_required_staff']   = 1;
$l['tfa_required_members'] = 1;
Settings::update( array( 'limits' => $l ) );

echo wp_json_encode(
	array(
		'sec'    => array( 'login' => 'tfa.segreteria', 'pass' => $pw['sec'], 'secret' => $s1 ),
		'emp'    => array( 'login' => 'tfa.dipendente', 'pass' => $pw['emp'] ),
		'mem'    => array( 'email' => 'tfa.socia@example.com', 'pass' => $pw['mem'], 'secret' => $s2, 'link' => $link ),
		'free'   => array( 'email' => 'tfa.socio@example.com', 'pass' => $pw['free'] ),
		'login'  => \AssociazioneSemplice\Frontend\MemberLoginPage::url(),
		'area'   => \AssociazioneSemplice\Gatekeeper::area_url(),
	)
) . "\n";
