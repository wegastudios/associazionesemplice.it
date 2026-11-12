<?php /** @var array $all @var array $on @var string $flash */
$csrf = Portale\App::csrf();
?>
<section class="page"><div class="wrap" style="max-width:860px">
	<div class="account-head">
		<div><h1>Impostazioni del portale</h1></div>
		<a class="btn alt small" href="/account">Torna al tuo account</a>
	</div>
	<?php if ( ! empty( $flash ) ) : ?><div class="flash" role="status"><?php echo e( $flash ); ?></div><?php endif; ?>
	<form method="post" action="/account/impostazioni">
		<input type="hidden" name="csrf" value="<?php echo e( $csrf ); ?>">
		<div class="sub">
			<div class="sub-top"><h2>Funzioni</h2></div>
			<?php foreach ( $all as $key => $def ) : ?>
				<label style="display:flex;gap:12px;align-items:flex-start;padding:10px 0;border-top:1px solid var(--line);cursor:pointer">
					<input type="checkbox" name="<?php echo e( $key ); ?>" value="1" style="width:22px;height:22px;margin-top:2px"<?php echo ! empty( $on[ $key ] ) ? ' checked' : ''; ?>>
					<span><b><?php echo e( $def[1] ); ?></b><br><span class="muted small"><?php echo e( $def[2] ); ?></span></span>
				</label>
			<?php endforeach; ?>
			<button class="btn" type="submit" style="margin-top:12px">Salva</button>
			<p class="hint">Vale subito, per tutti. Le impostazioni dei prezzi, dei limiti e dei testi stanno in <a href="/wordpress/admin/impostazioni">Gestione del plugin</a>.</p>
		</div>
	</form>
</div></section>
