<?php
namespace AssociazioneSemplice;

defined( 'ABSPATH' ) || exit;

/**
 * Icone in linea (Lucide, licenza ISC, https://lucide.dev): solo quelle usate, nessuna richiesta esterna.
 * Tratto e angoli arrotondati si decidono da CSS (.asem-icon). Sono decorative: la parola sta sempre accanto.
 */
final class Icons {

	/** Contenuto SVG (viewBox 24) di ogni icona. */
	const SHAPES = array(
		'id-card'       => '<path d="M16 10h2"/><path d="M16 14h2"/><path d="M6.17 15a3 3 0 0 1 5.66 0"/><circle cx="9" cy="11" r="2"/><rect x="2" y="5" width="20" height="14" rx="2"/>',
		'calendar'      => '<path d="M8 2v4"/><path d="M16 2v4"/><rect x="3" y="4" width="18" height="18" rx="2"/><path d="M3 10h18"/>',
		'wallet'        => '<path d="M19 7V4a1 1 0 0 0-1-1H5a2 2 0 0 0 0 4h15a1 1 0 0 1 1 1v4h-3a2 2 0 0 0 0 4h3a1 1 0 0 0 1-1v-2a1 1 0 0 0-1-1"/><path d="M3 5v14a2 2 0 0 0 2 2h15a1 1 0 0 0 1-1v-4"/>',
		'search'        => '<circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/>',
		'chevron-right' => '<path d="m9 18 6-6-6-6"/>',
		'ellipsis'      => '<circle cx="12" cy="12" r="1"/><circle cx="19" cy="12" r="1"/><circle cx="5" cy="12" r="1"/>',
		'plus'          => '<path d="M5 12h14"/><path d="M12 5v14"/>',
		'check'         => '<path d="M20 6 9 17l-5-5"/>',
		'x'             => '<path d="M18 6 6 18"/><path d="m6 6 12 12"/>',
		'user-round'    => '<circle cx="12" cy="8" r="5"/><path d="M20 21a8 8 0 0 0-16 0"/>',
		'triangle-alert' => '<path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3"/><path d="M12 9v4"/><path d="M12 17h.01"/>',
	);

	/** SVG di un'icona; stringa vuota se il nome non esiste. Da stampare con wp_kses( …, Html::allowed() ). */
	public static function svg( string $name ): string {
		if ( ! isset( self::SHAPES[ $name ] ) ) {
			return '';
		}
		return '<svg class="asem-icon" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" focusable="false" aria-hidden="true">' . self::SHAPES[ $name ] . '</svg>';
	}
}
