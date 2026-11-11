<?php /** @var string $email @var array $areas */ ?>
<section class="page"><div class="wrap" style="max-width:860px">
	<div class="account-head">
		<div><h1>Il tuo account</h1><div class="muted"><?php echo e( $email ); ?></div></div>
		<form method="post" action="/account/esci"><input type="hidden" name="csrf" value="<?php echo e( Portale\App::csrf() ); ?>"><button class="btn alt small" type="submit">Esci</button></form>
	</div>
	<?php include __DIR__ . '/_areas.php'; ?>
	<?php if ( ! array_intersect_key( array( 'wordpress' => 1, 'saas' => 1, 'supporto' => 1 ), (array) $areas ) ) : ?><p class="muted">Per ora non hai aree a cui accedere.</p><?php endif; ?>
</div></section>
