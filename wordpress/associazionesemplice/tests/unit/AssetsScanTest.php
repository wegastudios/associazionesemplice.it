<?php
use AssociazioneSemplice\Frontend\Assets;
use PHPUnit\Framework\TestCase;

final class AssetsScanTest extends TestCase {

	public function test_a_text_without_the_plugin_is_left_alone(): void {
		$this->assertSame( array( 'found' => false, 'private' => false ), Assets::scan( 'Ciao [gallery] e <!-- wp:paragraph --><p>x</p><!-- /wp:paragraph -->' ) );
		$this->assertSame( array( 'found' => false, 'private' => false ), Assets::scan( '' ) );
	}

	public function test_public_views_are_found_but_not_private(): void {
		$r = Assets::scan( '[associazionesemplice_eventi limite="5"] [associazionesemplice_prossimi_eventi] [associazionesemplice_evento_dati evento="3"]' );
		$this->assertTrue( $r['found'] );
		$this->assertFalse( $r['private'] );
	}

	public function test_member_views_and_forms_are_private(): void {
		foreach ( array( 'area_soci', 'tessera', 'mie_attivita', 'adesione', 'evento_prenotazione', 'riservato', 'accesso', 'vista_di_un_estensione' ) as $view ) {
			$r = Assets::scan( 'Testo [associazionesemplice_' . $view . '] testo' );
			$this->assertTrue( $r['found'] && $r['private'], $view );
		}
	}

	public function test_one_private_view_among_public_ones_makes_the_page_private(): void {
		$r = Assets::scan( '[associazionesemplice_eventi] [associazionesemplice_pagamenti]' );
		$this->assertTrue( $r['private'] );
	}

	public function test_an_escaped_shortcode_is_not_a_shortcode(): void {
		$r = Assets::scan( '[[associazionesemplice_area_soci]]' );
		$this->assertFalse( $r['found'] );
	}

	public function test_blocks_are_read_with_their_view(): void {
		$this->assertFalse( Assets::scan( '<!-- wp:associazionesemplice/vista {"vista":"eventi","limite":"5"} /-->' )['private'] );
		$this->assertTrue( Assets::scan( '<!-- wp:associazionesemplice/vista {"vista":"tessera"} /-->' )['private'] );
		$this->assertTrue( Assets::scan( '<!-- wp:associazionesemplice/vista /-->' )['private'], 'senza scelta è l\'area soci' );
		$this->assertTrue( Assets::scan( '<!-- wp:associazionesemplice/riservato {"accesso":"soci"} --><p>x</p><!-- /wp:associazionesemplice/riservato -->' )['private'] );
		$this->assertTrue( Assets::scan( '<!-- wp:associazionesemplice/vista {"vista":"eventi"} /--><!-- wp:associazionesemplice/vista {"vista":"profilo"} /-->' )['private'] );
	}

	public function test_elementor_widgets_are_read_from_their_data(): void {
		$public = '[{"widgetType":"associazionesemplice_view","settings":{"view":"eventi"}}]';
		$this->assertSame( array( 'found' => true, 'private' => false ), Assets::scan( $public ) );
		$this->assertTrue( Assets::scan( '[{"widgetType":"associazionesemplice_view","settings":[]}]' )['private'], 'senza scelta è l\'area soci' );
		$this->assertTrue( Assets::scan( '[{"widgetType":"associazionesemplice_view","settings":{"view":"eventi"}},{"widgetType":"associazionesemplice_view","settings":[]}]' )['private'] );
		$this->assertTrue( Assets::scan( '[{"widgetType":"associazionesemplice_reserved","settings":{}}]' )['private'] );
	}
}
