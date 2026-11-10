<?php /** @var array $releases per prodotto: label, versions (version, created_at), latest (testo delle novità) */ ?>
<section class="page"><div class="wrap" style="max-width:760px">
	<div class="panel legal">
		<h1>Novità del plugin</h1>
		<?php foreach ( $releases as $r ) : ?>
			<h2><?php echo e( $r['label'] ); ?></h2>
			<?php if ( ! $r['versions'] ) : ?>
				<p>Nessuna versione pubblicata.</p>
			<?php else : ?>
				<p>Ultima versione: <strong><?php echo e( $r['versions'][0]['version'] ); ?></strong> · <?php echo e( substr( (string) $r['versions'][0]['created_at'], 0, 10 ) ); ?></p>
				<?php if ( '' !== $r['latest'] ) : ?><?php echo Portale\Legal::html( $r['latest'] ); // Già filtrato da Legal::html(). ?><?php endif; ?>
				<?php if ( count( $r['versions'] ) > 1 ) : ?>
					<details><summary>Versioni precedenti</summary><ul>
					<?php foreach ( array_slice( $r['versions'], 1 ) as $v ) : ?><li><?php echo e( $v['version'] ); ?> · <?php echo e( substr( (string) $v['created_at'], 0, 10 ) ); ?></li><?php endforeach; ?>
					</ul></details>
				<?php endif; ?>
			<?php endif; ?>
		<?php endforeach; ?>
		<p><a href="/wordpress">← Torna al plugin</a></p>
	</div>
</div></section>
