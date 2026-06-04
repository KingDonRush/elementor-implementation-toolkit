<?php
/**
 * Native admin UI for reusable filter presets.
 */

namespace EIT\Admin;

use EIT\Elementor\FilterTemplateManager;
use EIT\Support\FilterPresets;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class FilterPresetAdmin {

	const SAVE_ACTION = 'eit_save_filter_preset';
	const DELETE_ACTION = 'eit_delete_filter_preset';
	const DUPLICATE_ACTION = 'eit_duplicate_filter_preset';
	const CREATE_TEMPLATE_ACTION = 'eit_create_filter_template';
	const DELETE_TEMPLATE_ACTION = 'eit_delete_filter_template';

	use AdminFormFields;

	private $renderer;

	public function __construct( AdminRenderer $renderer ) {
		$this->renderer = $renderer;
	}

	public function render( $active_slug, array $tabs ) {
		$presets = FilterPresets::all();
		$preset_id = $this->current_preset_id();
		$view = sanitize_key( wp_unslash( $_GET['view'] ?? '' ) );
		$is_form = 'new' === $view || '' !== $preset_id;
		$preset = '' !== $preset_id ? FilterPresets::get( $preset_id ) : null;

		if ( $is_form && ! $preset ) {
			$preset = FilterPresets::blank();
			$preset['id'] = $preset_id;
		}

		$config = [
			'title'       => $is_form ? __( 'Edit Filter Preset', 'elementor-implementation-toolkit' ) : __( 'Filter Presets', 'elementor-implementation-toolkit' ),
			'actions'     => [
				[
					'label' => __( 'Add New', 'elementor-implementation-toolkit' ),
					'url'   => admin_url( 'admin.php?page=' . AdminPages::FILTERS_SLUG . '&view=new' ),
				],
			],
		];

		$this->renderer->render_shell(
			$active_slug,
			$tabs,
			$config,
			function () use ( $is_form, $preset, $presets ) {
				$this->renderer->render_notice( sanitize_key( wp_unslash( $_GET['eit_notice'] ?? '' ) ) );

				if ( $is_form ) {
					$this->render_form( $preset );
					return;
				}

				$this->render_list( $presets );
			}
		);
	}

	public function handle_save() {
		$this->assert_can_manage();
		check_admin_referer( self::SAVE_ACTION );

		$raw = isset( $_POST['preset'] ) && is_array( $_POST['preset'] ) ? wp_unslash( $_POST['preset'] ) : [];
		$raw = $this->normalize_preset_post( $raw );
		$id = FilterPresets::save( $raw );
		$after_save = isset( $_POST['eit_after_save'] ) ? sanitize_key( wp_unslash( $_POST['eit_after_save'] ) ) : '';

		if ( 'open_template' === $after_save ) {
			$template_id = $this->get_or_create_template_for_preset( $id );

			if ( ! is_wp_error( $template_id ) ) {
				wp_safe_redirect( FilterTemplateManager::get_edit_url( $template_id ) );
				exit;
			}

			$this->redirect(
				[
					'page'       => AdminPages::FILTERS_SLUG,
					'preset'     => $id,
					'eit_notice' => 'error',
				]
			);
		}

		$this->redirect(
			[
				'page'       => AdminPages::FILTERS_SLUG,
				'preset'     => $id,
				'eit_notice' => 'saved',
			]
		);
	}

	public function handle_delete() {
		$this->assert_can_manage();
		$id = $this->posted_or_requested_id( 'preset' );

		check_admin_referer( self::DELETE_ACTION . '_' . $id );
		FilterPresets::delete( $id );

		$this->redirect(
			[
				'page'       => AdminPages::FILTERS_SLUG,
				'eit_notice' => 'deleted',
			]
		);
	}

	public function handle_duplicate() {
		$this->assert_can_manage();
		$id = $this->posted_or_requested_id( 'preset' );

		check_admin_referer( self::DUPLICATE_ACTION . '_' . $id );
		$preset = FilterPresets::get( $id );

		if ( ! $preset ) {
			$this->redirect(
				[
					'page'       => AdminPages::FILTERS_SLUG,
					'eit_notice' => 'error',
				]
			);
		}

		$preset['id'] = '';
		$preset['slug'] = '';
		$preset['name'] = sprintf(
			/* translators: %s: preset name. */
			__( '%s Copy', 'elementor-implementation-toolkit' ),
			$preset['name'] ?? $id
		);

		$new_id = FilterPresets::save( $preset );

		$this->redirect(
			[
				'page'       => AdminPages::FILTERS_SLUG,
				'preset'     => $new_id,
				'eit_notice' => 'saved',
			]
		);
	}

	public function handle_create_template() {
		$this->assert_can_manage();
		$id = $this->posted_or_requested_id( 'preset' );

		check_admin_referer( self::CREATE_TEMPLATE_ACTION . '_' . $id );

		$title = isset( $_POST['template_title'] ) ? sanitize_text_field( wp_unslash( $_POST['template_title'] ) ) : '';
		$template_id = FilterTemplateManager::create_filter_template( $id, $title );

		if ( is_wp_error( $template_id ) ) {
			$this->redirect(
				[
					'page'       => AdminPages::FILTERS_SLUG,
					'preset'     => $id,
					'eit_notice' => 'error',
				]
			);
		}

		wp_safe_redirect( FilterTemplateManager::get_edit_url( $template_id ) );
		exit;
	}

	public function handle_delete_template() {
		$this->assert_can_manage();
		$template_id = isset( $_POST['template_id'] ) ? absint( $_POST['template_id'] ) : 0;
		$preset_id   = $this->posted_or_requested_id( 'preset' );

		check_admin_referer( self::DELETE_TEMPLATE_ACTION . '_' . $template_id );
		$deleted = FilterTemplateManager::delete_filter_template( $template_id );

		$this->redirect(
			[
				'page'       => AdminPages::FILTERS_SLUG,
				'preset'     => $preset_id,
				'eit_notice' => is_wp_error( $deleted ) ? 'error' : 'deleted',
			]
		);
	}

	private function render_list( array $presets ) {
		$all_presets = $presets;
		$search = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
		$draft_count = 0;

		foreach ( $all_presets as $preset ) {
			if ( empty( $preset['filters'] ?? [] ) ) {
				$draft_count++;
			}
		}

		if ( '' !== $search ) {
			$needle = strtolower( $search );
			$presets = array_filter(
				$presets,
				function ( $preset, $id ) use ( $needle ) {
					$haystack = strtolower(
						implode(
							' ',
							[
								$id,
								$preset['name'] ?? '',
								$preset['slug'] ?? '',
								$this->filter_labels( $preset['filters'] ?? [] ),
							]
						)
					);

					return false !== strpos( $haystack, $needle );
				},
				ARRAY_FILTER_USE_BOTH
			);
		}
		?>
		<div class="eit-panel eit-panel--table">
			<div class="eit-panel__header">
				<div>
					<h3><?php esc_html_e( 'Filter Presets', 'elementor-implementation-toolkit' ); ?></h3>
					<p><?php esc_html_e( 'Reusable filter behavior for Elementor archive and listing templates.', 'elementor-implementation-toolkit' ); ?></p>
				</div>
				<a class="button button-primary" href="<?php echo esc_url( admin_url( 'admin.php?page=' . AdminPages::FILTERS_SLUG . '&view=new' ) ); ?>">
					<?php esc_html_e( 'Add New', 'elementor-implementation-toolkit' ); ?>
				</a>
			</div>

			<div class="eit-table-tools">
				<div class="eit-view-links">
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . AdminPages::FILTERS_SLUG ) ); ?>"><?php esc_html_e( 'All', 'elementor-implementation-toolkit' ); ?></a>
					<span class="description">(<?php echo esc_html( count( $all_presets ) ); ?>)</span>
					<span class="description"> | </span>
					<span><?php esc_html_e( 'Draft', 'elementor-implementation-toolkit' ); ?></span>
					<span class="description">(<?php echo esc_html( $draft_count ); ?>)</span>
				</div>
				<form class="eit-search-box" method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>">
					<input type="hidden" name="page" value="<?php echo esc_attr( AdminPages::FILTERS_SLUG ); ?>" />
					<input type="search" name="s" value="<?php echo esc_attr( $search ); ?>" />
					<button type="submit" class="button"><?php esc_html_e( 'Search Presets', 'elementor-implementation-toolkit' ); ?></button>
				</form>
			</div>

			<?php if ( empty( $all_presets ) ) : ?>
				<?php
				$this->renderer->render_empty_state(
					__( 'No filter presets yet', 'elementor-implementation-toolkit' ),
					__( 'Create one preset here, then select it in the Elementor widget when multiple pages need the same filter behavior.', 'elementor-implementation-toolkit' ),
					admin_url( 'admin.php?page=' . AdminPages::FILTERS_SLUG . '&view=new' ),
					__( 'Create preset', 'elementor-implementation-toolkit' )
				);
				?>
			<?php elseif ( empty( $presets ) ) : ?>
				<?php
				$this->renderer->render_empty_state(
					__( 'No presets match this search', 'elementor-implementation-toolkit' ),
					__( 'Clear the search or create a new preset when the workflow needs a different filter group.', 'elementor-implementation-toolkit' )
				);
				?>
			<?php else : ?>
				<table class="widefat striped eit-admin-table">
					<thead>
						<tr>
							<th class="check-column"><input type="checkbox" /></th>
							<th><?php esc_html_e( 'Preset', 'elementor-implementation-toolkit' ); ?></th>
							<th><?php esc_html_e( 'Filters', 'elementor-implementation-toolkit' ); ?></th>
							<th><?php esc_html_e( 'Elementor handoff', 'elementor-implementation-toolkit' ); ?></th>
							<th><?php esc_html_e( 'Data source', 'elementor-implementation-toolkit' ); ?></th>
							<th><?php esc_html_e( 'Status', 'elementor-implementation-toolkit' ); ?></th>
							<th><?php esc_html_e( 'Actions', 'elementor-implementation-toolkit' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $presets as $id => $preset ) : ?>
							<?php
							$templates = FilterTemplateManager::get_templates( $id );
							$first_template = ! empty( $templates ) ? reset( $templates ) : null;
							$filter_labels = $this->filter_labels( $preset['filters'] ?? [] );
							$status = empty( $preset['filters'] ?? [] ) ? __( 'Draft', 'elementor-implementation-toolkit' ) : __( 'Ready', 'elementor-implementation-toolkit' );
							?>
							<tr>
								<td><input type="checkbox" /></td>
								<td>
									<a class="eit-row-title" href="<?php echo esc_url( admin_url( 'admin.php?page=' . AdminPages::FILTERS_SLUG . '&preset=' . rawurlencode( $id ) ) ); ?>"><?php echo esc_html( $preset['name'] ?? $id ); ?></a>
									<span class="eit-row-sub"><?php echo esc_html( $preset['slug'] ?? $id ); ?></span>
								</td>
								<td><?php echo esc_html( $filter_labels ?: __( 'No filters yet', 'elementor-implementation-toolkit' ) ); ?></td>
								<td>
									<?php
									if ( $first_template ) {
										printf(
											/* translators: %d: linked Elementor template count. */
											esc_html( _n( '%d template linked', '%d templates linked', count( $templates ), 'elementor-implementation-toolkit' ) ),
											absint( count( $templates ) )
										);
									} else {
										esc_html_e( 'Not linked', 'elementor-implementation-toolkit' );
									}
									?>
								</td>
								<td><?php echo esc_html( FilterPresets::provider_modes()[ $preset['provider_mode'] ?? 'dom' ] ?? __( 'DOM provider', 'elementor-implementation-toolkit' ) ); ?></td>
								<td><span class="eit-status-pill <?php echo empty( $preset['filters'] ?? [] ) ? 'is-neutral' : ''; ?>"><?php echo esc_html( $status ); ?></span></td>
								<td class="eit-row-actions">
									<a class="eit-mini-button" href="<?php echo esc_url( admin_url( 'admin.php?page=' . AdminPages::FILTERS_SLUG . '&preset=' . rawurlencode( $id ) ) ); ?>"><?php esc_html_e( 'Edit', 'elementor-implementation-toolkit' ); ?></a>
									<?php if ( $first_template ) : ?>
										<a class="eit-mini-button" href="<?php echo esc_url( FilterTemplateManager::get_edit_url( $first_template->ID ) ); ?>"><?php esc_html_e( 'Open in Elementor', 'elementor-implementation-toolkit' ); ?></a>
									<?php else : ?>
										<a class="eit-mini-button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=' . self::DUPLICATE_ACTION . '&preset=' . rawurlencode( $id ) ), self::DUPLICATE_ACTION . '_' . $id ) ); ?>"><?php esc_html_e( 'Duplicate', 'elementor-implementation-toolkit' ); ?></a>
									<?php endif; ?>
									<a class="eit-mini-button is-danger" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=' . self::DELETE_ACTION . '&preset=' . rawurlencode( $id ) ), self::DELETE_ACTION . '_' . $id ) ); ?>"><?php esc_html_e( 'Delete', 'elementor-implementation-toolkit' ); ?></a>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
				<p class="description eit-panel-footnote"><?php esc_html_e( 'Presets describe filter behavior. Elementor handles layout and visual placement.', 'elementor-implementation-toolkit' ); ?></p>
			<?php endif; ?>
		</div>
		<div class="eit-savebar">
			<div class="eit-advanced-panel">
				<?php $this->renderer->render_advanced_button( __( 'Advanced preset defaults', 'elementor-implementation-toolkit' ), 'eit-filter-list-advanced-modal' ); ?>
				<?php $this->renderer->render_modal_open( 'eit-filter-list-advanced-modal', __( 'Advanced preset defaults', 'elementor-implementation-toolkit' ) ); ?>
				<div class="eit-advanced-stack eit-advanced-stack--modal">
					<p><?php esc_html_e( 'Default provider and pagination settings are configured inside each preset, not from the list view.', 'elementor-implementation-toolkit' ); ?></p>
				</div>
				<?php $this->renderer->render_modal_close(); ?>
			</div>
			<div class="eit-actions-right">
				<a class="button button-primary" href="<?php echo esc_url( admin_url( 'admin.php?page=' . AdminPages::FILTERS_SLUG . '&view=new' ) ); ?>"><?php esc_html_e( 'Add New Preset', 'elementor-implementation-toolkit' ); ?></a>
			</div>
		</div>
		<?php
	}

	private function filter_labels( array $filters ) {
		$labels = [];

		foreach ( $filters as $filter ) {
			if ( ! is_array( $filter ) ) {
				continue;
			}

			$labels[] = $filter['label'] ?? $filter['key'] ?? $filter['type'] ?? '';
		}

		$labels = array_filter( array_map( 'trim', $labels ) );

		return implode( ', ', $labels );
	}

	private function render_form( array $preset ) {
		$is_existing = ! empty( $preset['id'] );
		$templates = $is_existing ? FilterTemplateManager::get_templates( $preset['id'] ?? '' ) : [];
		?>
		<form class="eit-admin-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="<?php echo esc_attr( self::SAVE_ACTION ); ?>" />
			<input type="hidden" name="preset[id]" value="<?php echo esc_attr( $preset['id'] ?? '' ); ?>" />
			<?php wp_nonce_field( self::SAVE_ACTION ); ?>

			<section class="eit-panel">
				<div class="eit-panel__header">
					<div>
						<h3><?php esc_html_e( 'Preset setup', 'elementor-implementation-toolkit' ); ?></h3>
						<p><?php esc_html_e( 'Name the preset, then hand it to Elementor when you need a reusable Theme Builder filter area.', 'elementor-implementation-toolkit' ); ?></p>
					</div>
				</div>
				<div class="eit-setup-layout">
					<div class="eit-form-stack">
						<label class="eit-field">
							<span><?php esc_html_e( 'Name', 'elementor-implementation-toolkit' ); ?></span>
							<input type="text" name="preset[name]" value="<?php echo esc_attr( $preset['name'] ?? '' ); ?>" placeholder="<?php echo esc_attr__( 'Shop - Main Filters', 'elementor-implementation-toolkit' ); ?>" />
							<small class="description"><?php esc_html_e( 'The name is for your reference only.', 'elementor-implementation-toolkit' ); ?></small>
						</label>
						<label class="eit-field">
							<span><?php esc_html_e( 'Slug', 'elementor-implementation-toolkit' ); ?></span>
							<input type="text" name="preset[slug]" value="<?php echo esc_attr( $preset['slug'] ?? '' ); ?>" />
							<small class="description"><?php esc_html_e( 'The slug is used in shortcodes and templates.', 'elementor-implementation-toolkit' ); ?></small>
						</label>
					</div>
					<?php $this->render_template_handoff_card( $preset, $templates ); ?>
				</div>
			</section>

			<?php $this->render_filter_rows( $preset['filters'] ?? [] ); ?>

			<div class="eit-savebar">
				<div class="eit-form-actions__advanced">
					<?php $this->render_advanced_preset_options( $preset ); ?>
				</div>
				<div class="eit-actions-right">
					<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=' . AdminPages::FILTERS_SLUG ) ); ?>"><?php esc_html_e( 'Cancel', 'elementor-implementation-toolkit' ); ?></a>
					<button type="submit" class="button button-primary"><?php esc_html_e( 'Save Preset', 'elementor-implementation-toolkit' ); ?></button>
				</div>
			</div>
		</form>
		<?php $this->render_filter_template_management( $preset, $is_existing, $templates ); ?>
		<?php
	}

	private function render_template_handoff_card( array $preset, array $templates ) {
		$has_templates = ! empty( $templates );
		?>
		<aside class="eit-handoff-card">
			<div class="eit-handoff-card__head">
				<span class="dashicons dashicons-admin-page" aria-hidden="true"></span>
				<div>
					<h4><?php esc_html_e( 'Theme Builder handoff', 'elementor-implementation-toolkit' ); ?></h4>
					<p><?php esc_html_e( 'Open this preset in Elementor and use it in your Theme Builder to build archive templates with these filters.', 'elementor-implementation-toolkit' ); ?></p>
				</div>
			</div>

			<?php if ( ! FilterTemplateManager::is_elementor_available() ) : ?>
				<div class="notice notice-warning inline">
					<p><?php esc_html_e( 'Elementor must be active to open preset templates.', 'elementor-implementation-toolkit' ); ?></p>
				</div>
			<?php endif; ?>

			<div class="eit-handoff-actions">
				<button type="submit" class="button button-primary" name="eit_after_save" value="open_template" <?php disabled( ! FilterTemplateManager::is_elementor_available() ); ?>>
					<span class="dashicons dashicons-external" aria-hidden="true"></span>
					<?php esc_html_e( 'Save and open in Elementor', 'elementor-implementation-toolkit' ); ?>
				</button>
				<button type="submit" class="button" name="eit_after_save" value="open_template" <?php disabled( ! FilterTemplateManager::is_elementor_available() ); ?>>
					<span class="dashicons dashicons-grid-view" aria-hidden="true"></span>
					<?php esc_html_e( 'Use in Theme Builder', 'elementor-implementation-toolkit' ); ?>
				</button>
			</div>

			<p class="eit-handoff-note">
				<span class="dashicons dashicons-yes" aria-hidden="true"></span>
				<?php if ( $has_templates ) : ?>
					<span>
						<?php
						printf(
							/* translators: %d: template count. */
							esc_html( _n( 'This preset has %d linked Elementor template.', 'This preset has %d linked Elementor templates.', count( $templates ), 'elementor-implementation-toolkit' ) ),
							absint( count( $templates ) )
						);
						?>
					</span>
				<?php else : ?>
					<span><?php esc_html_e( 'This preset is compatible with Elementor Theme Builder.', 'elementor-implementation-toolkit' ); ?></span>
				<?php endif; ?>
			</p>
		</aside>
		<?php
	}

	private function render_advanced_preset_options( array $preset ) {
		$modal_id = 'eit-preset-advanced-modal';
		?>
		<div class="eit-advanced-panel eit-advanced-panel--global">
			<?php $this->renderer->render_advanced_button( __( 'Advanced options', 'elementor-implementation-toolkit' ), $modal_id ); ?>
			<?php $this->renderer->render_modal_open( $modal_id, __( 'Advanced preset options', 'elementor-implementation-toolkit' ), 'eit-modal--wide' ); ?>
			<div class="eit-advanced-stack eit-advanced-stack--modal">
				<section>
					<h4><?php esc_html_e( 'Preset defaults', 'elementor-implementation-toolkit' ); ?></h4>
					<div class="eit-form-grid eit-form-grid--four">
						<?php $this->select_field( 'preset[apply_mode]', __( 'Apply mode', 'elementor-implementation-toolkit' ), $preset['apply_mode'] ?? 'auto', FilterPresets::apply_modes() ); ?>
						<?php $this->number_field( 'preset[per_page]', __( 'Items per page', 'elementor-implementation-toolkit' ), $preset['per_page'] ?? 9, 1, 96, 1 ); ?>
						<?php $this->checkbox_field( 'preset[sync_url]', __( 'Sync URL', 'elementor-implementation-toolkit' ), ! empty( $preset['sync_url'] ) ); ?>
						<?php $this->textarea_field( 'preset[description]', __( 'Internal note', 'elementor-implementation-toolkit' ), $preset['description'] ?? '', 3 ); ?>
					</div>
				</section>

				<section>
					<h4><?php esc_html_e( 'Display controls', 'elementor-implementation-toolkit' ); ?></h4>
					<div class="eit-form-grid eit-form-grid--four">
						<?php $this->select_field( 'preset[pagination_type]', __( 'Pagination', 'elementor-implementation-toolkit' ), $preset['pagination_type'] ?? 'numbers', FilterPresets::pagination_types() ); ?>
						<?php $this->checkbox_field( 'preset[show_result_count]', __( 'Show result count', 'elementor-implementation-toolkit' ), ! empty( $preset['show_result_count'] ) ); ?>
						<?php $this->checkbox_field( 'preset[show_active_chips]', __( 'Show active chips', 'elementor-implementation-toolkit' ), ! empty( $preset['show_active_chips'] ) ); ?>
						<?php $this->checkbox_field( 'preset[show_sort]', __( 'Show sort', 'elementor-implementation-toolkit' ), ! empty( $preset['show_sort'] ) ); ?>
					</div>
				</section>

				<section>
					<h4><?php esc_html_e( 'Provider defaults', 'elementor-implementation-toolkit' ); ?></h4>
					<div class="eit-form-grid">
						<?php $this->select_field( 'preset[provider_mode]', __( 'Data provider', 'elementor-implementation-toolkit' ), $preset['provider_mode'] ?? 'dom', FilterPresets::provider_modes() ); ?>
						<?php $this->text_field( 'preset[target_selector]', __( 'Default target selector', 'elementor-implementation-toolkit' ), $preset['target_selector'] ?? '', '.elementor-loop-container' ); ?>
						<?php $this->text_field( 'preset[item_selector]', __( 'Default item selector', 'elementor-implementation-toolkit' ), $preset['item_selector'] ?? '', '.product, article' ); ?>
					</div>
				</section>

				<section>
					<h4><?php esc_html_e( 'Labels and sort copy', 'elementor-implementation-toolkit' ); ?></h4>
					<div class="eit-form-grid">
						<?php $this->text_field( 'preset[result_count_text]', __( 'Result count text', 'elementor-implementation-toolkit' ), $preset['result_count_text'] ?? '{count} results' ); ?>
						<?php $this->text_field( 'preset[sort_label]', __( 'Sort label', 'elementor-implementation-toolkit' ), $preset['sort_label'] ?? 'Sort by' ); ?>
						<?php $this->textarea_field( 'preset[sort_options]', __( 'Sort options', 'elementor-implementation-toolkit' ), $preset['sort_options'] ?? '', 5 ); ?>
						<?php $this->text_field( 'preset[apply_text]', __( 'Apply button text', 'elementor-implementation-toolkit' ), $preset['apply_text'] ?? 'Apply filters' ); ?>
						<?php $this->text_field( 'preset[reset_text]', __( 'Reset button text', 'elementor-implementation-toolkit' ), $preset['reset_text'] ?? 'Reset' ); ?>
						<?php $this->text_field( 'preset[empty_text]', __( 'Empty state text', 'elementor-implementation-toolkit' ), $preset['empty_text'] ?? 'No matching items found.' ); ?>
						<?php $this->text_field( 'preset[previous_text]', __( 'Previous text', 'elementor-implementation-toolkit' ), $preset['previous_text'] ?? 'Previous' ); ?>
						<?php $this->text_field( 'preset[next_text]', __( 'Next text', 'elementor-implementation-toolkit' ), $preset['next_text'] ?? 'Next' ); ?>
					</div>
				</section>
			</div>
			<?php $this->renderer->render_modal_close(); ?>
		</div>
		<?php
	}

	private function render_filter_rows( array $filters ) {
		$filters = array_values( $filters );

		if ( empty( $filters ) ) {
			$filters[] = FilterPresets::blank_filter(
				[
					'label'       => __( 'Search', 'elementor-implementation-toolkit' ),
					'type'        => 'search',
					'key'         => 'title',
					'query_var'   => 'search',
					'placeholder' => __( 'Search...', 'elementor-implementation-toolkit' ),
				]
			);
		}

		?>
		<section class="eit-panel eit-panel--filters" data-eit-repeater data-eit-repeater-next-index="<?php echo esc_attr( count( $filters ) ); ?>">
			<div class="eit-panel__header">
				<div>
					<h3><?php esc_html_e( 'Filters', 'elementor-implementation-toolkit' ); ?></h3>
					<p><?php esc_html_e( 'Add, reorder and configure the filters in this preset.', 'elementor-implementation-toolkit' ); ?></p>
				</div>
				<button type="button" class="button button-primary" data-eit-add-row>
					<span class="dashicons dashicons-plus-alt2" aria-hidden="true"></span>
					<?php esc_html_e( 'Add filter', 'elementor-implementation-toolkit' ); ?>
				</button>
			</div>

			<div class="eit-filter-table-wrap">
				<table class="widefat eit-filter-table">
					<thead>
						<tr>
							<th class="column-order"><?php esc_html_e( '#', 'elementor-implementation-toolkit' ); ?></th>
							<th><?php esc_html_e( 'Filter', 'elementor-implementation-toolkit' ); ?></th>
							<th><?php esc_html_e( 'Label', 'elementor-implementation-toolkit' ); ?></th>
							<th><?php esc_html_e( 'Type', 'elementor-implementation-toolkit' ); ?></th>
							<th><?php esc_html_e( 'Settings', 'elementor-implementation-toolkit' ); ?></th>
							<th><?php esc_html_e( 'Actions', 'elementor-implementation-toolkit' ); ?></th>
						</tr>
					</thead>
					<tbody data-eit-repeat-list>
				<?php foreach ( $filters as $index => $filter ) : ?>
						<?php $this->render_filter_row( $filter, (string) $index ); ?>
				<?php endforeach; ?>
					</tbody>
				</table>

				<p class="description eit-filter-table-note"><?php esc_html_e( 'Drag and drop to reorder filters.', 'elementor-implementation-toolkit' ); ?></p>
			</div>

			<template data-eit-row-template>
				<?php $this->render_filter_row( FilterPresets::blank_filter( [ 'enabled' => true, 'show_label' => true ] ), '__index__' ); ?>
			</template>
		</section>
		<?php
	}

	private function render_filter_row( array $filter, $index ) {
		$prefix = 'preset[filters][' . $index . ']';
		$type = $filter['type'] ?? 'search';
		$type_label = FilterPresets::filter_types()[ $type ] ?? $type;
		$label = $filter['label'] ?? __( 'Filter', 'elementor-implementation-toolkit' );
		$display_index = is_numeric( $index ) ? ( (int) $index + 1 ) : 1;
		$settings_summary = $this->filter_settings_summary( $filter );
		?>
		<tr class="eit-repeat-row eit-filter-row">
			<td class="column-order">
				<span class="dashicons dashicons-menu" aria-hidden="true"></span>
				<span data-eit-row-number><?php echo esc_html( $display_index ); ?></span>
			</td>
			<td class="eit-filter-row__identity">
				<span class="eit-filter-icon <?php echo esc_attr( $this->filter_icon_class( $type ) ); ?>" data-eit-row-icon aria-hidden="true"></span>
				<strong data-eit-row-title><?php echo esc_html( $label ); ?></strong>
			</td>
			<td>
				<span data-eit-row-label data-eit-row-label-source="label"><?php echo esc_html( $label ); ?></span>
			</td>
			<td>
				<span data-eit-row-type><?php echo esc_html( $type_label ); ?></span>
			</td>
			<td class="eit-filter-row__settings">
				<span class="eit-filter-settings-summary" data-eit-row-settings><?php echo esc_html( $settings_summary ); ?></span>
			</td>
			<td class="eit-row-actions">
				<button type="button" class="button" data-eit-toggle-filter><?php esc_html_e( 'Edit', 'elementor-implementation-toolkit' ); ?></button>
				<button type="button" class="button button-link-delete" data-eit-remove-row><?php esc_html_e( 'Delete', 'elementor-implementation-toolkit' ); ?></button>
			</td>
		</tr>
		<tr class="eit-filter-editor-row" hidden>
			<td colspan="6">
				<?php $this->renderer->render_modal_open( 'eit-filter-editor-' . $index, __( 'Edit filter', 'elementor-implementation-toolkit' ), 'eit-modal--wide' ); ?>
				<div class="eit-filter-inline-grid">
					<input type="hidden" name="<?php echo esc_attr( $prefix ); ?>[enabled]" value="0" />
					<?php $this->checkbox_field( $prefix . '[enabled]', __( 'Enabled', 'elementor-implementation-toolkit' ), ! empty( $filter['enabled'] ) ); ?>
					<label class="eit-field">
						<span><?php esc_html_e( 'Type', 'elementor-implementation-toolkit' ); ?></span>
						<select id="<?php echo esc_attr( 'eit-filter-type-' . $index ); ?>" name="<?php echo esc_attr( $prefix ); ?>[type]" data-eit-row-type-source>
							<?php foreach ( FilterPresets::filter_types() as $option_value => $option_label ) : ?>
								<option value="<?php echo esc_attr( $option_value ); ?>" <?php selected( (string) $type, (string) $option_value ); ?>><?php echo esc_html( $option_label ); ?></option>
							<?php endforeach; ?>
						</select>
					</label>
					<label class="eit-field">
						<span><?php esc_html_e( 'Label', 'elementor-implementation-toolkit' ); ?></span>
						<input id="<?php echo esc_attr( 'eit-filter-label-' . $index ); ?>" type="text" name="<?php echo esc_attr( $prefix ); ?>[label]" value="<?php echo esc_attr( $filter['label'] ?? '' ); ?>" placeholder="<?php echo esc_attr__( 'Category', 'elementor-implementation-toolkit' ); ?>" />
					</label>
					<?php $this->text_field( $prefix . '[key]', __( 'Field or taxonomy key', 'elementor-implementation-toolkit' ), $filter['key'] ?? '', 'category, price, rating' ); ?>
					<?php $this->text_field( $prefix . '[placeholder]', __( 'Placeholder', 'elementor-implementation-toolkit' ), $filter['placeholder'] ?? '' ); ?>
					<?php $this->number_field( $prefix . '[range_min]', __( 'Range min', 'elementor-implementation-toolkit' ), $filter['range_min'] ?? 0, null, null, 'any' ); ?>
					<?php $this->number_field( $prefix . '[range_max]', __( 'Range max', 'elementor-implementation-toolkit' ), $filter['range_max'] ?? 100, null, null, 'any' ); ?>
					<details class="eit-advanced-panel eit-advanced-panel--inline">
						<?php $this->renderer->render_advanced_toggle( __( 'Advanced settings', 'elementor-implementation-toolkit' ) ); ?>
						<div class="eit-advanced-stack">
							<div class="eit-form-grid eit-form-grid--four">
								<?php $this->checkbox_field( $prefix . '[show_label]', __( 'Show label', 'elementor-implementation-toolkit' ), ! empty( $filter['show_label'] ) ); ?>
								<?php $this->checkbox_field( $prefix . '[show_count]', __( 'Show counts', 'elementor-implementation-toolkit' ), ! empty( $filter['show_count'] ) ); ?>
								<?php $this->text_field( $prefix . '[query_var]', __( 'URL parameter', 'elementor-implementation-toolkit' ), $filter['query_var'] ?? '' ); ?>
								<?php $this->text_field( $prefix . '[default_value]', __( 'Default value', 'elementor-implementation-toolkit' ), $filter['default_value'] ?? '' ); ?>
								<?php $this->number_field( $prefix . '[range_step]', __( 'Step', 'elementor-implementation-toolkit' ), $filter['range_step'] ?? 1, null, null, 'any' ); ?>
								<?php $this->select_field( $prefix . '[source]', __( 'Source', 'elementor-implementation-toolkit' ), $filter['source'] ?? 'visible_text', FilterPresets::source_types() ); ?>
								<?php $this->select_field( $prefix . '[compare]', __( 'Compare', 'elementor-implementation-toolkit' ), $filter['compare'] ?? 'contains', FilterPresets::compare_types() ); ?>
								<?php $this->select_field( $prefix . '[data_type]', __( 'Data type', 'elementor-implementation-toolkit' ), $filter['data_type'] ?? 'string', FilterPresets::data_types() ); ?>
								<?php $this->textarea_field( $prefix . '[options]', __( 'Options for choices, chips, swatches, or rating', 'elementor-implementation-toolkit' ), $filter['options'] ?? '', 4 ); ?>
							</div>
						</div>
					</details>
				</div>
				<?php $this->renderer->render_modal_close(); ?>
			</td>
		</tr>
		<?php
	}

	private function filter_icon_class( $type ) {
		$icons = [
			'search'   => 'dashicons dashicons-search',
			'select'   => 'dashicons dashicons-category',
			'range'    => 'dashicons dashicons-slides',
			'checkbox' => 'dashicons dashicons-yes-alt',
			'radio'    => 'dashicons dashicons-marker',
			'chips'    => 'dashicons dashicons-screenoptions',
			'toggle'   => 'dashicons dashicons-controls-repeat',
			'date'     => 'dashicons dashicons-calendar-alt',
			'rating'   => 'dashicons dashicons-star-filled',
			'swatch'   => 'dashicons dashicons-art',
		];

		return $icons[ $type ] ?? 'dashicons dashicons-filter';
	}

	private function filter_settings_summary( array $filter ) {
		$parts = [];

		if ( ! empty( $filter['placeholder'] ) ) {
			$parts[] = sprintf(
				/* translators: %s: filter placeholder. */
				__( 'Placeholder: %s', 'elementor-implementation-toolkit' ),
				$filter['placeholder']
			);
		}

		if ( ! empty( $filter['key'] ) ) {
			$parts[] = sprintf(
				/* translators: %s: field or taxonomy key. */
				__( 'Key: %s', 'elementor-implementation-toolkit' ),
				$filter['key']
			);
		}

		if ( 'range' === ( $filter['type'] ?? '' ) ) {
			$parts[] = sprintf(
				/* translators: 1: min value, 2: max value. */
				__( 'Range: %1$s-%2$s', 'elementor-implementation-toolkit' ),
				$filter['range_min'] ?? __( 'Auto', 'elementor-implementation-toolkit' ),
				$filter['range_max'] ?? __( 'Auto', 'elementor-implementation-toolkit' )
			);
		}

		return implode( ' - ', array_slice( $parts, 0, 2 ) ) ?: __( 'Default settings', 'elementor-implementation-toolkit' );
	}

	private function render_filter_template_management( array $preset, $is_existing, array $templates ) {
		if ( ! $is_existing || empty( $templates ) ) {
			return;
		}

		?>
		<section class="eit-panel eit-bridge-panel">
			<div class="eit-panel__header">
				<div>
					<h3><?php esc_html_e( 'Linked Elementor templates', 'elementor-implementation-toolkit' ); ?></h3>
					<p><?php esc_html_e( 'Manage the templates created from this preset. The main handoff stays at the top of the form.', 'elementor-implementation-toolkit' ); ?></p>
				</div>
			</div>

			<?php if ( ! FilterTemplateManager::is_elementor_available() ) : ?>
				<div class="notice notice-warning inline">
					<p><?php esc_html_e( 'Elementor must be active to create and edit filter templates.', 'elementor-implementation-toolkit' ); ?></p>
				</div>
			<?php endif; ?>

			<?php $this->render_template_rows( $templates, $preset['id'] ?? '' ); ?>
		</section>
		<?php
	}

	private function get_or_create_template_for_preset( $preset_id ) {
		$templates = FilterTemplateManager::get_templates( $preset_id );

		if ( ! empty( $templates ) ) {
			$template = reset( $templates );
			return absint( $template->ID );
		}

		return FilterTemplateManager::create_filter_template( $preset_id );
	}

	private function render_template_rows( array $templates, $preset_id ) {
		if ( empty( $templates ) ) {
			?>
			<div class="eit-empty-panel eit-empty-panel--compact">
				<h3><?php esc_html_e( 'No Elementor template yet', 'elementor-implementation-toolkit' ); ?></h3>
				<p><?php esc_html_e( 'Create one when you want a reusable filter layout for a page or theme template.', 'elementor-implementation-toolkit' ); ?></p>
			</div>
			<?php
			return;
		}
		?>
		<table class="widefat striped eit-admin-table">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Template', 'elementor-implementation-toolkit' ); ?></th>
					<th><?php esc_html_e( 'Status', 'elementor-implementation-toolkit' ); ?></th>
					<th><?php esc_html_e( 'Actions', 'elementor-implementation-toolkit' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $templates as $template ) : ?>
					<?php $status = get_post_status_object( $template->post_status ); ?>
					<tr>
						<td>
							<strong><?php echo esc_html( get_the_title( $template ) ); ?></strong>
							<div class="row-actions"><span><?php echo esc_html( '#' . $template->ID ); ?></span></div>
						</td>
						<td><?php echo esc_html( $status ? $status->label : $template->post_status ); ?></td>
						<td class="eit-row-actions">
							<a class="button button-primary" href="<?php echo esc_url( FilterTemplateManager::get_edit_url( $template->ID ) ); ?>"><?php esc_html_e( 'Edit in Elementor', 'elementor-implementation-toolkit' ); ?></a>
							<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="return confirm('<?php echo esc_js( __( 'Remove this filter template?', 'elementor-implementation-toolkit' ) ); ?>');">
								<input type="hidden" name="action" value="<?php echo esc_attr( self::DELETE_TEMPLATE_ACTION ); ?>" />
								<input type="hidden" name="template_id" value="<?php echo esc_attr( $template->ID ); ?>" />
								<input type="hidden" name="preset" value="<?php echo esc_attr( $preset_id ); ?>" />
								<?php wp_nonce_field( self::DELETE_TEMPLATE_ACTION . '_' . $template->ID ); ?>
								<button type="submit" class="button button-link-delete"><?php esc_html_e( 'Remove', 'elementor-implementation-toolkit' ); ?></button>
							</form>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	private function normalize_preset_post( $raw ) {
		$raw = is_array( $raw ) ? $raw : [];
		$filters = is_array( $raw['filters'] ?? null ) ? $raw['filters'] : [];
		$normalized_filters = [];

		foreach ( $filters as $filter ) {
			if ( ! is_array( $filter ) || ! $this->is_meaningful_filter( $filter ) ) {
				continue;
			}

			$normalized_filters[] = $filter;
		}

		$raw['filters'] = $normalized_filters;

		return $raw;
	}

	private function is_meaningful_filter( array $filter ) {
		if ( ! empty( $filter['enabled'] ) ) {
			return true;
		}

		foreach ( [ 'label', 'key', 'query_var', 'options', 'default_value' ] as $field ) {
			if ( '' !== trim( (string) ( $filter[ $field ] ?? '' ) ) ) {
				return true;
			}
		}

		return false;
	}

	private function current_preset_id() {
		return isset( $_GET['preset'] ) ? sanitize_key( wp_unslash( $_GET['preset'] ) ) : '';
	}

	private function posted_or_requested_id( $key ) {
		if ( isset( $_POST[ $key ] ) ) {
			return sanitize_key( wp_unslash( $_POST[ $key ] ) );
		}

		return isset( $_GET[ $key ] ) ? sanitize_key( wp_unslash( $_GET[ $key ] ) ) : '';
	}

	private function assert_can_manage() {
		if ( ! current_user_can( AdminPages::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to manage Toolkit settings.', 'elementor-implementation-toolkit' ) );
		}
	}

	private function redirect( array $args ) {
		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
		exit;
	}
}
