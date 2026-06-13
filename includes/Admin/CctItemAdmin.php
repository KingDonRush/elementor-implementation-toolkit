<?php
/**
 * Operational admin menus and CRUD screens for CCT rows.
 */

namespace EIT\Admin;

use EIT\CCT\DefinitionManager;
use EIT\CCT\FieldTypes;
use EIT\CCT\Repository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CctItemAdmin {

	const SAVE_ACTION = 'eit_save_cct_item';
	const DELETE_ACTION = 'eit_delete_cct_item';
	const PAGE_PREFIX = 'eit-cct-items-';

	private $repository;

	public function __construct() {
		$this->repository = new Repository();
	}

	public function register_menus() {
		$position = 59;
		foreach ( DefinitionManager::all( false ) as $slug => $definition ) {
			$page_slug = self::PAGE_PREFIX . $slug;
			add_menu_page(
				$definition['plural'] ?: ucfirst( $slug ),
				$definition['plural'] ?: ucfirst( $slug ),
				AdminPages::CAPABILITY,
				$page_slug,
				function () use ( $slug ) {
					$this->render( $slug );
				},
				$definition['menu_icon'] ?: 'dashicons-database',
				$position++
			);
		}
	}

	public function handle_save() {
		$this->assert_can_manage();
		check_admin_referer( self::SAVE_ACTION );
		$type = DefinitionManager::sanitize_slug( wp_unslash( $_POST['cct'] ?? '' ) );
		$id = absint( $_POST['item_id'] ?? 0 );
		$values = isset( $_POST['item'] ) && is_array( $_POST['item'] ) ? wp_unslash( $_POST['item'] ) : [];
		$definition = DefinitionManager::get( $type, false );

		if ( ! $definition || ! $this->required_fields_present( $type, $values ) ) {
			$this->redirect( $type, [ 'view' => $id ? 'edit' : 'new', 'item_id' => $id, 'eit_notice' => 'error' ] );
		}

		$saved = $this->repository->save( $type, $values, $id );
		if ( is_wp_error( $saved ) ) {
			$this->redirect( $type, [ 'view' => $id ? 'edit' : 'new', 'item_id' => $id, 'eit_notice' => 'error' ] );
		}

		$this->redirect( $type, [ 'view' => 'edit', 'item_id' => $saved, 'eit_notice' => 'saved' ] );
	}

	public function handle_delete() {
		$this->assert_can_manage();
		$type = DefinitionManager::sanitize_slug( wp_unslash( $_REQUEST['cct'] ?? '' ) );
		$id = absint( $_REQUEST['item_id'] ?? 0 );
		check_admin_referer( self::DELETE_ACTION . '_' . $type . '_' . $id );
		$this->repository->delete( $type, $id );
		$this->redirect( $type, [ 'eit_notice' => 'deleted' ] );
	}

	private function render( $type ) {
		$definition = DefinitionManager::get( $type, false );
		if ( ! $definition ) {
			wp_die( esc_html__( 'Content type not found.', 'elementor-implementation-toolkit' ) );
		}

		$view = sanitize_key( wp_unslash( $_GET['view'] ?? '' ) );
		$id = absint( $_GET['item_id'] ?? 0 );
		$item = $id ? $this->repository->get( $type, $id ) : null;
		$is_form = in_array( $view, [ 'new', 'edit' ], true );
		?>
		<div class="wrap eit-admin">
			<div class="eit-title-row">
				<h1><?php echo esc_html( $is_form ? sprintf( __( 'Edit %s', 'elementor-implementation-toolkit' ), $definition['singular'] ) : $definition['plural'] ); ?></h1>
				<a class="page-title-action" href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::PAGE_PREFIX . $type . '&view=new' ) ); ?>"><?php esc_html_e( 'Add New', 'elementor-implementation-toolkit' ); ?></a>
			</div>
			<div class="eit-native-content">
				<?php ( new AdminRenderer() )->render_notice( sanitize_key( wp_unslash( $_GET['eit_notice'] ?? '' ) ) ); ?>
				<?php $is_form ? $this->render_form( $type, $definition, $item ) : $this->render_list( $type, $definition ); ?>
			</div>
		</div>
		<?php
	}

	private function render_list( $type, array $definition ) {
		$status = sanitize_key( wp_unslash( $_GET['status'] ?? '' ) );
		$page = max( 1, absint( $_GET['paged'] ?? 1 ) );
		$search = sanitize_text_field( wp_unslash( $_GET['s'] ?? '' ) );
		$result = $this->repository->query(
			$type,
			[
				'status'   => $status ? [ $status ] : [ 'publish', 'draft', 'archived' ],
				'search'   => $search,
				'page'     => $page,
				'per_page' => 20,
				'orderby'  => sanitize_key( wp_unslash( $_GET['orderby'] ?? 'menu_order' ) ),
				'order'    => sanitize_key( wp_unslash( $_GET['order'] ?? 'asc' ) ),
			]
		);
		?>
		<div class="eit-panel eit-panel--table">
			<div class="eit-table-tools">
				<div class="eit-view-links">
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::PAGE_PREFIX . $type ) ); ?>"><?php esc_html_e( 'All', 'elementor-implementation-toolkit' ); ?></a>
					<?php foreach ( [ 'publish' => __( 'Published', 'elementor-implementation-toolkit' ), 'draft' => __( 'Drafts', 'elementor-implementation-toolkit' ), 'archived' => __( 'Archived', 'elementor-implementation-toolkit' ) ] as $key => $label ) : ?>
						<span> | </span><a href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::PAGE_PREFIX . $type . '&status=' . $key ) ); ?>"><?php echo esc_html( $label ); ?></a>
					<?php endforeach; ?>
				</div>
				<form class="eit-search-box" method="get"><input type="hidden" name="page" value="<?php echo esc_attr( self::PAGE_PREFIX . $type ); ?>"><input type="search" name="s" value="<?php echo esc_attr( $search ); ?>"><button class="button"><?php esc_html_e( 'Search', 'elementor-implementation-toolkit' ); ?></button></form>
			</div>
			<?php if ( empty( $result['items'] ) ) : ?>
				<?php ( new AdminRenderer() )->render_empty_state( __( 'No items found', 'elementor-implementation-toolkit' ), __( 'Create the first structured item for this content type.', 'elementor-implementation-toolkit' ), admin_url( 'admin.php?page=' . self::PAGE_PREFIX . $type . '&view=new' ), __( 'Add item', 'elementor-implementation-toolkit' ) ); ?>
			<?php else : ?>
				<table class="widefat striped eit-admin-table">
					<thead><tr><th><?php esc_html_e( 'Title', 'elementor-implementation-toolkit' ); ?></th><th><?php esc_html_e( 'Status', 'elementor-implementation-toolkit' ); ?></th><th><?php esc_html_e( 'Order', 'elementor-implementation-toolkit' ); ?></th><th><?php esc_html_e( 'Updated', 'elementor-implementation-toolkit' ); ?></th><th><?php esc_html_e( 'Actions', 'elementor-implementation-toolkit' ); ?></th></tr></thead>
					<tbody><?php foreach ( $result['items'] as $item ) : ?>
						<tr>
							<td><a class="eit-row-title" href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::PAGE_PREFIX . $type . '&view=edit&item_id=' . $item['id'] ) ); ?>"><?php echo esc_html( $item['title'] ); ?></a><span class="eit-row-sub">#<?php echo esc_html( $item['id'] ); ?></span></td>
							<td><span class="eit-status-pill <?php echo 'publish' === $item['status'] ? '' : 'is-neutral'; ?>"><?php echo esc_html( ucfirst( $item['status'] ) ); ?></span></td>
							<td><?php echo esc_html( $item['menu_order'] ); ?></td>
							<td><?php echo esc_html( $item['updated_at'] ); ?></td>
							<td class="eit-row-actions"><a class="eit-mini-button" href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::PAGE_PREFIX . $type . '&view=edit&item_id=' . $item['id'] ) ); ?>"><?php esc_html_e( 'Edit', 'elementor-implementation-toolkit' ); ?></a><a class="eit-mini-button is-danger" data-eit-confirm="<?php echo esc_attr__( 'Permanently delete this item?', 'elementor-implementation-toolkit' ); ?>" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=' . self::DELETE_ACTION . '&cct=' . $type . '&item_id=' . $item['id'] ), self::DELETE_ACTION . '_' . $type . '_' . $item['id'] ) ); ?>"><?php esc_html_e( 'Delete', 'elementor-implementation-toolkit' ); ?></a></td>
						</tr>
					<?php endforeach; ?></tbody>
				</table>
				<?php $this->render_pagination( $type, $result, $status, $search ); ?>
			<?php endif; ?>
		</div>
		<?php
	}

	private function render_form( $type, array $definition, $item ) {
		$item = is_array( $item ) ? $item : [ 'title' => '', 'status' => 'publish', 'menu_order' => 0 ];
		?>
		<form class="eit-admin-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="<?php echo esc_attr( self::SAVE_ACTION ); ?>"><input type="hidden" name="cct" value="<?php echo esc_attr( $type ); ?>"><input type="hidden" name="item_id" value="<?php echo esc_attr( $item['id'] ?? 0 ); ?>">
			<?php wp_nonce_field( self::SAVE_ACTION ); ?>
			<div class="eit-layout-grid">
				<section class="eit-panel"><div class="eit-panel__header"><h3><?php echo esc_html( $definition['singular'] ); ?></h3></div><div class="eit-panel__body"><div class="eit-form-grid">
					<label class="eit-field eit-field--wide"><span><?php esc_html_e( 'Title', 'elementor-implementation-toolkit' ); ?></span><input type="text" required name="item[title]" value="<?php echo esc_attr( $item['title'] ); ?>"></label>
					<label class="eit-field"><span><?php esc_html_e( 'Status', 'elementor-implementation-toolkit' ); ?></span><select name="item[status]"><?php foreach ( [ 'publish' => __( 'Published', 'elementor-implementation-toolkit' ), 'draft' => __( 'Draft', 'elementor-implementation-toolkit' ), 'archived' => __( 'Archived', 'elementor-implementation-toolkit' ) ] as $value => $label ) : ?><option value="<?php echo esc_attr( $value ); ?>" <?php selected( $item['status'], $value ); ?>><?php echo esc_html( $label ); ?></option><?php endforeach; ?></select></label>
					<label class="eit-field"><span><?php esc_html_e( 'Manual order', 'elementor-implementation-toolkit' ); ?></span><input type="number" name="item[menu_order]" value="<?php echo esc_attr( $item['menu_order'] ); ?>"></label>
				</div></div></section>
				<section class="eit-panel"><div class="eit-panel__header"><h3><?php esc_html_e( 'Storage', 'elementor-implementation-toolkit' ); ?></h3></div><div class="eit-panel__body"><div class="eit-muted-strip"><?php echo esc_html( sprintf( __( 'Stored in %s without generating a WordPress single.', 'elementor-implementation-toolkit' ), $GLOBALS['wpdb']->prefix . 'eit_cct_' . $type ) ); ?></div></div></section>
			</div>
			<section class="eit-panel"><div class="eit-panel__header"><h3><?php esc_html_e( 'Fields', 'elementor-implementation-toolkit' ); ?></h3></div><div class="eit-panel__body"><div class="eit-form-grid">
				<?php foreach ( DefinitionManager::fields( $type ) as $key => $field ) : ?>
					<?php $this->render_item_field( $key, $field, $item[ $key ] ?? $field['default'] ?? '' ); ?>
				<?php endforeach; ?>
			</div></div></section>
			<div class="eit-savebar"><a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::PAGE_PREFIX . $type ) ); ?>"><?php esc_html_e( 'Cancel', 'elementor-implementation-toolkit' ); ?></a><button class="button button-primary"><?php esc_html_e( 'Save Item', 'elementor-implementation-toolkit' ); ?></button></div>
		</form>
		<?php
	}

	private function render_item_field( $key, array $field, $value ) {
		$name = 'item[' . $key . ']';
		$required = ! empty( $field['required'] ) ? ' required' : '';
		$label = $field['label'] ?: $key;

		if ( 'boolean' === $field['type'] ) {
			echo '<label class="eit-check-field"><input type="checkbox" name="' . esc_attr( $name ) . '" value="1" ' . checked( ! empty( $value ), true, false ) . '><span>' . esc_html( $label ) . '</span></label>';
			return;
		}

		if ( 'textarea' === $field['type'] ) {
			echo '<label class="eit-field eit-field--wide"><span>' . esc_html( $label ) . '</span><textarea name="' . esc_attr( $name ) . '" rows="5"' . esc_attr( $required ) . '>' . esc_textarea( $value ) . '</textarea></label>';
			return;
		}

		if ( in_array( $field['type'], [ 'select', 'multiselect' ], true ) ) {
			$multiple = 'multiselect' === $field['type'];
			$selected = $multiple ? (array) $value : [ (string) $value ];
			echo '<label class="eit-field"><span>' . esc_html( $label ) . '</span><select name="' . esc_attr( $name ) . ( $multiple ? '[]' : '' ) . '"' . ( $multiple ? ' multiple size="5"' : '' ) . esc_attr( $required ) . '>';
			foreach ( FieldTypes::options( $field ) as $option_value => $option_label ) {
				echo '<option value="' . esc_attr( $option_value ) . '" ' . selected( in_array( $option_value, $selected, true ), true, false ) . '>' . esc_html( $option_label ) . '</option>';
			}
			echo '</select></label>';
			return;
		}

		if ( in_array( $field['type'], [ 'image', 'gallery' ], true ) ) {
			$stored = is_array( $value ) ? implode( ',', $value ) : $value;
			echo '<label class="eit-field eit-field--wide" data-eit-media-field data-eit-media-multiple="' . ( 'gallery' === $field['type'] ? '1' : '0' ) . '"><span>' . esc_html( $label ) . '</span><span class="eit-media-control"><input type="text" name="' . esc_attr( $name ) . '" value="' . esc_attr( $stored ) . '" readonly><button type="button" class="button" data-eit-select-media>' . esc_html__( 'Select media', 'elementor-implementation-toolkit' ) . '</button><button type="button" class="button-link" data-eit-clear-media>' . esc_html__( 'Clear', 'elementor-implementation-toolkit' ) . '</button></span></label>';
			return;
		}

		$input_type = [ 'number' => 'number', 'url' => 'url', 'email' => 'email', 'date' => 'date', 'color' => 'color' ][ $field['type'] ] ?? 'text';
		echo '<label class="eit-field"><span>' . esc_html( $label ) . '</span><input type="' . esc_attr( $input_type ) . '" name="' . esc_attr( $name ) . '" value="' . esc_attr( $value ) . '"' . esc_attr( $required ) . '></label>';
	}

	private function render_pagination( $type, array $result, $status, $search ) {
		if ( $result['pages'] < 2 ) {
			return;
		}
		$base = add_query_arg( [ 'page' => self::PAGE_PREFIX . $type, 'status' => $status, 's' => $search, 'paged' => '%#%' ], admin_url( 'admin.php' ) );
		echo '<div class="tablenav"><div class="tablenav-pages">' . wp_kses_post( paginate_links( [ 'base' => $base, 'current' => $result['page'], 'total' => $result['pages'] ] ) ) . '</div></div>';
	}

	private function required_fields_present( $type, array $values ) {
		if ( '' === trim( (string) ( $values['title'] ?? '' ) ) ) {
			return false;
		}
		foreach ( DefinitionManager::fields( $type ) as $key => $field ) {
			if ( ! empty( $field['required'] ) && empty( $values[ $key ] ) ) {
				return false;
			}
		}
		return true;
	}

	private function assert_can_manage() {
		if ( ! current_user_can( AdminPages::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to manage this content.', 'elementor-implementation-toolkit' ) );
		}
	}

	private function redirect( $type, array $args ) {
		$args = array_merge( [ 'page' => self::PAGE_PREFIX . $type ], $args );
		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
		exit;
	}
}
