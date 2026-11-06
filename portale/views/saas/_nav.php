<?php /** @var string $nav @var string $flash @var string $admin_email @var bool $has_plugin */
// Menu della gestione della piattaforma SaaS: autonomo rispetto a quello della gestione del plugin.
$tabs = array( 'associazioni' => array( '/saas', 'Associazioni' ) );
?>
<div class="wrap" style="padding-top:28px">
<div class="admin-top"><h1>Piattaforma SaaS</h1><span class="muted small"><?php echo e( $admin_email ); ?><?php if ( $has_plugin ) : ?> · <a href="/admin">Gestione plugin</a><?php endif; ?> · <a href="/account">La mia area cliente</a></span></div>
<nav class="tabs" aria-label="Piattaforma SaaS">
<?php foreach ( $tabs as $k => $t ) : ?><a href="<?php echo e( $t[0] ); ?>"<?php echo $k === $nav ? ' aria-current="page"' : ''; ?>><?php echo e( $t[1] ); ?></a><?php endforeach; ?>
</nav>
<?php if ( $flash ) : ?><div class="flash" role="status"><?php echo e( $flash ); ?></div><?php endif; ?>
</div>
