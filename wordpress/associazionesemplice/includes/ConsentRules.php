<?php
namespace AssociazioneSemplice;

defined( 'ABSPATH' ) || exit;

/** Consensi ai canali facoltativi (SMS e newsletter): regole pure. Il consenso è per persona e per canale, e si può revocare. */
final class ConsentRules {

	const SMS        = 'sms';
	const NEWSLETTER = 'newsletter';

	const SOURCES = array(
		'paper' => 'Modulo cartaceo',
		'oral'  => 'A voce',
		'web'   => 'Dall\'area soci',
		'admin' => 'Registrato dalla segreteria',
	);

	public static function channels(): array {
		return array(
			self::SMS        => 'Messaggi SMS',
			self::NEWSLETTER => 'Newsletter',
		);
	}

	public static function is_channel( string $channel ): bool {
		return isset( self::channels()[ $channel ] );
	}

	/** Il consenso vale solo se è stato dato e non è stato revocato dopo. */
	public static function is_granted( ?array $row ): bool {
		if ( ! $row || empty( $row['granted_at'] ) ) {
			return false;
		}
		return empty( $row['revoked_at'] ) || strcmp( (string) $row['revoked_at'], (string) $row['granted_at'] ) < 0;
	}

	public static function source( string $source ): string {
		return isset( self::SOURCES[ $source ] ) ? $source : 'admin';
	}

	/** Frase per l'interessato, da mostrare accanto alla casella. */
	public static function notice( string $channel, string $association ): string {
		$who = '' !== $association ? $association : 'l\'associazione';
		if ( self::SMS === $channel ) {
			return 'Acconsento a ricevere SMS da ' . $who . ' (avvisi, scadenze, comunicazioni ai soci). Posso revocare il consenso in qualsiasi momento.';
		}
		return 'Acconsento a ricevere la newsletter di ' . $who . ' all\'indirizzo email indicato. Posso revocare il consenso in qualsiasi momento.';
	}
}
