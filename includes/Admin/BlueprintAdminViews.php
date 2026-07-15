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
						<th><?php esc_html_e( 'Request ID', 'elementor-implementation-toolkit' ); ?></th>
					</tr></thead>
					<tbody>
					<?php foreach ( $runs as $run ) : ?>
						<tr>
							<td><strong><?php echo esc_html( $run['operation'] ); ?></strong><?php if ( $run['error_message'] ) : ?><span class="eit-row-sub"><?php echo esc_html( $run['error_message'] ); ?></span><?php endif; ?></td>
							<td><code><?php echo esc_html( substr( $run['blueprint_id'], 0, 8 ) ); ?></code></td>
							<td><span class="eit-status-pill <?php echo 'failed' === $run['status'] ? 'is-warning' : ''; ?>"><?php echo esc_html( $run['status'] ); ?></span></td>
							<td><?php echo esc_html( get_date_from_gmt( $run['started_at'], 'Y-m-d H:i:s' ) ); ?></td>
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
		$registry_groups = [
			'field_primitives' => __( 'Field primitives', 'elementor-implementation-toolkit' ),
			'storage_adapters' => __( 'Storage adapters', 'elementor-implementation-toolkit' ),
			'collection_providers' => __( 'Collection providers', 'elementor-implementation-toolkit' ),
			'form_actions' => __( 'Form actions', 'elementor-implementation-toolkit' ),
			'presentation_adapters' => __( 'Presentation adapters', 'elementor-implementation-toolkit' ),
		];
		?>
		<div class="eit-settings-grid">
			<section class="eit-setting-card">
				<h2><?php esc_html_e( 'Blueprint infrastructure', 'elementor-implementation-toolkit' ); ?></h2>
				<p><?php echo esc_html( $diagnostics['infrastructure']['message'] ); ?></p>
				<div class="eit-switch-line"><span><?php esc_html_e( 'Required tables', 'elementor-implementation-toolkit' ); ?></span><strong><?php echo esc_html( $diagnostics['infrastructure']['tables'] ); ?></strong></div>
				<div class="eit-switch-line"><span><?php esc_html_e( 'Stored systems', 'elementor-implementation-toolkit' ); ?></span><strong><?php echo esc_html( $diagnostics['blueprints'] ); ?></strong></div>
			</section>
			<section class="eit-setting-card">
				<h2><?php esc_html_e( 'Elementor runtime', 'elementor-implementation-toolkit' ); ?></h2>
				<p><?php esc_html_e( 'This reports detection only; compatibility remains proven by the editor canary suite.', 'elementor-implementation-toolkit' ); ?></p>
				<div class="eit-switch-line"><span><?php esc_html_e( 'Detected', 'elementor-implementation-toolkit' ); ?></span><strong><?php echo $diagnostics['elementor']['available'] ? esc_html__( 'Yes', 'elementor-implementation-toolkit' ) : esc_html__( 'No', 'elementor-implementation-toolkit' ); ?></strong></div>
				<div class="eit-switch-line"><span><?php esc_html_e( 'Version', 'elementor-implementation-toolkit' ); ?></span><code><?php echo esc_html( $diagnostics['elementor']['version'] ?: '—' ); ?></code></div>
			</section>
			<?php foreach ( $registry_groups as $key => $label ) : ?>
				<section class="eit-setting-card">
					<h2><?php echo esc_html( $label ); ?></h2>
					<?php if ( empty( $diagnostics[ $key ] ) ) : ?>
						<p><?php esc_html_e( 'No extensions are registered for this contract yet.', 'elementor-implementation-toolkit' ); ?></p>
					<?php else : ?>
						<?php foreach ( $diagnostics[ $key ] as $id => $health ) : ?>
							<div class="eit-switch-line"><code><?php echo esc_html( $id ); ?></code><span class="eit-status-pill <?php echo empty( $health['ok'] ) ? 'is-warning' : ''; ?>"><?php echo empty( $health['ok'] ) ? esc_html__( 'Health check failed', 'elementor-implementation-toolkit' ) : esc_html__( 'Health check passed', 'elementor-implementation-toolkit' ); ?></span></div>
						<?php endforeach; ?>
					<?php endif; ?>
				</section>
			<?php endforeach; ?>
		</div>
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
