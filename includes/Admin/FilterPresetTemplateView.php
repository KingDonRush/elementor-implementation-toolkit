<?php
/**
 * Explicit Elementor template handoff for a filter preset.
 */

namespace EIT\Admin;

use EIT\Elementor\FilterTemplateManager;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class FilterPresetTemplateView {

	public function render_handoff( array $templates ) {
		$has_templates = ! empty( $templates );
		?>
		<aside class="eit-handoff-card">
			<div class="eit-handoff-card__head">
				<span class="dashicons dashicons-admin-page" aria-hidden="true"></span>
				<div>
					<h4><?php esc_html_e( 'Filter controls handoff', 'elementor-implementation-toolkit' ); ?></h4>
					<p><?php esc_html_e( 'Open this preset in Elementor to design reusable filter controls for a page or template.', 'elementor-implementation-toolkit' ); ?></p>
				</div>
			</div>
			<?php if ( ! FilterTemplateManager::is_elementor_available() ) : ?>
				<div class="notice notice-warning inline"><p><?php esc_html_e( 'Elementor must be active to open preset templates.', 'elementor-implementation-toolkit' ); ?></p></div>
			<?php endif; ?>
			<div class="eit-handoff-actions">
				<button type="submit" class="button button-primary" name="eit_after_save" value="open_template" <?php disabled( ! FilterTemplateManager::is_elementor_available() ); ?>>
					<span class="dashicons dashicons-external" aria-hidden="true"></span>
					<?php echo $has_templates ? esc_html__( 'Save and open in Elementor', 'elementor-implementation-toolkit' ) : esc_html__( 'Save and create in Elementor', 'elementor-implementation-toolkit' ); ?>
				</button>
			</div>
			<p class="eit-handoff-note">
				<span class="dashicons dashicons-yes" aria-hidden="true"></span>
				<?php if ( $has_templates ) : ?>
					<span><?php printf( esc_html( _n( 'This preset has %d linked Elementor template.', 'This preset has %d linked Elementor templates.', count( $templates ), 'elementor-implementation-toolkit' ) ), absint( count( $templates ) ) ); ?></span>
				<?php else : ?>
					<span><?php esc_html_e( 'This preset can create an Elementor filter-controls template.', 'elementor-implementation-toolkit' ); ?></span>
				<?php endif; ?>
			</p>
		</aside>
		<?php
	}

	public function render_management( array $preset, $is_existing, array $templates ) {
		if ( ! $is_existing || empty( $templates ) ) {
			return;
		}
		?>
		<section class="eit-panel eit-bridge-panel">
			<div class="eit-panel__header">
				<div>
					<h3><?php esc_html_e( 'Linked Elementor templates', 'elementor-implementation-toolkit' ); ?></h3>
					<p><?php esc_html_e( 'Manage filter-control templates created from this preset. The main handoff stays at the top of the form.', 'elementor-implementation-toolkit' ); ?></p>
				</div>
			</div>
			<?php if ( ! FilterTemplateManager::is_elementor_available() ) : ?>
				<div class="notice notice-warning inline"><p><?php esc_html_e( 'Elementor must be active to create and edit filter templates.', 'elementor-implementation-toolkit' ); ?></p></div>
			<?php endif; ?>
			<?php $this->render_rows( $templates, $preset['id'] ?? '' ); ?>
		</section>
		<?php
	}

	private function render_rows( array $templates, $preset_id ) {
		?>
		<table class="widefat striped eit-admin-table">
			<thead><tr><th><?php esc_html_e( 'Template', 'elementor-implementation-toolkit' ); ?></th><th><?php esc_html_e( 'Status', 'elementor-implementation-toolkit' ); ?></th><th><?php esc_html_e( 'Actions', 'elementor-implementation-toolkit' ); ?></th></tr></thead>
			<tbody>
				<?php foreach ( $templates as $template ) : ?>
					<?php $status = get_post_status_object( $template->post_status ); ?>
					<tr>
						<td><strong><?php echo esc_html( get_the_title( $template ) ); ?></strong><div class="row-actions"><span><?php echo esc_html( '#' . $template->ID ); ?></span></div></td>
						<td><?php echo esc_html( $status ? $status->label : $template->post_status ); ?></td>
						<td class="eit-row-actions">
							<a class="button button-primary" href="<?php echo esc_url( FilterTemplateManager::get_edit_url( $template->ID ) ); ?>"><?php esc_html_e( 'Edit in Elementor', 'elementor-implementation-toolkit' ); ?></a>
							<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="return confirm('<?php echo esc_js( __( 'Remove this filter template?', 'elementor-implementation-toolkit' ) ); ?>');">
								<input type="hidden" name="action" value="<?php echo esc_attr( FilterPresetAdmin::DELETE_TEMPLATE_ACTION ); ?>" />
								<input type="hidden" name="template_id" value="<?php echo esc_attr( $template->ID ); ?>" />
								<input type="hidden" name="preset" value="<?php echo esc_attr( $preset_id ); ?>" />
								<?php wp_nonce_field( FilterPresetAdmin::DELETE_TEMPLATE_ACTION . '_' . $template->ID ); ?>
								<button type="submit" class="button button-link-delete"><?php esc_html_e( 'Remove', 'elementor-implementation-toolkit' ); ?></button>
							</form>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}
}
