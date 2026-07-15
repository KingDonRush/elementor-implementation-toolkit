<?php
/**
 * List view for Toolkit custom post type definitions.
 */

namespace EIT\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CptDefinitionListView {

	private $renderer;

	public function __construct( AdminRenderer $renderer ) {
		$this->renderer = $renderer;
	}

	public function render( array $definitions ) {
		$all_definitions = $definitions;
		$search = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';

		if ( '' !== $search ) {
			$needle = strtolower( $search );
			$definitions = array_filter(
				$definitions,
				function ( $definition, $slug ) use ( $needle ) {
					$haystack = strtolower(
						implode(
							' ',
							[
								$slug,
								$definition['singular'] ?? '',
								$definition['plural'] ?? '',
								$this->row_labels( $definition['meta_fields'] ?? [], 'label', 'key' ),
								$this->row_labels( $definition['taxonomies'] ?? [], 'plural', 'slug' ),
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
					<h3><?php esc_html_e( 'CPT / Post Types', 'elementor-implementation-toolkit' ); ?></h3>
					<p><?php esc_html_e( 'Reusable WordPress structures for projects that need more than posts and pages.', 'elementor-implementation-toolkit' ); ?></p>
				</div>
				<a class="button button-primary" href="<?php echo esc_url( admin_url( 'admin.php?page=' . AdminPages::CPT_SLUG . '&view=new' ) ); ?>">
					<?php esc_html_e( 'Add New', 'elementor-implementation-toolkit' ); ?>
				</a>
			</div>

			<div class="eit-table-tools">
				<div class="eit-view-links">
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . AdminPages::CPT_SLUG ) ); ?>"><?php esc_html_e( 'All', 'elementor-implementation-toolkit' ); ?></a>
					<span class="description">(<?php echo esc_html( count( $all_definitions ) ); ?>)</span>
				</div>
				<form class="eit-search-box" method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>">
					<input type="hidden" name="page" value="<?php echo esc_attr( AdminPages::CPT_SLUG ); ?>" />
					<input type="search" name="s" value="<?php echo esc_attr( $search ); ?>" />
					<button type="submit" class="button"><?php esc_html_e( 'Search Post Types', 'elementor-implementation-toolkit' ); ?></button>
				</form>
			</div>

			<?php if ( empty( $all_definitions ) ) : ?>
				<?php
				$this->renderer->render_empty_state(
					__( 'No custom post types yet', 'elementor-implementation-toolkit' ),
					__( 'Create one when a project needs custom content, typed fields, and Elementor-ready structure.', 'elementor-implementation-toolkit' ),
					admin_url( 'admin.php?page=' . AdminPages::CPT_SLUG . '&view=new' ),
					__( 'Create post type', 'elementor-implementation-toolkit' )
				);
				?>
			<?php elseif ( empty( $definitions ) ) : ?>
				<?php
				$this->renderer->render_empty_state(
					__( 'No post types match this search', 'elementor-implementation-toolkit' ),
					__( 'Clear the search or create a new post type for the data model you need.', 'elementor-implementation-toolkit' )
				);
				?>
			<?php else : ?>
				<table class="widefat striped eit-admin-table">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Post type', 'elementor-implementation-toolkit' ); ?></th>
							<th><?php esc_html_e( 'Meta fields', 'elementor-implementation-toolkit' ); ?></th>
							<th><?php esc_html_e( 'Taxonomies', 'elementor-implementation-toolkit' ); ?></th>
							<th><?php esc_html_e( 'Status', 'elementor-implementation-toolkit' ); ?></th>
							<th><?php esc_html_e( 'Actions', 'elementor-implementation-toolkit' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $definitions as $slug => $definition ) : ?>
							<tr>
								<td>
									<a class="eit-row-title" href="<?php echo esc_url( admin_url( 'admin.php?page=' . AdminPages::CPT_SLUG . '&cpt=' . rawurlencode( $slug ) ) ); ?>"><?php echo esc_html( $definition['plural'] ?: $slug ); ?></a>
									<span class="eit-row-sub"><?php echo esc_html( $slug ); ?></span>
								</td>
								<td><?php echo esc_html( $this->row_labels( $definition['meta_fields'] ?? [], 'label', 'key' ) ?: count( $definition['meta_fields'] ?? [] ) ); ?></td>
								<td><?php echo esc_html( $this->row_labels( $definition['taxonomies'] ?? [], 'plural', 'slug' ) ?: count( $definition['taxonomies'] ?? [] ) ); ?></td>
								<td><span class="eit-status-pill <?php echo empty( $definition['public'] ) ? 'is-neutral' : ''; ?>"><?php echo ! empty( $definition['public'] ) ? esc_html__( 'Active', 'elementor-implementation-toolkit' ) : esc_html__( 'Private', 'elementor-implementation-toolkit' ); ?></span></td>
								<td class="eit-row-actions">
									<a class="eit-mini-button" href="<?php echo esc_url( admin_url( 'admin.php?page=' . AdminPages::CPT_SLUG . '&cpt=' . rawurlencode( $slug ) ) ); ?>"><?php esc_html_e( 'Edit', 'elementor-implementation-toolkit' ); ?></a>
									<a class="eit-mini-button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=' . CptManagerAdmin::DUPLICATE_ACTION . '&cpt=' . rawurlencode( $slug ) ), CptManagerAdmin::DUPLICATE_ACTION . '_' . $slug ) ); ?>"><?php esc_html_e( 'Duplicate', 'elementor-implementation-toolkit' ); ?></a>
									<a class="eit-mini-button is-danger" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=' . CptManagerAdmin::DELETE_ACTION . '&cpt=' . rawurlencode( $slug ) ), CptManagerAdmin::DELETE_ACTION . '_' . $slug ) ); ?>"><?php esc_html_e( 'Delete', 'elementor-implementation-toolkit' ); ?></a>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>
		<?php
	}

	private function row_labels( array $rows, $primary_key, $fallback_key ) {
		$labels = [];

		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}

			$label = trim( (string) ( $row[ $primary_key ] ?? $row[ $fallback_key ] ?? '' ) );
			if ( '' !== $label ) {
				$labels[] = $label;
			}
		}

		return implode( ', ', array_slice( $labels, 0, 4 ) );
	}
}
