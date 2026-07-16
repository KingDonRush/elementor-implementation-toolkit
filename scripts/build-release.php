<?php
/**
 * Builds and verifies a deterministic runtime-only plugin archive.
 */

$root = realpath( dirname( __DIR__ ) );
date_default_timezone_set( 'UTC' );
if ( false === $root ) {
	fwrite( STDERR, "Unable to resolve plugin root.\n" );
	exit( 1 );
}
$main = file_get_contents( $root . '/elementor-implementation-toolkit.php' );
if ( false === $main || ! preg_match( "/define\( 'EIT_VERSION', '([^']+)' \);/", $main, $match ) ) {
	fwrite( STDERR, "Unable to resolve EIT_VERSION.\n" );
	exit( 1 );
}
$version = $match[1];
$slug = 'elementor-implementation-toolkit';
$dist = $root . '/dist';
if ( ! is_dir( $dist ) && ! mkdir( $dist, 0775, true ) ) {
	fwrite( STDERR, "Unable to create dist directory.\n" );
	exit( 1 );
}
$archive_path = $dist . '/' . $slug . '-' . $version . '.zip';
if ( file_exists( $archive_path ) && ! unlink( $archive_path ) ) {
	fwrite( STDERR, "Unable to replace existing archive.\n" );
	exit( 1 );
}

$root_files = [ 'elementor-implementation-toolkit.php', 'uninstall.php', 'readme.txt', 'readme.md' ];
$public_docs = [ 'docs/compatibility.md', 'docs/upgrade-guide.md', 'docs/uninstall.md' ];
$runtime_dirs = [ 'includes', 'templates', 'assets/css', 'assets/js', 'assets/images', 'languages' ];
$files = [];
foreach ( array_merge( $root_files, $public_docs ) as $relative ) {
	if ( is_file( $root . '/' . $relative ) ) {
		$files[] = $relative;
	}
}
foreach ( $runtime_dirs as $directory ) {
	$path = $root . '/' . $directory;
	if ( ! is_dir( $path ) ) {
		continue;
	}
	$iterator = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $path, FilesystemIterator::SKIP_DOTS ) );
	foreach ( $iterator as $file ) {
		if ( $file->isFile() ) {
			$files[] = str_replace( '\\', '/', substr( $file->getPathname(), strlen( $root ) + 1 ) );
		}
	}
}
$files = array_values( array_unique( $files ) );
sort( $files, SORT_STRING );

$zip = new ZipArchive();
if ( true !== $zip->open( $archive_path, ZipArchive::CREATE | ZipArchive::EXCL ) ) {
	fwrite( STDERR, "Unable to create release archive.\n" );
	exit( 1 );
}
$fixed_time = gmmktime( 0, 0, 0, 1, 1, 2026 );
foreach ( $files as $relative ) {
	$name = $slug . '/' . $relative;
	if ( ! $zip->addFile( $root . '/' . $relative, $name ) ) {
		$zip->close();
		fwrite( STDERR, 'Unable to add ' . $relative . ".\n" );
		exit( 1 );
	}
	$zip->setMtimeName( $name, $fixed_time );
	$zip->setCompressionName( $name, ZipArchive::CM_DEFLATE, 9 );
}
$zip->setArchiveComment( 'Elementor Implementation Toolkit ' . $version );
$zip->close();

$verify = new ZipArchive();
if ( true !== $verify->open( $archive_path ) ) {
	fwrite( STDERR, "Unable to verify release archive.\n" );
	exit( 1 );
}
$names = [];
for ( $index = 0; $index < $verify->numFiles; ++$index ) {
	$names[] = $verify->getNameIndex( $index );
}
$verify->close();
$required = array_map( fn( $file ) => $slug . '/' . $file, array_merge( $root_files, [ 'templates/route.php' ] ) );
$forbidden = [ '/tests/', '/scripts/', '/assets/src/', '/assets/design/', '/vendor/', '/node_modules/', '/.git/', '.map' ];
$errors = array_diff( $required, $names );
foreach ( $names as $name ) {
	if ( 0 !== strpos( $name, $slug . '/' ) ) {
		$errors[] = 'invalid-root:' . $name;
	}
	foreach ( $forbidden as $fragment ) {
		if ( false !== strpos( $name, $fragment ) ) {
			$errors[] = 'forbidden:' . $name;
		}
	}
}
if ( $errors ) {
	unlink( $archive_path );
	fwrite( STDERR, "Release archive failed verification:\n- " . implode( "\n- ", $errors ) . "\n" );
	exit( 1 );
}

$checksum = hash_file( 'sha256', $archive_path );
file_put_contents( $dist . '/SHA256SUMS', $checksum . '  ' . basename( $archive_path ) . "\n" );
printf( "Built %s\nFiles: %d\nBytes: %d\nSHA-256: %s\n", $archive_path, count( $names ), filesize( $archive_path ), $checksum );
