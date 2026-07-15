<?php
/**
 * Syntax-check every versioned PHP source without shell-specific path parsing.
 */

$root = dirname( __DIR__ );
$directories = [ $root . '/includes', $root . '/scripts', $root . '/tests/phpunit' ];
$files = [ $root . '/elementor-implementation-toolkit.php' ];

foreach ( $directories as $directory ) {
	if ( ! is_dir( $directory ) ) {
		continue;
	}

	$iterator = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $directory, FilesystemIterator::SKIP_DOTS ) );
	foreach ( $iterator as $file ) {
		if ( $file->isFile() && 'php' === strtolower( $file->getExtension() ) ) {
			$files[] = $file->getPathname();
		}
	}
}

sort( $files );
foreach ( $files as $file ) {
	$command = escapeshellarg( PHP_BINARY ) . ' -l ' . escapeshellarg( $file );
	exec( $command, $output, $exit_code );
	if ( 0 !== $exit_code ) {
		fwrite( STDERR, implode( PHP_EOL, $output ) . PHP_EOL );
		exit( $exit_code );
	}
	$output = [];
}

fwrite( STDOUT, sprintf( "Syntax OK: %d PHP files.\n", count( $files ) ) );
