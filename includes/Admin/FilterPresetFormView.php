<?php
/**
 * Edit form for a reusable filter preset.
 */

namespace EIT\Admin;

use EIT\Elementor\FilterTemplateManager;
use EIT\Support\FilterPresets;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class FilterPresetFormView {

	use AdminFormFields;

	private $renderer;
	private $preview;
	private $templates;

	public function __construct( AdminRenderer $renderer, FilterPresetPreviewView $preview, FilterPresetTemplateView $templates ) {
		$this->renderer = $renderer;
		$this->preview = $preview;
		$this->templates = $templates;
	}

	public function render( array $preset ) {
		$is_existing = ! empty( $preset['id'] );
		$templates = $is_existing ? FilterTemplateManager::get_templates( $preset['id'] ?? '' ) : [];
		?>
		<form class="eit-admin-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="<?php echo esc_attr( FilterPresetAdmin::SAVE_ACTION ); ?>" />
			<input type="hidden" name="preset[id]" value="<?php echo esc_attr( $preset['id'] ?? '' ); ?>" />
			<?php wp_nonce_field( FilterPresetAdmin::SAVE_ACTION ); ?>
			<section class="eit-panel">
				<div class="eit-panel__header">
					<div><h3><?php esc_html_e( 'Preset setup', 'elementor-implementation-toolkit' ); ?></h3><p><?php esc_html_e( 'Name the preset, then create reusable filter controls or place the widget manually in Elementor.', 'elementor-implementation-toolkit' ); ?></p></div>
				</div>
				<div class="eit-setup-layout">
					<div class="eit-form-stack">
						<label class="eit-field"><span><?php esc_html_e( 'Name', 'elementor-implementation-toolkit' ); ?></span><input type="text" name="preset[name]" value="<?php echo esc_attr( $preset['name'] ?? '' ); ?>" placeholder="<?php echo esc_attr__( 'Shop - Main Filters', 'elementor-implementation-toolkit' ); ?>" /><small class="description"><?php esc_html_e( 'The name is for your reference only.', 'elementor-implementation-toolkit' ); ?></small></label>
						<label class="eit-field"><span><?php esc_html_e( 'Slug', 'elementor-implementation-toolkit' ); ?></span><input type="text" name="preset[slug]" value="<?php echo esc_attr( $preset['slug'] ?? '' ); ?>" /><small class="description"><?php esc_html_e( 'The slug is used in shortcodes and templates.', 'elementor-implementation-toolkit' ); ?></small></label>
					</div>
					<?php $this->templates->render_handoff( $templates ); ?>
				</div>
			</section>

			<?php $this->preview->render_observability( $preset ); ?>
			<?php $this->render_filter_rows( $preset['filters'] ?? [] ); ?>

			<div class="eit-savebar">
				<div class="eit-form-actions__advanced"><?php $this->render_advanced_options( $preset ); ?></div>
				<div class="eit-actions-right">
					<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=' . AdminPages::FILTERS_SLUG ) ); ?>"><?php esc_html_e( 'Cancel', 'elementor-implementation-toolkit' ); ?></a>
					<button type="submit" class="button button-primary"><?php esc_html_e( 'Save Preset', 'elementor-implementation-toolkit' ); ?></button>
				</div>
			</div>
		</form>
		<?php $this->templates->render_management( $preset, $is_existing, $templates ); ?>
		<?php
	}

	private function render_advanced_options( array $preset ) {
		$modal_id = 'eit-preset-advanced-modal';
		$provider_mode = $preset['provider_mode'] ?? 'dom';
		?>
		<div class="eit-advanced-panel eit-advanced-panel--global">
			<?php $this->renderer->render_advanced_button( __( 'Advanced options', 'elementor-implementation-toolkit' ), $modal_id ); ?>
			<?php $this->renderer->render_modal_open( $modal_id, __( 'Advanced preset options', 'elementor-implementation-toolkit' ), 'eit-modal--wide' ); ?>
			<div class="eit-advanced-stack eit-advanced-stack--modal">
				<section>
					<h4><?php esc_html_e( 'Preset defaults', 'elementor-implementation-toolkit' ); ?></h4>
					<div class="eit-form-grid eit-form-grid--four">
						<?php $this->select_field( 'preset[apply_mode]', __( 'Apply mode', 'elementor-implementation-toolkit' ), $preset['apply_mode'] ?? 'auto', FilterPresets::apply_modes() ); ?>
						<?php $this->number_field( 'preset[search_debounce_ms]', __( 'Search debounce (ms)', 'elementor-implementation-toolkit' ), $preset['search_debounce_ms'] ?? 250, 0, 2000, 50 ); ?>
						<?php $this->number_field( 'preset[per_page]', __( 'Items per page', 'elementor-implementation-toolkit' ), $preset['per_page'] ?? 24, 1, 48, 1 ); ?>
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
					<h4><?php esc_html_e( 'Legacy provider defaults', 'elementor-implementation-toolkit' ); ?></h4>
					<p class="description"><?php esc_html_e( 'These selectors keep existing 1.x widgets compatible. New Blueprint collections will not require manual selectors.', 'elementor-implementation-toolkit' ); ?></p>
					<div class="eit-form-grid">
						<?php $this->select_field( 'preset[provider_mode]', __( 'Runtime provider', 'elementor-implementation-toolkit' ), $provider_mode, FilterPresets::editable_provider_modes( $provider_mode ) ); ?>
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
		?>
		<section class="eit-panel eit-panel--filters" data-eit-repeater data-eit-repeater-next-index="<?php echo esc_attr( count( $filters ) ); ?>">
			<div class="eit-panel__header">
				<div><h3><?php esc_html_e( 'Filters', 'elementor-implementation-toolkit' ); ?></h3><p><?php esc_html_e( 'Add, reorder and configure the filters in this preset.', 'elementor-implementation-toolkit' ); ?></p></div>
				<button type="button" class="button button-primary" data-eit-add-row><span class="dashicons dashicons-plus-alt2" aria-hidden="true"></span><?php esc_html_e( 'Add filter', 'elementor-implementation-toolkit' ); ?></button>
			</div>
			<div class="eit-filter-table-wrap">
				<table class="widefat eit-filter-table">
					<thead><tr><th class="column-order"><?php esc_html_e( '#', 'elementor-implementation-toolkit' ); ?></th><th><?php esc_html_e( 'Filter', 'elementor-implementation-toolkit' ); ?></th><th><?php esc_html_e( 'Label', 'elementor-implementation-toolkit' ); ?></th><th><?php esc_html_e( 'Type', 'elementor-implementation-toolkit' ); ?></th><th><?php esc_html_e( 'Settings', 'elementor-implementation-toolkit' ); ?></th><th><?php esc_html_e( 'Actions', 'elementor-implementation-toolkit' ); ?></th></tr></thead>
					<tbody data-eit-repeat-list><?php foreach ( $filters as $index => $filter ) : ?><?php $this->render_filter_row( $filter, (string) $index ); ?><?php endforeach; ?></tbody>
				</table>
				<p class="description eit-filter-table-note"><?php esc_html_e( 'Drag and drop to reorder filters.', 'elementor-implementation-toolkit' ); ?></p>
			</div>
			<template data-eit-row-template><?php $this->render_filter_row( FilterPresets::blank_filter( [ 'enabled' => true, 'show_label' => true ] ), '__index__' ); ?></template>
		</section>
		<?php
	}

	private function render_filter_row( array $filter, $index ) {
		$prefix = 'preset[filters][' . $index . ']';
		$type = $filter['type'] ?? 'search';
		$type_label = FilterPresets::filter_types()[ $type ] ?? $type;
		$label = $filter['label'] ?? __( 'Filter', 'elementor-implementation-toolkit' );
		?>
		<tr class="eit-repeat-row eit-filter-row">
			<td class="column-order"><span class="dashicons dashicons-menu" aria-hidden="true"></span><span data-eit-row-number><?php echo esc_html( is_numeric( $index ) ? ( (int) $index + 1 ) : 1 ); ?></span></td>
			<td class="eit-filter-row__identity"><span class="eit-filter-icon <?php echo esc_attr( FilterPresetInspector::icon_class( $type ) ); ?>" data-eit-row-icon aria-hidden="true"></span><strong data-eit-row-title><?php echo esc_html( $label ); ?></strong></td>
			<td><span data-eit-row-label data-eit-row-label-source="label"><?php echo esc_html( $label ); ?></span></td>
			<td><span data-eit-row-type><?php echo esc_html( $type_label ); ?></span></td>
			<td class="eit-filter-row__settings"><span class="eit-filter-settings-summary" data-eit-row-settings><?php echo esc_html( $this->settings_summary( $filter ) ); ?></span></td>
			<td class="eit-row-actions"><button type="button" class="button" data-eit-toggle-filter><?php esc_html_e( 'Edit', 'elementor-implementation-toolkit' ); ?></button><button type="button" class="button button-link-delete" data-eit-remove-row><?php esc_html_e( 'Delete', 'elementor-implementation-toolkit' ); ?></button></td>
		</tr>
		<tr class="eit-filter-editor-row" hidden><td colspan="6">
			<?php $this->renderer->render_modal_open( 'eit-filter-editor-' . $index, __( 'Edit filter', 'elementor-implementation-toolkit' ), 'eit-modal--wide' ); ?>
			<div class="eit-filter-inline-grid">
				<input type="hidden" name="<?php echo esc_attr( $prefix ); ?>[enabled]" value="0" />
				<?php $this->checkbox_field( $prefix . '[enabled]', __( 'Enabled', 'elementor-implementation-toolkit' ), ! empty( $filter['enabled'] ) ); ?>
				<label class="eit-field"><span><?php esc_html_e( 'Type', 'elementor-implementation-toolkit' ); ?></span><select id="<?php echo esc_attr( 'eit-filter-type-' . $index ); ?>" name="<?php echo esc_attr( $prefix ); ?>[type]" data-eit-row-type-source><?php foreach ( FilterPresets::filter_types() as $value => $type_name ) : ?><option value="<?php echo esc_attr( $value ); ?>" <?php selected( (string) $type, (string) $value ); ?>><?php echo esc_html( $type_name ); ?></option><?php endforeach; ?></select></label>
				<label class="eit-field"><span><?php esc_html_e( 'Label', 'elementor-implementation-toolkit' ); ?></span><input id="<?php echo esc_attr( 'eit-filter-label-' . $index ); ?>" type="text" name="<?php echo esc_attr( $prefix ); ?>[label]" value="<?php echo esc_attr( $filter['label'] ?? '' ); ?>" placeholder="<?php echo esc_attr__( 'Category', 'elementor-implementation-toolkit' ); ?>" /></label>
				<?php $this->text_field( $prefix . '[field_binding]', __( 'Field binding', 'elementor-implementation-toolkit' ), $filter['field_binding'] ?? '', 'Dynamic tag or field key' ); ?>
				<input type="hidden" name="<?php echo esc_attr( $prefix ); ?>[field_binding_dynamic]" value="<?php echo esc_attr( $filter['field_binding_dynamic'] ?? '' ); ?>" />
				<?php $this->text_field( $prefix . '[key]', __( 'Field or taxonomy key', 'elementor-implementation-toolkit' ), $filter['key'] ?? '', 'category, price, rating' ); ?>
				<input type="hidden" name="<?php echo esc_attr( $prefix ); ?>[resolved_key]" value="<?php echo esc_attr( $filter['resolved_key'] ?? '' ); ?>" />
				<input type="hidden" name="<?php echo esc_attr( $prefix ); ?>[key_source]" value="<?php echo esc_attr( $filter['key_source'] ?? '' ); ?>" />
				<?php $this->text_field( $prefix . '[placeholder]', __( 'Placeholder', 'elementor-implementation-toolkit' ), $filter['placeholder'] ?? '' ); ?>
				<?php $this->number_field( $prefix . '[range_min]', __( 'Range min', 'elementor-implementation-toolkit' ), $filter['range_min'] ?? 0, null, null, 'any' ); ?>
				<?php $this->number_field( $prefix . '[range_max]', __( 'Range max', 'elementor-implementation-toolkit' ), $filter['range_max'] ?? 100, null, null, 'any' ); ?>
				<details class="eit-advanced-panel eit-advanced-panel--inline">
					<?php $this->renderer->render_advanced_toggle( __( 'Advanced settings', 'elementor-implementation-toolkit' ) ); ?>
					<div class="eit-advanced-stack"><div class="eit-form-grid eit-form-grid--four">
						<?php $this->checkbox_field( $prefix . '[show_label]', __( 'Show label', 'elementor-implementation-toolkit' ), ! empty( $filter['show_label'] ) ); ?>
						<?php $this->checkbox_field( $prefix . '[show_count]', __( 'Show counts', 'elementor-implementation-toolkit' ), ! empty( $filter['show_count'] ) ); ?>
						<?php $this->text_field( $prefix . '[query_var]', __( 'URL parameter', 'elementor-implementation-toolkit' ), $filter['query_var'] ?? '' ); ?>
						<?php $this->text_field( $prefix . '[default_value]', __( 'Default value', 'elementor-implementation-toolkit' ), $filter['default_value'] ?? '' ); ?>
						<?php $this->checkbox_field( $prefix . '[radio_show_all]', __( 'Radio all option', 'elementor-implementation-toolkit' ), ! empty( $filter['radio_show_all'] ) ); ?>
						<?php $this->text_field( $prefix . '[radio_all_label]', __( 'Radio all label', 'elementor-implementation-toolkit' ), $filter['radio_all_label'] ?? __( 'All', 'elementor-implementation-toolkit' ) ); ?>
						<?php $this->number_field( $prefix . '[range_step]', __( 'Step', 'elementor-implementation-toolkit' ), $filter['range_step'] ?? 1, null, null, 'any' ); ?>
						<?php $this->number_field( $prefix . '[layout_width]', __( 'Block width (%)', 'elementor-implementation-toolkit' ), $filter['layout_width'] ?? 100, 10, 100, 1 ); ?>
						<?php $this->select_field( $prefix . '[source]', __( 'Source', 'elementor-implementation-toolkit' ), $filter['source'] ?? 'visible_text', FilterPresets::source_types() ); ?>
						<?php $this->select_field( $prefix . '[compare]', __( 'Compare', 'elementor-implementation-toolkit' ), $filter['compare'] ?? 'contains', FilterPresets::compare_types() ); ?>
						<?php $this->select_field( $prefix . '[data_type]', __( 'Data type', 'elementor-implementation-toolkit' ), $filter['data_type'] ?? 'string', FilterPresets::data_types() ); ?>
						<?php $this->textarea_field( $prefix . '[options]', __( 'Options for choices, chips, swatches, or rating', 'elementor-implementation-toolkit' ), $filter['options'] ?? '', 4 ); ?>
					</div></div>
				</details>
			</div>
			<?php $this->renderer->render_modal_close(); ?>
		</td></tr>
		<?php
	}

	private function settings_summary( array $filter ) {
		$parts = [];
		if ( ! empty( $filter['placeholder'] ) ) {
			$parts[] = sprintf( __( 'Placeholder: %s', 'elementor-implementation-toolkit' ), $filter['placeholder'] );
		}
		if ( ! empty( $filter['key'] ) ) {
			$parts[] = sprintf( __( 'Key: %s', 'elementor-implementation-toolkit' ), $filter['key'] );
		}
		if ( ! empty( $filter['field_binding'] ) ) {
			$parts[] = sprintf( __( 'Binding: %s', 'elementor-implementation-toolkit' ), $filter['field_binding'] );
		}
		if ( 'range' === ( $filter['type'] ?? '' ) ) {
			$parts[] = sprintf( __( 'Range: %1$s-%2$s', 'elementor-implementation-toolkit' ), $filter['range_min'] ?? __( 'Auto', 'elementor-implementation-toolkit' ), $filter['range_max'] ?? __( 'Auto', 'elementor-implementation-toolkit' ) );
		}
		return implode( ' - ', array_slice( $parts, 0, 2 ) ) ?: __( 'Default settings', 'elementor-implementation-toolkit' );
	}
}
