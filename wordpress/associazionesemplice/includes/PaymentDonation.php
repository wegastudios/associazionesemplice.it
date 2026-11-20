<?php
namespace AssociazioneSemplice;

defined( 'ABSPATH' ) || exit;

/**
 * Donazione aggiunta al pagamento («Aggiungi X € per una donazione a …»): spenta di default, la propone l'ente con un importo suggerito
 * che chi paga può cambiare. È una voce di pagamento a sé: in prima nota va come «Erogazione liberale», ha una ricevuta sua e sulla
 * donazione non si ribalta la commissione di incasso.
 */
final class PaymentDonation {

	const MIN_CENTS = 100;
	const MAX_CENTS = 100000;

	/** La spunta compare: pagamenti online attivi, opzione accesa. Il checkout di WooCommerce non la supporta (le voci devono essere prodotti). */
	public static function enabled(): bool {
		return Edition::has( 'payments' ) && ! empty( Settings::get( 'donation_on' ) ) && PaymentConfig::WOOCOMMERCE !== (string) Settings::get( 'payment_provider' );
	}

	public static function suggested_cents(): int {
		return self::clamp( (int) Settings::get( 'donation_amount_cents' ) );
	}

	public static function clamp( int $cents ): int {
		return max( self::MIN_CENTS, min( self::MAX_CENTS, $cents ) );
	}

	/** Importo scritto da chi paga («5», «5,50») in centesimi; null se non è valido o fuori dai limiti. */
	public static function parse_amount( string $text ): ?int {
		$cents = Money::parse( $text );
		return null !== $cents && $cents >= self::MIN_CENTS && $cents <= self::MAX_CENTS ? $cents : null;
	}

	/** «È anche detraibile.» solo se l'ente lo ha dichiarato nelle impostazioni fiscali. */
	public static function deductible_note(): string {
		return ! empty( Settings::get( 'donation_deductible' ) ) ? 'È anche detraibile.' : '';
	}

	/** Voce di pagamento della donazione, intestata a chi paga. $purpose = causale (vuoto = «Donazione a <ente>»). */
	public static function item( array $payer, int $cents, string $purpose = '' ): array {
		$assoc = trim( (string) Settings::get( 'association_name' ) );
		$label = '' !== trim( $purpose ) ? trim( $purpose ) : ( '' !== $assoc ? 'Donazione a ' . $assoc : 'Donazione' );
		return array(
			'type'         => PaymentItems::DONATION,
			'person_id'    => (int) $payer['id'],
			'person_name'  => trim( (string) $payer['first_name'] . ' ' . (string) $payer['last_name'] ),
			'amount_cents' => $cents,
			'label'        => mb_substr( $label, 0, 120 ),
		);
	}

	/**
	 * La persona a cui intestare la donazione. Se l'email è già nel gestionale (socio o contatto) la donazione si lega alla sua scheda, che non si tocca;
	 * altrimenti nasce un contatto («ospite», non socio) con i dati scritti nel modulo, solo per la ricevuta.
	 *
	 * @param array $d first_name, last_name, email, tax_code?, address?, zip?, city?
	 */
	public static function donor( array $d ): array {
		$email = strtolower( trim( (string) $d['email'] ) );
		$found = Plugin::people()->find_by_email( $email );
		if ( $found ) {
			return $found;
		}
		$db  = Db::db();
		$now = Db::now();
		$nul = static function ( $v ) {
			$v = trim( (string) $v );
			return '' === $v ? null : $v;
		};
		$ok  = $db->insert(
			Db::t( 'people' ),
			array(
				'type'       => MemberType::GUEST,
				'first_name' => mb_substr( trim( (string) $d['first_name'] ), 0, 120 ),
				'last_name'  => mb_substr( trim( (string) $d['last_name'] ), 0, 120 ),
				'email'      => $email,
				'tax_code'   => $nul( $d['tax_code'] ?? '' ),
				'address'    => $nul( mb_substr( (string) ( $d['address'] ?? '' ), 0, 190 ) ),
				'zip'        => $nul( mb_substr( (string) ( $d['zip'] ?? '' ), 0, 12 ) ),
				'city'       => $nul( mb_substr( (string) ( $d['city'] ?? '' ), 0, 100 ) ),
				'joined_on'  => Db::today(),
				'created_at' => $now,
				'updated_at' => $now,
			)
		);
		if ( ! $ok ) {
			throw new \InvalidArgumentException( 'Non è stato possibile registrare i tuoi dati: riprova più tardi.' );
		}
		$id = (int) $db->insert_id;
		Audit::log( 'person.created', 'person', $id, array( 'type' => MemberType::GUEST, 'via' => 'donation' ) );
		$person = Plugin::people()->get( $id );
		if ( ! $person ) {
			throw new \InvalidArgumentException( 'Non è stato possibile registrare i tuoi dati: riprova più tardi.' );
		}
		return $person;
	}

	/** Il pagamento è una donazione dalla pagina pubblica (una sola voce, segnata come tale)? */
	public static function is_public( array $items ): bool {
		return 1 === count( $items ) && PaymentItems::DONATION === ( $items[0]['type'] ?? '' ) && ! empty( $items[0]['public'] );
	}

	/**
	 * Ringraziamento a chi ha donato, con la ricevuta in allegato (se la ricevuta non si può preparare, solo il ringraziamento).
	 * Se non c'è un indirizzo email non succede nulla: la donazione è già registrata.
	 */
	public static function thank( ?array $p, array $items ): void {
		$donor = $p ? Plugin::people()->get( (int) $p['payer_person_id'] ) : null;
		if ( ! $p || ! $donor || empty( $donor['email'] ) || ! is_email( (string) $donor['email'] ) ) {
			return;
		}
		$assoc  = trim( (string) Settings::get( 'association_name' ) );
		$amount = Money::format( (int) $p['amount_cents'] );
		$intro  = 'Ciao ' . $donor['first_name'] . ",\n\ngrazie per la donazione di " . $amount . ( '' !== $assoc ? ' a ' . $assoc : '' ) . ': il tuo aiuto ci serve davvero.';
		$subject = Texts::subject( 'Grazie per la tua donazione' );
		if ( empty( $p['review'] ) && Edition::has( 'receipts' ) ) {
			$last = Receipts::list_for_payer( (int) $donor['id'], 1, true );
			if ( $last ) {
				try {
					Receipts::email(
						(string) $last[0]['key'],
						static function ( array $r ) use ( $intro, $subject ): array {
							return array( $subject, $intro . "\n\nIn allegato trovi la ricevuta n. " . $r['number'] . " della tua donazione.\n\nGrazie ancora." );
						}
					);
					return;
				} catch ( \Throwable $e ) {
					Audit::log( 'payment.donation_receipt_mail_failed', 'payment', (int) $p['id'], array() );
				}
			}
		}
		Texts::mail( (string) $donor['email'], $subject, $intro . "\n\nTi scriveremo la ricevuta appena possibile.\n\nGrazie ancora." );
	}
}
