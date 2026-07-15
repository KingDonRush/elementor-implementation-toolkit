<?php
/**
 * Library list for reusable filter presets.
 */

namespace EIT\Admin;

use EIT\Elementor\FilterTemplateManager;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class FilterPresetListView {

	private $renderer;
	private $inspector;
	private $preview;

	public function __construct( AdminRenderer $renderer, FilterPresetInspector $inspector, FilterPresetPreviewView $preview ) {
		$this->renderer = $renderer;
		$this->inspector = $inspector;
		$this->preview = $preview;
	}

	public function render( array $presets ) {
		$all_presets = $presets;
		$search = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
		$summary = $this->inspector->library_summary( $all_presets );

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
								$this->inspector->source_label( $preset ),
								$this->inspector->filter_labels( $preset['filters'] ?? [] ),
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
					<h3><?php esc_html_e( 'Preset Library', 'elementor-implementation-toolkit' ); ?></h3>
					<p><?php esc_html_e( 'Saved filter configurations, reuse metadata, and health signals. Build visually in Elementor; use wp-admin to audit and recover.', 'elementor-implementation-toolkit' ); ?></p>
				</div>
				<a class="button button-primary" href="<?php echo esc_url( admin_url( 'admin.php?page=' . AdminPages::FILTERS_SLUG . '&view=new' ) ); ?>"><?php esc_html_e( 'Add New', 'elementor-implementation-toolkit' ); ?></a>
			</div>

			<div class="eit-table-tools">
				<div class="eit-view-links">
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . AdminPages::FILTERS_SLUG ) ); ?>"><?php esc_html_e( 'All', 'elementor-implementation-toolkit' ); ?></a>
					<span class="description">(<?php echo esc_html( count( $all_presets ) ); ?>)</span>
					<span class="description"> | </span>
					<span><?php esc_html_e( 'Draft', 'elementor-implementation-toolkit' ); ?></span>
					<span class="description">(<?php echo esc_html( $summary['draft'] ); ?>)</span>
					<span class="description"> | </span>
					<span><?php esc_html_e( 'Needs attention', 'elementor-implementation-toolkit' ); ?></span>
					<span class="description">(<?php echo esc_html( $summary['attention'] ); ?>)</span>
				</div>
				<form class="eit-search-box" method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>">
					<input type="hidden" name="page" value="<?php echo esc_attr( AdminPages::FILTERS_SLUG ); ?>" />
					<input type="search" name="s" value="<?php echo esc_attr( $search ); ?>" />
					<button type="submit" class="button"><?php esc_html_e( 'Search Presets', 'elementor-implementation-toolkit' ); ?></button>
				</form>
			</div>

			<div class="eit-library-summary" aria-label="<?php echo esc_attr__( 'Preset library summary', 'elementor-implementation-toolkit' ); ?>">
				<div class="eit-library-summary__item"><strong><?php echo esc_html( count( $all_presets ) ); ?></strong><span><?php esc_html_e( 'Saved presets', 'elementor-implementation-toolkit' ); ?></span></div>
				<div class="eit-library-summary__item"><strong><?php echo esc_html( $summary['widget'] ); ?></strong><span><?php esc_html_e( 'From Elementor widgets', 'elementor-implementation-toolkit' ); ?></span></div>
				<div class="eit-library-summary__item"><strong><?php echo esc_html( $summary['filters'] ); ?></strong><span><?php esc_html_e( 'Total filters', 'elementor-implementation-toolkit' ); ?></span></div>
				<div class="eit-library-summary__item"><strong><?php echo esc_html( $summary['attention'] ); ?></strong><span><?php esc_html_e( 'Diagnostics to review', 'elementor-implementation-toolkit' ); ?></span></div>
			</div>

			<?php if ( empty( $all_presets ) ) : ?>
				<?php $this->render_empty_library(); ?>
			<?php elseif ( empty( $presets ) ) : ?>
				<?php $this->renderer->render_empty_state( __( 'No presets match this search', 'elementor-implementation-toolkit' ), __( 'Clear the search or create a new preset when the workflow needs a different filter group.', 'elementor-implementation-toolkit' ) ); ?>
			<?php else : ?>
				<?php $this->render_table( $presets ); ?>
			<?php endif; ?>
		</div>
		<?php
	}

	private function render_empty_library() {
		$this->renderer->render_empty_state(
			__( 'No filter presets yet', 'elementor-implementation-toolkit' ),
			__( 'Build filters directly in the Elementor widget, save them as a preset, then use this screen as the library and recovery point.', 'elementor-implementation-toolkit' ),
			admin_url( 'admin.php?page=' . AdminPages::FILTERS_SLUG . '&view=new' ),
			__( 'Create preset', 'elementor-implementation-toolkit' )
		);
	}

	private function render_table( array $presets ) {
		$preview_modals = [];
		?>
		<table class="widefat striped eit-admin-table">
			<thead><tr><th><?php esc_html_e( 'Preset', 'elementor-implementation-toolkit' ); ?></th><th><?php esc_html_e( 'Source', 'elementor-implementation-toolkit' ); ?></th><th><?php esc_html_e( 'Filters', 'elementor-implementation-toolkit' ); ?></th><th><?php esc_html_e( 'Selectors', 'elementor-implementation-toolkit' ); ?></th><th><?php esc_html_e( 'Updated', 'elementor-implementation-toolkit' ); ?></th><th><?php esc_html_e( 'Health', 'elementor-implementation-toolkit' ); ?></th><th><?php esc_html_e( 'Actions', 'elementor-implementation-toolkit' ); ?></th></tr></thead>
			<tbody>
				<?php foreach ( $presets as $id => $preset ) : ?>
					<?php
					$templates = FilterTemplateManager::get_templates( $id );
					$first_template = $templates ? reset( $templates ) : null;
					$diagnostics = $this->inspector->diagnostics( $preset );
					$health = $this->inspector->health( $diagnostics );
					$modal_id = 'eit-preset-preview-' . sanitize_html_class( $id );
					$preview_modals[] = compact( 'id', 'modal_id', 'preset', 'diagnostics' );
					?>
					<tr>
						<td><a class="eit-row-title" href="<?php echo esc_url( admin_url( 'admin.php?page=' . AdminPages::FILTERS_SLUG . '&preset=' . rawurlencode( $id ) ) ); ?>"><?php echo esc_html( $preset['name'] ?? $id ); ?></a><span class="eit-row-sub"><?php echo esc_html( $preset['slug'] ?? $id ); ?></span><span class="eit-row-sub"><?php echo esc_html( sprintf( __( 'Key: %s', 'elementor-implementation-toolkit' ), $id ) ); ?></span></td>
						<td><span class="eit-source-mark"><?php echo esc_html( $this->inspector->source_label( $preset ) ); ?></span><span class="eit-row-sub"><?php echo esc_html( $this->inspector->source_detail( $preset ) ); ?></span></td>
						<td><strong><?php echo esc_html( count( $preset['filters'] ?? [] ) ); ?></strong><span class="eit-row-sub"><?php echo esc_html( $this->inspector->filter_labels( $preset['filters'] ?? [] ) ?: __( 'No filters yet', 'elementor-implementation-toolkit' ) ); ?></span></td>
						<td><?php $this->preview->render_selector_summary( $preset ); ?></td>
						<td><?php echo esc_html( $this->inspector->updated_label( $preset ) ); ?></td>
						<td><span class="<?php echo esc_attr( $health['class'] ); ?>"><?php echo esc_html( $health['label'] ); ?></span><span class="eit-row-sub"><?php echo esc_html( $health['summary'] ); ?></span></td>
						<td class="eit-row-actions">
							<a class="eit-mini-button" href="<?php echo esc_url( admin_url( 'admin.php?page=' . AdminPages::FILTERS_SLUG . '&preset=' . rawurlencode( $id ) ) ); ?>"><?php esc_html_e( 'Edit', 'elementor-implementation-toolkit' ); ?></a>
							<button type="button" class="eit-mini-button" data-eit-open-modal="<?php echo esc_attr( $modal_id ); ?>"><?php esc_html_e( 'Preview', 'elementor-implementation-toolkit' ); ?></button>
							<?php if ( $first_template ) : ?><a class="eit-mini-button" href="<?php echo esc_url( FilterTemplateManager::get_edit_url( $first_template->ID ) ); ?>"><?php esc_html_e( 'Open in Elementor', 'elementor-implementation-toolkit' ); ?></a><?php endif; ?>
							<a class="eit-mini-button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=' . FilterPresetAdmin::DUPLICATE_ACTION . '&preset=' . rawurlencode( $id ) ), FilterPresetAdmin::DUPLICATE_ACTION . '_' . $id ) ); ?>"><?php esc_html_e( 'Duplicate', 'elementor-implementation-toolkit' ); ?></a>
							<a class="eit-mini-button is-danger" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=' . FilterPresetAdmin::DELETE_ACTION . '&preset=' . rawurlencode( $id ) ), FilterPresetAdmin::DELETE_ACTION . '_' . $id ) ); ?>"><?php esc_html_e( 'Delete', 'elementor-implementation-toolkit' ); ?></a>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php foreach ( $preview_modals as $modal ) : ?>
			<?php $this->preview->render_modal( $modal['modal_id'], $modal['id'], $modal['preset'], $modal['diagnostics'] ); ?>
		<?php endforeach; ?>
		<p class="description eit-panel-footnote"><?php esc_html_e( 'Presets describe filter behavior. Elementor handles layout and visual placement; this library shows reuse and health, not final visual QA.', 'elementor-implementation-toolkit' ); ?></p>
		<?php
	}
}
