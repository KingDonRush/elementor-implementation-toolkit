<?php
/**
 * Checks authored plugin files against modularization line budgets.
 *
 * Usage:
 * php scripts/verify-line-budget.php
 * php scripts/verify-line-budget.php --strict
 */

$root = realpath( dirname( __DIR__ ) );

if ( false === $root ) {
	fwrite( STDERR, "Unable to resolve plugin root.\n" );
	exit( 1 );
}

$strict = in_array( '--strict', $argv, true );

$extensions = [
	'php' => true,
	'js'  => true,
	'css' => true,
];

$excluded_dirs = [
	'.git'         => true,
	'build'        => true,
	'dist'         => true,
	'node_modules' => true,
	'vendor'       => true,
];

$tracked_debt = [
	'assets/css/eit-admin.css'                                      => 'TASK-020',
	'assets/css/eit-frontend.css'                                   => 'TASK-023',
	'assets/js/eit-editor.js'                                       => 'TASK-021',
	'assets/js/eit-frontend.js'                                     => 'TASK-022',
	'includes/Admin/CptManagerAdmin.php'                            => 'TASK-024',
	'includes/Admin/FilterPresetAdmin.php'                          => 'TASK-019',
	'includes/CPT/CptManager.php'                                   => 'TASK-024',
	'includes/Elementor/FilterController/ContentControls.php'       => 'TASK-026',
	'includes/Support/FilterPresets.php'                            => 'TASK-025',
	'includes/Support/FilterResolver.php'                           => 'TASK-025',
	'scripts/verify-filter-controller-robustness.php'               => 'TASK-027',
];

function eit_line_budget_relative_path( $root, $path ) {
	return str_replace( DIRECTORY_SEPARATOR, '/', substr( $path, strlen( $root ) + 1 ) );
}

function eit_line_budget_count_lines( $path ) {
	$contents = file_get_contents( $path );

	if ( false === $contents || '' === $contents ) {
		return 0;
	}

	return substr_count( $contents, "\n" ) + ( "\n" === substr( $contents, -1 ) ? 0 : 1 );
}

function eit_line_budget_risk( $lines ) {
	if ( $lines >= 1200 ) {
		return 'critical';
	}

	if ( $lines >= 800 ) {
		return 'high';
	}

	if ( $lines >= 450 ) {
		return 'blocking';
	}

	if ( $lines >= 400 ) {
		return 'review';
	}

	return 'ok';
}

function eit_line_budget_domain( $path ) {
	if ( 0 === strpos( $path, 'includes/Admin/' ) ) {
		return 'Admin';
	}

	if ( 0 === strpos( $path, 'includes/CPT/' ) ) {
		return 'CPT';
	}

	if ( 0 === strpos( $path, 'includes/Rest/' ) ) {
		return 'REST';
	}

	if ( 0 === strpos( $path, 'includes/Elementor/' ) ) {
		return 'Elementor';
	}

	if ( 0 === strpos( $path, 'includes/Support/' ) ) {
		return 'Support';
	}

	if ( 0 === strpos( $path, 'includes/Core/' ) ) {
		return 'Core';
	}

	if ( 0 === strpos( $path, 'assets/css/' ) ) {
		return 'CSS assets';
	}

	if ( 0 === strpos( $path, 'assets/js/' ) ) {
		return 'JS assets';
	}

	if ( 0 === strpos( $path, 'scripts/' ) ) {
		return 'Scripts';
	}

	return 'Other';
}

function eit_line_budget_scan( $root, array $extensions, array $excluded_dirs ) {
	$directory = new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS );
	$filter    = new RecursiveCallbackFilterIterator(
		$directory,
		function ( $current ) use ( $excluded_dirs ) {
			if ( $current->isDir() ) {
				return ! isset( $excluded_dirs[ $current->getFilename() ] );
			}

			return true;
		}
	);
	$iterator  = new RecursiveIteratorIterator( $filter );
	$records   = [];

	foreach ( $iterator as $file ) {
		if ( ! $file->isFile() ) {
			continue;
		}

		$extension = strtolower( $file->getExtension() );

		if ( ! isset( $extensions[ $extension ] ) ) {
			continue;
		}

		$path  = eit_line_budget_relative_path( $root, $file->getPathname() );
		$lines = eit_line_budget_count_lines( $file->getPathname() );

		$records[] = [
			'path'   => $path,
			'lines'  => $lines,
			'risk'   => eit_line_budget_risk( $lines ),
			'domain' => eit_line_budget_domain( $path ),
		];
	}

	usort(
		$records,
		function ( $a, $b ) {
			return $b['lines'] <=> $a['lines'];
		}
	);

	return $records;
}

$records        = eit_line_budget_scan( $root, $extensions, $excluded_dirs );
$untracked_debt = [];
$counts         = [
	'critical' => 0,
	'high'     => 0,
	'blocking' => 0,
	'review'   => 0,
	'ok'       => 0,
];

echo "Elementor Implementation Toolkit line-budget report\n";
echo "Target: 200-400 lines. New untracked debt >=450 lines fails.\n\n";
printf( "%7s | %-9s | %-10s | %-11s | %s\n", 'Lines', 'Risk', 'Domain', 'Tracking', 'Path' );
echo str_repeat( '-', 96 ) . "\n";

foreach ( $records as $record ) {
	$counts[ $record['risk'] ]++;

	if ( 'ok' === $record['risk'] ) {
		continue;
	}

	$tracking = $tracked_debt[ $record['path'] ] ?? '';

	if ( '' === $tracking && ( $record['lines'] >= 450 || ( $strict && $record['lines'] >= 400 ) ) ) {
		$untracked_debt[] = $record;
		$tracking = 'MISSING';
	}

	printf(
		"%7d | %-9s | %-10s | %-11s | %s\n",
		$record['lines'],
		$record['risk'],
		$record['domain'],
		'' === $tracking ? 'review' : $tracking,
		$record['path']
	);
}

echo "\nSummary:\n";
foreach ( $counts as $risk => $count ) {
	printf( "- %-9s %d\n", $risk . ':', $count );
}

if ( [] !== $untracked_debt ) {
	fwrite( STDERR, "\nUntracked line-budget debt found. Create a split task before adding feature work here.\n" );
	exit( 1 );
}

echo "\nNo untracked blocking line-budget debt found.\n";
exit( 0 );
