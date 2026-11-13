<?php /** @var string $org @var string $email */
$app = rtrim( (string) Portale\Config::get( 'saas_app_url', 'https://app.associazionesemplice.it/gestionale' ), '/' );
?>
<section class="page"><div class="wrap" style="max-width:640px">
	<h1>Grazie! L'ordine per <?php echo e( $org ); ?> è registrato</h1>
	<ol class="steps-list">
		<li><b>Controlla la posta.</b> Ti abbiamo scritto a <b><?php echo e( $email ); ?></b> con le indicazioni per scegliere la password.</li>
		<li><b>Tra pochi minuti il gestionale è pronto.</b> Te lo diciamo con una seconda email.</li>
		<li><b>Entra da <a href="<?php echo e( $app ); ?>"><?php echo e( $app ); ?></a></b> e segui la configurazione guidata.</li>
	</ol>
	<p class="muted">Non trovi l'email? Guarda nella posta indesiderata, oppure <a href="<?php echo e( Portale\SupportSso::supportUrl() ); ?>">scrivici dal supporto</a>.</p>
	<p><a class="btn" href="/account">Vai al tuo account</a></p>
</div></section>
