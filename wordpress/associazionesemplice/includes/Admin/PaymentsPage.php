<?php
namespace AssociazioneSemplice\Admin;

use AssociazioneSemplice\Bank;
use AssociazioneSemplice\Iban;
use AssociazioneSemplice\Money;
use AssociazioneSemplice\PaymentConfig;
use AssociazioneSemplice\PaymentItems;
use AssociazioneSemplice\Plugin;
use AssociazioneSemplice\Settings;

defined( 'ABSPATH' ) || exit;

/** Elenco dei pagamenti online (Stripe / PayPal / negozio) con il loro stato e quelli da controllare; impostazioni dei pagamenti e del bonifico. */
final class PaymentsPage {

	const STATUS = array(
		'paid' => 'Pagato', 'pending' => 'In attesa di conferma', 'created' => 'Avviato', 'processing' => 'In registrazione',
		'cancelled' => 'Annullato dal socio', 'failed' => 'Non riuscito', 'expired' => 'Scaduto',
	);

	/** Righe di personalizzazione di un metodo di pagamento: dicitura del pulsante e nota sotto il pulsante. */
	private static function label_rows( string $gateway, string $name, array $s, string $class, string $hint ): string {
		return '<tr class="' . esc_attr( $class ) . '"><th>' . esc_html( $name ) . ' — pulsante</th><td><input type="text" name="pay_label_' . esc_attr( $gateway ) . '" value="' . esc_attr( (string) $s[ 'pay_label_' . $gateway ] ) . '" class="regular-text" maxlength="60" placeholder="' . esc_attr( PaymentConfig::DEFAULT_LABELS[ $gateway ] ) . '"> '
			. '<p class="description">La scritta del pulsante che vedono i soci. Vuoto = «' . esc_html( PaymentConfig::DEFAULT_LABELS[ $gateway ] ) . '».' . ( '' !== $hint ? ' ' . esc_html( $hint ) : '' ) . '</p></td></tr>'
			. '<tr class="' . esc_attr( $class ) . '"><th>' . esc_html( $name ) . ' — nota</th><td><input type="text" name="pay_note_' . esc_attr( $gateway ) . '" value="' . esc_attr( (string) $s[ 'pay_note_' . $gateway ] ) . '" class="large-text" maxlength="300" placeholder="' . esc_attr( PaymentConfig::note( $gateway ) ) . '">'
			. '<p class="description">La frase sotto il pulsante. Vuoto = la frase standard.</p></td></tr>';
	}

	/** Costi di incasso di un metodo: commissione fissa e/o percentuale, e se ribaltarla sull'operazione (spento di default). */
	private static function fee_rows( string $gateway, string $name, array $s, string $class ): string {
		$fixed = (int) ( $s[ 'pay_fee_fixed_' . $gateway ] ?? 0 );
		$pct   = (int) ( $s[ 'pay_fee_pct_' . $gateway ] ?? 0 );
		return '<tr class="' . esc_attr( $class ) . '"><th>' . esc_html( $name ) . ' — commissioni</th><td>'
			. 'fissa <input type="text" name="pay_fee_fixed_' . esc_attr( $gateway ) . '" value="' . esc_attr( $fixed > 0 ? Money::plain( $fixed ) : '' ) . '" size="5" inputmode="decimal" placeholder="0,25"> € '
			. '+ <input type="text" name="pay_fee_pct_' . esc_attr( $gateway ) . '" value="' . esc_attr( $pct > 0 ? rtrim( rtrim( number_format( $pct / 100, 2, ',', '' ), '0' ), ',' ) : '' ) . '" size="4" inputmode="decimal" placeholder="1,5"> % per ogni pagamento'
			. '<p class="description">Quanto ti costa incassare con questo metodo. I valori di partenza sono le tariffe standard pubblicate dal gestore (carte europee standard per Stripe, vendite nazionali per PayPal, pagamenti online per SumUp): confrontali con il tuo contratto. Svuota i campi se non vuoi indicarli.</p>'
			. '<label><input type="checkbox" name="pay_fee_pass_' . esc_attr( $gateway ) . '" value="1"' . checked( ! empty( $s[ 'pay_fee_pass_' . $gateway ] ), true, false ) . '> Ribalta la commissione su chi paga, in automatico</label>'
			. '<p class="description">Con la spunta il totale da pagare aumenta di quanto serve a coprire la commissione e il socio la vede indicata sotto il pulsante. <strong>Attenzione:</strong> in Italia e nell\'Unione europea è vietato far pagare un supplemento a chi paga con carta (e con alcuni bonifici/addebiti) se è un consumatore (direttiva PSD2, D.Lgs. 11/2010): la spunta è spenta di partenza e usarla è una scelta e una responsabilità dell\'associazione. Se non sei sicuro lasciala spenta.</p></td></tr>';
	}

	/** Impostazioni dei pagamenti online (spenti di default: "Nessuno"). */
	private static function settings_card(): void {
		$s = \AssociazioneSemplice\Settings::all();
		echo '<div class="asem-card"><h2>Come incassare online</h2>';
		Ui::form_open( 'asem_save_payment_settings', Ui::url( 'asem-payments' ) );
		echo '<table class="form-table"><tbody>';
		echo '<tr><th>Come incassare online</th><td><select name="payment_provider" id="asem-pay-provider">' . Ui::options( PaymentConfig::providers(), $s['payment_provider'] ) . '</select>' // phpcs:ignore WordPress.Security.EscapeOutput
			. '<p class="description"><strong>Nessuno</strong> disattiva i pagamenti online: i soci vedono cosa devono e, se lo attivi più sotto, le coordinate per il bonifico. '
			. '<strong>WooCommerce</strong> è alternativo a Stripe e PayPal. <strong>Stripe e PayPal insieme</strong> mostrano due pulsanti, con le diciture che scegli: utile ad esempio per offrire il pagamento a rate di PayPal accanto alla carta. '
			. 'Con Stripe o PayPal i soci pagano su una <strong>pagina ospitata dal gateway</strong> (i dati della carta non passano dal sito) e l\'incasso entra da solo in prima nota sul conto "Stripe" o "PayPal". Prima di usarli in produzione verifica il funzionamento con le chiavi di prova.</p></td></tr>';
		echo self::label_rows( PaymentConfig::STRIPE, 'Stripe', $s, 'asem-pay-stripe', 'Ad esempio «Paga con carta».' ); // phpcs:ignore WordPress.Security.EscapeOutput
		echo self::label_rows( PaymentConfig::PAYPAL, 'PayPal', $s, 'asem-pay-paypal', 'Se il tuo account PayPal offre il pagamento a rate puoi scrivere ad esempio «Paga a rate con PayPal» e spiegare nella nota come funziona.' ); // phpcs:ignore WordPress.Security.EscapeOutput
		echo self::label_rows( PaymentConfig::SUMUP, 'SumUp', $s, 'asem-pay-sumup', 'Ad esempio «Paga con carta (SumUp)».' ); // phpcs:ignore WordPress.Security.EscapeOutput
		echo self::label_rows( PaymentConfig::WOOCOMMERCE, 'Negozio', $s, 'asem-pay-woocommerce', '' ); // phpcs:ignore WordPress.Security.EscapeOutput
		echo SettingsPage::gateway_row( 'stripe_mode', 'Stripe — modalità', $s, 'select', array( 'test' => 'Prova (test)', 'live' => 'Reale (live)' ), 'asem-pay-stripe' ); // phpcs:ignore WordPress.Security.EscapeOutput
		echo SettingsPage::gateway_row( 'stripe_publishable_key', 'Stripe — chiave pubblicabile', $s, 'text', array(), 'asem-pay-stripe', 'pk_test_… / pk_live_…' ); // phpcs:ignore WordPress.Security.EscapeOutput
		echo SettingsPage::secret_row( 'stripe_secret_key', 'Stripe — chiave segreta', 'asem-pay-stripe', 'sk_test_… / sk_live_… (o rk_… con restrizioni)' ); // phpcs:ignore WordPress.Security.EscapeOutput
		echo SettingsPage::secret_row( 'stripe_webhook_secret', 'Stripe — segreto del webhook', 'asem-pay-stripe', 'whsec_… (da Stripe → Sviluppatori → Webhook)' ); // phpcs:ignore WordPress.Security.EscapeOutput
		echo '<tr class="asem-pay-stripe"><th>Indirizzo del webhook</th><td><code>' . esc_html( rest_url( 'associazionesemplice/v1/webhooks/stripe' ) ) . '</code><p class="description">In Stripe (Sviluppatori → Webhook) aggiungi questo indirizzo con gli eventi <code>checkout.session.completed</code>, <code>checkout.session.async_payment_succeeded</code> e <code>checkout.session.expired</code>, poi incolla qui il segreto <code>whsec_…</code>. È una rete di sicurezza: il pagamento si conferma anche quando il socio torna sul sito e con un controllo automatico ogni ora.</p></td></tr>';
		echo '<tr class="asem-pay-paypal"><th>Conferma dei pagamenti</th><td><p class="description">PayPal non richiede configurazioni aggiuntive: il pagamento si conferma quando il socio torna sul sito e con un controllo automatico ogni ora.</p></td></tr>';
		echo SettingsPage::gateway_row( 'paypal_mode', 'PayPal — modalità', $s, 'select', array( 'sandbox' => 'Prova (sandbox)', 'live' => 'Reale (live)' ), 'asem-pay-paypal' ); // phpcs:ignore WordPress.Security.EscapeOutput
		echo SettingsPage::gateway_row( 'paypal_client_id', 'PayPal — Client ID', $s, 'text', array(), 'asem-pay-paypal', '' ); // phpcs:ignore WordPress.Security.EscapeOutput
		echo SettingsPage::secret_row( 'paypal_client_secret', 'PayPal — Client Secret', 'asem-pay-paypal', '' ); // phpcs:ignore WordPress.Security.EscapeOutput
		echo SettingsPage::gateway_row( 'sumup_merchant_code', 'SumUp — codice commerciante', $s, 'text', array(), 'asem-pay-sumup', 'MABCD123 (da SumUp → Profilo)' ); // phpcs:ignore WordPress.Security.EscapeOutput
		echo SettingsPage::secret_row( 'sumup_api_key', 'SumUp — chiave API', 'asem-pay-sumup', 'sup_sk_… (da SumUp → Sviluppatori → Chiavi API)' ); // phpcs:ignore WordPress.Security.EscapeOutput
		echo '<tr class="asem-pay-sumup"><th>Conferma dei pagamenti</th><td><p class="description">SumUp non richiede webhook: il pagamento si conferma quando il socio torna sul sito e con un controllo automatico ogni ora.</p></td></tr>';
		echo self::fee_rows( PaymentConfig::STRIPE, 'Stripe', $s, 'asem-pay-stripe' ); // phpcs:ignore WordPress.Security.EscapeOutput
		echo self::fee_rows( PaymentConfig::PAYPAL, 'PayPal', $s, 'asem-pay-paypal' ); // phpcs:ignore WordPress.Security.EscapeOutput
		echo self::fee_rows( PaymentConfig::SUMUP, 'SumUp', $s, 'asem-pay-sumup' ); // phpcs:ignore WordPress.Security.EscapeOutput
		echo '<tr><th>Commissioni in prima nota</th><td><label><input type="checkbox" name="pay_fee_book" value="1"' . checked( ! empty( $s['pay_fee_book'] ), true, false ) . '> Registra come spesa la commissione stimata a ogni incasso online</label><p class="description">A ogni pagamento confermato si registra la spesa «Commissione di incasso» calcolata dai valori che hai indicato sopra (fissa + percentuale sul totale addebitato), sul conto del metodo usato. È una stima: quando arriva l\'estratto conto del gestore correggi la spesa se la cifra vera è diversa. Non vale per le domande di adesione.</p></td></tr>';
		if ( PaymentConfig::NONE !== $s['payment_provider'] ) {
			$check = PaymentConfig::validate( \AssociazioneSemplice\ProSettings::payment_config() );
			foreach ( $check['errors'] as $e ) {
				echo '<tr><th></th><td class="asem-neg">⚠ ' . esc_html( $e ) . '</td></tr>';
			}
			foreach ( $check['warnings'] as $w ) {
				echo '<tr><th></th><td class="asem-warn">ℹ ' . esc_html( $w ) . '</td></tr>';
			}
		}
		echo '<tr><th>Sicurezza delle chiavi</th><td><p class="description">Le chiavi segrete sono salvate <strong>cifrate</strong> nel database e non vengono mai mostrate né scritte nel registro azioni: si inseriscono qui e basta, senza toccare file. '
			. 'La cifratura è legata a questo sito: se copi il database su un altro sito (ad esempio lo staging) le chiavi non vi sono leggibili e vanno reinserite. Questo impedisce a un sito di prova di usare per errore le chiavi reali. Usa le chiavi di prova finché non hai verificato il funzionamento.</p></td></tr>';
		foreach ( \AssociazioneSemplice\Settings::secret_keys() as $sk ) {
			if ( Settings::secret_unreadable( $sk ) ) {
				echo '<tr><th></th><td class="asem-warn">⚠ Una chiave è salvata ma non è leggibile su questo sito: reinseriscila.</td></tr>';
				break;
			}
		}
		echo '</tbody></table>';
		submit_button( 'Salva' );
		Ui::form_close();
		echo '<h2>Prova di connessione</h2><p class="description">Usa le chiavi già salvate (salva prima le impostazioni). Non muove denaro: Stripe legge il saldo, PayPal chiede un token di accesso, SumUp legge il profilo dell\'account.</p><div style="display:flex;gap:12px;flex-wrap:wrap">';
		foreach ( array( PaymentConfig::STRIPE => 'Verifica connessione Stripe', PaymentConfig::PAYPAL => 'Verifica connessione PayPal', PaymentConfig::SUMUP => 'Verifica connessione SumUp' ) as $prov => $label ) {
			Ui::form_open( 'asem_test_gateway', Ui::url( 'asem-payments' ) );
			echo Ui::hidden( 'provider', $prov ) . '<button class="button">' . esc_html( $label ) . '</button>'; // phpcs:ignore WordPress.Security.EscapeOutput
			Ui::form_close();
		}
		echo '</div>';
		echo '</div>';
	}

	public static function render(): void {
		$status = Ui::get_str( 'status' );
		$review = '1' === Ui::get_str( 'review' );
		$rows   = Plugin::payments()->list( array( 'status' => $status, 'review' => $review ), 200 );
		$svc    = Plugin::payments();

		Ui::header( 'Impostazioni: pagamenti online' );
		self::settings_card();
		BankPage::card();
		echo '<h2>Pagamenti ricevuti</h2>';
		if ( ! $svc->enabled() ) {
			echo '<div class="notice notice-info inline"><p>I pagamenti online non sono attivi: scegli Stripe, PayPal o entrambi e inserisci le chiavi qui sopra. Nel frattempo i soci possono pagare in sede o, se lo attivi, con il bonifico.</p></div>';
		}
		echo '<form method="get" class="asem-filters"><input type="hidden" name="page" value="asem-payments"><select name="status">' . Ui::options( self::STATUS, $status, 'Tutti gli stati' ) . '</select> ' // phpcs:ignore WordPress.Security.EscapeOutput
			. '<label><input type="checkbox" name="review" value="1"' . checked( $review, true, false ) . '> Solo da controllare</label> <button class="button">Filtra</button></form>';
		Ui::form_open( 'asem_check_payments', Ui::url( 'asem-payments' ) );
		echo '<p><button class="button">Verifica i pagamenti in sospeso</button> <span class="description">Interroga il gateway sui pagamenti non ancora confermati (lo fa già da solo ogni ora).</span></p>';
		Ui::form_close();

		echo '<table class="widefat striped"><thead><tr><th>Data</th><th>Chi ha pagato</th><th>Metodo</th><th>Importo</th><th>Stato</th><th>Voci</th><th></th></tr></thead><tbody>';
		if ( ! $rows ) {
			echo '<tr><td colspan="7">Nessun pagamento.</td></tr>';
		}
		foreach ( $rows as $r ) {
			$items = array_map( array( PaymentItems::class, 'line_name' ), (array) json_decode( (string) $r['items'], true ) );
			$state = esc_html( self::STATUS[ $r['status'] ] ?? $r['status'] );
			if ( 'paid' === $r['status'] ) {
				$state = '<span class="asem-ok">' . $state . '</span>';
			} elseif ( in_array( $r['status'], array( 'failed', 'expired' ), true ) ) {
				$state = '<span class="asem-neg">' . $state . '</span>';
			}
			echo '<tr><td>' . esc_html( mysql2date( 'd/m/Y H:i', $r['created_at'] ) ) . '</td><td>' . esc_html( (string) $r['payer_name'] ) . '</td>'
				. '<td>' . esc_html( array( 'paypal' => 'PayPal', 'sumup' => 'SumUp', 'woocommerce' => 'WooCommerce' )[ $r['provider'] ] ?? 'Stripe' ) . '</td><td>' . esc_html( Money::format( (int) $r['amount_cents'] ) ) . '</td><td>' . $state // phpcs:ignore WordPress.Security.EscapeOutput
				. ( $r['review'] ? '<br><strong class="asem-warn">⚠ da controllare</strong>' : '' ) . ( ! empty( $r['refund_due'] ) ? '<br><strong class="asem-neg">↩ da rimborsare</strong> <span class="description">(domanda di adesione rifiutata: restituisci la quota dal pannello del gateway)</span>' : '' ) . ( $r['error'] ? '<br><span class="description">' . esc_html( $r['error'] ) . '</span>' : '' ) . '</td>'
				. '<td>' . esc_html( implode( '; ', $items ) ) . '<br><span class="description">' . esc_html( (string) $r['provider_ref'] ) . '</span></td><td>';
			if ( ! empty( $r['refund_due'] ) ) {
				Ui::form_open( 'asem_payment_refunded', Ui::url( 'asem-payments' ) );
				echo Ui::hidden( 'id', $r['id'] ) . '<button class="button button-small">Rimborsato</button>'; // phpcs:ignore WordPress.Security.EscapeOutput
				Ui::form_close();
			}
			if ( $r['review'] ) {
				Ui::form_open( 'asem_payment_reviewed', Ui::url( 'asem-payments' ) );
				echo Ui::hidden( 'id', $r['id'] ) . '<button class="button button-small">Controllato</button>'; // phpcs:ignore WordPress.Security.EscapeOutput
				Ui::form_close();
			}
			echo '</td></tr>';
		}
		echo '</tbody></table>';
		echo '<p class="description">I pagamenti riusciti sono già in <a href="' . esc_url( Ui::url( 'asem-ledger' ) ) . '">Prima nota</a> sul conto "Stripe" o "PayPal". '
			. 'Le commissioni del gateway si registrano da sole come spesa stimata (se lo hai scelto più sopra); il trasferimento sul conto corrente (payout) si registra a mano con un giroconto. I rimborsi si fanno dal pannello del gateway e si registrano come spesa. I bonifici arrivati sul conto si registrano a mano da «Nuovo incasso».</p>';
		Ui::footer();
	}
}
