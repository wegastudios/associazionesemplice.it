<?php /** Listino e confronto del SaaS, generati dal catalogo letto dal portale (mai prezzi scritti a mano). @var array|null $catalog */
if ( empty( $catalog ) ) : ?>
<div class="panel" id="prezzi-saas"><h2>Gestionale online</h2><p>I piani del gestionale online saranno qui a breve.</p></div>
<?php return; endif;
$doc    = $catalog['doc'];
$plans  = Portale\Catalog::plans( $doc );
$groups = Portale\Catalog::comparison( $doc );
$show_cards = $show_cards ?? true; // /prezzi: solo le schede; /confronto: solo la tabella; /saas: entrambe
$show_table = $show_table ?? true;
?>
<section class="section product-saas" id="prezzi-saas"><div class="wrap">
	<div class="section-head"><span class="eyebrow">Gestionale online</span><h2>Piani e prezzi</h2><p>Importi IVA esclusa. Il prezzo cresce con i soci: nessun limite blocca la segreteria.</p></div>
	<?php if ( $show_cards ) : ?><div class="plans">
	<?php foreach ( $plans as $p ) :
		$m = Portale\Catalog::from( $p, 'month' );
		$y = Portale\Catalog::from( $p, 'year' );
		$members = 'members_tiers' === ( Portale\Catalog::price( $p, 'month' )['kind'] ?? '' ); ?>
		<div class="plan">
			<h3><?php echo e( (string) $p['name'] ); ?></h3>
			<p class="muted"><?php echo e( (string) ( $p['audience'] ?? '' ) ); ?></p>
			<?php if ( $m ) : ?><p class="price"><?php echo $members ? 'da ' : ''; ?><strong><?php echo e( money( $m[0] ) ); ?></strong> al mese + IVA</p><?php endif; ?>
			<?php if ( $y ) : ?><p class="fine"><?php echo $members ? 'da ' : ''; ?><?php echo e( money( $y[0] ) ); ?> all'anno + IVA</p><?php endif; ?>
			<?php if ( ! empty( $p['trial_days'] ) ) : ?><p class="fine">Prova gratuita di <?php echo (int) $p['trial_days']; ?> giorni, senza carta.</p><?php endif; ?>
		</div>
	<?php endforeach; ?>
	</div><?php endif; ?>
	<?php if ( $show_table && $plans ) : ?>
	<div class="cmp-wrap"><table class="cmp"><thead><tr><th scope="col">Funzione</th><?php foreach ( $plans as $p ) : ?><th scope="col"><?php echo e( (string) $p['name'] ); ?></th><?php endforeach; ?></tr></thead><tbody>
	<?php foreach ( $groups as $g => $feats ) : ?>
		<?php if ( '' !== $g ) : ?><tr><th colspan="<?php echo count( $plans ) + 1; ?>" scope="colgroup"><?php echo e( (string) $g ); ?></th></tr><?php endif; ?>
		<?php foreach ( $feats as $f ) : ?>
		<tr><th scope="row"><?php echo e( (string) $f['text'] ); ?></th>
		<?php foreach ( $plans as $p ) :
			$st = (array) ( $p['features'][ $f['id'] ] ?? array() );
			$s  = (string) ( $st['state'] ?? 'excluded' ); ?>
			<td><?php echo 'included' === $s ? '<span aria-label="Incluso">✓</span>' : ( 'limit' === $s ? e( (string) ( $st['text'] ?? '' ) ) : '<span aria-label="Non incluso">—</span>' ); ?></td>
		<?php endforeach; ?></tr>
		<?php endforeach; ?>
	<?php endforeach; ?>
	</tbody></table></div>
	<?php endif; ?>
	<p class="fine center">Listino aggiornato al <?php echo e( date_it( $catalog['fetched_at'] ) ); ?>.</p>
</div></section>
