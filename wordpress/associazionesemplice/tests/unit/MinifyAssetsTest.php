<?php
use PHPUnit\Framework\TestCase;

/** La riduzione di frontend.css e frontend.js in build.sh (wordpress/minify-assets.pl, senza Node): non cambia il codice, toglie solo il superfluo. */
final class MinifyAssetsTest extends TestCase {

	private function tool(): string {
		return dirname( __DIR__, 3 ) . '/minify-assets.pl';
	}

	/** @return string|null il file ridotto, null se lo strumento rifiuta il file */
	private function run_tool( string $kind, string $source ): ?string {
		if ( ! is_file( $this->tool() ) || '' === trim( (string) shell_exec( 'command -v perl 2>/dev/null' ) ) ) {
			$this->markTestSkipped( 'perl non disponibile' );
		}
		$in  = tempnam( sys_get_temp_dir(), 'asemin' );
		$out = $in . '.out';
		file_put_contents( $in, $source );
		exec( 'perl ' . escapeshellarg( $this->tool() ) . ' ' . $kind . ' ' . escapeshellarg( $in ) . ' ' . escapeshellarg( $out ) . ' 2>&1', $lines, $code );
		$res = 0 === $code && is_file( $out ) ? (string) file_get_contents( $out ) : null;
		@unlink( $in );
		@unlink( $out );
		return $res;
	}

	public function test_js_keeps_strings_regexes_and_divisions(): void {
		$src = "/* intestazione */\n(function () {\n\t'use strict';\n\t// un commento\n\tvar u = 'http://esempio.it/a//b'; // coda\n\tvar r = /^(\\d{4})\\/\\d+\$/.exec( u );\n\tvar d = total / 100 / 2;\n\tvar s = \"a  b\";\n\n\t/* due\n\t   righe */\n\treturn r;\n})();\n";
		$min = $this->run_tool( 'js', $src );
		$this->assertNotNull( $min );
		$this->assertStringContainsString( "'http://esempio.it/a//b'", $min, 'una stringa con // non è un commento' );
		$this->assertStringContainsString( '/^(\d{4})\/\d+$/.exec( u );', $min, 'la regex resta com\'è' );
		$this->assertStringContainsString( 'total / 100 / 2', $min, 'una divisione non è una regex' );
		$this->assertStringContainsString( '"a  b"', $min, 'gli spazi dentro le stringhe restano' );
		$this->assertStringNotContainsString( 'commento', $min );
		$this->assertStringNotContainsString( 'intestazione', $min );
		$this->assertStringNotContainsString( "\t", $min );
		$this->assertStringNotContainsString( "\n\n", $min );
		$this->assertStringContainsString( "\n", trim( $min ), 'gli a capo restano (niente rischi con il «;» automatico)' );
		$this->assertLessThan( strlen( $src ), strlen( $min ) );
	}

	public function test_css_keeps_strings_and_selectors_that_depend_on_spaces(): void {
		$src = "/* nota { } */\n.a :hover, .b > .c {\n\tcontent: attr(data-x) \": \";\n\tmargin: 0 0 .5em;\n\tcolor: rgba(1, 2, 3, .5);\n}\n@media (max-width: 700px) and (min-width: 300px) {\n\t.a { top: calc(1px + 2px); }\n}\n";
		$min = $this->run_tool( 'css', $src );
		$this->assertNotNull( $min );
		$this->assertStringContainsString( '.a :hover,.b > .c{', $min, 'lo spazio prima di :hover (discendente) resta' );
		$this->assertStringContainsString( 'content:attr(data-x) ": "', $min );
		$this->assertStringContainsString( 'margin:0 0 .5em', $min );
		$this->assertStringContainsString( 'rgba(1,2,3,.5)', $min );
		$this->assertStringContainsString( '@media (max-width:700px) and (min-width:300px){', $min );
		$this->assertStringContainsString( 'calc(1px + 2px)', $min, 'dentro calc() gli spazi attorno a + servono' );
		$this->assertStringNotContainsString( 'nota', $min );
		$this->assertStringNotContainsString( ';}', $min );
	}

	public function test_the_real_files_pass_the_tool_check(): void {
		foreach ( array( 'css' => 'frontend.css', 'js' => 'frontend.js' ) as $kind => $file ) {
			$src = (string) file_get_contents( dirname( __DIR__, 2 ) . '/assets/' . $file );
			$min = $this->run_tool( $kind, $src );
			$this->assertNotNull( $min, $file . ': lo strumento deve poter ridurre il file vero' );
			$this->assertLessThan( strlen( $src ) * 0.9, strlen( $min ), $file . ': almeno il 10% in meno' );
		}
	}

	public function test_build_uses_the_tool_and_falls_back_to_the_source(): void {
		$build = (string) file_get_contents( dirname( __DIR__, 3 ) . '/build.sh' );
		$this->assertStringContainsString( 'perl minify-assets.pl "$kind" "$asset" "$asset.min.$kind"', $build );
		$this->assertStringContainsString( 'resta com\'è (non ridotto)', $build, 'senza perl o con il controllo non superato resta il sorgente' );
	}

	public function test_script_is_deferred_only_where_wordpress_can(): void {
		$src = (string) file_get_contents( dirname( __DIR__, 2 ) . '/includes/Frontend/Assets.php' );
		$this->assertStringContainsString( "version_compare( (string) get_bloginfo( 'version' ), '6.3', '>=' )", $src );
		$this->assertStringContainsString( "array( 'strategy' => 'defer', 'in_footer' => true )", $src );
		$this->assertStringContainsString( 'self::script_args() );', $src );
	}
}
