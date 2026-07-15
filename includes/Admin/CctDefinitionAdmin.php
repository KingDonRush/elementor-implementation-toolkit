<?php
/**
 * WordPress-native administration for CCT definitions.
 */

namespace EIT\Admin;

use EIT\CCT\DefinitionManager;
use EIT\CCT\FieldTypes;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CctDefinitionAdmin {

	const SAVE_ACTION = 'eit_save_cct_definition';
	const ARCHIVE_ACTION = 'eit_archive_cct_definition';
	const RESTORE_ACTION = 'eit_restore_cct_definition';
	const DELETE_ACTION = 'eit_delete_cct_definition_permanently';

	use AdminFormFields;

	private $renderer;

	public function __construct( AdminRenderer $renderer ) {
		$this->renderer = $renderer;
	}

	public function render( $active_slug, array $tabs ) {
		$requested_slug = isset( $_GET['cct'] ) ? sanitize_key( wp_unslash( $_GET['cct'] ) ) : '';
		$slug = DefinitionManager::sanitize_slug( $requested_slug );
		$is_form = 'new' === sanitize_key( wp_unslash( $_GET['view'] ?? '' ) ) || '' !== $slug;
		$definition = $slug ? DefinitionManager::get( $slug ) : null;
		$form_state = $is_form ? AdminFormState::pull( 'cct-definition' ) : null;

		if ( $is_form && ! $definition ) {
			$definition = DefinitionManager::blank();
		}
		if ( $form_state ) {
			$definition = $this->form_definition( $form_state['values'] ?? [], $definition ?: DefinitionManager::blank() );
		}

		$this->renderer->render_shell(
			$active_slug,
			$tabs,
			[
				'title'   => $is_form ? __( 'Edit Content Type', 'elementor-implementation-toolkit' ) : __( 'Content Types', 'elementor-implementation-toolkit' ),
				'actions' => [
					[
						'label' => __( 'Add New', 'elementor-implementation-toolkit' ),
						'url'   => admin_url( 'admin.php?page=' . AdminPages::CCT_SLUG . '&view=new' ),
					],
				],
			],
			function () use ( $is_form, $definition, $form_state ) {
				$this->renderer->render_notice( sanitize_key( wp_unslash( $_GET['eit_notice'] ?? '' ) ) );
				$this->renderer->render_form_error( $form_state['error'] ?? '' );
				if ( $is_form ) {
					$this->render_form( $definition );
				} else {
					$this->render_list();
				}
			}
		);
	}

	public function handle_save() {
		$this->assert_can_manage();
		check_admin_referer( self::SAVE_ACTION );

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- DefinitionManager sanitizes the nested contract field by field.
		$raw = isset( $_POST['definition'] ) && is_array( $_POST['definition'] ) ? wp_unslash( $_POST['definition'] ) : [];
		$slug = DefinitionManager::save( $raw );
		if ( is_wp_error( $slug ) ) {
			AdminFormState::store( 'cct-definition', $raw, $slug->get_error_message() );
			$original_slug = DefinitionManager::sanitize_slug( $raw['original_slug'] ?? '' );
			$this->redirect(
				$original_slug
					? [ 'page' => AdminPages::CCT_SLUG, 'cct' => $original_slug, 'eit_notice' => 'error' ]
					: [ 'page' => AdminPages::CCT_SLUG, 'view' => 'new', 'eit_notice' => 'error' ]
			);
		}
		$this->redirect( [ 'page' => AdminPages::CCT_SLUG, 'cct' => $slug, 'eit_notice' => 'saved' ] );
	}

	public function handle_archive() {
		$this->handle_state_action( self::ARCHIVE_ACTION, [ DefinitionManager::class, 'archive' ], 'archived' );
	}

	public function handle_restore() {
		$this->handle_state_action( self::RESTORE_ACTION, [ DefinitionManager::class, 'restore' ], 'restored' );
	}

	public function handle_delete() {
		$this->assert_can_manage();
		$requested_slug = isset( $_GET['cct'] ) ? sanitize_key( wp_unslash( $_GET['cct'] ) ) : '';
		$slug = DefinitionManager::sanitize_slug( $requested_slug );
		check_admin_referer( self::DELETE_ACTION . '_' . $slug );
		$definition = DefinitionManager::get( $slug );

		if ( ! $definition || 'archived' !== ( $definition['state'] ?? 'active' ) ) {
			$this->redirect( [ 'page' => AdminPages::CCT_SLUG, 'eit_notice' => 'error' ] );
		}

		$result = DefinitionManager::delete_permanently( $slug );
		$this->redirect( [ 'page' => AdminPages::CCT_SLUG, 'eit_notice' => is_wp_error( $result ) ? 'error' : 'deleted' ] );
	}

	private function handle_state_action( $action, $callback, $notice ) {
		$this->assert_can_manage();
		$requested_slug = isset( $_GET['cct'] ) ? sanitize_key( wp_unslash( $_GET['cct'] ) ) : '';
		$slug = DefinitionManager::sanitize_slug( $requested_slug );
		check_admin_referer( $action . '_' . $slug );
		$result = call_user_func( $callback, $slug );
		$this->redirect( [ 'page' => AdminPages::CCT_SLUG, 'eit_notice' => is_wp_error( $result ) || ! $result ? 'error' : $notice ] );
	}

	private function render_list() {
		$definitions = DefinitionManager::all();
		$search = sanitize_text_field( wp_unslash( $_GET['s'] ?? '' ) );

		if ( '' !== $search ) {
			$definitions = array_filter(
				$definitions,
				function ( $definition ) use ( $search ) {
					$haystack = implode( ' ', [ $definition['slug'], $definition['singular'], $definition['plural'], $definition['description'] ] );
					return false !== stripos( $haystack, $search );
				}
			);
		}
		?>
		<div class="eit-panel eit-panel--table">
			<div class="eit-panel__header">
				<div>
					<h3><?php esc_html_e( 'Custom Content Types', 'elementor-implementation-toolkit' ); ?></h3>
					<p><?php esc_html_e( 'Structured content stored in dedicated tables and exposed to Elementor listings.', 'elementor-implementation-toolkit' ); ?></p>
				</div>
				<a class="button button-primary" href="<?php echo esc_url( admin_url( 'admin.php?page=' . AdminPages::CCT_SLUG . '&view=new' ) ); ?>"><?php esc_html_e( 'Add New', 'elementor-implementation-toolkit' ); ?></a>
			</div>
			<div class="eit-table-tools">
				<span class="description"><?php echo esc_html( sprintf( _n( '%d content type', '%d content types', count( $definitions ), 'elementor-implementation-toolkit' ), count( $definitions ) ) ); ?></span>
				<form class="eit-search-box" method="get">
					<input type="hidden" name="page" value="<?php echo esc_attr( AdminPages::CCT_SLUG ); ?>">
					<input type="search" name="s" value="<?php echo esc_attr( $search ); ?>">
					<button class="button"><?php esc_html_e( 'Search Content Types', 'elementor-implementation-toolkit' ); ?></button>
				</form>
			</div>
			<?php if ( empty( $definitions ) ) : ?>
				<?php $this->renderer->render_empty_state( __( 'No content types yet', 'elementor-implementation-toolkit' ), __( 'Create a table-backed content model for listings that do not need WordPress singles.', 'elementor-implementation-toolkit' ), admin_url( 'admin.php?page=' . AdminPages::CCT_SLUG . '&view=new' ), __( 'Create content type', 'elementor-implementation-toolkit' ) ); ?>
			<?php else : ?>
				<table class="widefat striped eit-admin-table">
					<thead><tr><th><?php esc_html_e( 'Content type', 'elementor-implementation-toolkit' ); ?></th><th><?php esc_html_e( 'Fields', 'elementor-implementation-toolkit' ); ?></th><th><?php esc_html_e( 'Storage', 'elementor-implementation-toolkit' ); ?></th><th><?php esc_html_e( 'State', 'elementor-implementation-toolkit' ); ?></th><th><?php esc_html_e( 'Actions', 'elementor-implementation-toolkit' ); ?></th></tr></thead>
					<tbody>
					<?php foreach ( $definitions as $slug => $definition ) : ?>
						<?php $archived = 'archived' === ( $definition['state'] ?? 'active' ); ?>
						<tr>
							<td><a class="eit-row-title" href="<?php echo esc_url( admin_url( 'admin.php?page=' . AdminPages::CCT_SLUG . '&cct=' . rawurlencode( $slug ) ) ); ?>"><?php echo esc_html( $definition['plural'] ?: $slug ); ?></a><span class="eit-row-sub"><?php echo esc_html( $slug ); ?></span></td>
							<td><?php echo esc_html( count( $definition['fields'] ?? [] ) ); ?></td>
							<td><code><?php echo esc_html( $GLOBALS['wpdb']->prefix . 'eit_cct_' . $slug ); ?></code></td>
							<td><span class="eit-status-pill <?php echo $archived ? 'is-neutral' : ''; ?>"><?php echo $archived ? esc_html__( 'Archived', 'elementor-implementation-toolkit' ) : esc_html__( 'Active', 'elementor-implementation-toolkit' ); ?></span></td>
							<td class="eit-row-actions">
								<a class="eit-mini-button" href="<?php echo esc_url( admin_url( 'admin.php?page=' . AdminPages::CCT_SLUG . '&cct=' . rawurlencode( $slug ) ) ); ?>"><?php esc_html_e( 'Edit', 'elementor-implementation-toolkit' ); ?></a>
								<?php if ( $archived ) : ?>
									<a class="eit-mini-button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=' . self::RESTORE_ACTION . '&cct=' . rawurlencode( $slug ) ), self::RESTORE_ACTION . '_' . $slug ) ); ?>"><?php esc_html_e( 'Restore', 'elementor-implementation-toolkit' ); ?></a>
									<a class="eit-mini-button is-danger" data-eit-confirm="<?php echo esc_attr__( 'Permanently delete this definition, table, and all rows?', 'elementor-implementation-toolkit' ); ?>" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=' . self::DELETE_ACTION . '&cct=' . rawurlencode( $slug ) ), self::DELETE_ACTION . '_' . $slug ) ); ?>"><?php esc_html_e( 'Delete permanently', 'elementor-implementation-toolkit' ); ?></a>
								<?php else : ?>
									<a class="eit-mini-button is-danger" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=' . self::ARCHIVE_ACTION . '&cct=' . rawurlencode( $slug ) ), self::ARCHIVE_ACTION . '_' . $slug ) ); ?>"><?php esc_html_e( 'Archive', 'elementor-implementation-toolkit' ); ?></a>
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>
		<?php
	}

	private function render_form( array $definition ) {
		$fields = array_values(
			array_filter(
				$definition['fields'] ?? [],
				function ( $field ) {
					return ! empty( $field['active'] );
				}
			)
		);
		?>
		<form class="eit-admin-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="<?php echo esc_attr( self::SAVE_ACTION ); ?>">
			<input type="hidden" name="definition[original_slug]" value="<?php echo esc_attr( $definition['slug'] ?? '' ); ?>">
			<?php wp_nonce_field( self::SAVE_ACTION ); ?>
			<div class="eit-layout-grid">
				<section class="eit-panel">
					<div class="eit-panel__header"><div><h3><?php esc_html_e( 'Content type setup', 'elementor-implementation-toolkit' ); ?></h3><p><?php esc_html_e( 'The slug becomes the permanent table identifier after the first save.', 'elementor-implementation-toolkit' ); ?></p></div></div>
					<div class="eit-panel__body"><div class="eit-form-grid">
						<?php $this->text_field( 'definition[singular]', __( 'Singular label', 'elementor-implementation-toolkit' ), $definition['singular'] ?? '', 'Project' ); ?>
						<?php $this->text_field( 'definition[plural]', __( 'Plural label', 'elementor-implementation-toolkit' ), $definition['plural'] ?? '', 'Projects' ); ?>
						<?php $this->text_field( 'definition[slug]', __( 'Slug', 'elementor-implementation-toolkit' ), $definition['slug'] ?? '', 'projects', [ 'readonly' => ! empty( $definition['original_slug'] ?? $definition['slug'] ?? '' ) ] ); ?>
						<?php $this->text_field( 'definition[menu_icon]', __( 'Menu icon', 'elementor-implementation-toolkit' ), $definition['menu_icon'] ?? 'dashicons-database', 'dashicons-portfolio' ); ?>
						<?php $this->textarea_field( 'definition[description]', __( 'Description', 'elementor-implementation-toolkit' ), $definition['description'] ?? '', 3 ); ?>
						<?php $this->checkbox_field( 'definition[public]', __( 'Publicly queryable', 'elementor-implementation-toolkit' ), ! empty( $definition['public'] ) ); ?>
					</div></div>
				</section>
				<section class="eit-panel">
					<div class="eit-panel__header"><div><h3><?php esc_html_e( 'Elementor contract', 'elementor-implementation-toolkit' ); ?></h3><p><?php esc_html_e( 'Loop widgets select this type as a source. Dynamic Tags read the current row.', 'elementor-implementation-toolkit' ); ?></p></div></div>
					<div class="eit-panel__body eit-field-stack">
						<div class="eit-muted-strip"><?php esc_html_e( 'No permalink or single template is created.', 'elementor-implementation-toolkit' ); ?></div>
						<div class="eit-muted-strip"><?php esc_html_e( 'Archiving keeps the table and every stored row.', 'elementor-implementation-toolkit' ); ?></div>
					</div>
				</section>
			</div>
			<section class="eit-panel" data-eit-repeater data-eit-repeater-next-index="<?php echo esc_attr( count( $fields ) ); ?>">
				<div class="eit-panel__header"><div><h3><?php esc_html_e( 'Fields', 'elementor-implementation-toolkit' ); ?></h3><p><?php esc_html_e( 'Removed fields stay as inactive database columns. Mark fields filterable only when listings need them.', 'elementor-implementation-toolkit' ); ?></p></div><button type="button" class="button" data-eit-add-row><?php esc_html_e( 'Add field', 'elementor-implementation-toolkit' ); ?></button></div>
				<div class="eit-panel__body" data-eit-repeat-list>
					<?php foreach ( $fields as $index => $field ) : ?>
						<?php $this->render_field_row( $index, $field ); ?>
					<?php endforeach; ?>
				</div>
				<template data-eit-row-template><?php $this->render_field_row( '__index__', DefinitionManager::blank_field() ); ?></template>
			</section>
			<div class="eit-savebar"><a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=' . AdminPages::CCT_SLUG ) ); ?>"><?php esc_html_e( 'Cancel', 'elementor-implementation-toolkit' ); ?></a><button class="button button-primary"><?php esc_html_e( 'Save Content Type', 'elementor-implementation-toolkit' ); ?></button></div>
		</form>
		<?php
	}

	private function render_field_row( $index, array $field ) {
		$base = 'definition[fields][' . $index . ']';
		$original_key = DefinitionManager::sanitize_field_key( $field['original_key'] ?? $field['key'] ?? '' );
		?>
		<div class="eit-repeat-row eit-cct-field-row">
			<div class="eit-repeat-row__summary"><span class="eit-row-index" data-eit-row-number></span><strong data-eit-row-title data-eit-row-title-source="label"><?php echo esc_html( $field['label'] ?: __( 'New field', 'elementor-implementation-toolkit' ) ); ?></strong><small data-eit-row-type><?php echo esc_html( FieldTypes::labels()[ $field['type'] ] ?? $field['type'] ); ?></small><button type="button" class="button-link-delete" data-eit-remove-row><?php esc_html_e( 'Remove', 'elementor-implementation-toolkit' ); ?></button></div>
			<div class="eit-form-grid eit-repeat-row__fields">
				<?php $this->text_field( $base . '[label]', __( 'Label', 'elementor-implementation-toolkit' ), $field['label'] ?? '', 'Project summary' ); ?>
				<input type="hidden" name="<?php echo esc_attr( $base . '[original_key]' ); ?>" value="<?php echo esc_attr( $original_key ); ?>">
				<?php $this->text_field( $base . '[key]', __( 'Key', 'elementor-implementation-toolkit' ), $field['key'] ?? '', 'summary', [ 'readonly' => '' !== $original_key ] ); ?>
				<?php if ( '' !== $original_key ) : ?><input type="hidden" name="<?php echo esc_attr( $base . '[type]' ); ?>" value="<?php echo esc_attr( $field['type'] ?? 'text' ); ?>"><?php endif; ?>
				<?php $this->select_field( $base . '[type]', __( 'Type', 'elementor-implementation-toolkit' ), $field['type'] ?? 'text', FieldTypes::labels(), [ 'disabled' => '' !== $original_key ] ); ?>
				<?php $this->text_field( $base . '[default]', __( 'Default', 'elementor-implementation-toolkit' ), is_array( $field['default'] ?? '' ) ? implode( ',', $field['default'] ) : ( $field['default'] ?? '' ) ); ?>
				<?php $this->textarea_field( $base . '[options]', __( 'Options (value | Label)', 'elementor-implementation-toolkit' ), $field['options'] ?? '', 3 ); ?>
				<?php $this->checkbox_field( $base . '[required]', __( 'Required', 'elementor-implementation-toolkit' ), ! empty( $field['required'] ) ); ?>
				<?php $this->checkbox_field( $base . '[filterable]', __( 'Filterable and searchable', 'elementor-implementation-toolkit' ), ! empty( $field['filterable'] ) ); ?>
				<input type="hidden" name="<?php echo esc_attr( $base . '[active]' ); ?>" value="1">
			</div>
		</div>
		<?php
	}

	private function assert_can_manage() {
		if ( ! current_user_can( AdminPages::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to manage content types.', 'elementor-implementation-toolkit' ) );
		}
	}

	private function form_definition( array $values, array $fallback ) {
		$definition = array_merge( DefinitionManager::blank(), $fallback, $values );
		$definition['original_slug'] = DefinitionManager::sanitize_slug( $values['original_slug'] ?? $fallback['slug'] ?? '' );
		$definition['fields'] = array_map(
			function ( $field ) {
				return array_merge( DefinitionManager::blank_field(), is_array( $field ) ? $field : [] );
			},
			(array) ( $values['fields'] ?? $fallback['fields'] ?? [] )
		);

		return $definition;
	}

	private function redirect( array $args ) {
		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
		exit;
	}
}
