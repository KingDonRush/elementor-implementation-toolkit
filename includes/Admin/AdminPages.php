<?php
/**
 * WordPress admin screens for the implementation toolkit.
 */

namespace EIT\Admin;

use EIT\CPT\CptManager;
use EIT\Support\FilterPresets;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AdminPages {

	const CAPABILITY = 'manage_options';
	const DASHBOARD_SLUG = 'eit-toolkit';
	const FILTERS_SLUG = 'eit-filter-presets';
	const CPT_SLUG = 'eit-cpt-manager';
	const INTEGRATIONS_SLUG = 'eit-integrations';

	public function init_hooks() {
		add_action( 'admin_menu', [ $this, 'register_menu' ] );
		add_action( 'admin_post_eit_save_filter_preset', [ $this, 'save_filter_preset' ] );
		add_action( 'admin_post_eit_delete_filter_preset', [ $this, 'delete_filter_preset' ] );
		add_action( 'admin_post_eit_save_cpt', [ $this, 'save_cpt' ] );
		add_action( 'admin_post_eit_delete_cpt', [ $this, 'delete_cpt' ] );
		add_action( 'admin_post_eit_save_integration_pattern', [ $this, 'save_integration_pattern' ] );
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
			[ $this, 'render_filter_presets' ]
		);

		add_submenu_page(
			self::DASHBOARD_SLUG,
			__( 'CPT Manager', 'elementor-implementation-toolkit' ),
			__( 'CPT Manager', 'elementor-implementation-toolkit' ),
			self::CAPABILITY,
			self::CPT_SLUG,
			[ $this, 'render_cpt_manager' ]
		);

		add_submenu_page(
			self::DASHBOARD_SLUG,
			__( 'Integrations', 'elementor-implementation-toolkit' ),
			__( 'Integrations', 'elementor-implementation-toolkit' ),
			self::CAPABILITY,
			self::INTEGRATIONS_SLUG,
			[ $this, 'render_integrations' ]
		);
	}

	public function render_dashboard() {
		$this->render_shell_start( __( 'Elementor Implementation Toolkit', 'elementor-implementation-toolkit' ), self::DASHBOARD_SLUG );
		?>
		<div class="eit-hero">
			<div>
				<p class="eit-kicker"><?php esc_html_e( 'V0.1 implementation layer', 'elementor-implementation-toolkit' ); ?></p>
				<h2><?php esc_html_e( 'Backend controls for practical Elementor builds.', 'elementor-implementation-toolkit' ); ?></h2>
				<p><?php esc_html_e( 'Create reusable filter presets, manage compact CPT structures, and keep Elementor widgets focused on placement and styling.', 'elementor-implementation-toolkit' ); ?></p>
			</div>
			<div class="eit-hero__actions">
				<a class="button button-primary" href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::FILTERS_SLUG ) ); ?>"><?php esc_html_e( 'Create Filter Preset', 'elementor-implementation-toolkit' ); ?></a>
				<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::CPT_SLUG ) ); ?>"><?php esc_html_e( 'Manage CPTs', 'elementor-implementation-toolkit' ); ?></a>
				<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::INTEGRATIONS_SLUG ) ); ?>"><?php esc_html_e( 'Open Integrations', 'elementor-implementation-toolkit' ); ?></a>
			</div>
		</div>

		<div class="eit-stat-grid">
			<?php $this->render_stat_card( __( 'Filter presets', 'elementor-implementation-toolkit' ), count( FilterPresets::all() ), __( 'Reusable backend filter definitions for the Elementor Filter Controller widget.', 'elementor-implementation-toolkit' ) ); ?>
			<?php $this->render_stat_card( __( 'Managed CPTs', 'elementor-implementation-toolkit' ), count( CptManager::all() ), __( 'Custom post types registered by this toolkit. Deleting a definition does not delete posts.', 'elementor-implementation-toolkit' ) ); ?>
			<?php $this->render_stat_card( __( 'Superpower modules', 'elementor-implementation-toolkit' ), count( IntegrationPatterns::all() ), __( 'Admin contracts for optional implementation modules. Runtime adapters stay separate.', 'elementor-implementation-toolkit' ) ); ?>
		</div>

		<div class="eit-card-grid eit-card-grid--two">
			<section class="eit-card">
				<h3><?php esc_html_e( 'Filter architecture', 'elementor-implementation-toolkit' ); ?></h3>
				<p><?php esc_html_e( 'Presets store provider mode, selectors, URL behavior, pagination, sort options, and detailed filter metadata that would clutter an Elementor widget panel.', 'elementor-implementation-toolkit' ); ?></p>
				<ul class="eit-clean-list">
					<li><?php esc_html_e( 'DOM provider first, adapter-ready later.', 'elementor-implementation-toolkit' ); ?></li>
					<li><?php esc_html_e( 'No dependency on Elementor Pro, JetEngine, or Pro Elements.', 'elementor-implementation-toolkit' ); ?></li>
					<li><?php esc_html_e( 'Widget remains responsible for placement and Style tab polish.', 'elementor-implementation-toolkit' ); ?></li>
				</ul>
			</section>
			<section class="eit-card">
				<h3><?php esc_html_e( 'CPT manager boundaries', 'elementor-implementation-toolkit' ); ?></h3>
				<p><?php esc_html_e( 'This is a compact content-model helper, not a full JetEngine clone. It registers post types, taxonomies, and safe meta boxes from stored options.', 'elementor-implementation-toolkit' ); ?></p>
				<ul class="eit-clean-list">
					<li><?php esc_html_e( 'No arbitrary PHP callbacks in saved settings.', 'elementor-implementation-toolkit' ); ?></li>
					<li><?php esc_html_e( 'REST exposure is explicit per CPT and meta field.', 'elementor-implementation-toolkit' ); ?></li>
					<li><?php esc_html_e( 'Meta fields are sanitized by type on save.', 'elementor-implementation-toolkit' ); ?></li>
				</ul>
			</section>
		</div>
		<?php
		$this->render_shell_end();
	}

	public function render_filter_presets() {
		$presets = FilterPresets::all();
		$edit_id = isset( $_GET['edit'] ) ? sanitize_key( wp_unslash( $_GET['edit'] ) ) : '';
		$preset = $edit_id ? FilterPresets::get( $edit_id ) : null;
		$form_id = 'eit-filter-preset-form';

		if ( ! $preset ) {
			$preset = FilterPresets::blank();
		}

		$filters = ! empty( $preset['filters'] ) ? array_values( $preset['filters'] ) : FilterPresets::blank()['filters'];
		$price_index = null;

		foreach ( $filters as $index => $filter ) {
			if ( 'range' === ( $filter['type'] ?? '' ) ) {
				$price_index = $index;
				break;
			}
		}

		if ( null === $price_index ) {
			$filters[] = FilterPresets::blank_filter(
				[
					'label'      => __( 'Price Range', 'elementor-implementation-toolkit' ),
					'type'       => 'range',
					'key'        => '_price',
					'source'     => 'meta',
					'query_var'  => 'price',
					'compare'    => 'between',
					'data_type'  => 'number',
					'range_min'  => 0,
					'range_max'  => 1000,
					'range_step' => 10,
				]
			);
			$price_index = array_key_last( $filters );
		}

		$preset['filters'] = $filters;
		$price_filter = $filters[ $price_index ];
		$module_cards = array_values(
			array_map(
				function ( $filter, $index ) use ( $price_index ) {
					return [
						'index'    => $index,
						'label'    => $filter['label'] ?: __( 'Filter', 'elementor-implementation-toolkit' ),
						'type'     => $filter['type'] ?? 'search',
						'selected' => (int) $index === (int) $price_index,
						'enabled'  => ! empty( $filter['enabled'] ),
					];
				},
				$filters,
				array_keys( $filters )
			)
		);

		$module_cards[] = [
			'index'    => 'sort',
			'label'    => __( 'Sort', 'elementor-implementation-toolkit' ),
			'type'     => 'sort',
			'selected' => false,
			'enabled'  => ! empty( $preset['show_sort'] ),
		];

		$this->render_shell_start(
			__( 'Filter Preset', 'elementor-implementation-toolkit' ),
			self::FILTERS_SLUG,
			[
				'form_id'       => $form_id,
				'field_name'    => 'preset[name]',
				'field_value'   => $preset['name'] ?: __( 'Product Archive', 'elementor-implementation-toolkit' ),
				'field_prefix'  => __( 'Preset:', 'elementor-implementation-toolkit' ),
				'subtitle'      => __( 'Implementation Architecture Builder', 'elementor-implementation-toolkit' ),
				'primary_label' => __( 'Save Preset', 'elementor-implementation-toolkit' ),
				'preview_label' => __( 'Preview', 'elementor-implementation-toolkit' ),
			]
		);
		$this->render_notice();
		?>
		<?php if ( ! empty( $presets ) ) : ?>
			<div class="eit-library-strip">
				<span><?php esc_html_e( 'Preset library', 'elementor-implementation-toolkit' ); ?></span>
				<div>
					<a class="eit-library-chip <?php echo '' === $edit_id ? 'is-active' : ''; ?>" href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::FILTERS_SLUG ) ); ?>"><?php esc_html_e( 'New preset', 'elementor-implementation-toolkit' ); ?></a>
					<?php foreach ( $presets as $id => $saved ) : ?>
						<a class="eit-library-chip <?php echo $id === $edit_id ? 'is-active' : ''; ?>" href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::FILTERS_SLUG . '&edit=' . rawurlencode( $id ) ) ); ?>">
							<?php echo esc_html( $saved['name'] ?: $id ); ?>
							<small><?php echo esc_html( count( $saved['filters'] ?? [] ) ); ?></small>
						</a>
					<?php endforeach; ?>
				</div>
			</div>
		<?php endif; ?>

		<form id="<?php echo esc_attr( $form_id ); ?>" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="eit-admin-form eit-architecture-form">
			<input type="hidden" name="action" value="eit_save_filter_preset" />
			<input type="hidden" name="preset[id]" value="<?php echo esc_attr( $preset['id'] ?? '' ); ?>" />
			<?php wp_nonce_field( 'eit_save_filter_preset' ); ?>

			<?php foreach ( $filters as $index => $filter ) : ?>
				<?php if ( (int) $index !== (int) $price_index ) : ?>
					<?php $this->render_filter_hidden_fields( $filter, (string) $index ); ?>
				<?php endif; ?>
			<?php endforeach; ?>

			<div class="eit-sot-workbench" data-eit-sot>
				<main class="eit-sot-main">
					<section class="eit-sot-object-card">
						<div class="eit-sot-object-card__icon"><?php echo $this->icon_img( 'filter-object-map' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></div>
						<div>
							<span><?php esc_html_e( 'Filter Preset Object Map', 'elementor-implementation-toolkit' ); ?></span>
							<h2><?php echo esc_html( $preset['name'] ?: __( 'Product Archive', 'elementor-implementation-toolkit' ) ); ?></h2>
							<p><?php esc_html_e( 'Root configuration object controlling provider contract, data binding, modules and controller output.', 'elementor-implementation-toolkit' ); ?></p>
						</div>
						<strong class="eit-sot-status"><?php esc_html_e( 'Active', 'elementor-implementation-toolkit' ); ?></strong>
					</section>

					<nav class="eit-sot-state-nav" aria-label="<?php echo esc_attr__( 'Filter architecture states', 'elementor-implementation-toolkit' ); ?>">
						<button type="button" class="is-active" data-eit-sot-target="root"><?php echo $this->icon_img( 'object' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><?php esc_html_e( 'Object Map', 'elementor-implementation-toolkit' ); ?></button>
						<button type="button" data-eit-sot-target="provider"><?php echo $this->icon_img( 'provider' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><?php esc_html_e( 'Provider Contract', 'elementor-implementation-toolkit' ); ?></button>
						<button type="button" data-eit-sot-target="modules"><?php echo $this->icon_img( 'module' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><?php esc_html_e( 'Price Module', 'elementor-implementation-toolkit' ); ?></button>
						<button type="button" data-eit-sot-target="output"><?php echo $this->icon_img( 'output' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><?php esc_html_e( 'Output Behavior', 'elementor-implementation-toolkit' ); ?></button>
					</nav>

					<section class="eit-sot-panel is-active" data-eit-sot-panel="root">
						<div class="eit-sot-map">
							<div class="eit-sot-root-node">
								<?php echo $this->icon_img( 'filter-object-map' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
								<div>
									<span><?php esc_html_e( 'ROOT', 'elementor-implementation-toolkit' ); ?></span>
									<strong><?php esc_html_e( 'Filter Preset', 'elementor-implementation-toolkit' ); ?></strong>
									<small><?php esc_html_e( 'Owns all configuration layers.', 'elementor-implementation-toolkit' ); ?></small>
								</div>
							</div>
							<div class="eit-sot-layer-stack">
								<?php $this->render_sot_layer_card( '1', 'scope', __( 'Identity & Scope', 'elementor-implementation-toolkit' ), __( 'Product Archive', 'elementor-implementation-toolkit' ), [ __( 'Slug: product-archive', 'elementor-implementation-toolkit' ), __( 'Intent: Archive Filtering', 'elementor-implementation-toolkit' ), __( 'Status: Active', 'elementor-implementation-toolkit' ) ], 'root' ); ?>
								<?php $this->render_sot_layer_card( '2', 'provider', __( 'Provider Contract', 'elementor-implementation-toolkit' ), __( 'DOM Provider', 'elementor-implementation-toolkit' ), [ __( 'Target: Products #1', 'elementor-implementation-toolkit' ), __( 'Item: .product-card', 'elementor-implementation-toolkit' ), __( 'Identity: Auto Priority', 'elementor-implementation-toolkit' ) ], 'provider' ); ?>
								<?php $this->render_sot_layer_card( '3', 'schema', __( 'Data Binding Schema', 'elementor-implementation-toolkit' ), __( 'Meta Field + Taxonomy', 'elementor-implementation-toolkit' ), [ __( 'Key: _price', 'elementor-implementation-toolkit' ), __( 'Compare: BETWEEN', 'elementor-implementation-toolkit' ), __( 'Query var: price', 'elementor-implementation-toolkit' ) ], 'modules' ); ?>
								<?php $this->render_sot_layer_card( '4', 'module', __( 'Filter Modules', 'elementor-implementation-toolkit' ), sprintf( /* translators: %d: module count. */ __( '%d modules', 'elementor-implementation-toolkit' ), count( $module_cards ) ), wp_list_pluck( $module_cards, 'label' ), 'modules', 'purple' ); ?>
								<?php $this->render_sot_layer_card( '5', 'output', __( 'Controller Output', 'elementor-implementation-toolkit' ), __( 'AJAX + URL State', 'elementor-implementation-toolkit' ), [ __( 'Apply: Auto', 'elementor-implementation-toolkit' ), __( 'Chips: Enabled', 'elementor-implementation-toolkit' ), __( 'Pagination: Numbered', 'elementor-implementation-toolkit' ) ], 'output', 'blue' ); ?>
							</div>
						</div>
					</section>

					<section class="eit-sot-panel" data-eit-sot-panel="provider">
						<div class="eit-provider-builder">
							<div class="eit-sot-section-title">
								<?php echo $this->icon_img( 'provider' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
								<div>
									<h2><?php esc_html_e( 'Provider Contract Builder', 'elementor-implementation-toolkit' ); ?></h2>
									<p><?php esc_html_e( 'Defines how this preset connects to an existing listing without rendering a grid.', 'elementor-implementation-toolkit' ); ?></p>
								</div>
							</div>
							<div class="eit-provider-steps">
								<section class="eit-provider-step is-selected">
									<span>1</span>
									<div>
										<h3><?php esc_html_e( 'Provider Mode', 'elementor-implementation-toolkit' ); ?></h3>
										<?php $this->select_field( 'preset[provider_mode]', __( 'Mode', 'elementor-implementation-toolkit' ), $preset['provider_mode'] ?? 'dom', FilterPresets::provider_modes() ); ?>
									</div>
								</section>
								<section class="eit-provider-step">
									<span>2</span>
									<div>
										<h3><?php esc_html_e( 'Target Discovery', 'elementor-implementation-toolkit' ); ?></h3>
										<div class="eit-detected-targets">
											<i><?php esc_html_e( 'Products #1', 'elementor-implementation-toolkit' ); ?></i>
											<i><?php esc_html_e( 'Products #2', 'elementor-implementation-toolkit' ); ?></i>
											<i><?php esc_html_e( 'Listing #3', 'elementor-implementation-toolkit' ); ?></i>
										</div>
										<?php $this->text_field( 'preset[target_selector]', __( 'Manual CSS selector', 'elementor-implementation-toolkit' ), $preset['target_selector'] ?? '', '.products-grid' ); ?>
									</div>
								</section>
								<section class="eit-provider-step">
									<span>3</span>
									<div>
										<h3><?php esc_html_e( 'Item Boundary', 'elementor-implementation-toolkit' ); ?></h3>
										<?php $this->text_field( 'preset[item_selector]', __( 'Item selector', 'elementor-implementation-toolkit' ), $preset['item_selector'] ?? '', '.product-card, article' ); ?>
										<small><?php esc_html_e( 'Fallback selectors and exclude nodes stay adapter-ready.', 'elementor-implementation-toolkit' ); ?></small>
									</div>
								</section>
								<section class="eit-provider-step eit-provider-step--resolver">
									<span>4</span>
									<div>
										<h3><?php esc_html_e( 'Identity Resolver', 'elementor-implementation-toolkit' ); ?></h3>
										<ol class="eit-resolver-list">
											<li><strong>data-eit-post-id</strong><em><?php esc_html_e( 'High', 'elementor-implementation-toolkit' ); ?></em></li>
											<li><strong>data-post-id</strong><em><?php esc_html_e( 'High', 'elementor-implementation-toolkit' ); ?></em></li>
											<li><strong>permalink</strong><em><?php esc_html_e( 'Medium', 'elementor-implementation-toolkit' ); ?></em></li>
											<li><strong>class post-&lt;id&gt;</strong><em><?php esc_html_e( 'Low', 'elementor-implementation-toolkit' ); ?></em></li>
											<li><strong>visible text</strong><em><?php esc_html_e( 'Fallback', 'elementor-implementation-toolkit' ); ?></em></li>
										</ol>
									</div>
								</section>
								<section class="eit-provider-step">
									<span>5</span>
									<div>
										<h3><?php esc_html_e( 'Data Availability', 'elementor-implementation-toolkit' ); ?></h3>
										<div class="eit-detected-targets">
											<i><?php esc_html_e( 'Data attributes: 12', 'elementor-implementation-toolkit' ); ?></i>
											<i><?php esc_html_e( 'Visible text: 28', 'elementor-implementation-toolkit' ); ?></i>
											<i><?php esc_html_e( 'Taxonomy hints: 6', 'elementor-implementation-toolkit' ); ?></i>
											<i><?php esc_html_e( 'Meta hints: 8', 'elementor-implementation-toolkit' ); ?></i>
										</div>
										<small><?php esc_html_e( 'This is an admin contract preview. Live detection is resolved by the editor and frontend scripts.', 'elementor-implementation-toolkit' ); ?></small>
									</div>
								</section>
							</div>
						</div>
					</section>

					<section class="eit-sot-panel" data-eit-sot-panel="modules">
						<div class="eit-module-schema">
							<div class="eit-parent-context">
								<span><?php esc_html_e( 'Parent Context (Inherited)', 'elementor-implementation-toolkit' ); ?></span>
								<div>
									<strong><?php echo $this->icon_img( 'provider' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><?php esc_html_e( 'DOM Provider', 'elementor-implementation-toolkit' ); ?></strong>
									<strong><?php echo $this->icon_img( 'existing-cards' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><?php esc_html_e( 'Products #1', 'elementor-implementation-toolkit' ); ?></strong>
									<strong><?php echo $this->icon_img( 'target-bullseye' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><?php echo esc_html( $preset['item_selector'] ?: '.product-card' ); ?></strong>
									<strong><?php echo $this->icon_img( 'url-router' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><?php esc_html_e( 'URL Sync', 'elementor-implementation-toolkit' ); ?></strong>
								</div>
							</div>
							<div class="eit-module-grid">
								<aside class="eit-module-list">
									<?php foreach ( $module_cards as $card ) : ?>
										<article class="<?php echo $card['selected'] ? 'is-selected' : ''; ?>">
											<?php echo $this->icon_img( $this->filter_icon_name( $card['type'] ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
											<div>
												<strong><?php echo esc_html( $card['label'] ); ?></strong>
												<small><?php echo esc_html( ucfirst( str_replace( '_', ' ', $card['type'] ) ) ); ?></small>
											</div>
											<i><?php echo $card['enabled'] ? esc_html__( 'Enabled', 'elementor-implementation-toolkit' ) : esc_html__( 'Draft', 'elementor-implementation-toolkit' ); ?></i>
										</article>
									<?php endforeach; ?>
								</aside>
								<section class="eit-price-schema">
									<div class="eit-price-schema__header">
										<?php echo $this->icon_img( 'range-sliders' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
										<div>
											<h2><?php esc_html_e( 'Price Range', 'elementor-implementation-toolkit' ); ?></h2>
											<p><?php esc_html_e( 'Range module configuration and data binding.', 'elementor-implementation-toolkit' ); ?></p>
										</div>
										<span><?php esc_html_e( 'Module Override', 'elementor-implementation-toolkit' ); ?></span>
									</div>
									<?php $this->render_price_module_fields( $price_filter, (string) $price_index ); ?>
								</section>
							</div>
						</div>
					</section>

					<section class="eit-sot-panel" data-eit-sot-panel="output">
						<div class="eit-output-behavior">
							<div class="eit-sot-section-title">
								<?php echo $this->icon_img( 'output' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
								<div>
									<h2><?php esc_html_e( 'Output Behavior', 'elementor-implementation-toolkit' ); ?></h2>
									<p><?php esc_html_e( 'Defines how filter state is applied, represented and synchronized.', 'elementor-implementation-toolkit' ); ?></p>
								</div>
							</div>
							<div class="eit-output-grid">
								<section class="is-selected">
									<h3><?php echo $this->icon_img( 'apply-strategy' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><?php esc_html_e( 'Apply Strategy', 'elementor-implementation-toolkit' ); ?></h3>
									<?php $this->select_field( 'preset[apply_mode]', __( 'Apply mode', 'elementor-implementation-toolkit' ), $preset['apply_mode'] ?? 'auto', FilterPresets::apply_modes() ); ?>
								</section>
								<section>
									<h3><?php echo $this->icon_img( 'url-router' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><?php esc_html_e( 'URL State', 'elementor-implementation-toolkit' ); ?></h3>
									<?php $this->checkbox_field( 'preset[sync_url]', __( 'Sync URL', 'elementor-implementation-toolkit' ), ! empty( $preset['sync_url'] ) ); ?>
								</section>
								<section>
									<h3><?php echo $this->icon_img( 'chips' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><?php esc_html_e( 'Active Chips', 'elementor-implementation-toolkit' ); ?></h3>
									<?php $this->checkbox_field( 'preset[show_active_chips]', __( 'Show chips', 'elementor-implementation-toolkit' ), ! empty( $preset['show_active_chips'] ) ); ?>
								</section>
								<section>
									<h3><?php echo $this->icon_img( 'result-count' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><?php esc_html_e( 'Result Count', 'elementor-implementation-toolkit' ); ?></h3>
									<?php $this->checkbox_field( 'preset[show_result_count]', __( 'Live count', 'elementor-implementation-toolkit' ), ! empty( $preset['show_result_count'] ) ); ?>
								</section>
								<section>
									<h3><?php echo $this->icon_img( 'sort-arrows' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><?php esc_html_e( 'Sorting', 'elementor-implementation-toolkit' ); ?></h3>
									<?php $this->checkbox_field( 'preset[show_sort]', __( 'Sort control', 'elementor-implementation-toolkit' ), ! empty( $preset['show_sort'] ) ); ?>
								</section>
								<section>
									<h3><?php echo $this->icon_img( 'pagination' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><?php esc_html_e( 'Pagination', 'elementor-implementation-toolkit' ); ?></h3>
									<?php $this->number_field( 'preset[per_page]', __( 'Per page', 'elementor-implementation-toolkit' ), $preset['per_page'] ?? 9, 1, 96, 1 ); ?>
									<?php $this->select_field( 'preset[pagination_type]', __( 'Type', 'elementor-implementation-toolkit' ), $preset['pagination_type'] ?? 'numbers', FilterPresets::pagination_types() ); ?>
								</section>
							</div>
							<div class="eit-runtime-flow">
								<span><?php echo $this->icon_img( 'url-router' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><?php esc_html_e( 'URL State', 'elementor-implementation-toolkit' ); ?></span>
								<b>→</b>
								<span><?php echo $this->icon_img( 'ajax-bolt' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><?php esc_html_e( 'AJAX Resolver', 'elementor-implementation-toolkit' ); ?></span>
								<b>→</b>
								<span><?php echo $this->icon_img( 'dom-code' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><?php esc_html_e( 'DOM Provider', 'elementor-implementation-toolkit' ); ?></span>
								<b>→</b>
								<span><?php echo $this->icon_img( 'reset-refresh' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><?php esc_html_e( 'Apply / Reset', 'elementor-implementation-toolkit' ); ?></span>
							</div>
						</div>
					</section>
				</main>

				<aside class="eit-sot-inspector">
					<div class="eit-sot-inspector__head">
						<?php echo $this->icon_img( 'inspector-sliders' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
						<div>
							<span><?php esc_html_e( 'Inspector', 'elementor-implementation-toolkit' ); ?></span>
							<strong data-eit-sot-inspector-title><?php esc_html_e( 'Filter Preset (Root)', 'elementor-implementation-toolkit' ); ?></strong>
						</div>
					</div>
					<section class="eit-sot-inspector-panel is-active" data-eit-sot-inspector-panel="root">
						<h3><?php esc_html_e( 'Identity', 'elementor-implementation-toolkit' ); ?></h3>
						<?php $this->text_field( 'preset[slug]', __( 'Slug', 'elementor-implementation-toolkit' ), $preset['slug'] ?? '', 'product-archive' ); ?>
						<?php $this->text_field( 'preset[description]', __( 'Description', 'elementor-implementation-toolkit' ), $preset['description'] ?? '', 'Main filter configuration for product archive listing' ); ?>
						<h3><?php esc_html_e( 'Scope', 'elementor-implementation-toolkit' ); ?></h3>
						<div class="eit-static-contract">
							<dl><dt><?php esc_html_e( 'Intent', 'elementor-implementation-toolkit' ); ?></dt><dd><?php esc_html_e( 'Archive Filtering', 'elementor-implementation-toolkit' ); ?></dd></dl>
							<dl><dt><?php esc_html_e( 'Applies to', 'elementor-implementation-toolkit' ); ?></dt><dd><?php esc_html_e( 'All Queries', 'elementor-implementation-toolkit' ); ?></dd></dl>
							<dl><dt><?php esc_html_e( 'Version', 'elementor-implementation-toolkit' ); ?></dt><dd>1.0.0</dd></dl>
						</div>
					</section>
					<section class="eit-sot-inspector-panel" data-eit-sot-inspector-panel="provider">
						<h3><?php esc_html_e( 'Detection Preview', 'elementor-implementation-toolkit' ); ?></h3>
						<div class="eit-detection-preview">
							<div></div><div class="is-selected"></div><div></div>
						</div>
						<p><?php esc_html_e( 'Hover targets on the page later to highlight detection. V0.2 stores the contract only.', 'elementor-implementation-toolkit' ); ?></p>
						<h3><?php esc_html_e( 'Fallback Behavior', 'elementor-implementation-toolkit' ); ?></h3>
						<div class="eit-static-contract">
							<dl><dt><?php esc_html_e( 'If no ID is found', 'elementor-implementation-toolkit' ); ?></dt><dd><?php esc_html_e( 'Use visible text as last resort', 'elementor-implementation-toolkit' ); ?></dd></dl>
							<dl><dt><?php esc_html_e( 'Warning', 'elementor-implementation-toolkit' ); ?></dt><dd><?php esc_html_e( 'Show in console during development', 'elementor-implementation-toolkit' ); ?></dd></dl>
						</div>
					</section>
					<section class="eit-sot-inspector-panel" data-eit-sot-inspector-panel="modules">
						<h3><?php esc_html_e( 'Price Range Inspector', 'elementor-implementation-toolkit' ); ?></h3>
						<p><?php esc_html_e( 'This inspector edits the selected range module. Inherited rows stay represented in the center schema.', 'elementor-implementation-toolkit' ); ?></p>
						<div class="eit-static-contract">
							<dl><dt><?php esc_html_e( 'Inherited', 'elementor-implementation-toolkit' ); ?></dt><dd><?php esc_html_e( 'Provider, target listing, item selector, URL sync', 'elementor-implementation-toolkit' ); ?></dd></dl>
							<dl><dt><?php esc_html_e( 'Override', 'elementor-implementation-toolkit' ); ?></dt><dd><?php esc_html_e( 'Label, bounds, display, behavior', 'elementor-implementation-toolkit' ); ?></dd></dl>
						</div>
					</section>
					<section class="eit-sot-inspector-panel" data-eit-sot-inspector-panel="output">
						<h3><?php esc_html_e( 'Output Copy', 'elementor-implementation-toolkit' ); ?></h3>
						<?php $this->text_field( 'preset[result_count_text]', __( 'Result count', 'elementor-implementation-toolkit' ), $preset['result_count_text'] ?? '', '{count} results' ); ?>
						<?php $this->text_field( 'preset[empty_text]', __( 'Empty state', 'elementor-implementation-toolkit' ), $preset['empty_text'] ?? '', 'No matching items found.' ); ?>
						<?php $this->text_field( 'preset[apply_text]', __( 'Apply', 'elementor-implementation-toolkit' ), $preset['apply_text'] ?? '', 'Apply filters' ); ?>
						<?php $this->text_field( 'preset[reset_text]', __( 'Reset', 'elementor-implementation-toolkit' ), $preset['reset_text'] ?? '', 'Reset' ); ?>
						<h3><?php esc_html_e( 'Sort Contract', 'elementor-implementation-toolkit' ); ?></h3>
						<?php $this->text_field( 'preset[sort_label]', __( 'Label', 'elementor-implementation-toolkit' ), $preset['sort_label'] ?? '', 'Sort by' ); ?>
						<?php $this->render_sort_options_builder( $preset['sort_options'] ?? '' ); ?>
						<h3><?php esc_html_e( 'Pagination Copy', 'elementor-implementation-toolkit' ); ?></h3>
						<?php $this->text_field( 'preset[previous_text]', __( 'Previous', 'elementor-implementation-toolkit' ), $preset['previous_text'] ?? '', 'Previous' ); ?>
						<?php $this->text_field( 'preset[next_text]', __( 'Next', 'elementor-implementation-toolkit' ), $preset['next_text'] ?? '', 'Next' ); ?>
					</section>
					<?php if ( $edit_id ) : ?>
						<section class="eit-sot-danger">
							<h3><?php esc_html_e( 'Danger Zone', 'elementor-implementation-toolkit' ); ?></h3>
							<a class="eit-danger-link submitdelete" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=eit_delete_filter_preset&id=' . rawurlencode( $edit_id ) ), 'eit_delete_filter_preset_' . $edit_id ) ); ?>"><?php esc_html_e( 'Delete preset', 'elementor-implementation-toolkit' ); ?></a>
						</section>
					<?php endif; ?>
				</aside>
			</div>
		</form>

		<div id="eit-preview-modal" class="eit-modal-overlay">
			<div class="eit-modal-panel">
				<div class="eit-modal-header">
					<h3><?php esc_html_e( 'Filter Controller Preview', 'elementor-implementation-toolkit' ); ?></h3>
					<button type="button" class="eit-modal-close" data-eit-modal-close>&times;</button>
				</div>
				<div class="eit-modal-body">
					<div id="eit-preview-content" class="eit-preview-controller"></div>
				</div>
			</div>
		</div>

		<?php
		$this->render_shell_end();
	}

	public function render_cpt_manager() {
		$definitions = CptManager::all();
		$edit_slug = isset( $_GET['edit'] ) ? sanitize_key( wp_unslash( $_GET['edit'] ) ) : '';
		$definition = $edit_slug ? CptManager::get( $edit_slug ) : null;
		$form_id = 'eit-cpt-form';

		if ( ! $definition ) {
			$definition = CptManager::blank();
		}

		$this->render_shell_start(
			__( 'CPT Manager', 'elementor-implementation-toolkit' ),
			self::CPT_SLUG,
			[
				'form_id'       => $form_id,
				'field_name'    => 'cpt[plural]',
				'field_value'   => $definition['plural'] ?: __( 'Products', 'elementor-implementation-toolkit' ),
				'field_prefix'  => __( 'Content Type:', 'elementor-implementation-toolkit' ),
				'subtitle'      => __( 'Visual Content Architecture Builder', 'elementor-implementation-toolkit' ),
				'primary_label' => __( 'Save', 'elementor-implementation-toolkit' ),
				'preview_label' => __( 'Model Preview', 'elementor-implementation-toolkit' ),
			]
		);
		$this->render_notice();
		?>
		<?php if ( ! empty( $definitions ) ) : ?>
			<div class="eit-library-strip">
				<span><?php esc_html_e( 'Content library', 'elementor-implementation-toolkit' ); ?></span>
				<div>
					<a class="eit-library-chip <?php echo '' === $edit_slug ? 'is-active' : ''; ?>" href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::CPT_SLUG ) ); ?>"><?php esc_html_e( 'New CPT', 'elementor-implementation-toolkit' ); ?></a>
					<?php foreach ( $definitions as $slug => $saved ) : ?>
						<a class="eit-library-chip <?php echo $slug === $edit_slug ? 'is-active' : ''; ?>" href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::CPT_SLUG . '&edit=' . rawurlencode( $slug ) ) ); ?>">
							<?php echo esc_html( $saved['plural'] ?: $slug ); ?>
							<small><?php echo esc_html( count( $saved['meta_fields'] ?? [] ) ); ?></small>
						</a>
					<?php endforeach; ?>
				</div>
			</div>
		<?php endif; ?>

		<form id="<?php echo esc_attr( $form_id ); ?>" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="eit-admin-form eit-architecture-form">
			<input type="hidden" name="action" value="eit_save_cpt" />
			<?php wp_nonce_field( 'eit_save_cpt' ); ?>

			<div class="eit-builder-layout eit-builder-layout--architecture eit-builder-layout--cpt" data-eit-repeat-scope>
				<main class="eit-architecture-board">
					<div class="eit-architecture-canvas">
						<section class="eit-arch-column eit-arch-column--source is-selected" data-eit-builder-title="<?php echo esc_attr__( 'Identity & Labels', 'elementor-implementation-toolkit' ); ?>" data-eit-builder-type="<?php echo esc_attr__( 'Post type root', 'elementor-implementation-toolkit' ); ?>">
							<div class="eit-arch-heading">
								<?php echo $this->icon_img( 'source-database' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
								<h2><?php esc_html_e( 'Identity & Labels', 'elementor-implementation-toolkit' ); ?></h2>
							</div>
							<div class="eit-arch-stack">
								<article class="eit-arch-node">
									<?php echo $this->icon_img( 'post-type-document' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
									<div>
										<strong><?php esc_html_e( 'Post Type', 'elementor-implementation-toolkit' ); ?></strong>
										<?php $this->text_field( 'cpt[slug]', __( 'Slug', 'elementor-implementation-toolkit' ), $definition['slug'] ?? '', 'product', 'text', ! empty( $definition['slug'] ) ); ?>
									</div>
								</article>
								<article class="eit-arch-node">
									<?php echo $this->icon_img( 'meta-tag' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
									<div>
										<strong><?php esc_html_e( 'Labels', 'elementor-implementation-toolkit' ); ?></strong>
										<?php $this->text_field( 'cpt[singular]', __( 'Singular', 'elementor-implementation-toolkit' ), $definition['singular'] ?? '', 'Product' ); ?>
									</div>
								</article>
								<article class="eit-arch-node">
									<?php echo $this->icon_img( 'taxonomy-folder' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
									<div>
										<strong><?php esc_html_e( 'URL Shape', 'elementor-implementation-toolkit' ); ?></strong>
										<?php $this->text_field( 'cpt[rewrite_slug]', __( 'Rewrite slug', 'elementor-implementation-toolkit' ), $definition['rewrite_slug'] ?? '', 'products' ); ?>
									</div>
								</article>
								<article class="eit-arch-node">
									<?php echo $this->icon_img( 'runtime-gear' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
									<div>
										<strong><?php esc_html_e( 'Admin Identity', 'elementor-implementation-toolkit' ); ?></strong>
										<?php $this->text_field( 'cpt[menu_icon]', __( 'Dashicon', 'elementor-implementation-toolkit' ), $definition['menu_icon'] ?? '', 'dashicons-products' ); ?>
									</div>
								</article>
							</div>
						</section>

						<div class="eit-flow-connector" aria-hidden="true"><span>→</span></div>

						<section class="eit-arch-column eit-arch-column--contract" data-eit-builder-title="<?php echo esc_attr__( 'Registration Contract', 'elementor-implementation-toolkit' ); ?>" data-eit-builder-type="<?php echo esc_attr__( 'WordPress behavior', 'elementor-implementation-toolkit' ); ?>">
							<div class="eit-arch-heading">
								<?php echo $this->icon_img( 'query-funnel' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
								<h2><?php esc_html_e( 'Registration Contract', 'elementor-implementation-toolkit' ); ?></h2>
							</div>
							<div class="eit-vertical-flow">
								<article class="eit-arch-node">
									<?php echo $this->icon_img( 'context-crosshair' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
									<div>
										<strong><?php esc_html_e( 'Visibility', 'elementor-implementation-toolkit' ); ?></strong>
										<div class="eit-toggle-grid">
											<?php $this->checkbox_field( 'cpt[public]', __( 'Public', 'elementor-implementation-toolkit' ), ! empty( $definition['public'] ) ); ?>
											<?php $this->checkbox_field( 'cpt[show_in_rest]', __( 'REST / editor', 'elementor-implementation-toolkit' ), ! empty( $definition['show_in_rest'] ) ); ?>
										</div>
									</div>
								</article>
								<article class="eit-arch-node">
									<?php echo $this->icon_img( 'constraints-sliders' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
									<div>
										<strong><?php esc_html_e( 'Shape', 'elementor-implementation-toolkit' ); ?></strong>
										<div class="eit-toggle-grid">
											<?php $this->checkbox_field( 'cpt[has_archive]', __( 'Archive', 'elementor-implementation-toolkit' ), ! empty( $definition['has_archive'] ) ); ?>
											<?php $this->checkbox_field( 'cpt[hierarchical]', __( 'Hierarchical', 'elementor-implementation-toolkit' ), ! empty( $definition['hierarchical'] ) ); ?>
										</div>
									</div>
								</article>
								<article class="eit-arch-node">
									<?php echo $this->icon_img( 'base-query-layers' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
									<div>
										<strong><?php esc_html_e( 'Editor Supports', 'elementor-implementation-toolkit' ); ?></strong>
										<div class="eit-token-grid">
											<?php foreach ( CptManager::supports() as $support => $label ) : ?>
												<label class="eit-token-check">
													<input type="checkbox" name="cpt[supports][]" value="<?php echo esc_attr( $support ); ?>" <?php checked( in_array( $support, $definition['supports'] ?? [], true ) ); ?> />
													<span><?php echo esc_html( $label ); ?></span>
												</label>
											<?php endforeach; ?>
										</div>
									</div>
								</article>
							</div>
						</section>

						<div class="eit-flow-connector" aria-hidden="true"><span>→</span></div>

						<section class="eit-arch-column eit-arch-column--filters" data-eit-repeat-scope data-eit-builder-title="<?php echo esc_attr__( 'Taxonomy Layer', 'elementor-implementation-toolkit' ); ?>" data-eit-builder-type="<?php echo esc_attr__( 'Classification children', 'elementor-implementation-toolkit' ); ?>">
							<div class="eit-arch-heading">
								<?php echo $this->icon_img( 'taxonomy-folder' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
								<h2><?php esc_html_e( 'Taxonomy Layer', 'elementor-implementation-toolkit' ); ?></h2>
								<button type="button" class="eit-node-add" data-eit-add-row="taxonomy">+</button>
							</div>
							<div class="eit-repeater eit-filter-layer" data-eit-repeater="taxonomy" data-next-index="<?php echo esc_attr( count( $definition['taxonomies'] ?? [] ) ); ?>">
								<?php if ( empty( $definition['taxonomies'] ) ) : ?>
									<div class="eit-empty-node"><?php esc_html_e( 'No taxonomy children yet.', 'elementor-implementation-toolkit' ); ?></div>
								<?php else : ?>
									<?php foreach ( $definition['taxonomies'] ?? [] as $index => $taxonomy ) : ?>
										<?php $this->render_taxonomy_row( $taxonomy, (string) $index ); ?>
									<?php endforeach; ?>
								<?php endif; ?>
							</div>
							<template data-eit-template="taxonomy">
								<?php $this->render_taxonomy_row( CptManager::blank_taxonomy(), '__index__' ); ?>
							</template>
						</section>

						<div class="eit-flow-connector" aria-hidden="true"><span>→</span></div>

						<section class="eit-arch-column eit-arch-column--target" data-eit-repeat-scope data-eit-builder-title="<?php echo esc_attr__( 'Meta Layer', 'elementor-implementation-toolkit' ); ?>" data-eit-builder-type="<?php echo esc_attr__( 'Typed fields', 'elementor-implementation-toolkit' ); ?>">
							<div class="eit-arch-heading">
								<?php echo $this->icon_img( 'meta-tag' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
								<h2><?php esc_html_e( 'Meta Layer', 'elementor-implementation-toolkit' ); ?></h2>
								<button type="button" class="eit-node-add" data-eit-add-row="meta">+</button>
							</div>
							<div class="eit-repeater eit-filter-layer" data-eit-repeater="meta" data-next-index="<?php echo esc_attr( count( $definition['meta_fields'] ?? [] ) ); ?>">
								<?php if ( empty( $definition['meta_fields'] ) ) : ?>
									<div class="eit-empty-node"><?php esc_html_e( 'No meta fields yet.', 'elementor-implementation-toolkit' ); ?></div>
								<?php else : ?>
									<?php foreach ( $definition['meta_fields'] ?? [] as $index => $field ) : ?>
										<?php $this->render_meta_field_row( $field, (string) $index ); ?>
									<?php endforeach; ?>
								<?php endif; ?>
							</div>
							<template data-eit-template="meta">
								<?php $this->render_meta_field_row( CptManager::blank_meta_field(), '__index__' ); ?>
							</template>
						</section>

						<section class="eit-runtime-rail" data-eit-builder-title="<?php echo esc_attr__( 'REST/Admin Exposure', 'elementor-implementation-toolkit' ); ?>" data-eit-builder-type="<?php echo esc_attr__( 'Registration output', 'elementor-implementation-toolkit' ); ?>">
							<div class="eit-arch-heading">
								<?php echo $this->icon_img( 'runtime-gear' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
								<h2><?php esc_html_e( 'REST/Admin Exposure', 'elementor-implementation-toolkit' ); ?></h2>
							</div>
							<div class="eit-runtime-nodes">
								<span class="eit-rt-node"><?php echo $this->icon_img( 'existing-cards' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?> <?php esc_html_e( 'Admin UI', 'elementor-implementation-toolkit' ); ?></span>
								<span class="eit-rt-arrow" aria-hidden="true">→</span>
								<span class="eit-rt-node"><?php echo $this->icon_img( 'url-chain' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?> <?php esc_html_e( 'Rewrite', 'elementor-implementation-toolkit' ); ?></span>
								<span class="eit-rt-arrow" aria-hidden="true">→</span>
								<span class="eit-rt-node"><?php echo $this->icon_img( 'ajax-bolt' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?> <?php esc_html_e( 'REST Schema', 'elementor-implementation-toolkit' ); ?></span>
								<span class="eit-rt-arrow" aria-hidden="true">→</span>
								<span class="eit-rt-node"><?php echo $this->icon_img( 'reset-refresh' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?> <?php esc_html_e( 'Rewrite Flush', 'elementor-implementation-toolkit' ); ?></span>
							</div>
						</section>
					</div>
				</main>

				<aside class="eit-inspector">
					<div class="eit-inspector__panel">
						<div class="eit-inspector__top">
							<?php echo $this->icon_img( 'inspector-sliders' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
							<div>
								<span><?php esc_html_e( 'Inspector', 'elementor-implementation-toolkit' ); ?></span>
								<h3 data-eit-inspector-title><?php esc_html_e( 'Identity & Labels', 'elementor-implementation-toolkit' ); ?></h3>
								<p data-eit-inspector-type><?php esc_html_e( 'Post type root', 'elementor-implementation-toolkit' ); ?></p>
							</div>
						</div>
						<div class="eit-inspector-sections">
							<section>
								<h4><?php esc_html_e( 'Description', 'elementor-implementation-toolkit' ); ?></h4>
								<?php $this->text_field( 'cpt[description]', __( 'Intent', 'elementor-implementation-toolkit' ), $definition['description'] ?? '', 'Products used in the budget demo' ); ?>
							</section>
							<section>
								<h4><?php esc_html_e( 'Registration Notes', 'elementor-implementation-toolkit' ); ?></h4>
								<div class="eit-state-preview">
									<strong><?php esc_html_e( 'Managed output', 'elementor-implementation-toolkit' ); ?></strong>
									<div class="eit-preview-stack">
										<i><?php esc_html_e( 'Post type', 'elementor-implementation-toolkit' ); ?></i>
										<i><?php esc_html_e( 'Taxonomies', 'elementor-implementation-toolkit' ); ?></i>
										<i><?php esc_html_e( 'Meta box', 'elementor-implementation-toolkit' ); ?></i>
									</div>
									<small><?php esc_html_e( 'Deleting a definition does not delete content.', 'elementor-implementation-toolkit' ); ?></small>
								</div>
							</section>
							<?php if ( $edit_slug ) : ?>
								<section>
									<h4><?php esc_html_e( 'Danger Zone', 'elementor-implementation-toolkit' ); ?></h4>
									<a class="eit-danger-link submitdelete" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=eit_delete_cpt&slug=' . rawurlencode( $edit_slug ) ), 'eit_delete_cpt_' . $edit_slug ) ); ?>"><?php esc_html_e( 'Delete definition', 'elementor-implementation-toolkit' ); ?></a>
								</section>
							<?php endif; ?>
						</div>
					</div>
				</aside>
			</div>
		</form>

		<div id="eit-cpt-preview-modal" class="eit-modal-overlay">
			<div class="eit-modal-panel">
				<div class="eit-modal-header">
					<h3><?php esc_html_e( 'Registration Preview', 'elementor-implementation-toolkit' ); ?></h3>
					<button type="button" class="eit-modal-close" data-eit-modal-close>&times;</button>
				</div>
				<div class="eit-modal-body">
					<div id="eit-cpt-preview-content" class="eit-reg-preview"></div>
				</div>
			</div>
		</div>

		<?php
		$this->render_shell_end();
	}

	public function render_integrations() {
		$patterns = IntegrationPatterns::all();
		$active_id = isset( $_GET['pattern'] ) ? sanitize_key( wp_unslash( $_GET['pattern'] ) ) : '';

		if ( ! $active_id || ! isset( $patterns[ $active_id ] ) ) {
			$active_id = key( $patterns );
		}

		$pattern = $patterns[ $active_id ];
		$form_id = 'eit-integration-form';

		$this->render_shell_start(
			__( 'Integrations', 'elementor-implementation-toolkit' ),
			self::INTEGRATIONS_SLUG,
			[
				'form_id'       => $form_id,
				'field_value'   => $pattern['title'],
				'field_prefix'  => __( 'Superpower:', 'elementor-implementation-toolkit' ),
				'subtitle'      => __( 'Integrations / Superpowers Architecture', 'elementor-implementation-toolkit' ),
				'primary_label' => __( 'Save', 'elementor-implementation-toolkit' ),
				'preview_label' => __( 'Module Preview', 'elementor-implementation-toolkit' ),
				'status'        => $pattern['status'],
				'status_label'  => IntegrationPatterns::statuses()[ $pattern['status'] ] ?? $pattern['status'],
			]
		);
		$this->render_notice();
		?>
		<div class="eit-library-strip eit-library-strip--patterns">
			<span><?php esc_html_e( 'Superpowers', 'elementor-implementation-toolkit' ); ?></span>
			<div>
				<?php foreach ( $patterns as $id => $saved ) : ?>
					<a class="eit-library-chip eit-library-chip--module <?php echo $id === $active_id ? 'is-active' : ''; ?>" href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::INTEGRATIONS_SLUG . '&pattern=' . rawurlencode( $id ) ) ); ?>">
						<?php echo $this->icon_img( $saved['icon'], 'eit-library-chip__icon' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
						<?php echo esc_html( $saved['title'] ); ?>
						<small class="is-<?php echo esc_attr( $saved['status'] ); ?>"><?php echo esc_html( IntegrationPatterns::statuses()[ $saved['status'] ] ?? $saved['status'] ); ?></small>
					</a>
				<?php endforeach; ?>
			</div>
		</div>

		<form id="<?php echo esc_attr( $form_id ); ?>" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="eit-admin-form eit-architecture-form" data-eit-pattern-title="<?php echo esc_attr( $pattern['title'] ); ?>" data-eit-pattern-description="<?php echo esc_attr( $pattern['description'] ); ?>">
			<input type="hidden" name="action" value="eit_save_integration_pattern" />
			<input type="hidden" name="pattern[id]" value="<?php echo esc_attr( $pattern['id'] ); ?>" />
			<?php wp_nonce_field( 'eit_save_integration_pattern' ); ?>

			<div class="eit-builder-layout eit-builder-layout--architecture eit-builder-layout--integrations" data-eit-repeat-scope>
				<main class="eit-architecture-board">
					<div class="eit-architecture-canvas eit-architecture-canvas--integrations">
						<section class="eit-arch-column eit-arch-column--source is-selected" data-eit-builder-title="<?php echo esc_attr__( 'Identity & Scope', 'elementor-implementation-toolkit' ); ?>" data-eit-builder-type="<?php echo esc_attr__( 'Module boundary', 'elementor-implementation-toolkit' ); ?>">
							<div class="eit-arch-heading">
								<?php echo $this->icon_img( $pattern['icon'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
								<h2><?php esc_html_e( 'Identity & Scope', 'elementor-implementation-toolkit' ); ?></h2>
							</div>
							<div class="eit-arch-stack">
								<article class="eit-arch-node eit-arch-node--module">
									<?php echo $this->icon_img( 'object' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
									<div>
										<strong><?php echo esc_html( $pattern['title'] ); ?></strong>
										<small><?php echo esc_html( $pattern['description'] ); ?></small>
										<?php echo AdminComponents::status_badge( $pattern['status'], IntegrationPatterns::statuses()[ $pattern['status'] ] ?? $pattern['status'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
									</div>
								</article>
								<?php $this->render_integration_layer_fields( $pattern, 'scope' ); ?>
							</div>
						</section>

						<div class="eit-flow-connector" aria-hidden="true"><span>→</span></div>

						<?php $this->render_integration_layer_column( $pattern, 'contract', __( 'Admin Contract', 'elementor-implementation-toolkit' ), 'contract', 'eit-arch-column--contract' ); ?>

						<div class="eit-flow-connector" aria-hidden="true"><span>→</span></div>

						<?php $this->render_integration_layer_column( $pattern, 'binding', __( 'Binding Layer', 'elementor-implementation-toolkit' ), 'binding', 'eit-arch-column--filters' ); ?>

						<div class="eit-flow-connector" aria-hidden="true"><span>→</span></div>

						<?php $this->render_integration_layer_column( $pattern, 'output', __( 'Preview Output', 'elementor-implementation-toolkit' ), 'output', 'eit-arch-column--target' ); ?>

						<section class="eit-runtime-rail" data-eit-builder-title="<?php echo esc_attr__( 'Runtime Boundary', 'elementor-implementation-toolkit' ); ?>" data-eit-builder-type="<?php echo esc_attr__( 'Future adapter boundary', 'elementor-implementation-toolkit' ); ?>">
							<div class="eit-arch-heading">
								<?php echo $this->icon_img( 'runtime' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
								<h2><?php esc_html_e( 'Runtime Boundary', 'elementor-implementation-toolkit' ); ?></h2>
							</div>
							<div class="eit-runtime-nodes eit-runtime-nodes--fields">
								<?php $this->render_integration_layer_fields( $pattern, 'runtime', true ); ?>
							</div>
						</section>
					</div>
				</main>

				<aside class="eit-inspector">
					<div class="eit-inspector__panel">
						<div class="eit-inspector__top">
							<?php echo $this->icon_img( 'inspector' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
							<div>
								<span><?php esc_html_e( 'Inspector', 'elementor-implementation-toolkit' ); ?></span>
								<h3 data-eit-inspector-title><?php echo esc_html( $pattern['title'] ); ?></h3>
								<p data-eit-inspector-type><?php esc_html_e( 'Integration module contract', 'elementor-implementation-toolkit' ); ?></p>
							</div>
						</div>
						<div class="eit-inspector-sections">
							<section>
								<h4><?php esc_html_e( 'Module Status', 'elementor-implementation-toolkit' ); ?></h4>
								<?php $this->select_field( 'pattern[status]', __( 'Status', 'elementor-implementation-toolkit' ), $pattern['status'], IntegrationPatterns::statuses() ); ?>
								<div class="eit-status-help">
									<?php echo AdminComponents::status_badge( $pattern['status'], IntegrationPatterns::statuses()[ $pattern['status'] ] ?? $pattern['status'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
									<small><?php esc_html_e( 'Status is admin-facing in V0.2. It does not enable deep runtime behavior yet.', 'elementor-implementation-toolkit' ); ?></small>
								</div>
							</section>
							<section>
								<h4><?php esc_html_e( 'Architecture Layers', 'elementor-implementation-toolkit' ); ?></h4>
								<div class="eit-layer-map">
									<?php foreach ( $pattern['layers'] as $layer_id => $layer ) : ?>
										<span>
											<?php echo $this->icon_img( $layer['icon'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
											<b><?php echo esc_html( $layer['label'] ); ?></b>
											<small><?php echo esc_html( $layer['summary'] ); ?></small>
										</span>
									<?php endforeach; ?>
								</div>
							</section>
							<section>
								<h4><?php esc_html_e( 'V0.2 Boundary', 'elementor-implementation-toolkit' ); ?></h4>
								<div class="eit-state-preview">
									<strong><?php esc_html_e( 'Admin contract only', 'elementor-implementation-toolkit' ); ?></strong>
									<div class="eit-preview-stack">
										<i><?php esc_html_e( 'Saved settings', 'elementor-implementation-toolkit' ); ?></i>
										<i><?php esc_html_e( 'Context preview', 'elementor-implementation-toolkit' ); ?></i>
										<i><?php esc_html_e( 'Future adapter', 'elementor-implementation-toolkit' ); ?></i>
									</div>
									<small><?php esc_html_e( 'This keeps the Toolkit self-contained while the demo site exposes which integrations deserve runtime implementation.', 'elementor-implementation-toolkit' ); ?></small>
								</div>
							</section>
						</div>
					</div>
				</aside>
			</div>
		</form>

		<div id="eit-integration-preview-modal" class="eit-modal-overlay">
			<div class="eit-modal-panel">
				<div class="eit-modal-header">
					<h3><?php esc_html_e( 'Integration Contract Preview', 'elementor-implementation-toolkit' ); ?></h3>
					<button type="button" class="eit-modal-close" data-eit-modal-close>&times;</button>
				</div>
				<div class="eit-modal-body">
					<div id="eit-integration-preview-content" class="eit-reg-preview eit-integration-preview"></div>
				</div>
			</div>
		</div>

		<?php
		$this->render_shell_end();
	}

	public function save_filter_preset() {
		$this->guard_admin_action( 'eit_save_filter_preset' );
		$raw = isset( $_POST['preset'] ) && is_array( $_POST['preset'] ) ? wp_unslash( $_POST['preset'] ) : [];
		$id = FilterPresets::save( $raw );

		wp_safe_redirect( admin_url( 'admin.php?page=' . self::FILTERS_SLUG . '&edit=' . rawurlencode( $id ) . '&eit_notice=saved' ) );
		exit;
	}

	public function delete_filter_preset() {
		$id = isset( $_GET['id'] ) ? sanitize_key( wp_unslash( $_GET['id'] ) ) : '';
		$this->guard_admin_action( 'eit_delete_filter_preset_' . $id );
		FilterPresets::delete( $id );

		wp_safe_redirect( admin_url( 'admin.php?page=' . self::FILTERS_SLUG . '&eit_notice=deleted' ) );
		exit;
	}

	public function save_cpt() {
		$this->guard_admin_action( 'eit_save_cpt' );
		$raw = isset( $_POST['cpt'] ) && is_array( $_POST['cpt'] ) ? wp_unslash( $_POST['cpt'] ) : [];
		$slug = CptManager::save_definition( $raw );

		wp_safe_redirect( admin_url( 'admin.php?page=' . self::CPT_SLUG . '&edit=' . rawurlencode( $slug ) . '&eit_notice=saved' ) );
		exit;
	}

	public function delete_cpt() {
		$slug = isset( $_GET['slug'] ) ? sanitize_key( wp_unslash( $_GET['slug'] ) ) : '';
		$this->guard_admin_action( 'eit_delete_cpt_' . $slug );
		CptManager::delete_definition( $slug );

		wp_safe_redirect( admin_url( 'admin.php?page=' . self::CPT_SLUG . '&eit_notice=deleted' ) );
		exit;
	}

	public function save_integration_pattern() {
		$this->guard_admin_action( 'eit_save_integration_pattern' );
		$raw = isset( $_POST['pattern'] ) && is_array( $_POST['pattern'] ) ? wp_unslash( $_POST['pattern'] ) : [];
		$id = IntegrationPatterns::save( $raw );

		wp_safe_redirect( admin_url( 'admin.php?page=' . self::INTEGRATIONS_SLUG . '&pattern=' . rawurlencode( $id ) . '&eit_notice=saved' ) );
		exit;
	}

	private function guard_admin_action( $nonce_action ) {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You are not allowed to manage this toolkit.', 'elementor-implementation-toolkit' ) );
		}

		check_admin_referer( $nonce_action );
	}

	private function render_shell_start( $title, $active_slug, array $args = [] ) {
		$form_id       = $args['form_id'] ?? '';
		$field_name    = $args['field_name'] ?? '';
		$field_value   = $args['field_value'] ?? $title;
		$field_prefix  = $args['field_prefix'] ?? __( 'Module:', 'elementor-implementation-toolkit' );
		$subtitle      = $args['subtitle'] ?? __( 'Visual implementation architecture builder', 'elementor-implementation-toolkit' );
		$primary_label = $args['primary_label'] ?? __( 'Save', 'elementor-implementation-toolkit' );
		$preview_label = $args['preview_label'] ?? __( 'Preview', 'elementor-implementation-toolkit' );
		$status        = $args['status'] ?? 'active';
		$status_label  = $args['status_label'] ?? __( 'Active', 'elementor-implementation-toolkit' );
		?>
		<div class="wrap eit-admin-wrap eit-product-shell">
			<header class="eit-app-bar">
				<a class="eit-brand-lockup" href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::DASHBOARD_SLUG ) ); ?>">
					<?php echo $this->icon_img( 'logo-layers', 'eit-brand-lockup__icon' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					<span>
						<strong><?php esc_html_e( 'Elementor Implementation Toolkit', 'elementor-implementation-toolkit' ); ?></strong>
						<small><?php echo esc_html( $subtitle ); ?></small>
					</span>
				</a>

				<nav class="eit-tabs" aria-label="<?php echo esc_attr__( 'Toolkit sections', 'elementor-implementation-toolkit' ); ?>">
					<?php $this->nav_tab( self::DASHBOARD_SLUG, __( 'Dashboard', 'elementor-implementation-toolkit' ), $active_slug ); ?>
					<?php $this->nav_tab( self::FILTERS_SLUG, __( 'Filters', 'elementor-implementation-toolkit' ), $active_slug ); ?>
					<?php $this->nav_tab( self::CPT_SLUG, __( 'CPTs', 'elementor-implementation-toolkit' ), $active_slug ); ?>
					<?php $this->nav_tab( self::INTEGRATIONS_SLUG, __( 'Integrations', 'elementor-implementation-toolkit' ), $active_slug ); ?>
				</nav>

				<div class="eit-focus-pill">
					<span><?php echo esc_html( $field_prefix ); ?></span>
					<?php if ( $field_name && $form_id ) : ?>
						<input form="<?php echo esc_attr( $form_id ); ?>" name="<?php echo esc_attr( $field_name ); ?>" value="<?php echo esc_attr( $field_value ); ?>" placeholder="<?php echo esc_attr( $title ); ?>" />
					<?php else : ?>
						<strong><?php echo esc_html( $field_value ); ?></strong>
					<?php endif; ?>
					<i class="is-<?php echo esc_attr( sanitize_key( $status ) ); ?>"><?php echo esc_html( $status_label ); ?></i>
				</div>

				<div class="eit-top-actions">
					<?php if ( $form_id ) : ?>
						<button type="submit" form="<?php echo esc_attr( $form_id ); ?>" class="eit-action-button eit-action-button--ghost">
							<?php echo $this->icon_img( 'save-disk' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
							<?php echo esc_html( $primary_label ); ?>
						</button>
						<button type="button" class="eit-action-button eit-action-button--ghost" data-eit-preview>
							<?php echo $this->icon_img( 'preview-eye' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
							<?php echo esc_html( $preview_label ); ?>
						</button>
						<button type="submit" form="<?php echo esc_attr( $form_id ); ?>" class="eit-action-button eit-action-button--primary">
							<?php echo $this->icon_img( 'publish-rocket' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
							<?php esc_html_e( 'Publish', 'elementor-implementation-toolkit' ); ?>
						</button>
					<?php else : ?>
						<a class="eit-action-button eit-action-button--ghost" href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::FILTERS_SLUG ) ); ?>"><?php esc_html_e( 'Filter Builder', 'elementor-implementation-toolkit' ); ?></a>
						<a class="eit-action-button eit-action-button--ghost" href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::CPT_SLUG ) ); ?>"><?php esc_html_e( 'CPT Builder', 'elementor-implementation-toolkit' ); ?></a>
						<a class="eit-action-button eit-action-button--primary" href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::INTEGRATIONS_SLUG ) ); ?>"><?php esc_html_e( 'Superpowers', 'elementor-implementation-toolkit' ); ?></a>
					<?php endif; ?>
				</div>
			</header>
		<?php
	}

	private function render_shell_end() {
		echo '</div>';
	}

	private function nav_tab( $slug, $label, $active_slug ) {
		$class = $slug === $active_slug ? 'eit-tab is-active' : 'eit-tab';
		echo '<a class="' . esc_attr( $class ) . '" href="' . esc_url( admin_url( 'admin.php?page=' . $slug ) ) . '">' . esc_html( $label ) . '</a>';
	}

	private function icon_img( $name, $class = '' ) {
		return AdminComponents::icon( $name, $class );
	}

	private function filter_icon_name( $type ) {
		$map = [
			'search'   => 'search',
			'checkbox' => 'checkbox',
			'radio'    => 'checkbox',
			'select'   => 'checkbox',
			'chips'    => 'chips',
			'toggle'   => 'constraints-sliders',
			'range'    => 'range-sliders',
			'date'     => 'post-type-document',
			'swatch'   => 'swatches',
			'rating'   => 'rating-star',
			'sort'     => 'sort-arrows',
		];

		$type = sanitize_key( $type );

		return $map[ $type ] ?? 'filter-funnel';
	}

	private function render_filter_hidden_fields( array $filter, $index ) {
		$base = 'preset[filters][' . $index . ']';
		$text_fields = [
			'label',
			'type',
			'key',
			'source',
			'query_var',
			'compare',
			'data_type',
			'placeholder',
			'options',
			'range_min',
			'range_max',
			'range_step',
			'default_value',
			'empty_behavior',
		];

		foreach ( $text_fields as $field ) {
			$value = $filter[ $field ] ?? FilterPresets::blank_filter()[ $field ] ?? '';
			echo '<input type="hidden" name="' . esc_attr( $base . '[' . $field . ']' ) . '" value="' . esc_attr( $value ) . '" />';
		}

		foreach ( [ 'enabled', 'show_label', 'show_count' ] as $field ) {
			echo '<input type="hidden" name="' . esc_attr( $base . '[' . $field . ']' ) . '" value="' . esc_attr( ! empty( $filter[ $field ] ) ? '1' : '0' ) . '" />';
		}
	}

	private function render_sot_layer_card( $number, $icon, $title, $summary, array $facts, $target, $tone = 'teal' ) {
		?>
		<button type="button" class="eit-sot-layer-card eit-sot-layer-card--<?php echo esc_attr( sanitize_key( $tone ) ); ?>" data-eit-sot-target="<?php echo esc_attr( $target ); ?>">
			<span class="eit-sot-layer-card__index"><?php echo esc_html( $number ); ?></span>
			<?php echo $this->icon_img( $icon, 'eit-sot-layer-card__icon' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			<span class="eit-sot-layer-card__title">
				<strong><?php echo esc_html( $title ); ?></strong>
				<small><?php echo esc_html( $summary ); ?></small>
			</span>
			<span class="eit-sot-layer-card__facts">
				<?php foreach ( array_slice( $facts, 0, 6 ) as $fact ) : ?>
					<i><?php echo esc_html( $fact ); ?></i>
				<?php endforeach; ?>
			</span>
			<span class="eit-sot-layer-card__arrow" aria-hidden="true">›</span>
		</button>
		<?php
	}

	private function render_price_module_fields( array $filter, $index ) {
		$base = 'preset[filters][' . $index . ']';
		?>
		<input type="hidden" name="<?php echo esc_attr( $base . '[type]' ); ?>" value="range" />
		<input type="hidden" name="<?php echo esc_attr( $base . '[options]' ); ?>" value="<?php echo esc_attr( $filter['options'] ?? '' ); ?>" />

		<div class="eit-price-schema__rows">
			<section class="eit-price-schema-row">
				<header>
					<?php echo $this->icon_img( 'object' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					<strong><?php esc_html_e( 'Identity', 'elementor-implementation-toolkit' ); ?></strong>
					<small><?php esc_html_e( 'Module label and URL state key.', 'elementor-implementation-toolkit' ); ?></small>
				</header>
				<div class="eit-field-grid">
					<?php $this->text_field( $base . '[label]', __( 'Label', 'elementor-implementation-toolkit' ), $filter['label'] ?? '', 'Price Range' ); ?>
					<?php $this->text_field( $base . '[query_var]', __( 'Query var', 'elementor-implementation-toolkit' ), $filter['query_var'] ?? '', 'price' ); ?>
					<?php $this->text_field( $base . '[placeholder]', __( 'Placeholder', 'elementor-implementation-toolkit' ), $filter['placeholder'] ?? '', 'Any price' ); ?>
					<?php $this->text_field( $base . '[default_value]', __( 'Default', 'elementor-implementation-toolkit' ), $filter['default_value'] ?? '', '' ); ?>
				</div>
				<div class="eit-toggle-grid">
					<?php $this->checkbox_field( $base . '[enabled]', __( 'Enabled', 'elementor-implementation-toolkit' ), ! empty( $filter['enabled'] ) ); ?>
					<?php $this->checkbox_field( $base . '[show_label]', __( 'Show label', 'elementor-implementation-toolkit' ), ! empty( $filter['show_label'] ) ); ?>
				</div>
			</section>

			<section class="eit-price-schema-row">
				<header>
					<?php echo $this->icon_img( 'binding' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					<strong><?php esc_html_e( 'Data Binding', 'elementor-implementation-toolkit' ); ?></strong>
					<small><?php esc_html_e( 'Where the controller reads price data.', 'elementor-implementation-toolkit' ); ?></small>
				</header>
				<div class="eit-field-grid">
					<?php $this->select_field( $base . '[source]', __( 'Source', 'elementor-implementation-toolkit' ), $filter['source'] ?? 'meta', FilterPresets::source_types() ); ?>
					<?php $this->text_field( $base . '[key]', __( 'Data key', 'elementor-implementation-toolkit' ), $filter['key'] ?? '', '_price' ); ?>
					<?php $this->select_field( $base . '[compare]', __( 'Compare', 'elementor-implementation-toolkit' ), $filter['compare'] ?? 'between', FilterPresets::compare_types() ); ?>
					<?php $this->select_field( $base . '[data_type]', __( 'Data type', 'elementor-implementation-toolkit' ), $filter['data_type'] ?? 'number', FilterPresets::data_types() ); ?>
				</div>
			</section>

			<section class="eit-price-schema-row">
				<header>
					<?php echo $this->icon_img( 'constraints-sliders' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					<strong><?php esc_html_e( 'Bounds', 'elementor-implementation-toolkit' ); ?></strong>
					<small><?php esc_html_e( 'Slider limits and fallback behavior.', 'elementor-implementation-toolkit' ); ?></small>
				</header>
				<div class="eit-field-grid eit-field-grid--three">
					<?php $this->number_field( $base . '[range_min]', __( 'Min', 'elementor-implementation-toolkit' ), $filter['range_min'] ?? 0, null, null, 'any' ); ?>
					<?php $this->number_field( $base . '[range_max]', __( 'Max', 'elementor-implementation-toolkit' ), $filter['range_max'] ?? 1000, null, null, 'any' ); ?>
					<?php $this->number_field( $base . '[range_step]', __( 'Step', 'elementor-implementation-toolkit' ), $filter['range_step'] ?? 10, null, null, 'any' ); ?>
				</div>
			</section>

			<section class="eit-price-schema-row">
				<header>
					<?php echo $this->icon_img( 'state-feedback-loop' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					<strong><?php esc_html_e( 'Behavior', 'elementor-implementation-toolkit' ); ?></strong>
					<small><?php esc_html_e( 'How empty values and counts affect state.', 'elementor-implementation-toolkit' ); ?></small>
				</header>
				<div class="eit-field-grid">
					<?php $this->select_field( $base . '[empty_behavior]', __( 'Empty behavior', 'elementor-implementation-toolkit' ), $filter['empty_behavior'] ?? 'ignore', [ 'ignore' => __( 'Ignore', 'elementor-implementation-toolkit' ), 'hide_all' => __( 'Hide all', 'elementor-implementation-toolkit' ) ] ); ?>
					<div class="eit-readonly-summary">
						<span><?php esc_html_e( 'Inherited target', 'elementor-implementation-toolkit' ); ?></span>
						<strong><?php esc_html_e( 'DOM Provider / Products #1', 'elementor-implementation-toolkit' ); ?></strong>
					</div>
				</div>
				<div class="eit-toggle-grid">
					<?php $this->checkbox_field( $base . '[show_count]', __( 'Count ready', 'elementor-implementation-toolkit' ), ! empty( $filter['show_count'] ) ); ?>
				</div>
			</section>
		</div>
		<?php
	}

	private function render_notice() {
		$notice = isset( $_GET['eit_notice'] ) ? sanitize_key( wp_unslash( $_GET['eit_notice'] ) ) : '';

		if ( ! $notice ) {
			return;
		}

		$message = 'deleted' === $notice ? __( 'Deleted.', 'elementor-implementation-toolkit' ) : __( 'Saved.', 'elementor-implementation-toolkit' );
		echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( $message ) . '</p></div>';
	}

	private function render_stat_card( $label, $value, $description ) {
		?>
		<section class="eit-stat-card">
			<span><?php echo esc_html( $label ); ?></span>
			<strong><?php echo esc_html( $value ); ?></strong>
			<p><?php echo esc_html( $description ); ?></p>
		</section>
		<?php
	}

	private function builder_block_header( $title, $badge, $description ) {
		?>
		<div class="eit-block-header">
			<div>
				<span class="eit-block-eyebrow"><?php echo esc_html( $badge ); ?></span>
				<h3><?php echo esc_html( $title ); ?></h3>
				<p><?php echo esc_html( $description ); ?></p>
			</div>
		</div>
		<?php
	}

	private function render_sort_options_builder( $raw_options ) {
		$this->render_choice_builder( 'preset[sort_options_items]', $raw_options, __( 'Sort rules', 'elementor-implementation-toolkit' ), false );
	}

	private function render_integration_layer_column( array $pattern, $layer_id, $title, $icon, $class ) {
		$layer = $pattern['layers'][ $layer_id ] ?? [
			'label'   => $title,
			'summary' => '',
			'icon'    => $icon,
		];
		?>
		<section class="eit-arch-column <?php echo esc_attr( $class ); ?>" data-eit-builder-title="<?php echo esc_attr( $layer['label'] ); ?>" data-eit-builder-type="<?php echo esc_attr( $layer['summary'] ); ?>">
			<div class="eit-arch-heading">
				<?php echo $this->icon_img( $icon ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				<h2><?php echo esc_html( $title ); ?></h2>
			</div>
			<div class="eit-vertical-flow">
				<?php $this->render_integration_layer_fields( $pattern, $layer_id ); ?>
			</div>
		</section>
		<?php
	}

	private function render_integration_layer_fields( array $pattern, $layer_id, $runtime = false ) {
		$matched = array_filter(
			$pattern['fields'],
			static function ( $field ) use ( $layer_id ) {
				return $layer_id === ( $field['layer'] ?? '' );
			}
		);

		if ( empty( $matched ) ) {
			echo '<div class="eit-empty-node">' . esc_html__( 'No controls for this layer yet.', 'elementor-implementation-toolkit' ) . '</div>';
			return;
		}

		foreach ( $matched as $field ) {
			$node_class = $runtime ? 'eit-rt-node eit-rt-node--field' : 'eit-arch-node eit-arch-node--field';
			$tag        = $runtime ? 'div' : 'article';
			?>
			<<?php echo tag_escape( $tag ); ?> class="<?php echo esc_attr( $node_class ); ?>" data-eit-builder-title="<?php echo esc_attr( $field['label'] ); ?>" data-eit-builder-type="<?php echo esc_attr( ucfirst( $field['type'] ) ); ?>">
				<?php echo $this->icon_img( $pattern['layers'][ $layer_id ]['icon'] ?? 'module' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				<div>
					<strong><?php echo esc_html( $field['label'] ); ?></strong>
					<?php $this->render_integration_field( $pattern, $field ); ?>
				</div>
			</<?php echo tag_escape( $tag ); ?>>
			<?php
		}
	}

	private function render_integration_field( array $pattern, array $field ) {
		$key         = $field['key'];
		$name        = 'pattern[values][' . $key . ']';
		$value       = $pattern['values'][ $key ] ?? $field['default'];
		$placeholder = $field['placeholder'] ?? '';

		switch ( $field['type'] ) {
			case 'toggle':
				$this->checkbox_field( $name, __( 'Enabled', 'elementor-implementation-toolkit' ), ! empty( $value ) );
				break;
			case 'number':
				$this->number_field( $name, __( 'Value', 'elementor-implementation-toolkit' ), $value, 0, null, 1 );
				break;
			case 'select':
				$this->select_field( $name, __( 'Mode', 'elementor-implementation-toolkit' ), $value, $field['options'] ?? [] );
				break;
			case 'color':
				$this->text_field( $name, __( 'Color', 'elementor-implementation-toolkit' ), $value, $placeholder, 'color' );
				break;
			default:
				$this->text_field( $name, __( 'Value', 'elementor-implementation-toolkit' ), $value, $placeholder );
				break;
		}
	}

	private function render_choice_builder( $name, $raw_options, $title, $include_visual ) {
		$choices = $this->parse_choice_items( $raw_options, $include_visual );
		$template_choice = [
			'value'  => '',
			'label'  => '',
			'visual' => '',
		];
		?>
		<div class="eit-choice-builder" data-eit-repeat-scope>
			<div class="eit-choice-builder__header">
				<div>
					<span><?php echo esc_html( $title ); ?></span>
					<p><?php esc_html_e( 'Each option is an editable child item, not a coded line.', 'elementor-implementation-toolkit' ); ?></p>
				</div>
				<button type="button" class="eit-tertiary-action" data-eit-add-row="choice"><?php esc_html_e( 'Add option', 'elementor-implementation-toolkit' ); ?></button>
			</div>
			<div class="eit-choice-list" data-eit-repeater="choice" data-next-index="<?php echo esc_attr( count( $choices ) ); ?>">
				<?php foreach ( $choices as $index => $choice ) : ?>
					<?php $this->render_choice_row( $name, (string) $index, $choice, $include_visual ); ?>
				<?php endforeach; ?>
			</div>
			<template data-eit-template="choice">
				<?php $this->render_choice_row( $name, '__index__', $template_choice, $include_visual ); ?>
			</template>
		</div>
		<?php
	}

	private function render_choice_row( $name, $index, array $choice, $include_visual ) {
		$base = $name . '[' . $index . ']';
		?>
		<div class="eit-choice-row <?php echo $include_visual ? 'eit-choice-row--visual' : 'eit-choice-row--compact'; ?> eit-repeater-row">
			<div class="eit-choice-row__grip" aria-hidden="true"></div>
			<?php $this->text_field( $base . '[value]', __( 'Value', 'elementor-implementation-toolkit' ), $choice['value'] ?? '', 'featured' ); ?>
			<?php $this->text_field( $base . '[label]', __( 'Label', 'elementor-implementation-toolkit' ), $choice['label'] ?? '', 'Featured' ); ?>
			<?php if ( $include_visual ) : ?>
				<?php $this->text_field( $base . '[visual]', __( 'Visual', 'elementor-implementation-toolkit' ), $choice['visual'] ?? '', '#d6368f or image URL' ); ?>
			<?php endif; ?>
			<button type="button" class="eit-icon-action" data-eit-remove-row aria-label="<?php echo esc_attr__( 'Remove option', 'elementor-implementation-toolkit' ); ?>">×</button>
		</div>
		<?php
	}

	private function parse_choice_items( $raw_options, $include_visual ) {
		$items = [];
		$lines = preg_split( '/\r\n|\r|\n/', (string) $raw_options );

		foreach ( $lines as $line ) {
			$line = trim( $line );

			if ( '' === $line ) {
				continue;
			}

			$parts = array_map( 'trim', explode( '|', $line ) );
			$value = sanitize_text_field( $parts[0] ?? '' );

			if ( '' === $value ) {
				continue;
			}

			$items[] = [
				'value'  => $value,
				'label'  => sanitize_text_field( $parts[1] ?? $value ),
				'visual' => $include_visual ? sanitize_text_field( $parts[2] ?? '' ) : '',
			];
		}

		return $items;
	}

	private function render_filter_row( array $filter, $index ) {
		$base = 'preset[filters][' . $index . ']';
		$type = $filter['type'] ?? 'search';
		$is_range = 'range' === $type;
		?>
		<div class="eit-node eit-filter-card eit-repeater-row <?php echo $is_range ? 'is-selected' : ''; ?>" data-eit-repeat-scope data-eit-builder-title="<?php echo esc_attr( $filter['label'] ?: __( 'Filter', 'elementor-implementation-toolkit' ) ); ?>" data-eit-builder-type="<?php echo esc_attr( ucfirst( $type ) ); ?>">
			<div class="eit-filter-card__grip" aria-hidden="true"><i></i><i></i><i></i></div>
			<div class="eit-filter-card__body">
				<div class="eit-filter-card__top">
					<?php echo $this->icon_img( $this->filter_icon_name( $type ), 'eit-filter-card__icon' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					<div>
						<?php $this->text_field( $base . '[label]', __( 'Label', 'elementor-implementation-toolkit' ), $filter['label'] ?? '', 'Category' ); ?>
						<?php $this->select_field( $base . '[type]', __( 'Type', 'elementor-implementation-toolkit' ), $type, FilterPresets::filter_types() ); ?>
					</div>
					<button type="button" class="eit-icon-action" data-eit-remove-row aria-label="<?php echo esc_attr__( 'Remove filter', 'elementor-implementation-toolkit' ); ?>">×</button>
				</div>

				<div class="eit-contract-chips" aria-hidden="true">
					<span><?php esc_html_e( 'Input', 'elementor-implementation-toolkit' ); ?></span>
					<span><?php esc_html_e( 'Compare', 'elementor-implementation-toolkit' ); ?></span>
					<span><?php esc_html_e( 'Output State', 'elementor-implementation-toolkit' ); ?></span>
				</div>

				<div class="eit-filter-card__details">
					<section>
						<h4><?php esc_html_e( 'Control', 'elementor-implementation-toolkit' ); ?></h4>
						<div class="eit-field-grid">
							<?php $this->text_field( $base . '[placeholder]', __( 'Placeholder', 'elementor-implementation-toolkit' ), $filter['placeholder'] ?? '', 'Search products...' ); ?>
							<?php $this->text_field( $base . '[default_value]', __( 'Default', 'elementor-implementation-toolkit' ), $filter['default_value'] ?? '', '' ); ?>
						</div>
						<div class="eit-toggle-grid">
							<?php $this->checkbox_field( $base . '[enabled]', __( 'Enabled', 'elementor-implementation-toolkit' ), ! empty( $filter['enabled'] ) ); ?>
							<?php $this->checkbox_field( $base . '[show_label]', __( 'Show label', 'elementor-implementation-toolkit' ), ! empty( $filter['show_label'] ) ); ?>
							<?php $this->checkbox_field( $base . '[show_count]', __( 'Count-ready', 'elementor-implementation-toolkit' ), ! empty( $filter['show_count'] ) ); ?>
						</div>
					</section>
					<section>
						<h4><?php esc_html_e( 'Data Binding', 'elementor-implementation-toolkit' ); ?></h4>
						<div class="eit-field-grid">
							<?php $this->text_field( $base . '[key]', __( 'Data key', 'elementor-implementation-toolkit' ), $filter['key'] ?? '', 'category, price, rating' ); ?>
							<?php $this->select_field( $base . '[source]', __( 'Source', 'elementor-implementation-toolkit' ), $filter['source'] ?? 'visible_text', FilterPresets::source_types() ); ?>
							<?php $this->text_field( $base . '[query_var]', __( 'URL key', 'elementor-implementation-toolkit' ), $filter['query_var'] ?? '', 'category' ); ?>
							<?php $this->select_field( $base . '[compare]', __( 'Compare', 'elementor-implementation-toolkit' ), $filter['compare'] ?? 'contains', FilterPresets::compare_types() ); ?>
							<?php $this->select_field( $base . '[data_type]', __( 'Data type', 'elementor-implementation-toolkit' ), $filter['data_type'] ?? 'string', FilterPresets::data_types() ); ?>
						</div>
					</section>
					<section>
						<h4><?php esc_html_e( 'Bounds', 'elementor-implementation-toolkit' ); ?></h4>
						<div class="eit-field-grid eit-field-grid--three">
							<?php $this->number_field( $base . '[range_min]', __( 'Min', 'elementor-implementation-toolkit' ), $filter['range_min'] ?? 0, null, null, 'any' ); ?>
							<?php $this->number_field( $base . '[range_max]', __( 'Max', 'elementor-implementation-toolkit' ), $filter['range_max'] ?? 100, null, null, 'any' ); ?>
							<?php $this->number_field( $base . '[range_step]', __( 'Step', 'elementor-implementation-toolkit' ), $filter['range_step'] ?? 1, null, null, 'any' ); ?>
						</div>
					</section>
					<section>
						<?php $this->render_choice_builder( $base . '[options_items]', $filter['options'] ?? '', __( 'Choices', 'elementor-implementation-toolkit' ), true ); ?>
					</section>
				</div>
			</div>
		</div>
		<?php
	}

	private function render_taxonomy_row( array $taxonomy, $index ) {
		$base = 'cpt[taxonomies][' . $index . ']';
		?>
		<div class="eit-node eit-repeater-row" data-eit-builder-title="<?php echo esc_attr( $taxonomy['plural'] ?: __( 'Taxonomy', 'elementor-implementation-toolkit' ) ); ?>" data-eit-builder-type="<?php echo esc_attr__( 'Taxonomy', 'elementor-implementation-toolkit' ); ?>">
			<div class="eit-node__rail" aria-hidden="true"></div>
			<div class="eit-node__body">
				<div class="eit-node__top">
					<div>
						<span class="eit-node__type"><?php esc_html_e( 'Taxonomy', 'elementor-implementation-toolkit' ); ?></span>
						<strong><?php echo esc_html( $taxonomy['plural'] ?: __( 'Taxonomy', 'elementor-implementation-toolkit' ) ); ?></strong>
					</div>
					<button type="button" class="eit-danger-action" data-eit-remove-row><?php esc_html_e( 'Remove', 'elementor-implementation-toolkit' ); ?></button>
				</div>
				<div class="eit-child-panel">
					<div class="eit-child-panel__header">
						<span><?php esc_html_e( 'Labels and URL', 'elementor-implementation-toolkit' ); ?></span>
						<p><?php esc_html_e( 'Define the taxonomy identity attached to this CPT.', 'elementor-implementation-toolkit' ); ?></p>
					</div>
					<div class="eit-field-grid">
						<?php $this->text_field( $base . '[slug]', __( 'Slug', 'elementor-implementation-toolkit' ), $taxonomy['slug'] ?? '', 'product_category' ); ?>
						<?php $this->text_field( $base . '[singular]', __( 'Singular', 'elementor-implementation-toolkit' ), $taxonomy['singular'] ?? '', 'Category' ); ?>
						<?php $this->text_field( $base . '[plural]', __( 'Plural', 'elementor-implementation-toolkit' ), $taxonomy['plural'] ?? '', 'Categories' ); ?>
					</div>
					<div class="eit-toggle-grid">
						<?php $this->checkbox_field( $base . '[hierarchical]', __( 'Hierarchical', 'elementor-implementation-toolkit' ), ! empty( $taxonomy['hierarchical'] ) ); ?>
						<?php $this->checkbox_field( $base . '[public]', __( 'Public', 'elementor-implementation-toolkit' ), ! empty( $taxonomy['public'] ) ); ?>
						<?php $this->checkbox_field( $base . '[show_in_rest]', __( 'Show in REST', 'elementor-implementation-toolkit' ), ! empty( $taxonomy['show_in_rest'] ) ); ?>
					</div>
				</div>
			</div>
		</div>
		<?php
	}

	private function render_meta_field_row( array $field, $index ) {
		$base = 'cpt[meta_fields][' . $index . ']';
		?>
		<div class="eit-node eit-repeater-row" data-eit-repeat-scope data-eit-builder-title="<?php echo esc_attr( $field['label'] ?: __( 'Meta field', 'elementor-implementation-toolkit' ) ); ?>" data-eit-builder-type="<?php echo esc_attr__( 'Meta field', 'elementor-implementation-toolkit' ); ?>">
			<div class="eit-node__rail" aria-hidden="true"></div>
			<div class="eit-node__body">
				<div class="eit-node__top">
					<div>
						<span class="eit-node__type"><?php echo esc_html( ucfirst( $field['type'] ?? 'text' ) ); ?></span>
						<strong><?php echo esc_html( $field['label'] ?: __( 'Meta field', 'elementor-implementation-toolkit' ) ); ?></strong>
					</div>
					<button type="button" class="eit-danger-action" data-eit-remove-row><?php esc_html_e( 'Remove', 'elementor-implementation-toolkit' ); ?></button>
				</div>
				<div class="eit-child-panel">
					<div class="eit-child-panel__header">
						<span><?php esc_html_e( 'Field definition', 'elementor-implementation-toolkit' ); ?></span>
						<p><?php esc_html_e( 'This becomes a sanitized input inside the post editor meta box.', 'elementor-implementation-toolkit' ); ?></p>
					</div>
					<div class="eit-field-grid">
						<?php $this->text_field( $base . '[key]', __( 'Meta key', 'elementor-implementation-toolkit' ), $field['key'] ?? '', 'price' ); ?>
						<?php $this->text_field( $base . '[label]', __( 'Label', 'elementor-implementation-toolkit' ), $field['label'] ?? '', 'Price' ); ?>
						<?php $this->select_field( $base . '[type]', __( 'Type', 'elementor-implementation-toolkit' ), $field['type'] ?? 'text', CptManager::meta_field_types() ); ?>
						<?php $this->text_field( $base . '[default]', __( 'Default', 'elementor-implementation-toolkit' ), $field['default'] ?? '', '' ); ?>
					</div>
					<div class="eit-toggle-grid">
						<?php $this->checkbox_field( $base . '[required]', __( 'Required', 'elementor-implementation-toolkit' ), ! empty( $field['required'] ) ); ?>
						<?php $this->checkbox_field( $base . '[show_in_rest]', __( 'Show in REST', 'elementor-implementation-toolkit' ), ! empty( $field['show_in_rest'] ) ); ?>
					</div>
				</div>
				<?php $this->render_choice_builder( $base . '[options_items]', $field['options'] ?? '', __( 'Select choices', 'elementor-implementation-toolkit' ), false ); ?>
			</div>
		</div>
		<?php
	}

	private function text_field( $name, $label, $value, $placeholder = '', $type = 'text', $readonly = false ) {
		?>
		<label class="eit-field">
			<span><?php echo esc_html( $label ); ?></span>
			<input type="<?php echo esc_attr( $type ); ?>" name="<?php echo esc_attr( $name ); ?>" value="<?php echo esc_attr( $value ); ?>" placeholder="<?php echo esc_attr( $placeholder ); ?>" <?php echo $readonly ? 'readonly="readonly"' : ''; ?> />
		</label>
		<?php
	}

	private function number_field( $name, $label, $value, $min = null, $max = null, $step = 1 ) {
		?>
		<label class="eit-field">
			<span><?php echo esc_html( $label ); ?></span>
			<input type="number" name="<?php echo esc_attr( $name ); ?>" value="<?php echo esc_attr( $value ); ?>" <?php echo null !== $min ? 'min="' . esc_attr( $min ) . '"' : ''; ?> <?php echo null !== $max ? 'max="' . esc_attr( $max ) . '"' : ''; ?> step="<?php echo esc_attr( $step ); ?>" />
		</label>
		<?php
	}

	private function select_field( $name, $label, $value, array $options ) {
		?>
		<label class="eit-field">
			<span><?php echo esc_html( $label ); ?></span>
			<select name="<?php echo esc_attr( $name ); ?>">
				<?php foreach ( $options as $option_value => $option_label ) : ?>
					<option value="<?php echo esc_attr( $option_value ); ?>" <?php selected( (string) $value, (string) $option_value ); ?>><?php echo esc_html( $option_label ); ?></option>
				<?php endforeach; ?>
			</select>
		</label>
		<?php
	}

	private function checkbox_field( $name, $label, $checked ) {
		?>
		<label class="eit-switch">
			<input type="checkbox" name="<?php echo esc_attr( $name ); ?>" value="1" <?php checked( $checked ); ?> />
			<span><?php echo esc_html( $label ); ?></span>
		</label>
		<?php
	}
}
