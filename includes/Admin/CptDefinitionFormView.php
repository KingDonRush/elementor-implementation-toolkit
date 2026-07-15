<?php
/**
 * Form view for Toolkit custom post type definitions.
 */

namespace EIT\Admin;

use EIT\CPT\CptManager;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CptDefinitionFormView {

	use AdminFormFields;

	private $renderer;

	public function __construct( AdminRenderer $renderer ) {
		$this->renderer = $renderer;
	}

	public function render( array $definition ) {
		$is_existing = ! empty( $definition['slug'] );
		?>
		<form class="eit-admin-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="<?php echo esc_attr( CptManagerAdmin::SAVE_ACTION ); ?>" />
			<input type="hidden" name="definition[original_slug]" value="<?php echo esc_attr( $definition['original_slug'] ?? $definition['slug'] ?? '' ); ?>" />
			<?php wp_nonce_field( CptManagerAdmin::SAVE_ACTION ); ?>

			<div class="eit-layout-grid">
				<section class="eit-panel">
					<div class="eit-panel__header">
						<div>
							<h3><?php esc_html_e( 'Post type setup', 'elementor-implementation-toolkit' ); ?></h3>
							<p><?php esc_html_e( 'Name the content model and keep the slug stable once content exists.', 'elementor-implementation-toolkit' ); ?></p>
						</div>
						<?php if ( $is_existing ) : ?>
							<span class="eit-object-pill"><?php echo esc_html( $definition['slug'] ); ?></span>
						<?php endif; ?>
					</div>
					<div class="eit-panel__body">
						<div class="eit-form-grid">
							<?php $this->text_field( 'definition[singular]', __( 'Singular label', 'elementor-implementation-toolkit' ), $definition['singular'] ?? '', 'Portfolio Item' ); ?>
							<?php $this->text_field( 'definition[plural]', __( 'Plural label', 'elementor-implementation-toolkit' ), $definition['plural'] ?? '', 'Portfolio Items' ); ?>
							<?php $this->text_field( 'definition[slug]', __( 'Slug', 'elementor-implementation-toolkit' ), $definition['slug'] ?? '', 'portfolio_item', [ 'readonly' => ! empty( $definition['original_slug'] ?? $definition['slug'] ?? '' ) ] ); ?>
							<?php $this->text_field( 'definition[menu_icon]', __( 'Admin menu icon', 'elementor-implementation-toolkit' ), $definition['menu_icon'] ?? 'dashicons-screenoptions', 'dashicons-portfolio' ); ?>
							<?php $this->textarea_field( 'definition[description]', __( 'Description', 'elementor-implementation-toolkit' ), $definition['description'] ?? '', 3 ); ?>
						</div>
					</div>
				</section>

				<?php $this->render_elementor_bridge_card( $definition ); ?>
			</div>

			<div class="eit-layout-grid eit-layout-grid--collections">
				<section class="eit-panel">
					<div class="eit-panel__header">
						<div>
							<h3><?php esc_html_e( 'Meta fields', 'elementor-implementation-toolkit' ); ?></h3>
							<p><?php esc_html_e( 'Add as many fields as the post type needs. They render in a native WordPress meta box.', 'elementor-implementation-toolkit' ); ?></p>
						</div>
					</div>
					<?php $this->render_meta_rows( $definition['meta_fields'] ?? [] ); ?>
				</section>

				<section class="eit-panel">
					<div class="eit-panel__header">
						<div>
							<h3><?php esc_html_e( 'Taxonomies', 'elementor-implementation-toolkit' ); ?></h3>
							<p><?php esc_html_e( 'Add categories or tags for this post type when filtering or grouping needs structured terms.', 'elementor-implementation-toolkit' ); ?></p>
						</div>
					</div>
					<?php $this->render_taxonomy_rows( $definition['taxonomies'] ?? [] ); ?>
				</section>
			</div>

			<div class="eit-savebar">
				<div class="eit-form-actions__advanced">
					<?php $this->render_advanced_registration_options( $definition ); ?>
				</div>
				<div class="eit-actions-right">
					<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=' . AdminPages::CPT_SLUG ) ); ?>"><?php esc_html_e( 'Cancel', 'elementor-implementation-toolkit' ); ?></a>
					<button type="submit" class="button button-primary"><?php esc_html_e( 'Save Post Type', 'elementor-implementation-toolkit' ); ?></button>
				</div>
			</div>
		</form>
		<?php
	}

	private function render_elementor_bridge_card( array $definition ) {
		$slug = $definition['slug'] ?? '';
		$content_url = $slug ? admin_url( 'edit.php?post_type=' . rawurlencode( $slug ) ) : admin_url( 'admin.php?page=' . AdminPages::CPT_SLUG );
		?>
		<section class="eit-panel">
			<div class="eit-panel__header"><h3><?php esc_html_e( 'Elementor handoff', 'elementor-implementation-toolkit' ); ?></h3></div>
			<div class="eit-panel__body eit-field-stack">
				<div class="eit-handoff-card">
					<div class="eit-handoff-card__head">
						<span class="dashicons dashicons-admin-page" aria-hidden="true"></span>
						<div>
							<h4><?php esc_html_e( 'Templates can use this data model', 'elementor-implementation-toolkit' ); ?></h4>
							<p><?php esc_html_e( 'Create the data model here, then connect archives, singles, and widgets through Elementor.', 'elementor-implementation-toolkit' ); ?></p>
						</div>
					</div>
					<div class="eit-handoff-actions">
						<a class="button button-primary" href="<?php echo esc_url( $content_url ); ?>"><?php esc_html_e( 'Open content list', 'elementor-implementation-toolkit' ); ?></a>
						<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=' . AdminPages::FILTERS_SLUG . '&view=new' ) ); ?>"><?php esc_html_e( 'Create filter preset', 'elementor-implementation-toolkit' ); ?></a>
					</div>
				</div>
				<div class="eit-muted-strip"><?php esc_html_e( 'WordPress owns the content structure. Elementor owns layout, responsive behavior, and visual styling.', 'elementor-implementation-toolkit' ); ?></div>
			</div>
		</section>
		<?php
	}

	private function render_advanced_registration_options( array $definition ) {
		$modal_id = 'eit-cpt-advanced-registration-modal';
		?>
		<div class="eit-advanced-panel">
			<?php $this->renderer->render_advanced_button( __( 'Advanced post type settings', 'elementor-implementation-toolkit' ), $modal_id ); ?>
			<?php $this->renderer->render_modal_open( $modal_id, __( 'Advanced post type settings', 'elementor-implementation-toolkit' ), 'eit-modal--wide' ); ?>
			<div class="eit-advanced-stack eit-advanced-stack--modal">
				<section>
					<h4><?php esc_html_e( 'WordPress registration', 'elementor-implementation-toolkit' ); ?></h4>
					<div class="eit-form-grid eit-form-grid--four">
						<?php $this->text_field( 'definition[rewrite_slug]', __( 'Rewrite slug', 'elementor-implementation-toolkit' ), $definition['rewrite_slug'] ?? '' ); ?>
						<?php $this->checkbox_field( 'definition[public]', __( 'Public', 'elementor-implementation-toolkit' ), ! empty( $definition['public'] ) ); ?>
						<?php $this->checkbox_field( 'definition[show_in_rest]', __( 'Show in REST', 'elementor-implementation-toolkit' ), ! empty( $definition['show_in_rest'] ) ); ?>
						<?php $this->checkbox_field( 'definition[has_archive]', __( 'Has archive', 'elementor-implementation-toolkit' ), ! empty( $definition['has_archive'] ) ); ?>
						<?php $this->checkbox_field( 'definition[hierarchical]', __( 'Hierarchical', 'elementor-implementation-toolkit' ), ! empty( $definition['hierarchical'] ) ); ?>
					</div>
				</section>
				<section>
					<h4><?php esc_html_e( 'Editor supports', 'elementor-implementation-toolkit' ); ?></h4>
					<p class="description"><?php esc_html_e( 'The WordPress editor is opt-in. Leave it disabled for a structured content model.', 'elementor-implementation-toolkit' ); ?></p>
					<div class="eit-check-grid">
						<?php foreach ( CptManager::supports() as $support => $label ) : ?>
							<?php $this->checkbox_field( 'definition[supports][]', $label, in_array( $support, $definition['supports'] ?? [], true ) ); ?>
						<?php endforeach; ?>
					</div>
				</section>
			</div>
			<?php $this->renderer->render_modal_close(); ?>
		</div>
		<?php
	}

	private function render_taxonomy_rows( array $taxonomies ) {
		$taxonomies = array_values( $taxonomies );
		?>
		<div class="eit-repeater eit-repeater--table" data-eit-repeater data-eit-repeater-next-index="<?php echo esc_attr( count( $taxonomies ) ); ?>">
			<div class="eit-compact-table-wrap">
				<table class="widefat eit-compact-table">
					<thead><tr><th class="column-order"><?php esc_html_e( '#', 'elementor-implementation-toolkit' ); ?></th><th><?php esc_html_e( 'Taxonomy', 'elementor-implementation-toolkit' ); ?></th><th><?php esc_html_e( 'Structure', 'elementor-implementation-toolkit' ); ?></th><th><?php esc_html_e( 'Visibility', 'elementor-implementation-toolkit' ); ?></th><th><?php esc_html_e( 'Actions', 'elementor-implementation-toolkit' ); ?></th></tr></thead>
					<tbody data-eit-repeat-list><?php foreach ( $taxonomies as $index => $taxonomy ) : ?><?php $this->render_taxonomy_row( $taxonomy, (string) $index ); ?><?php endforeach; ?></tbody>
				</table>
			</div>
			<div class="eit-repeat-toolbar"><button type="button" class="button" data-eit-add-row><?php esc_html_e( 'Add taxonomy', 'elementor-implementation-toolkit' ); ?></button></div>
			<template data-eit-row-template><?php $this->render_taxonomy_row( CptManager::blank_taxonomy( [ 'public' => true, 'show_in_rest' => true ] ), '__index__' ); ?></template>
		</div>
		<?php
	}

	private function render_taxonomy_row( array $taxonomy, $index ) {
		$prefix = 'definition[taxonomies][' . $index . ']';
		$original_slug = \EIT\CPT\DefinitionManager::sanitize_taxonomy_slug( $taxonomy['original_slug'] ?? $taxonomy['slug'] ?? '' );
		$label = $taxonomy['plural'] ?: $taxonomy['slug'] ?: __( 'Taxonomy', 'elementor-implementation-toolkit' );
		$structure = ! empty( $taxonomy['hierarchical'] ) ? __( 'Category-like', 'elementor-implementation-toolkit' ) : __( 'Tag-like', 'elementor-implementation-toolkit' );
		$status = ! empty( $taxonomy['public'] ) ? __( 'Public', 'elementor-implementation-toolkit' ) : __( 'Private', 'elementor-implementation-toolkit' );
		?>
		<tr class="eit-repeat-row eit-taxonomy-row">
			<td class="column-order"><span data-eit-row-number><?php echo esc_html( is_numeric( $index ) ? ( (int) $index + 1 ) : 1 ); ?></span></td>
			<td><strong data-eit-row-title data-eit-row-title-source="plural"><?php echo esc_html( $label ); ?></strong><span class="eit-row-sub"><?php echo esc_html( $taxonomy['slug'] ?? '' ); ?></span></td>
			<td><span data-eit-row-type><?php echo esc_html( $structure ); ?></span></td>
			<td><span class="eit-status-pill <?php echo empty( $taxonomy['public'] ) ? 'is-neutral' : ''; ?>"><?php echo esc_html( $status ); ?></span></td>
			<td class="eit-row-actions"><button type="button" class="button" data-eit-open-row><?php esc_html_e( 'Edit', 'elementor-implementation-toolkit' ); ?></button><button type="button" class="button button-link-delete" data-eit-remove-row><?php esc_html_e( 'Delete', 'elementor-implementation-toolkit' ); ?></button></td>
		</tr>
		<tr class="eit-editor-modal-row" hidden><td colspan="5">
			<?php $this->renderer->render_modal_open( 'eit-taxonomy-editor-' . $index, __( 'Edit taxonomy', 'elementor-implementation-toolkit' ), 'eit-modal--wide' ); ?>
			<div class="eit-form-grid eit-form-grid--four">
				<input type="hidden" name="<?php echo esc_attr( $prefix . '[original_slug]' ); ?>" value="<?php echo esc_attr( $original_slug ); ?>">
				<?php $this->text_field( $prefix . '[slug]', __( 'Slug', 'elementor-implementation-toolkit' ), $taxonomy['slug'] ?? '', 'project_type', [ 'readonly' => '' !== $original_slug ] ); ?>
				<?php $this->text_field( $prefix . '[singular]', __( 'Singular name', 'elementor-implementation-toolkit' ), $taxonomy['singular'] ?? '', 'Project Type' ); ?>
				<?php $this->text_field( $prefix . '[plural]', __( 'Plural name', 'elementor-implementation-toolkit' ), $taxonomy['plural'] ?? '', 'Project Types' ); ?>
				<?php $this->checkbox_field( $prefix . '[hierarchical]', __( 'Category-like', 'elementor-implementation-toolkit' ), ! empty( $taxonomy['hierarchical'] ) ); ?>
				<?php $this->checkbox_field( $prefix . '[public]', __( 'Public', 'elementor-implementation-toolkit' ), ! empty( $taxonomy['public'] ) ); ?>
				<?php $this->checkbox_field( $prefix . '[show_in_rest]', __( 'Show in REST', 'elementor-implementation-toolkit' ), ! empty( $taxonomy['show_in_rest'] ) ); ?>
			</div>
			<button type="button" class="button-link-delete" data-eit-remove-row><?php esc_html_e( 'Remove taxonomy', 'elementor-implementation-toolkit' ); ?></button>
			<?php $this->renderer->render_modal_close(); ?>
		</td></tr>
		<?php
	}

	private function render_meta_rows( array $fields ) {
		$fields = array_values( $fields );
		if ( empty( $fields ) ) {
			$fields[] = CptManager::blank_meta_field( [ 'show_in_rest' => true ] );
		}
		?>
		<div class="eit-repeater eit-repeater--table" data-eit-repeater data-eit-repeater-next-index="<?php echo esc_attr( count( $fields ) ); ?>">
			<div class="eit-compact-table-wrap">
				<table class="widefat eit-compact-table">
					<thead><tr><th class="column-order"><?php esc_html_e( '#', 'elementor-implementation-toolkit' ); ?></th><th><?php esc_html_e( 'Field', 'elementor-implementation-toolkit' ); ?></th><th><?php esc_html_e( 'Key', 'elementor-implementation-toolkit' ); ?></th><th><?php esc_html_e( 'Type', 'elementor-implementation-toolkit' ); ?></th><th><?php esc_html_e( 'Required', 'elementor-implementation-toolkit' ); ?></th><th><?php esc_html_e( 'Actions', 'elementor-implementation-toolkit' ); ?></th></tr></thead>
					<tbody data-eit-repeat-list><?php foreach ( $fields as $index => $field ) : ?><?php $this->render_meta_row( $field, (string) $index ); ?><?php endforeach; ?></tbody>
				</table>
			</div>
			<div class="eit-repeat-toolbar"><button type="button" class="button button-primary" data-eit-add-row><?php esc_html_e( 'Add meta field', 'elementor-implementation-toolkit' ); ?></button></div>
			<template data-eit-row-template><?php $this->render_meta_row( CptManager::blank_meta_field( [ 'show_in_rest' => true ] ), '__index__' ); ?></template>
		</div>
		<?php
	}

	private function render_meta_row( array $field, $index ) {
		$prefix = 'definition[meta_fields][' . $index . ']';
		$original_key = sanitize_key( $field['original_key'] ?? $field['key'] ?? '' );
		$label = $field['label'] ?: $field['key'] ?: __( 'Field', 'elementor-implementation-toolkit' );
		$type = $field['type'] ?? 'text';
		$type_label = CptManager::meta_field_types()[ $type ] ?? $type;
		?>
		<tr class="eit-repeat-row eit-meta-row">
			<td class="column-order"><span data-eit-row-number><?php echo esc_html( is_numeric( $index ) ? ( (int) $index + 1 ) : 1 ); ?></span></td>
			<td><strong data-eit-row-title data-eit-row-title-source="label"><?php echo esc_html( $label ); ?></strong></td>
			<td><span data-eit-row-label data-eit-row-label-source="key"><?php echo esc_html( $field['key'] ?? '' ); ?></span></td>
			<td><span data-eit-row-type><?php echo esc_html( $type_label ); ?></span></td>
			<td><span class="eit-status-pill <?php echo empty( $field['required'] ) ? 'is-neutral' : ''; ?>"><?php echo ! empty( $field['required'] ) ? esc_html__( 'Required', 'elementor-implementation-toolkit' ) : esc_html__( 'Optional', 'elementor-implementation-toolkit' ); ?></span></td>
			<td class="eit-row-actions"><button type="button" class="button" data-eit-open-row><?php esc_html_e( 'Edit', 'elementor-implementation-toolkit' ); ?></button><button type="button" class="button button-link-delete" data-eit-remove-row><?php esc_html_e( 'Delete', 'elementor-implementation-toolkit' ); ?></button></td>
		</tr>
		<tr class="eit-editor-modal-row" hidden><td colspan="6">
			<?php $this->renderer->render_modal_open( 'eit-meta-editor-' . $index, __( 'Edit meta field', 'elementor-implementation-toolkit' ), 'eit-modal--wide' ); ?>
			<div class="eit-form-grid eit-form-grid--four">
				<?php $this->text_field( $prefix . '[label]', __( 'Label', 'elementor-implementation-toolkit' ), $field['label'] ?? '', 'Price' ); ?>
				<input type="hidden" name="<?php echo esc_attr( $prefix . '[original_key]' ); ?>" value="<?php echo esc_attr( $original_key ); ?>">
				<?php $this->text_field( $prefix . '[key]', __( 'Field key', 'elementor-implementation-toolkit' ), $field['key'] ?? '', 'price', [ 'readonly' => '' !== $original_key ] ); ?>
				<label class="eit-field"><span><?php esc_html_e( 'Type', 'elementor-implementation-toolkit' ); ?></span><?php if ( '' !== $original_key ) : ?><input type="hidden" name="<?php echo esc_attr( $prefix ); ?>[type]" value="<?php echo esc_attr( $type ); ?>"><?php endif; ?><select name="<?php echo esc_attr( $prefix ); ?>[type]" data-eit-row-type-source <?php disabled( '' !== $original_key ); ?>><?php foreach ( CptManager::meta_field_types() as $option_value => $option_label ) : ?><option value="<?php echo esc_attr( $option_value ); ?>" <?php selected( (string) $type, (string) $option_value ); ?>><?php echo esc_html( $option_label ); ?></option><?php endforeach; ?></select></label>
				<?php $this->text_field( $prefix . '[default]', __( 'Default', 'elementor-implementation-toolkit' ), $field['default'] ?? '' ); ?>
				<?php $this->checkbox_field( $prefix . '[required]', __( 'Required', 'elementor-implementation-toolkit' ), ! empty( $field['required'] ) ); ?>
			</div>
			<details class="eit-advanced-panel eit-advanced-panel--inline">
				<?php $this->renderer->render_advanced_toggle( __( 'Advanced field settings', 'elementor-implementation-toolkit' ) ); ?>
				<div class="eit-advanced-stack"><div class="eit-form-grid">
					<?php $this->checkbox_field( $prefix . '[show_in_rest]', __( 'Show in REST', 'elementor-implementation-toolkit' ), ! empty( $field['show_in_rest'] ) ); ?>
					<?php $this->textarea_field( $prefix . '[options]', __( 'Options for select or radio', 'elementor-implementation-toolkit' ), $field['options'] ?? '', 4 ); ?>
				</div></div>
			</details>
			<button type="button" class="button-link-delete" data-eit-remove-row><?php esc_html_e( 'Remove field', 'elementor-implementation-toolkit' ); ?></button>
			<?php $this->renderer->render_modal_close(); ?>
		</td></tr>
		<?php
	}
}
