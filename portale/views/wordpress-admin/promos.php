<?php /** @var array $rows @var array $plans @var ?array $edit */ include __DIR__ . '/_nav.php';
$csrf = Portale\App::csrf();
$e0   = $edit ?: array( 'code' => '', 'kind' => 'percent', 'value' => 10, 'plans' => '[]', 'starts_on' => '', 'ends_on' => '', 'max_uses' => null );
$sel  = (array) json_decode( (string) $e0['plans'], true );
$val  = 'amount' === $e0['kind'] ? number_format( $e0['value'] / 100, 2, ',', '' ) : (string) $e0['value'];
?>
<section class="admin"><div class="wrap" style="max-width:900px">
	<h2>Codici promo del plugin</h2>
	<p class="muted">Ogni codice vale solo per i piani del plugin (le offerte combinate con il gestionale online non esistono). Il cliente scrive il codice all'acquisto; il portale chiede qui se vale e lo conferma solo a pagamento riuscito, una volta per ordine. Quelli attivi compaiono nel catalogo pubblico. La promozione «in vetrina» con il prezzo barrato resta in <a href="/wordpress/admin/prezzi">Piani e prezzi</a>.</p>
	<form class="card2" method="post" action="/wordpress/admin/codici-promo">
		<input type="hidden" name="csrf" value="<?php echo e( $csrf ); ?>">
		<h3><?php echo $edit ? 'Modifica ' . e( $edit['code'] ) : 'Nuovo codice'; ?></h3>
		<div class="form-grid two">
			<div><label>Codice <input type="text" name="code" value="<?php echo e( $e0['code'] ); ?>" maxlength="30" required pattern="[A-Za-z0-9_-]{3,30}"<?php echo $edit ? ' readonly' : ''; ?>></label></div>
			<div><label>Tipo di sconto
				<select name="kind"><option value="percent"<?php echo 'percent' === $e0['kind'] ? ' selected' : ''; ?>>Percentuale</option><option value="amount"<?php echo 'amount' === $e0['kind'] ? ' selected' : ''; ?>>Importo in euro</option></select></label></div>
			<div><label>Valore (% oppure euro) <input type="text" name="value" value="<?php echo e( $val ); ?>" inputmode="decimal" required></label></div>
			<div><label>Limite d'uso (vuoto = illimitato) <input type="number" name="max_uses" min="1" value="<?php echo e( $e0['max_uses'] ?? '' ); ?>"></label></div>
			<div><label>Dal giorno <input type="date" name="starts_on" value="<?php echo e( $e0['starts_on'] ); ?>"></label></div>
			<div><label>Fino al giorno (compreso) <input type="date" name="ends_on" value="<?php echo e( $e0['ends_on'] ); ?>" required></label></div>
		</div>
		<fieldset style="margin-top:8px"><legend>Piani validi (nessuno spuntato = tutti)</legend>
		<?php foreach ( $plans as $p ) : ?><label class="check"><input type="checkbox" name="plans[]" value="<?php echo e( $p['code'] ); ?>"<?php echo in_array( $p['code'], $sel, true ) ? ' checked' : ''; ?>> <?php echo e( $p['name'] ); ?></label> <?php endforeach; ?>
		</fieldset>
		<div class="row-actions"><button class="btn" type="submit">Salva codice</button></div>
	</form>
	<div class="tbl-wrap"><table class="tbl"><thead><tr><th>Codice</th><th>Sconto</th><th>Piani</th><th>Periodo</th><th>Usi</th><th>Stato</th><th></th></tr></thead><tbody>
	<?php foreach ( $rows as $r ) : $live = Portale\Plugin\Promos::isLive( $r ); $pl = (array) json_decode( (string) $r['plans'], true ); ?>
		<tr><td><a href="/wordpress/admin/codici-promo?codice=<?php echo e( rawurlencode( $r['code'] ) ); ?>"><?php echo e( $r['code'] ); ?></a></td>
		<td><?php echo 'percent' === $r['kind'] ? (int) $r['value'] . '%' : e( number_format( $r['value'] / 100, 2, ',', '.' ) ) . ' €'; ?></td>
		<td><?php echo $pl ? e( implode( ', ', $pl ) ) : 'tutti'; ?></td>
		<td><?php echo e( ( $r['starts_on'] ? date_it( $r['starts_on'] ) . ' → ' : 'fino al ' ) . date_it( $r['ends_on'] ) ); ?></td>
		<td><?php echo (int) $r['used']; ?><?php echo null !== $r['max_uses'] ? ' / ' . (int) $r['max_uses'] : ''; ?></td>
		<td><span class="badge <?php echo $live ? 'ok' : ''; ?>"><?php echo $live ? 'attivo' : ( $r['active'] ? 'non in corso' : 'sospeso' ); ?></span></td>
		<td><form method="post" action="/wordpress/admin/codici-promo/stato"><input type="hidden" name="csrf" value="<?php echo e( $csrf ); ?>"><input type="hidden" name="code" value="<?php echo e( $r['code'] ); ?>"><?php if ( ! $r['active'] ) : ?><input type="hidden" name="active" value="1"><button class="btn small" type="submit">Riattiva</button><?php else : ?><button class="btn alt small" type="submit">Sospendi</button><?php endif; ?></form></td></tr>
	<?php endforeach; ?>
	<?php if ( ! $rows ) : ?><tr><td colspan="7" class="muted">Nessun codice per ora.</td></tr><?php endif; ?>
	</tbody></table></div>
</div></section>
