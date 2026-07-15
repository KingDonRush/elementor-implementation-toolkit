<?php
eit_fc_skip( 'TEST-FC-ROBUSTNESS-001', 'Elementor multi-click Style cadence QA', [ 'owner' => 'Guilherme', 'reason' => 'Requires editor screenshots and nuanced multi-click panel flow.' ] );
eit_fc_skip( 'TEST-FC-ROBUSTNESS-002', 'Desktop/mobile visual containment screenshots', [ 'owner' => 'Guilherme', 'reason' => 'Requires browser/editor visual hierarchy judgment.' ] );
eit_fc_skip( 'TEST-FC-ROBUSTNESS-006', 'Range visual variants screenshots', [ 'owner' => 'Guilherme', 'reason' => 'Requires visual QA for vertical labels, ticks, icon handle feel, and responsive layout.' ] );
eit_fc_skip( 'TEST-FC-ROBUSTNESS-007', 'Option filter visual matrix', [ 'owner' => 'Guilherme', 'reason' => 'Type-specific option visuals are not complete enough for a full visual pass.' ] );
eit_fc_skip( 'TEST-FC-ROBUSTNESS-008', 'Rating icon visual QA', [ 'owner' => 'Guilherme', 'reason' => 'Requires visual QA for rating icon choice, active state, spacing, and label balance.' ] );
eit_fc_skip( 'TEST-FC-ROBUSTNESS-010', 'Elementor fallback warning screenshot path', [ 'owner' => 'Guilherme', 'reason' => 'Requires normal and simulated fallback editor screenshots plus console notes.' ] );
eit_fc_skip( 'TEST-FC-ROBUSTNESS-011', 'Search visual and interaction QA', [ 'owner' => 'Guilherme', 'reason' => 'Requires editor/frontend visual QA for icon, clear button, focus state, and typing feel.' ] );
eit_fc_skip( 'TEST-FC-ROBUSTNESS-012', 'Select native picker visual QA', [ 'owner' => 'Guilherme', 'reason' => 'Requires editor/frontend QA for closed-field styling, long labels, browser picker behavior, and mobile picker feel.' ] );
eit_fc_skip( 'TEST-FC-ROBUSTNESS-013', 'Checkbox visual and keyboard QA', [ 'owner' => 'Guilherme', 'reason' => 'Requires editor/frontend QA for indicator feel, multiple checked states, keyboard focus, counts, wrapping, and mobile layout.' ] );
eit_fc_skip( 'TEST-FC-ROBUSTNESS-014', 'Radio visual and keyboard QA', [ 'owner' => 'Guilherme', 'reason' => 'Requires editor/frontend QA for single-choice clarity, segmented mode, All option clearing, keyboard arrows, focus, and mobile wrapping.' ] );
eit_fc_skip( 'TEST-FC-ROBUSTNESS-015', 'Chips visual and keyboard QA', [ 'owner' => 'Guilherme', 'reason' => 'Requires editor/frontend QA for compact token feel, multi-select clarity, hidden input keyboard behavior, long labels, scroll row, grid, and mobile wrapping.' ] );
eit_fc_skip( 'TEST-FC-ROBUSTNESS-016', 'Toggle visual and keyboard QA', [ 'owner' => 'Guilherme', 'reason' => 'Requires editor/frontend QA for off/on clarity, label position, state text, focus, reset, URL restore, and mobile full-row behavior.' ] );
eit_fc_skip( 'TEST-FC-ROBUSTNESS-017', 'Swatch visual and keyboard QA', [ 'owner' => 'Guilherme', 'reason' => 'Requires editor/frontend QA for hex colors, image swatches, invalid visual fallback, label visibility, selected ring contrast, keyboard focus, and mobile tap targets.' ] );
eit_fc_skip( 'TEST-FC-ROBUSTNESS-018', 'Date visual and browser-native QA', [ 'owner' => 'Guilherme', 'reason' => 'Requires editor/frontend QA for native picker behavior, labels, inverted state, clear action, active chip text, mobile stacking, and browser differences.' ] );

$failures = array_values(
	array_filter(
		$GLOBALS['eit_results'],
		function ( $result ) {
			return 'FAIL' === $result['status'];
		}
	)
);

echo wp_json_encode(
	[
		'summary' => [
			'passed' => count(
				array_filter(
					$GLOBALS['eit_results'],
					function ( $result ) {
						return 'PASS' === $result['status'];
					}
				)
			),
			'failed' => count( $failures ),
			'skipped_human_qa' => count(
				array_filter(
					$GLOBALS['eit_results'],
					function ( $result ) {
						return 'SKIP_HUMAN_QA' === $result['status'];
					}
				)
			),
		],
		'results' => $GLOBALS['eit_results'],
	],
	JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
) . "\n";

if ( $failures ) {
	exit( 1 );
}
