<?php
/**
 * Read-only preview and diagnostics views for filter presets.
 */

namespace EIT\Admin;

use EIT\Support\FilterPresets;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class FilterPresetPreviewView {

	private $renderer;
	private $inspector;

	public function __construct( AdminRenderer $renderer, FilterPresetInspector $inspector ) {
		$this->renderer = $renderer;
		$this->inspector = $inspector;
	}

	public function render_selector_summary( array $preset ) {
		$target = trim( (string) ( $preset['target_selector'] ?? '' ) );
		$item = trim( (string) ( $preset['item_selector'] ?? '' ) );
		?>
		<div class="eit-selector-stack">
			<span><?php echo esc_html( $target ?: __( 'No target selector', 'elementor-implementation-toolkit' ) ); ?></span>
			<span><?php echo esc_html( $item ?: __( 'Auto item detection', 'elementor-implementation-toolkit' ) ); ?></span>
		</div>
		<?php
	}

	public function render_modal( $modal_id, $preset_id, array $preset, array $diagnostics ) {
		$this->renderer->render_modal_open(
			$modal_id,
			sprintf( __( 'Preset preview: %s', 'elementor-implementation-toolkit' ), $preset['name'] ?? $preset_id ),
			'eit-modal--wide'
		);
		?>
		<div class="eit-preview-grid">
			<section>
				<h4><?php esc_html_e( 'Structure loaded by the widget', 'elementor-implementation-toolkit' ); ?></h4>
				<?php $this->render_preview( $preset ); ?>
			</section>
			<section>
				<h4><?php esc_html_e( 'Diagnostics', 'elementor-implementation-toolkit' ); ?></h4>
				<?php $this->render_diagnostics( $diagnostics ); ?>
			</section>
		</div>
		<?php
		$this->renderer->render_modal_close();
	}

	public function render_observability( array $preset ) {
		$diagnostics = $this->inspector->diagnostics( $preset );
		$health = $this->inspector->health( $diagnostics );
		?>
		<section class="eit-panel eit-panel--observability">
			<div class="eit-panel__header">
				<div>
					<h3><?php esc_html_e( 'Library preview and diagnostics', 'elementor-implementation-toolkit' ); ?></h3>
					<p><?php esc_html_e( 'This is the reusable preset shape the widget loads. Visual styling remains in Elementor.', 'elementor-implementation-toolkit' ); ?></p>
				</div>
				<span class="<?php echo esc_attr( $health['class'] ); ?>"><?php echo esc_html( $health['label'] ); ?></span>
			</div>
			<div class="eit-preview-grid eit-panel__body">
				<section>
					<h4><?php esc_html_e( 'Source and reuse', 'elementor-implementation-toolkit' ); ?></h4>
					<dl class="eit-definition-list">
						<div>
							<dt><?php esc_html_e( 'Source', 'elementor-implementation-toolkit' ); ?></dt>
							<dd><?php echo esc_html( $this->inspector->source_label( $preset ) ); ?></dd>
						</div>
						<div>
							<dt><?php esc_html_e( 'Source detail', 'elementor-implementation-toolkit' ); ?></dt>
							<dd><?php echo esc_html( $this->inspector->source_detail( $preset ) ); ?></dd>
						</div>
						<div>
							<dt><?php esc_html_e( 'Updated', 'elementor-implementation-toolkit' ); ?></dt>
							<dd><?php echo esc_html( $this->inspector->updated_label( $preset ) ); ?></dd>
						</div>
						<div>
							<dt><?php esc_html_e( 'Provider', 'elementor-implementation-toolkit' ); ?></dt>
							<dd><?php echo esc_html( FilterPresets::provider_modes()[ $preset['provider_mode'] ?? 'dom' ] ?? __( 'DOM provider', 'elementor-implementation-toolkit' ) ); ?></dd>
						</div>
					</dl>
				</section>
				<section>
					<h4><?php esc_html_e( 'Structure preview', 'elementor-implementation-toolkit' ); ?></h4>
					<?php $this->render_preview( $preset ); ?>
				</section>
				<section>
					<h4><?php esc_html_e( 'Diagnostics', 'elementor-implementation-toolkit' ); ?></h4>
					<?php $this->render_diagnostics( $diagnostics ); ?>
				</section>
			</div>
		</section>
		<?php
	}

	private function render_preview( array $preset ) {
		$filters = is_array( $preset['filters'] ?? null ) ? $preset['filters'] : [];
		?>
		<div class="eit-preset-preview">
			<div class="eit-preview-meta">
				<span><?php echo esc_html( FilterPresets::apply_modes()[ $preset['apply_mode'] ?? 'auto' ] ?? __( 'Auto apply', 'elementor-implementation-toolkit' ) ); ?></span>
				<span><?php echo esc_html( sprintf( __( '%d per page', 'elementor-implementation-toolkit' ), absint( $preset['per_page'] ?? 24 ) ) ); ?></span>
				<span><?php echo ! empty( $preset['sync_url'] ) ? esc_html__( 'URL sync on', 'elementor-implementation-toolkit' ) : esc_html__( 'URL sync off', 'elementor-implementation-toolkit' ); ?></span>
			</div>
			<?php if ( empty( $filters ) ) : ?>
				<p class="description"><?php esc_html_e( 'No filters are saved in this preset yet.', 'elementor-implementation-toolkit' ); ?></p>
			<?php else : ?>
				<ol class="eit-preview-filters">
					<?php foreach ( $filters as $filter ) : ?>
						<?php $this->render_filter_preview( $filter ); ?>
					<?php endforeach; ?>
				</ol>
			<?php endif; ?>
		</div>
		<?php
	}

	private function render_filter_preview( array $filter ) {
		$type = $filter['type'] ?? 'search';
		$type_label = FilterPresets::filter_types()[ $type ] ?? $type;
		?>
		<li class="eit-preview-filter <?php echo empty( $filter['enabled'] ) ? 'is-disabled' : ''; ?>">
			<span class="eit-filter-icon <?php echo esc_attr( FilterPresetInspector::icon_class( $type ) ); ?>" aria-hidden="true"></span>
			<div>
				<strong><?php echo esc_html( $filter['label'] ?? __( 'Filter', 'elementor-implementation-toolkit' ) ); ?></strong>
				<span class="eit-preview-chip"><?php echo esc_html( $type_label ); ?></span>
				<dl class="eit-definition-list eit-definition-list--compact">
					<div><dt><?php esc_html_e( 'Key', 'elementor-implementation-toolkit' ); ?></dt><dd><?php echo esc_html( $filter['key'] ?? __( 'None', 'elementor-implementation-toolkit' ) ); ?></dd></div>
					<?php if ( ! empty( $filter['field_binding'] ) ) : ?>
						<div><dt><?php esc_html_e( 'Binding', 'elementor-implementation-toolkit' ); ?></dt><dd><?php echo esc_html( $filter['field_binding'] ); ?></dd></div>
					<?php endif; ?>
					<div><dt><?php esc_html_e( 'Source', 'elementor-implementation-toolkit' ); ?></dt><dd><?php echo esc_html( FilterPresets::source_types()[ $filter['source'] ?? 'visible_text' ] ?? __( 'Visible text', 'elementor-implementation-toolkit' ) ); ?></dd></div>
					<div><dt><?php esc_html_e( 'Compare', 'elementor-implementation-toolkit' ); ?></dt><dd><?php echo esc_html( FilterPresets::compare_types()[ $filter['compare'] ?? 'contains' ] ?? __( 'Contains', 'elementor-implementation-toolkit' ) ); ?></dd></div>
					<?php if ( 'range' === $type ) : ?>
						<div><dt><?php esc_html_e( 'Range', 'elementor-implementation-toolkit' ); ?></dt><dd><?php echo esc_html( ( $filter['range_min'] ?? 0 ) . ' - ' . ( $filter['range_max'] ?? 100 ) ); ?></dd></div>
					<?php endif; ?>
					<?php if ( in_array( $type, FilterPresetInspector::option_based_filter_types(), true ) ) : ?>
						<div><dt><?php esc_html_e( 'Options', 'elementor-implementation-toolkit' ); ?></dt><dd><?php echo esc_html( sprintf( __( '%d saved', 'elementor-implementation-toolkit' ), FilterPresetInspector::option_count( $filter['options'] ?? '' ) ) ); ?></dd></div>
					<?php endif; ?>
				</dl>
			</div>
		</li>
		<?php
	}

	private function render_diagnostics( array $diagnostics ) {
		$visible = array_filter(
			$diagnostics,
			function ( $diagnostic ) {
				return 'info' !== ( $diagnostic['severity'] ?? '' );
			}
		);
		if ( empty( $visible ) ) {
			?>
			<div class="eit-diagnostic is-ok">
				<strong><?php esc_html_e( 'No blocking issues found', 'elementor-implementation-toolkit' ); ?></strong>
				<p><?php esc_html_e( 'Selectors still need real page confirmation in Elementor or on the frontend.', 'elementor-implementation-toolkit' ); ?></p>
			</div>
			<?php
		}
		?>
		<ul class="eit-diagnostic-list">
			<?php foreach ( $diagnostics as $diagnostic ) : ?>
				<li class="eit-diagnostic is-<?php echo esc_attr( $diagnostic['severity'] ?? 'info' ); ?>">
					<strong><?php echo esc_html( $diagnostic['title'] ?? '' ); ?></strong>
					<p><?php echo esc_html( $diagnostic['message'] ?? '' ); ?></p>
				</li>
			<?php endforeach; ?>
		</ul>
		<?php
	}
}
