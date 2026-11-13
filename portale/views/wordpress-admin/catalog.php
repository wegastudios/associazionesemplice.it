<?php /** @var array $draft @var bool $has_draft @var array $plans @var array $errors @var ?array $published @var array $history @var string[] $levels */ include __DIR__ . '/_nav.php';
$csrf   = Portale\App::csrf();
$states = array( 'included' => 'Incluso', 'limit' => 'Con limite', 'excluded' => 'Non incluso' );
$levelN = array( 'free' => 'Gratuita', 'paid' => 'Standard', 'top' => 'Top' );
$lines  = implode( "\n", array_map( function ( $f ) { return $f['id'] . ' | ' . $f['text'] . ( '' !== $f['group'] . $f['compare'] ? ' | ' . $f['group'] . ( '' !== $f['compare'] ? ' | ' . $f['compare'] : '' ) : '' ); }, $draft['features'] ) );
?>
<section class="admin"><div class="wrap" style="max-width:980px">
	<h2>Catalogo del plugin <span class="badge <?php echo $has_draft ? '' : 'ok'; ?>"><?php echo $has_draft ? 'bozza non pubblicata' : 'bozza uguale al pubblicato'; ?></span></h2>
	<p class="muted">Le modifiche restano nella bozza e non le vede nessuno finché non premi «Pubblica». Il negozio e il resto del portale leggono solo l'ultima revisione pubblicata<?php echo $published ? ' (ora la ' . (int) $published['rev'] . ')' : ' (per ora nessuna: vale la configurazione di oggi)'; ?>.
	Nomi e prezzi dei piani si cambiano in <a href="/wordpress/admin/prezzi">Piani e prezzi</a>; il catalogo li legge da lì. Ogni piano cambia di <em>versione</em> solo se ne cambia il contenuto: chi ha già comprato resta sulla versione comprata.</p>
	<?php if ( $errors ) : ?><div class="flash" role="alert"><strong>Prima di pubblicare:</strong><ul><?php foreach ( $errors as $er ) : ?><li><?php echo e( $er ); ?></li><?php endforeach; ?></ul></div><?php endif; ?>

	<form class="card2" method="post" action="/wordpress/admin/catalogo/caratteristiche">
		<input type="hidden" name="csrf" value="<?php echo e( $csrf ); ?>">
		<h3>Caratteristiche</h3>
		<p class="muted small">Una per riga, nel formato <code>codice | testo | gruppo | chiave di confronto</code> (codice: lettere minuscole, cifre e trattino basso; testo senza HTML, fino a 160 caratteri; gruppo e chiave sono facoltativi e servono alle pagine di confronto, chiavi ammesse: <?php echo e( implode( ', ', Portale\Plugin\CatalogStore::COMPARE_KEYS ) ); ?>). Il codice non va cambiato dopo la pubblicazione. Dopo il salvataggio compaiono nella tabella qui sotto.</p>
		<textarea name="features" rows="8" style="width:100%" aria-label="Caratteristiche"><?php echo e( $lines ); ?></textarea>
		<div class="row-actions"><button class="btn small" type="submit">Salva caratteristiche nella bozza</button></div>
	</form>

	<form class="card2" method="post" action="/wordpress/admin/catalogo/importa-confronto" onsubmit="<?php echo js_confirm( 'Sostituire caratteristiche e tabella della bozza con le righe del confronto di oggi?' ); ?>">
		<input type="hidden" name="csrf" value="<?php echo e( $csrf ); ?>">
		<h3>Parti dal confronto di oggi</h3>
		<p class="muted small">Carica nella bozza le righe della pagina «Confronto» (gruppi, funzioni e cosa è incluso in ogni piano) con codici stabili. Non pubblica niente.</p>
		<div class="row-actions"><button class="btn alt small" type="submit">Importa nella bozza</button></div>
	</form>

	<form class="card2" method="post" action="/wordpress/admin/catalogo/piani">
		<input type="hidden" name="csrf" value="<?php echo e( $csrf ); ?>">
		<h3>Piani</h3>
		<?php foreach ( $plans as $p ) : $c = $p['code']; $d = $draft['plans'][ $c ] ?? array( 'audience' => '', 'visible' => true, 'order' => 0, 'support_level' => 'paid', 'sites' => null ); ?>
		<fieldset style="margin:12px 0;padding:12px;border:1px solid var(--line,#ddd);border-radius:8px">
			<legend><strong><?php echo e( $p['name'] ); ?></strong> <span class="muted small">codice «<?php echo e( $c ); ?>»</span></legend>
			<div class="form-grid two">
				<div><label>A chi è rivolto <input type="text" name="plan[<?php echo e( $c ); ?>][audience]" value="<?php echo e( $d['audience'] ); ?>" maxlength="200"></label></div>
				<div><label>Livello di assistenza
					<?php if ( 'free' === $c ) : ?><input type="hidden" name="plan[free][support_level]" value="free"><input type="text" value="Gratuita" disabled aria-label="Assistenza"><?php else : ?><select name="plan[<?php echo e( $c ); ?>][support_level]"><?php foreach ( array( 'paid', 'top' ) as $l ) : ?><option value="<?php echo e( $l ); ?>"<?php echo $l === $d['support_level'] ? ' selected' : ''; ?>><?php echo e( $levelN[ $l ] ?? $l ); ?></option><?php endforeach; ?></select><?php endif; ?></label></div>
				<div><label>Siti ammessi (vuoto = nessun limite) <input type="number" name="plan[<?php echo e( $c ); ?>][sites]" value="<?php echo e( $d['sites'] ?? '' ); ?>" min="1" max="1000"></label></div>
				<div><label>Ordine <input type="number" name="plan[<?php echo e( $c ); ?>][order]" value="<?php echo (int) $d['order']; ?>" min="0" max="999"></label></div>
			</div>
			<label class="check"><input type="checkbox" name="plan[<?php echo e( $c ); ?>][visible]" value="1"<?php echo $d['visible'] ? ' checked' : ''; ?>> Visibile nel catalogo</label>
		</fieldset>
		<?php endforeach; ?>
		<?php if ( $draft['features'] ) : ?>
		<div class="tbl-wrap"><table class="tbl"><thead><tr><th>Caratteristica</th><?php foreach ( $plans as $p ) : ?><th><?php echo e( $p['name'] ); ?></th><?php endforeach; ?></tr></thead><tbody>
		<?php foreach ( $draft['features'] as $f ) : ?>
			<tr><td><?php echo e( $f['text'] ); ?><br><span class="muted small"><?php echo e( $f['id'] ); ?></span></td>
			<?php foreach ( $plans as $p ) : $c = $p['code']; $cell = $draft['matrix'][ $c ][ $f['id'] ] ?? array( 'state' => 'excluded', 'note' => '' ); ?>
				<td>
					<select name="cell[<?php echo e( $c ); ?>][<?php echo e( $f['id'] ); ?>]" aria-label="<?php echo e( $f['text'] . ' · ' . $p['name'] ); ?>"><?php foreach ( $states as $k => $l ) : ?><option value="<?php echo e( $k ); ?>"<?php echo $k === $cell['state'] ? ' selected' : ''; ?>><?php echo e( $l ); ?></option><?php endforeach; ?></select>
					<input type="text" name="note[<?php echo e( $c ); ?>][<?php echo e( $f['id'] ); ?>]" value="<?php echo e( $cell['note'] ); ?>" maxlength="120" placeholder="nota (es. fino a 100 soci)" aria-label="Nota">
				</td>
			<?php endforeach; ?></tr>
		<?php endforeach; ?></tbody></table></div>
		<?php else : ?><p class="muted">Aggiungi prima le caratteristiche, poi potrai dire in quale piano sono incluse.</p><?php endif; ?>
		<div class="row-actions"><button class="btn small" type="submit">Salva piani nella bozza</button></div>
	</form>

	<div class="card2">
		<h3>Pubblica</h3>
		<form method="post" action="/wordpress/admin/catalogo/pubblica" style="display:inline"><input type="hidden" name="csrf" value="<?php echo e( $csrf ); ?>"><button class="btn" type="submit"<?php echo $errors ? ' disabled' : ''; ?> onclick="<?php echo js_confirm( 'Pubblicare il catalogo? Da ora il portale legge questa revisione.' ); ?>">Pubblica</button></form>
		<?php if ( $has_draft ) : ?><form method="post" action="/wordpress/admin/catalogo/scarta" style="display:inline" onsubmit="<?php echo js_confirm( 'Scartare la bozza e tornare all\'ultima revisione pubblicata?' ); ?>"><input type="hidden" name="csrf" value="<?php echo e( $csrf ); ?>"><button class="btn alt small" type="submit">Scarta la bozza</button></form><?php endif; ?>
		<p class="muted small" style="margin-top:12px">Catalogo pubblico (sola lettura, firmato più avanti): <a href="/wordpress/api/catalogo/v1">/wordpress/api/catalogo/v1</a></p>
		<?php if ( $history ) : ?><h4>Revisioni pubblicate</h4><ul class="small"><?php foreach ( $history as $h ) : ?><li>Revisione <?php echo (int) $h['rev']; ?> · <?php echo e( date_it( $h['at'] ) ); ?> · <?php echo e( $h['actor'] ); ?></li><?php endforeach; ?></ul><?php endif; ?>
	</div>
</div></section>
