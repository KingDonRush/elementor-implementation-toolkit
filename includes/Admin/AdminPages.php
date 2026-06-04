<?php
/**
 * Clean WordPress admin entry points for the implementation toolkit.
 */

namespace EIT\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AdminPages {

	const CAPABILITY = 'manage_options';
	const DASHBOARD_SLUG = 'eit-toolkit';
	const FILTERS_SLUG = 'eit-filter-presets';
	const CPT_SLUG = 'eit-cpt-manager';
	const INTEGRATIONS_SLUG = 'eit-integrations';

	private $renderer;
	private $filter_preset_admin;
	private $cpt_manager_admin;

	public function init_hooks() {
		add_action( 'admin_menu', [ $this, 'register_menu' ] );
		add_action( 'admin_post_' . FilterPresetAdmin::SAVE_ACTION, [ $this->filter_preset_admin(), 'handle_save' ] );
		add_action( 'admin_post_' . FilterPresetAdmin::DELETE_ACTION, [ $this->filter_preset_admin(), 'handle_delete' ] );
		add_action( 'admin_post_' . FilterPresetAdmin::DUPLICATE_ACTION, [ $this->filter_preset_admin(), 'handle_duplicate' ] );
		add_action( 'admin_post_' . FilterPresetAdmin::CREATE_TEMPLATE_ACTION, [ $this->filter_preset_admin(), 'handle_create_template' ] );
		add_action( 'admin_post_' . FilterPresetAdmin::DELETE_TEMPLATE_ACTION, [ $this->filter_preset_admin(), 'handle_delete_template' ] );
		add_action( 'admin_post_' . CptManagerAdmin::SAVE_ACTION, [ $this->cpt_manager_admin(), 'handle_save' ] );
		add_action( 'admin_post_' . CptManagerAdmin::DELETE_ACTION, [ $this->cpt_manager_admin(), 'handle_delete' ] );
		add_action( 'admin_post_' . CptManagerAdmin::DUPLICATE_ACTION, [ $this->cpt_manager_admin(), 'handle_duplicate' ] );
	}

	public function register_menu() {
		add_menu_page(
			__( 'Implementation Toolkit', 'elementor-implementation-toolkit' ),
			__( 'Implementation Toolkit', 'elementor-implementation-toolkit' ),
			self::CAPABILITY,
			self::DASHBOARD_SLUG,
			[ $this, 'render_dashboard' ],
			'dashicons-superhero',
			58
		);

		add_submenu_page(
			self::DASHBOARD_SLUG,
			__( 'Toolkit Dashboard', 'elementor-implementation-toolkit' ),
			__( 'Dashboard', 'elementor-implementation-toolkit' ),
			self::CAPABILITY,
			self::DASHBOARD_SLUG,
			[ $this, 'render_dashboard' ]
		);

		add_submenu_page(
			self::DASHBOARD_SLUG,
			__( 'Filter Presets', 'elementor-implementation-toolkit' ),
			__( 'Filter Presets', 'elementor-implementation-toolkit' ),
			self::CAPABILITY,
			self::FILTERS_SLUG,
			[ $this, 'render_filters' ]
		);

		add_submenu_page(
			self::DASHBOARD_SLUG,
			__( 'Post Types', 'elementor-implementation-toolkit' ),
			__( 'Post Types', 'elementor-implementation-toolkit' ),
			self::CAPABILITY,
			self::CPT_SLUG,
			[ $this, 'render_cpts' ]
		);

		add_submenu_page(
			self::DASHBOARD_SLUG,
			__( 'Toolkit Settings', 'elementor-implementation-toolkit' ),
			__( 'Settings', 'elementor-implementation-toolkit' ),
			self::CAPABILITY,
			self::INTEGRATIONS_SLUG,
			[ $this, 'render_integrations' ]
		);
	}

	public function render_dashboard() {
		$config = [
			'title'       => __( 'Implementation Toolkit', 'elementor-implementation-toolkit' ),
			'actions'     => [
				[
					'label' => __( 'Add Filter Preset', 'elementor-implementation-toolkit' ),
					'url'   => admin_url( 'admin.php?page=' . self::FILTERS_SLUG . '&view=new' ),
				],
			],
		];

		$this->renderer()->render_shell(
			self::DASHBOARD_SLUG,
			$this->tabs(),
			$config,
			function () {
				$this->render_dashboard_cards();
			}
		);
	}

	public function render_filters() {
		$this->filter_preset_admin()->render( self::FILTERS_SLUG, $this->tabs() );
	}

	public function render_cpts() {
		$this->cpt_manager_admin()->render( self::CPT_SLUG, $this->tabs() );
	}

	public function render_integrations() {
		$config = [
			'title'       => __( 'Settings', 'elementor-implementation-toolkit' ),
		];

		$this->renderer()->render_shell(
			self::INTEGRATIONS_SLUG,
			$this->tabs(),
			$config,
			function () {
				$this->render_diagnostics_cards();
			}
		);
	}

	private function tabs() {
		return [
			self::DASHBOARD_SLUG    => [
				'label' => __( 'Dashboard', 'elementor-implementation-toolkit' ),
			],
			self::FILTERS_SLUG      => [
				'label' => __( 'Filter Presets', 'elementor-implementation-toolkit' ),
			],
			self::CPT_SLUG          => [
				'label' => __( 'CPT / Post Types', 'elementor-implementation-toolkit' ),
			],
			self::INTEGRATIONS_SLUG => [
				'label' => __( 'Settings', 'elementor-implementation-toolkit' ),
			],
		];
	}

	private function render_dashboard_cards() {
		$presets = \EIT\Support\FilterPresets::all();
		$definitions = \EIT\CPT\CptManager::all();
		$filter_count = count( $presets );
		$post_type_count = count( $definitions );
		$linked_template_count = 0;

		foreach ( $presets as $preset_id => $preset ) {
			$linked_template_count += count( \EIT\Elementor\FilterTemplateManager::get_templates( $preset_id ) );
		}
		?>
		<div class="eit-notice-line">
			<span class="dashicons dashicons-yes" aria-hidden="true"></span>
			<p><?php esc_html_e( 'Elementor bridge is ready. Presets and post types can be edited from WordPress and opened in Elementor when visual work begins.', 'elementor-implementation-toolkit' ); ?></p>
		</div>

		<div class="eit-metrics-grid">
			<div class="eit-metric"><strong><?php echo esc_html( $filter_count ); ?></strong><p><?php esc_html_e( 'Filter presets', 'elementor-implementation-toolkit' ); ?></p></div>
			<div class="eit-metric"><strong><?php echo esc_html( $post_type_count ); ?></strong><p><?php esc_html_e( 'Custom post types', 'elementor-implementation-toolkit' ); ?></p></div>
			<div class="eit-metric"><strong><?php echo esc_html( $linked_template_count ); ?></strong><p><?php esc_html_e( 'Elementor templates linked', 'elementor-implementation-toolkit' ); ?></p></div>
		</div>

		<div class="eit-layout-grid eit-layout-grid--dashboard">
			<section class="eit-panel">
				<div class="eit-panel__header">
					<h2><?php esc_html_e( 'Recent presets', 'elementor-implementation-toolkit' ); ?></h2>
					<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::FILTERS_SLUG ) ); ?>"><?php esc_html_e( 'View all', 'elementor-implementation-toolkit' ); ?></a>
				</div>
				<div class="eit-panel__body">
					<?php if ( empty( $presets ) ) : ?>
						<?php
						$this->renderer()->render_empty_state(
							__( 'No filter presets yet', 'elementor-implementation-toolkit' ),
							__( 'Create one preset, then connect it to Elementor when the visual template is ready.', 'elementor-implementation-toolkit' ),
							admin_url( 'admin.php?page=' . self::FILTERS_SLUG . '&view=new' ),
							__( 'Create preset', 'elementor-implementation-toolkit' )
						);
						?>
					<?php else : ?>
						<table class="widefat striped eit-admin-table">
							<thead>
								<tr>
									<th><?php esc_html_e( 'Preset', 'elementor-implementation-toolkit' ); ?></th>
									<th><?php esc_html_e( 'Filters', 'elementor-implementation-toolkit' ); ?></th>
									<th><?php esc_html_e( 'Template', 'elementor-implementation-toolkit' ); ?></th>
									<th><?php esc_html_e( 'Status', 'elementor-implementation-toolkit' ); ?></th>
								</tr>
							</thead>
							<tbody>
								<?php foreach ( array_slice( $presets, 0, 3, true ) as $id => $preset ) : ?>
									<?php
									$templates = \EIT\Elementor\FilterTemplateManager::get_templates( $id );
									$filters = $preset['filters'] ?? [];
									?>
									<tr>
										<td><a class="eit-row-title" href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::FILTERS_SLUG . '&preset=' . rawurlencode( $id ) ) ); ?>"><?php echo esc_html( $preset['name'] ?? $id ); ?></a><span class="eit-row-sub"><?php echo esc_html( $preset['slug'] ?? $id ); ?></span></td>
										<td><?php echo esc_html( sprintf( _n( '%d filter', '%d filters', count( $filters ), 'elementor-implementation-toolkit' ), count( $filters ) ) ); ?></td>
										<td><?php echo empty( $templates ) ? esc_html__( 'Not linked', 'elementor-implementation-toolkit' ) : esc_html__( 'Archive template', 'elementor-implementation-toolkit' ); ?></td>
										<td><span class="eit-status-pill <?php echo empty( $filters ) ? 'is-neutral' : ''; ?>"><?php echo empty( $filters ) ? esc_html__( 'Draft', 'elementor-implementation-toolkit' ) : esc_html__( 'Ready', 'elementor-implementation-toolkit' ); ?></span></td>
									</tr>
								<?php endforeach; ?>
							</tbody>
						</table>
					<?php endif; ?>
				</div>
			</section>

			<section class="eit-panel">
				<div class="eit-panel__header">
					<h2><?php esc_html_e( 'Next actions', 'elementor-implementation-toolkit' ); ?></h2>
				</div>
				<div class="eit-panel__body eit-field-stack">
					<div class="eit-handoff-card">
						<div class="eit-handoff-card__head">
							<span class="dashicons dashicons-admin-page" aria-hidden="true"></span>
							<div>
								<h4><?php esc_html_e( 'Open the archive in Elementor', 'elementor-implementation-toolkit' ); ?></h4>
								<p><?php esc_html_e( 'Continue visual layout from the linked preset or create a new Theme Builder handoff.', 'elementor-implementation-toolkit' ); ?></p>
							</div>
						</div>
						<div class="eit-handoff-actions">
							<a class="button button-primary" href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::FILTERS_SLUG ) ); ?>"><?php esc_html_e( 'Manage presets', 'elementor-implementation-toolkit' ); ?></a>
							<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::CPT_SLUG ) ); ?>"><?php esc_html_e( 'Manage post types', 'elementor-implementation-toolkit' ); ?></a>
						</div>
					</div>
					<div class="eit-muted-strip"><?php esc_html_e( 'CPT setup stays in WordPress. Visual placement stays in Elementor.', 'elementor-implementation-toolkit' ); ?></div>
				</div>
			</section>
		</div>

		<div class="eit-savebar">
			<div class="eit-advanced-panel">
				<?php $this->renderer()->render_advanced_button( __( 'Advanced diagnostics', 'elementor-implementation-toolkit' ), 'eit-dashboard-advanced-modal' ); ?>
				<?php $this->renderer()->render_modal_open( 'eit-dashboard-advanced-modal', __( 'Advanced diagnostics', 'elementor-implementation-toolkit' ) ); ?>
				<div class="eit-advanced-stack eit-advanced-stack--modal">
					<p><?php esc_html_e( 'Use Settings for provider checks, template links, and admin behavior diagnostics.', 'elementor-implementation-toolkit' ); ?></p>
				</div>
				<?php $this->renderer()->render_modal_close(); ?>
			</div>
		</div>
		<?php
	}

	private function render_diagnostics_cards() {
		?>
		<div class="eit-settings-grid">
			<section class="eit-setting-card">
				<h2><?php esc_html_e( 'Elementor bridge', 'elementor-implementation-toolkit' ); ?></h2>
				<p><?php esc_html_e( 'Presets and post types are handed off to Elementor templates without replacing Elementor as the builder.', 'elementor-implementation-toolkit' ); ?></p>
				<div class="eit-switch-line"><span><?php esc_html_e( 'Preset handoff buttons', 'elementor-implementation-toolkit' ); ?></span><span class="eit-status-pill"><?php esc_html_e( 'Ready', 'elementor-implementation-toolkit' ); ?></span></div>
				<div class="eit-switch-line"><span><?php esc_html_e( 'Widget preset selector', 'elementor-implementation-toolkit' ); ?></span><span class="eit-status-pill"><?php esc_html_e( 'Ready', 'elementor-implementation-toolkit' ); ?></span></div>
			</section>

			<section class="eit-setting-card">
				<h2><?php esc_html_e( 'Data providers', 'elementor-implementation-toolkit' ); ?></h2>
				<p><?php esc_html_e( 'Current provider status for the Filter Controller runtime.', 'elementor-implementation-toolkit' ); ?></p>
				<table class="widefat eit-admin-table">
					<tbody>
						<tr><td><?php esc_html_e( 'DOM provider', 'elementor-implementation-toolkit' ); ?></td><td><span class="eit-status-pill"><?php esc_html_e( 'Active', 'elementor-implementation-toolkit' ); ?></span></td></tr>
						<tr><td><?php esc_html_e( 'WordPress enrichment', 'elementor-implementation-toolkit' ); ?></td><td><span class="eit-status-pill"><?php esc_html_e( 'Automatic', 'elementor-implementation-toolkit' ); ?></span></td></tr>
						<tr><td><?php esc_html_e( 'Template links', 'elementor-implementation-toolkit' ); ?></td><td><span class="eit-status-pill is-warning"><?php esc_html_e( 'Check per preset', 'elementor-implementation-toolkit' ); ?></span></td></tr>
					</tbody>
				</table>
			</section>

			<section class="eit-setting-card">
				<h2><?php esc_html_e( 'Admin behavior', 'elementor-implementation-toolkit' ); ?></h2>
				<p><?php esc_html_e( 'Simple creation workflows stay visible. Provider tuning and compatibility checks stay behind advanced disclosures.', 'elementor-implementation-toolkit' ); ?></p>
				<div class="eit-muted-strip"><?php esc_html_e( 'Settings that persist global options will be added only when the runtime needs them.', 'elementor-implementation-toolkit' ); ?></div>
			</section>

			<section class="eit-setting-card">
				<h2><?php esc_html_e( 'Diagnostics', 'elementor-implementation-toolkit' ); ?></h2>
				<p><?php esc_html_e( 'Compatibility checks for Elementor, CPT registration, and preset template links.', 'elementor-implementation-toolkit' ); ?></p>
				<table class="widefat eit-admin-table">
					<tbody>
						<tr><td><?php esc_html_e( 'Elementor', 'elementor-implementation-toolkit' ); ?></td><td><span class="eit-status-pill"><?php echo \EIT\Elementor\FilterTemplateManager::is_elementor_available() ? esc_html__( 'Ready', 'elementor-implementation-toolkit' ) : esc_html__( 'Inactive', 'elementor-implementation-toolkit' ); ?></span></td></tr>
						<tr><td><?php esc_html_e( 'CPT definitions', 'elementor-implementation-toolkit' ); ?></td><td><span class="eit-status-pill"><?php esc_html_e( 'Ready', 'elementor-implementation-toolkit' ); ?></span></td></tr>
						<tr><td><?php esc_html_e( 'Filter presets', 'elementor-implementation-toolkit' ); ?></td><td><span class="eit-status-pill"><?php esc_html_e( 'Ready', 'elementor-implementation-toolkit' ); ?></span></td></tr>
					</tbody>
				</table>
			</section>
		</div>
		<?php
	}

	private function renderer() {
		if ( ! $this->renderer ) {
			$this->renderer = new AdminRenderer();
		}

		return $this->renderer;
	}

	private function filter_preset_admin() {
		if ( ! $this->filter_preset_admin ) {
			$this->filter_preset_admin = new FilterPresetAdmin( $this->renderer() );
		}

		return $this->filter_preset_admin;
	}

	private function cpt_manager_admin() {
		if ( ! $this->cpt_manager_admin ) {
			$this->cpt_manager_admin = new CptManagerAdmin( $this->renderer() );
		}

		return $this->cpt_manager_admin;
	}
}
