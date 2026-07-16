<?php
/**
 * WordPress-native shells for Blueprint administration outside the map app.
 */

namespace EIT\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BlueprintAdminViews {

	private $presenter;

	public function render_systems_mount() {
		?>
		<div id="eit-systems-app" class="eit-systems-root">
			<p class="eit-systems-boot" role="status" aria-live="polite">
				<?php esc_html_e( 'Loading executable systems…', 'elementor-implementation-toolkit' ); ?>
			</p>
		</div>
		<noscript>
			<div class="notice notice-error"><p><?php esc_html_e( 'The Systems workspace requires JavaScript. Runs and diagnostics remain available without it.', 'elementor-implementation-toolkit' ); ?></p></div>
		</noscript>
		<?php
	}

	public function render_runs() {
		$runs = $this->presenter()->recent_runs();
		?>
		<section class="eit-panel eit-panel--table">
			<div class="eit-panel__header">
				<div>
					<h2><?php esc_html_e( 'Execution history', 'elementor-implementation-toolkit' ); ?></h2>
					<p><?php esc_html_e( 'Apply and recovery work is recorded with request IDs and redacted context.', 'elementor-implementation-toolkit' ); ?></p>
				</div>
			</div>
			<?php if ( empty( $runs ) ) : ?>
				<div class="eit-panel__body"><div class="eit-empty-panel"><h3><?php esc_html_e( 'No runs recorded', 'elementor-implementation-toolkit' ); ?></h3><p><?php esc_html_e( 'A run appears after a Blueprint publication or another governed runtime operation starts.', 'elementor-implementation-toolkit' ); ?></p></div></div>
			<?php else : ?>
				<table class="widefat striped eit-admin-table">
					<thead><tr>
						<th><?php esc_html_e( 'Operation', 'elementor-implementation-toolkit' ); ?></th>
						<th><?php esc_html_e( 'System', 'elementor-implementation-toolkit' ); ?></th>
						<th><?php esc_html_e( 'Observed status', 'elementor-implementation-toolkit' ); ?></th>
						<th><?php esc_html_e( 'Started', 'elementor-implementation-toolkit' ); ?></th>
						<th><?php esc_html_e( 'Events', 'elementor-implementation-toolkit' ); ?></th>
						<th><?php esc_html_e( 'Request ID', 'elementor-implementation-toolkit' ); ?></th>
					</tr></thead>
					<tbody>
					<?php foreach ( $runs as $run ) : ?>
						<tr>
							<td><strong><?php echo esc_html( $run['operation'] ); ?></strong><?php if ( $run['error_message'] ) : ?><span class="eit-row-sub"><?php echo esc_html( $run['error_message'] ); ?></span><?php endif; ?></td>
							<td><code><?php echo esc_html( substr( $run['blueprint_id'], 0, 8 ) ); ?></code></td>
							<td><span class="eit-status-pill <?php echo 'failed' === $run['status'] ? 'is-warning' : ''; ?>"><?php echo esc_html( $run['status'] ); ?></span></td>
							<td><?php echo esc_html( get_date_from_gmt( $run['started_at'], 'Y-m-d H:i:s' ) ); ?></td>
							<td><?php echo esc_html( (string) ( $run['event_count'] ?? 0 ) ); ?></td>
							<td><code><?php echo esc_html( $run['request_id'] ); ?></code></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</section>
		<?php
	}

	public function render_diagnostics() {
		$diagnostics = $this->presenter()->diagnostics();
		$inventory = $this->presenter()->migration_inventory();
		$scenarios = $this->presenter()->scenarios();
		$handoff_notes = $this->presenter()->handoff_notes();
		$registry_groups = [
			'field_primitives' => __( 'Field primitives', 'elementor-implementation-toolkit' ),
			'storage_adapters' => __( 'Storage adapters', 'elementor-implementation-toolkit' ),
			'collection_providers' => __( 'Collection providers', 'elementor-implementation-toolkit' ),
			'form_actions' => __( 'Form actions', 'elementor-implementation-toolkit' ),
			'presentation_adapters' => __( 'Presentation adapters', 'elementor-implementation-toolkit' ),
		];
		?>
		<?php $this->render_migration_inventory( $inventory ); ?>
		<div class="eit-settings-grid">
			<section class="eit-setting-card">
				<h2><?php esc_html_e( 'Blueprint infrastructure', 'elementor-implementation-toolkit' ); ?></h2>
				<p><?php echo esc_html( $diagnostics['infrastructure']['message'] ); ?></p>
				<div class="eit-switch-line"><span><?php esc_html_e( 'Required tables', 'elementor-implementation-toolkit' ); ?></span><strong><?php echo esc_html( $diagnostics['infrastructure']['tables'] ); ?></strong></div>
				<div class="eit-switch-line"><span><?php esc_html_e( 'Stored systems', 'elementor-implementation-toolkit' ); ?></span><strong><?php echo esc_html( $diagnostics['blueprints'] ); ?></strong></div>
			</section>
			<section class="eit-setting-card">
				<h2><?php esc_html_e( 'WooCommerce runtime', 'elementor-implementation-toolkit' ); ?></h2>
				<p><?php esc_html_e( 'The adapter schema remains visible when WooCommerce is absent, but live product behavior is not certified.', 'elementor-implementation-toolkit' ); ?></p>
				<div class="eit-switch-line"><span><?php esc_html_e( 'Detected', 'elementor-implementation-toolkit' ); ?></span><strong><?php echo $diagnostics['woocommerce']['available'] ? esc_html__( 'Yes', 'elementor-implementation-toolkit' ) : esc_html__( 'No', 'elementor-implementation-toolkit' ); ?></strong></div>
				<div class="eit-switch-line"><span><?php esc_html_e( 'Version', 'elementor-implementation-toolkit' ); ?></span><code><?php echo esc_html( $diagnostics['woocommerce']['version'] ?: '—' ); ?></code></div>
			</section>
			<section class="eit-setting-card">
				<h2><?php esc_html_e( 'Flight Recorder', 'elementor-implementation-toolkit' ); ?></h2>
				<p><?php esc_html_e( 'Run context and ordered events are bounded and redacted before persistence.', 'elementor-implementation-toolkit' ); ?></p>
				<div class="eit-switch-line"><span><?php esc_html_e( 'Recent runs', 'elementor-implementation-toolkit' ); ?></span><strong><?php echo esc_html( (string) $diagnostics['flight_recorder']['runs'] ); ?></strong></div>
				<div class="eit-switch-line"><span><?php esc_html_e( 'Recorded events', 'elementor-implementation-toolkit' ); ?></span><strong><?php echo esc_html( (string) $diagnostics['flight_recorder']['events'] ); ?></strong></div>
			</section>
			<section class="eit-setting-card">
				<h2><?php esc_html_e( 'Elementor runtime', 'elementor-implementation-toolkit' ); ?></h2>
				<p><?php esc_html_e( 'This reports detection only; compatibility remains proven by the editor canary suite.', 'elementor-implementation-toolkit' ); ?></p>
				<div class="eit-switch-line"><span><?php esc_html_e( 'Detected', 'elementor-implementation-toolkit' ); ?></span><strong><?php echo $diagnostics['elementor']['available'] ? esc_html__( 'Yes', 'elementor-implementation-toolkit' ) : esc_html__( 'No', 'elementor-implementation-toolkit' ); ?></strong></div>
				<div class="eit-switch-line"><span><?php esc_html_e( 'Version', 'elementor-implementation-toolkit' ); ?></span><code><?php echo esc_html( $diagnostics['elementor']['version'] ?: '—' ); ?></code></div>
			</section>
			<?php foreach ( $registry_groups as $key => $label ) : ?>
				<?php $this->render_registry_group( $label, $diagnostics[ $key ] ); ?>
			<?php endforeach; ?>
		</div>
		<?php $this->render_scenarios( $scenarios ); ?>
		<?php $this->render_handoff_notes( $handoff_notes ); ?>
		<?php
	}

	private function render_registry_group( $label, array $items ) {
		$total = count( $items );
		$unavailable = count(
			array_filter(
				$items,
				function ( $health ) {
					return empty( $health['ok'] );
				}
			)
		);
		?>
		<section class="eit-setting-card eit-registry-card">
			<h2><?php echo esc_html( $label ); ?></h2>
			<?php if ( 0 === $total ) : ?>
				<p><?php esc_html_e( 'No extensions are registered for this contract yet.', 'elementor-implementation-toolkit' ); ?></p>
			<?php else : ?>
				<p>
					<?php
					echo esc_html(
						sprintf(
							/* translators: 1: registered contract count, 2: unavailable contract count. */
							__( '%1$d registered; %2$d unavailable in this runtime.', 'elementor-implementation-toolkit' ),
							$total,
							$unavailable
						)
					);
					?>
				</p>
				<details class="eit-registry-details" <?php echo $unavailable ? 'open' : ''; ?>>
					<summary><?php esc_html_e( 'Review contract health', 'elementor-implementation-toolkit' ); ?></summary>
					<div class="eit-registry-list">
						<?php foreach ( $items as $id => $health ) : ?>
							<div class="eit-registry-row">
								<div>
									<code><?php echo esc_html( $id ); ?></code>
									<?php if ( ! empty( $health['message'] ) ) : ?>
										<span class="eit-row-sub"><?php echo esc_html( $health['message'] ); ?></span>
									<?php endif; ?>
								</div>
								<span class="eit-status-pill <?php echo empty( $health['ok'] ) ? 'is-warning' : ''; ?>"><?php echo empty( $health['ok'] ) ? esc_html__( 'Unavailable', 'elementor-implementation-toolkit' ) : esc_html__( 'Available', 'elementor-implementation-toolkit' ); ?></span>
							</div>
						<?php endforeach; ?>
					</div>
				</details>
			<?php endif; ?>
		</section>
		<?php
	}

	private function render_migration_inventory( array $inventory ) {
		$pilot = $inventory['pilot'];
		$pilot_ready = (int) $pilot['cpt']['expected_records'] === (int) $pilot['cpt']['observed_records']
			&& (int) $pilot['cct']['expected_records'] === (int) $pilot['cct']['observed_records']
			&& (int) $pilot['elementor']['expected_active_documents'] === (int) $pilot['elementor']['observed_active_documents'];
		?>
		<section class="eit-panel eit-panel--table" data-eit-migration>
			<div class="eit-panel__header"><div><h2><?php esc_html_e( 'Legacy shadow migration', 'elementor-implementation-toolkit' ); ?></h2><p><?php esc_html_e( 'Choose sources, prepare a checksum-bound plan, then confirm draft creation. Runtime stays untouched.', 'elementor-implementation-toolkit' ); ?></p></div><span class="eit-status-pill <?php echo $pilot_ready ? '' : 'is-warning'; ?>"><?php echo $pilot_ready ? esc_html__( 'Pilot inventory matches', 'elementor-implementation-toolkit' ) : esc_html__( 'Pilot drift observed', 'elementor-implementation-toolkit' ); ?></span></div>
			<div class="eit-panel__body">
				<p class="eit-migration-pilot"><?php echo esc_html( sprintf( __( 'Pilot: __imoveis %1$d/%2$d records; projects %3$d/%4$d records; active Toolkit Elementor documents %5$d/%6$d.', 'elementor-implementation-toolkit' ), $pilot['cpt']['observed_records'], $pilot['cpt']['expected_records'], $pilot['cct']['observed_records'], $pilot['cct']['expected_records'], $pilot['elementor']['observed_active_documents'], $pilot['elementor']['expected_active_documents'] ) ); ?></p>
				<div class="eit-filter-table-wrap"><table class="widefat striped eit-admin-table"><thead><tr><th class="check-column"><span class="screen-reader-text"><?php esc_html_e( 'Select', 'elementor-implementation-toolkit' ); ?></span></th><th><?php esc_html_e( 'Source', 'elementor-implementation-toolkit' ); ?></th><th><?php esc_html_e( 'Candidate', 'elementor-implementation-toolkit' ); ?></th><th><?php esc_html_e( 'Records', 'elementor-implementation-toolkit' ); ?></th><th><?php esc_html_e( 'Shadow status', 'elementor-implementation-toolkit' ); ?></th></tr></thead><tbody>
				<?php foreach ( $inventory['items'] as $item ) : ?>
					<tr><th class="check-column"><input type="checkbox" data-eit-migration-source data-source-type="<?php echo esc_attr( $item['source_type'] ); ?>" data-source-key="<?php echo esc_attr( $item['source_key'] ); ?>" aria-label="<?php echo esc_attr( sprintf( __( 'Select %s', 'elementor-implementation-toolkit' ), $item['name'] ) ); ?>"></th><td><code><?php echo esc_html( $item['source_type'] ); ?></code><span class="eit-row-sub"><?php echo esc_html( $item['source_key'] ); ?></span></td><td><strong><?php echo esc_html( $item['name'] ); ?></strong><?php if ( isset( $item['revision_count'] ) ) : ?><span class="eit-row-sub"><?php echo esc_html( sprintf( __( '%d backup revisions excluded from active usage', 'elementor-implementation-toolkit' ), $item['revision_count'] ) ); ?></span><?php endif; ?></td><td><?php echo esc_html( (string) $item['record_count'] ); ?></td><td><span class="eit-status-pill <?php echo in_array( $item['migration_status'], [ 'verified', 'not_imported' ], true ) ? 'is-neutral' : 'is-warning'; ?>"><?php echo esc_html( $item['migration_status'] ); ?></span></td></tr>
				<?php endforeach; ?>
				</tbody></table></div>
				<div class="eit-migration-actions"><button type="button" class="button button-secondary" data-eit-prepare-migration><?php esc_html_e( 'Prepare selected drafts', 'elementor-implementation-toolkit' ); ?></button><span role="status" aria-live="polite" data-eit-migration-status></span></div>
				<div class="eit-migration-confirm" data-eit-migration-confirm hidden><h3><?php esc_html_e( 'Confirm shadow import', 'elementor-implementation-toolkit' ); ?></h3><p data-eit-migration-summary></p><p><?php esc_html_e( 'This creates or refreshes untouched imported drafts and records comparisons. It does not publish, switch runtime or delete legacy data.', 'elementor-implementation-toolkit' ); ?></p><button type="button" class="button button-primary" data-eit-apply-migration><?php esc_html_e( 'Create drafts and compare', 'elementor-implementation-toolkit' ); ?></button><button type="button" class="button" data-eit-cancel-migration><?php esc_html_e( 'Cancel', 'elementor-implementation-toolkit' ); ?></button></div>
			</div>
		</section>
		<?php
	}

	private function render_scenarios( array $scenarios ) {
		?>
		<section class="eit-panel eit-panel--table"><div class="eit-panel__header"><div><h2><?php esc_html_e( 'QA Scenario Runner', 'elementor-implementation-toolkit' ); ?></h2><p><?php esc_html_e( 'Saved cases replay published Collection or imported shadow contracts and retain only bounded result facts.', 'elementor-implementation-toolkit' ); ?></p></div></div>
		<?php if ( empty( $scenarios ) ) : ?><div class="eit-panel__body"><p><?php esc_html_e( 'No reproducible scenario is saved yet.', 'elementor-implementation-toolkit' ); ?></p></div><?php else : ?><table class="widefat striped eit-admin-table"><thead><tr><th><?php esc_html_e( 'Scenario', 'elementor-implementation-toolkit' ); ?></th><th><?php esc_html_e( 'Kind', 'elementor-implementation-toolkit' ); ?></th><th><?php esc_html_e( 'Last result', 'elementor-implementation-toolkit' ); ?></th></tr></thead><tbody><?php foreach ( $scenarios as $scenario ) : ?><tr><td><strong><?php echo esc_html( $scenario['name'] ); ?></strong><span class="eit-row-sub"><code><?php echo esc_html( $scenario['id'] ); ?></code></span></td><td><?php echo esc_html( $scenario['kind'] ); ?></td><td><span class="eit-status-pill <?php echo 'passed' === $scenario['status'] ? '' : 'is-warning'; ?>"><?php echo esc_html( $scenario['status'] ); ?></span></td></tr><?php endforeach; ?></tbody></table><?php endif; ?>
		</section>
		<?php
	}

	private function render_handoff_notes( $notes ) {
		?>
		<section class="eit-panel"><div class="eit-panel__header"><div><h2><?php esc_html_e( 'Factual handoff notes', 'elementor-implementation-toolkit' ); ?></h2><p><?php esc_html_e( 'Generated only from the current diagnostic projection; no visual approval is inferred.', 'elementor-implementation-toolkit' ); ?></p></div><button type="button" class="button" data-eit-copy-handoff><?php esc_html_e( 'Copy Markdown', 'elementor-implementation-toolkit' ); ?></button></div><div class="eit-panel__body"><textarea class="large-text code eit-handoff-notes" rows="18" readonly data-eit-handoff-notes aria-label="<?php esc_attr_e( 'Generated factual handoff notes', 'elementor-implementation-toolkit' ); ?>"><?php echo esc_textarea( $notes ); ?></textarea><span class="screen-reader-text" role="status" aria-live="polite" data-eit-handoff-status></span></div></section>
		<?php
	}

	public function render_settings() {
		?>
		<div class="eit-settings-grid">
			<section class="eit-setting-card">
				<h2><?php esc_html_e( 'Runtime boundaries', 'elementor-implementation-toolkit' ); ?></h2>
				<p><?php esc_html_e( 'Blueprints own system behavior. Elementor owns layout, style and responsive composition. WooCommerce remains authoritative for transactions.', 'elementor-implementation-toolkit' ); ?></p>
				<div class="eit-switch-line"><span><?php esc_html_e( 'Toolkit version', 'elementor-implementation-toolkit' ); ?></span><code><?php echo esc_html( EIT_VERSION ); ?></code></div>
			</section>
			<section class="eit-setting-card">
				<h2><?php esc_html_e( 'Legacy recovery surfaces', 'elementor-implementation-toolkit' ); ?></h2>
				<p><?php esc_html_e( 'These direct links remain available during the 1.x migration window, but they are no longer primary navigation.', 'elementor-implementation-toolkit' ); ?></p>
				<p><a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=' . AdminPages::FILTERS_SLUG ) ); ?>"><?php esc_html_e( 'Open filter presets', 'elementor-implementation-toolkit' ); ?></a></p>
				<p><a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=' . AdminPages::CPT_SLUG ) ); ?>"><?php esc_html_e( 'Open legacy post types', 'elementor-implementation-toolkit' ); ?></a></p>
				<p><a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=' . AdminPages::CCT_SLUG ) ); ?>"><?php esc_html_e( 'Open legacy content types', 'elementor-implementation-toolkit' ); ?></a></p>
			</section>
			<section class="eit-setting-card">
				<h2><?php esc_html_e( 'Entry workflow recovery', 'elementor-implementation-toolkit' ); ?></h2>
				<p><?php esc_html_e( 'Retry failed notifications and webhooks without resubmitting or duplicating content.', 'elementor-implementation-toolkit' ); ?></p>
				<p><a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=' . AdminPages::ENTRY_RECOVERY_SLUG ) ); ?>"><?php esc_html_e( 'Open Entry recovery', 'elementor-implementation-toolkit' ); ?></a></p>
			</section>
		</div>
		<?php
	}

	private function presenter() {
		if ( null === $this->presenter ) {
			$this->presenter = new BlueprintAdminPresenter();
		}
		return $this->presenter;
	}
}
