<?php /** @var string $nav @var string $flash @var string $admin_email */
// Sezioni della gestione: ognuna ha le sue schede. $nav è la scheda corrente.
$sections = array(
	'cruscotto'    => array( 'Cruscotto', array( 'cruscotto' => array( '/wordpress/admin', 'Cruscotto' ) ) ),
	'piani'        => array( 'Piani', array( 'prezzi' => array( '/wordpress/admin/prezzi', 'Piani e prezzi' ), 'catalogo' => array( '/wordpress/admin/catalogo', 'Catalogo' ), 'codici' => array( '/wordpress/admin/codici-promo', 'Codici promo' ), 'versioni' => array( '/wordpress/admin/versioni', 'Versioni del plugin' ) ) ),
	'clienti'      => array( 'Clienti', array( 'licenze' => array( '/wordpress/admin/licenze', 'Licenze' ), 'clienti' => array( '/wordpress/admin/clienti', 'Clienti e domini' ), 'wega' => array( '/wordpress/admin/wega', 'Da Wega' ), 'staff' => array( '/wordpress/admin/staff', 'Staff e supporto' ) ) ),
	'impostazioni' => array( 'Impostazioni', array( 'impostazioni' => array( '/wordpress/admin/impostazioni', 'Generali' ), 'pagamenti' => array( '/wordpress/admin/pagamenti', 'Pagamenti' ), 'email' => array( '/wordpress/admin/email', 'Email' ), 'registro' => array( '/wordpress/admin/registro', 'Registro' ), 'sicurezza' => array( '/wordpress/admin/sicurezza', 'Sicurezza' ) ) ),
	'pagine'       => array( 'Pagine', array( 'legale' => array( '/wordpress/admin/legale', 'Privacy e cookie' ), 'cookie' => array( '/wordpress/admin/cookie', 'Script e consenso' ) ) ),
);
if ( Portale\Staff::SUPPORT === ( $role ?? "" ) ) {
	unset( $sections["cruscotto"], $sections["piani"], $sections["impostazioni"], $sections["pagine"] );
	unset( $sections["clienti"][1]["wega"], $sections["clienti"][1]["staff"] );
	$sections["clienti"][1]["sicurezza"] = array( '/wordpress/admin/sicurezza', 'Sicurezza' );
}
if ( Portale\Plugin\StaffSso::enabled() && ! empty( $_SESSION['staff_sso'] ) ) {
	// Accesso staff unico: «Staff e supporto» diventa «Utenti» (elenco nel SaaS); il secondo fattore si gestisce là.
	if ( isset( $sections['clienti'][1]['staff'] ) ) {
		$sections['clienti'][1]['staff'] = array( '/wordpress/admin/utenti', 'Utenti' );
	}
	unset( $sections['impostazioni'][1]['sicurezza'], $sections['clienti'][1]['sicurezza'] );
}
if ( Portale\Plugin\Admin::SALES === ( $role ?? '' ) ) {
	unset( $sections['cruscotto'], $sections['impostazioni'], $sections['pagine'] );
	unset( $sections['piani'][1]['versioni'], $sections['clienti'][1]['wega'], $sections['clienti'][1]['staff'] );
}
$current = isset( $sections["cruscotto"] ) ? "cruscotto" : "clienti";
foreach ( $sections as $sk => $sec ) {
	if ( isset( $sec[1][ $nav ] ) ) {
		$current = $sk;
	}
}
?>
<div class="wrap" style="padding-top:28px">
<div class="admin-top"><h1>Gestione</h1><span class="muted small"><?php echo e( $admin_email ); ?> · <?php if ( ! empty( $has_saas ) ) : ?><a href="/saas">Piattaforma SaaS</a> · <?php endif; ?><?php if ( ! empty( $_SESSION['staff_sso'] ) ) : $sso_csrf = Portale\App::csrf(); foreach ( Portale\Plugin\StaffSso::AREA_NAMES as $ak => $an ) : if ( isset( $_SESSION['staff_sso']['areas'][ $ak ] ) ) : ?><form method="post" action="/wordpress/admin/vai/<?php echo e( $ak ); ?>" style="display:inline"><input type="hidden" name="csrf" value="<?php echo e( $sso_csrf ); ?>"><button type="submit" class="linklike">Vai a <?php echo e( $an ); ?></button></form> · <?php endif; endforeach; ?><form method="post" action="/wordpress/admin/esci" style="display:inline"><input type="hidden" name="csrf" value="<?php echo e( $sso_csrf ); ?>"><button type="submit" class="linklike">Esci</button></form> · <?php endif; ?><a href="/account">La mia area cliente</a><?php if ( empty( $_SESSION['staff_sso']['areas']['supporto'] ) ) : /* con «Vai a Supporto» il collegamento semplice sarebbe un doppione */ ?> · <a href="<?php echo e( Portale\SupportSso::enabled() ? '/supporto/accedi' : Portale\SupportSso::supportUrl() ); ?>">Supporto</a><?php endif; ?></span></div>
<nav class="tabs" aria-label="Gestione">
<?php foreach ( $sections as $sk => $sec ) : $first = array_values( $sec[1] )[0]; ?><a href="<?php echo e( $first[0] ); ?>"<?php echo $sk === $current ? ' aria-current="page"' : ''; ?>><?php echo e( $sec[0] ); ?></a><?php endforeach; ?>
</nav>
<?php if ( count( $sections[ $current ][1] ) > 1 ) : ?>
<nav class="tabs sub" aria-label="<?php echo e( $sections[ $current ][0] ); ?>">
<?php foreach ( $sections[ $current ][1] as $k => $t ) : ?><a href="<?php echo e( $t[0] ); ?>"<?php echo $k === $nav ? ' aria-current="page"' : ''; ?>><?php echo e( $t[1] ); ?></a><?php endforeach; ?>
</nav>
<?php endif; ?>
<?php if ( $flash ) : ?><div class="flash" role="status"><?php echo e( $flash ); ?></div><?php endif; ?>
</div>
