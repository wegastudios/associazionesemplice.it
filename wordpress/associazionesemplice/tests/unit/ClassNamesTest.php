<?php
use PHPUnit\Framework\TestCase;

/**
 * Un nome di classe scritto senza le barre (AssociazioneSempliceCsrf::field al posto di \AssociazioneSemplice\Csrf::field) è un errore fatale
 * che si vede solo quando quel punto del codice viene eseguito: qui si cerca in tutti i sorgenti.
 */
final class ClassNamesTest extends TestCase {

	public function test_no_class_name_lost_its_backslashes(): void {
		$root = dirname( __DIR__, 2 );
		$bad  = array();
		$it   = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ) );
		foreach ( $it as $f ) {
			$path = str_replace( '\\', '/', (string) $f );
			if ( '.php' !== substr( $path, -4 ) || false !== strpos( $path, '/tests/' ) || false !== strpos( $path, '/vendor/' ) ) {
				continue;
			}
			foreach ( file( $path ) as $n => $line ) {
				if ( preg_match( '/(?<![\\\\\w])AssociazioneSemplice[A-Z]\w*(::|\()/', $line, $m ) ) {
					$bad[] = substr( $path, strlen( $root ) ) . ':' . ( $n + 1 ) . ' ' . $m[0];
				}
			}
		}
		$this->assertSame( array(), $bad, "Nomi di classe senza le barre rovesciate:\n" . implode( "\n", $bad ) );
	}
}
