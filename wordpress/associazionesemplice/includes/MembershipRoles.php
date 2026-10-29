<?php
namespace AssociazioneSemplice;

defined( 'ABSPATH' ) || exit;

/** Il tipo di tesseramento da comunicare all'ente di promozione sportiva (atleta, istruttore, dirigente…). */
final class MembershipRoles {

	/** @return array<string,string> chiave => etichetta */
	public static function labels(): array {
		return array(
			'athlete'    => 'Atleta',
			'instructor' => 'Istruttore / tecnico',
			'official'   => 'Dirigente',
			'president'  => 'Presidente',
			'councillor' => 'Consigliere',
			'referee'    => 'Arbitro / giudice',
			'volunteer'  => 'Volontario',
			'member'     => 'Socio (non praticante)',
		);
	}

	public static function label( ?string $key ): string {
		$all = self::labels();
		return isset( $all[ (string) $key ] ) ? $all[ (string) $key ] : '';
	}

	public static function is_valid( ?string $key ): bool {
		return isset( self::labels()[ (string) $key ] );
	}
}
