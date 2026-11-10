<?php /** @var string $nav @var string $flash @var string $admin_email */
// Sezioni della gestione: ognuna ha le sue schede. $nav è la scheda corrente.
$sections = array(
	'cruscotto'    => array( 'Cruscotto', array( 'cruscotto' => array( '/wordpress/admin', 'Cruscotto' ) ) ),
	'piani'        => array( 'Piani', array( 'prezzi' => array( '/wordpress/admin/prezzi', 'Piani e prezzi' ), 'versioni' => array( '/wordpress/admin/versioni', 'Versioni del plugin' ) ) ),
	'clienti'      => array( 'Clienti', array( 'licenze' => array( '/wordpress/admin/licenze', 'Licenze' ), 'clienti' => array( '/wordpress/admin/clienti', 'Clienti e domini' ), 'wega' => array( '/wordpress/admin/wega', 'Da Wega' ), 'staff' => array( '/wordpress/admin/staff', 'Staff e supporto' ) ) ),
	'impostazioni' => array( 'Impostazioni', array( 'impostazioni' => array( '/wordpress/admin/impostazioni', 'Generali' ), 'pagamenti' => array( '/wordpress/admin/pagamenti', 'Pagamenti' ), 'email' => array( '/wordpress/admin/email', 'Email' ), 'registro' => array( '/wordpress/admin/registro', 'Registro' ), 'sicurezza' => array( '/wordpress/admin/sicurezza', 'Sicurezza' ) ) ),
	'pagine'       => array( 'Pagine', array( 'legale' => array( '/wordpress/admin/legale', 'Privacy e cookie' ), 'cookie' => array( '/wordpress/admin/cookie', 'Script e consenso' ) ) ),
);
if ( Portale\Staff::SUPPORT === ( $role ?? "" ) ) {
	unset( $sections["cruscotto"], $sections["piani"], $sections["impostazioni"], $sections["pagine"] );
	unset( $sections["clienti"][1]["wega"], $sections["clienti"][1]["staff"] );
	$sections["clienti"][1]["sicurezza"] = array( '/wordpress/admin/sicurezza', 'Sicurezza' );
}
$current = isset( $sections["cruscotto"] ) ? "cruscotto" : "clienti";
foreach ( $sections as $sk => $sec ) {
	if ( isset( $sec[1][ $nav ] ) ) {
		$current = $sk;
	}
}
?>
<div class="wrap" style="padding-top:28px">
<div class="admin-top"><h1>Gestione</h1><span class="muted small"><?php echo e( $admin_email ); ?> · <?php if ( ! empty( $has_saas ) ) : ?><a href="/saas">Piattaforma SaaS</a> · <?php endif; ?><a href="/account">La mia area cliente</a> · <a href="<?php echo e( Portale\SupportSso::enabled() ? '/supporto/accedi' : Portale\SupportSso::supportUrl() ); ?>">Supporto</a></span></div>
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
