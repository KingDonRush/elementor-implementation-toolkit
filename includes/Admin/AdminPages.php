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
	const CCT_SLUG = 'eit-content-types';
	const INTEGRATIONS_SLUG = 'eit-integrations';

	private $renderer;
	private $filter_preset_admin;
	private $cpt_manager_admin;
	private $cct_definition_admin;
	private $cct_item_admin;

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
		add_action( 'admin_post_' . CctDefinitionAdmin::SAVE_ACTION, [ $this->cct_definition_admin(), 'handle_save' ] );
		add_action( 'admin_post_' . CctDefinitionAdmin::ARCHIVE_ACTION, [ $this->cct_definition_admin(), 'handle_archive' ] );
		add_action( 'admin_post_' . CctDefinitionAdmin::RESTORE_ACTION, [ $this->cct_definition_admin(), 'handle_restore' ] );
		add_action( 'admin_post_' . CctDefinitionAdmin::DELETE_ACTION, [ $this->cct_definition_admin(), 'handle_delete' ] );
		add_action( 'admin_post_' . CctItemAdmin::SAVE_ACTION, [ $this->cct_item_admin(), 'handle_save' ] );
		add_action( 'admin_post_' . CctItemAdmin::DELETE_ACTION, [ $this->cct_item_admin(), 'handle_delete' ] );
		add_action( 'admin_menu', [ $this->cct_item_admin(), 'register_menus' ], 20 );
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
			__( 'Content Types', 'elementor-implementation-toolkit' ),
			__( 'Content Types', 'elementor-implementation-toolkit' ),
			self::CAPABILITY,
			self::CCT_SLUG,
			[ $this, 'render_ccts' ]
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

	public function render_ccts() {
		$this->cct_definition_admin()->render( self::CCT_SLUG, $this->tabs() );
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
			self::CCT_SLUG          => [
				'label' => __( 'Content Types', 'elementor-implementation-toolkit' ),
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
			<p>
				<?php esc_html_e( 'Configuration is stored in WordPress; Elementor output still depends on the selected page/template runtime.', 'elementor-implementation-toolkit' ); ?>
			</p>
		</div>

		<div class="eit-metrics-grid">
			<div class="eit-metric"><strong><?php echo esc_html( $filter_count ); ?></strong><p><?php esc_html_e( 'Filter presets', 'elementor-implementation-toolkit' ); ?></p></div>
			<div class="eit-metric"><strong><?php echo esc_html( $post_type_count ); ?></strong><p><?php esc_html_e( 'Custom post types', 'elementor-implementation-toolkit' ); ?></p></div>
			<div class="eit-metric">
				<strong><?php echo esc_html( $linked_template_count ); ?></strong>
				<p><?php esc_html_e( 'Filter templates linked', 'elementor-implementation-toolkit' ); ?></p>
			</div>
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
									<th><?php esc_html_e( 'Filter template', 'elementor-implementation-toolkit' ); ?></th>
									<th><?php esc_html_e( 'Status', 'elementor-implementation-toolkit' ); ?></th>
								</tr>
							</thead>
							<tbody>
								<?php foreach ( array_slice( $presets, 0, 3, true ) as $id => $preset ) : ?>
									<?php
									$templates = \EIT\Elementor\FilterTemplateManager::get_templates( $id );
									$filters = $preset['filters'] ?? [];
									$template_label = __( 'Not linked', 'elementor-implementation-toolkit' );
									$status_class = 'eit-status-pill';
									$status_label = __( 'Saved', 'elementor-implementation-toolkit' );

									if ( ! empty( $templates ) ) {
										$template_label = __( 'Controls linked', 'elementor-implementation-toolkit' );
									}

									if ( empty( $filters ) ) {
										$status_class = 'eit-status-pill is-neutral';
										$status_label = __( 'Draft', 'elementor-implementation-toolkit' );
									}
									?>
									<tr>
										<td><a class="eit-row-title" href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::FILTERS_SLUG . '&preset=' . rawurlencode( $id ) ) ); ?>"><?php echo esc_html( $preset['name'] ?? $id ); ?></a><span class="eit-row-sub"><?php echo esc_html( $preset['slug'] ?? $id ); ?></span></td>
										<td><?php echo esc_html( sprintf( _n( '%d filter', '%d filters', count( $filters ), 'elementor-implementation-toolkit' ), count( $filters ) ) ); ?></td>
										<td><?php echo esc_html( $template_label ); ?></td>
										<td><span class="<?php echo esc_attr( $status_class ); ?>"><?php echo esc_html( $status_label ); ?></span></td>
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
								<h3 class="eit-handoff-card__title"><?php esc_html_e( 'Prepare filter controls for Elementor', 'elementor-implementation-toolkit' ); ?></h3>
								<p><?php esc_html_e( 'Use linked filter-control templates or place the widget manually in the Elementor layout.', 'elementor-implementation-toolkit' ); ?></p>
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

		<?php
	}

	private function render_diagnostics_cards() {
		$cpt_count = count( \EIT\CPT\CptManager::all() );
		$preset_count = count( \EIT\Support\FilterPresets::all() );
		$elementor_status = \EIT\Elementor\FilterTemplateManager::is_elementor_available()
			? __( 'Active', 'elementor-implementation-toolkit' )
			: __( 'Inactive', 'elementor-implementation-toolkit' );
		$cpt_status = sprintf(
			_n( '%d stored definition', '%d stored definitions', $cpt_count, 'elementor-implementation-toolkit' ),
			$cpt_count
		);
		$preset_status = sprintf(
			_n( '%d stored preset', '%d stored presets', $preset_count, 'elementor-implementation-toolkit' ),
			$preset_count
		);
		?>
		<div class="eit-settings-grid">
			<section class="eit-setting-card">
				<h2><?php esc_html_e( 'Elementor handoff', 'elementor-implementation-toolkit' ); ?></h2>
				<p><?php esc_html_e( 'Presets and content models can be handed to Elementor, but page/template QA remains per layout.', 'elementor-implementation-toolkit' ); ?></p>
				<div class="eit-switch-line">
					<span><?php esc_html_e( 'Preset handoff buttons', 'elementor-implementation-toolkit' ); ?></span>
					<span class="eit-status-pill"><?php esc_html_e( 'Available', 'elementor-implementation-toolkit' ); ?></span>
				</div>
				<div class="eit-switch-line">
					<span><?php esc_html_e( 'Widget preset selector', 'elementor-implementation-toolkit' ); ?></span>
					<span class="eit-status-pill"><?php esc_html_e( 'Available', 'elementor-implementation-toolkit' ); ?></span>
				</div>
			</section>

			<section class="eit-setting-card">
				<h2><?php esc_html_e( 'Data providers', 'elementor-implementation-toolkit' ); ?></h2>
				<p><?php esc_html_e( 'Current provider assumptions for the Filter Controller runtime.', 'elementor-implementation-toolkit' ); ?></p>
				<table class="widefat eit-admin-table">
					<tbody>
						<tr>
							<td><?php esc_html_e( 'Legacy DOM snapshot', 'elementor-implementation-toolkit' ); ?></td>
							<td><span class="eit-status-pill is-warning"><?php esc_html_e( 'Limited to 200 items', 'elementor-implementation-toolkit' ); ?></span></td>
						</tr>
						<tr>
							<td><?php esc_html_e( 'CCT query provider', 'elementor-implementation-toolkit' ); ?></td>
							<td><span class="eit-status-pill"><?php esc_html_e( 'Server-side and status-scoped', 'elementor-implementation-toolkit' ); ?></span></td>
						</tr>
						<tr>
							<td><?php esc_html_e( 'Template links', 'elementor-implementation-toolkit' ); ?></td>
							<td><span class="eit-status-pill is-warning"><?php esc_html_e( 'Check per preset', 'elementor-implementation-toolkit' ); ?></span></td>
						</tr>
					</tbody>
				</table>
			</section>

			<section class="eit-setting-card">
				<h2><?php esc_html_e( 'Diagnostics', 'elementor-implementation-toolkit' ); ?></h2>
				<p><?php esc_html_e( 'Configuration facts for Elementor availability, stored CPT definitions, and stored filter presets.', 'elementor-implementation-toolkit' ); ?></p>
				<table class="widefat eit-admin-table">
					<tbody>
						<tr>
							<td><?php esc_html_e( 'Elementor', 'elementor-implementation-toolkit' ); ?></td>
							<td><span class="eit-status-pill"><?php echo esc_html( $elementor_status ); ?></span></td>
						</tr>
						<tr>
							<td><?php esc_html_e( 'CPT definitions', 'elementor-implementation-toolkit' ); ?></td>
							<td><span class="eit-status-pill"><?php echo esc_html( $cpt_status ); ?></span></td>
						</tr>
						<tr>
							<td><?php esc_html_e( 'Filter presets', 'elementor-implementation-toolkit' ); ?></td>
							<td><span class="eit-status-pill"><?php echo esc_html( $preset_status ); ?></span></td>
						</tr>
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

	private function cct_definition_admin() {
		if ( ! $this->cct_definition_admin ) {
			$this->cct_definition_admin = new CctDefinitionAdmin( $this->renderer() );
		}

		return $this->cct_definition_admin;
	}

	private function cct_item_admin() {
		if ( ! $this->cct_item_admin ) {
			$this->cct_item_admin = new CctItemAdmin();
		}

		return $this->cct_item_admin;
	}
}
