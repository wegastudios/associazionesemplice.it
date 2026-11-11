<?php
/** @var array $areas area → livello, dalla sessione unica */
$names = array( 'wordpress' => 'Gestione del plugin', 'saas' => 'Gestionale', 'supporto' => 'Supporto' );
$open  = array_intersect_key( $names, (array) $areas );
if ( $open ) : ?>
<div class="sub">
	<div class="sub-top"><h2>Le tue aree</h2></div>
	<div style="display:flex;gap:10px;flex-wrap:wrap">
	<?php foreach ( $open as $key => $label ) : ?>
		<form method="post" action="/account/area"><input type="hidden" name="csrf" value="<?php echo e( Portale\App::csrf() ); ?>"><input type="hidden" name="to" value="<?php echo e( $key ); ?>"><button class="btn small" type="submit">Entra in: <?php echo e( $label ); ?></button></form>
	<?php endforeach; ?>
	</div>
</div>
<?php endif; ?>
