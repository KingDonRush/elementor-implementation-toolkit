<?php
/**
 * Shared WordPress admin renderer.
 */

namespace EIT\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AdminRenderer {

	public function render_shell( $active_slug, array $tabs, array $config, callable $content ) {
		$title       = $config['title'] ?? __( 'Implementation Toolkit', 'elementor-implementation-toolkit' );
		$description = $config['description'] ?? '';
		?>
		<div class="wrap eit-admin">
			<div class="eit-title-row">
				<h1><?php echo esc_html( $title ); ?></h1>
				<?php foreach ( $config['actions'] ?? [] as $action ) : ?>
					<a class="page-title-action" href="<?php echo esc_url( $action['url'] ?? '#' ); ?>"><?php echo esc_html( $action['label'] ?? '' ); ?></a>
				<?php endforeach; ?>
			</div>

			<?php if ( $description ) : ?>
				<p class="description eit-page-description"><?php echo esc_html( $description ); ?></p>
			<?php endif; ?>

			<nav class="nav-tab-wrapper eit-native-tabs" aria-label="<?php echo esc_attr__( 'Toolkit admin sections', 'elementor-implementation-toolkit' ); ?>">
				<?php foreach ( $tabs as $slug => $tab ) : ?>
					<?php $class = $slug === $active_slug ? 'nav-tab nav-tab-active' : 'nav-tab'; ?>
					<a class="<?php echo esc_attr( $class ); ?>" href="<?php echo esc_url( admin_url( 'admin.php?page=' . $slug ) ); ?>">
						<?php echo esc_html( $tab['label'] ?? $slug ); ?>
					</a>
				<?php endforeach; ?>
			</nav>

			<div class="eit-native-content">
				<?php $content(); ?>
			</div>
		</div>
		<?php
	}

	public function render_notice( $code ) {
		$messages = [
			'saved'   => __( 'Saved successfully.', 'elementor-implementation-toolkit' ),
			'deleted' => __( 'Deleted successfully.', 'elementor-implementation-toolkit' ),
			'error'   => __( 'The action could not be completed.', 'elementor-implementation-toolkit' ),
		];

		if ( empty( $messages[ $code ] ) ) {
			return;
		}

		$type = 'error' === $code ? 'error' : 'success';
		?>
		<div class="notice notice-<?php echo esc_attr( $type ); ?> is-dismissible">
			<p><?php echo esc_html( $messages[ $code ] ); ?></p>
		</div>
		<?php
	}

	public function render_empty_state( $title, $description, $action_url = '', $action_label = '' ) {
		?>
		<div class="eit-empty-panel">
			<h3><?php echo esc_html( $title ); ?></h3>
			<p><?php echo esc_html( $description ); ?></p>
			<?php if ( $action_url && $action_label ) : ?>
				<a class="button button-primary" href="<?php echo esc_url( $action_url ); ?>"><?php echo esc_html( $action_label ); ?></a>
			<?php endif; ?>
		</div>
		<?php
	}

	public function render_advanced_toggle( $label ) {
		?>
		<summary class="eit-advanced-toggle">
			<span class="dashicons dashicons-admin-generic" aria-hidden="true"></span>
			<span><?php echo esc_html( $label ); ?></span>
			<span class="dashicons dashicons-arrow-down-alt2" aria-hidden="true"></span>
		</summary>
		<?php
	}

	public function render_advanced_button( $label, $target_id ) {
		?>
		<button type="button" class="eit-advanced-toggle" data-eit-open-modal="<?php echo esc_attr( $target_id ); ?>" aria-haspopup="dialog">
			<span class="dashicons dashicons-admin-generic" aria-hidden="true"></span>
			<span><?php echo esc_html( $label ); ?></span>
			<span class="dashicons dashicons-arrow-right-alt2" aria-hidden="true"></span>
		</button>
		<?php
	}

	public function render_modal_open( $id, $title, $modifier = '' ) {
		$class = trim( 'eit-modal ' . $modifier );
		?>
		<div class="<?php echo esc_attr( $class ); ?>" id="<?php echo esc_attr( $id ); ?>" hidden data-eit-modal>
			<button type="button" class="eit-modal__backdrop" data-eit-close-modal aria-label="<?php echo esc_attr__( 'Close dialog', 'elementor-implementation-toolkit' ); ?>"></button>
			<section class="eit-modal__panel" role="dialog" aria-modal="true" aria-labelledby="<?php echo esc_attr( $id ); ?>-title">
				<header class="eit-modal__header">
					<h2 id="<?php echo esc_attr( $id ); ?>-title"><?php echo esc_html( $title ); ?></h2>
					<button type="button" class="button-link eit-modal__close" data-eit-close-modal aria-label="<?php echo esc_attr__( 'Close dialog', 'elementor-implementation-toolkit' ); ?>">
						<span class="dashicons dashicons-no-alt" aria-hidden="true"></span>
					</button>
				</header>
				<div class="eit-modal__body">
		<?php
	}

	public function render_modal_close( $label = '' ) {
		$label = $label ?: __( 'Done', 'elementor-implementation-toolkit' );
		?>
				</div>
				<footer class="eit-modal__footer">
					<button type="button" class="button button-primary" data-eit-close-modal><?php echo esc_html( $label ); ?></button>
				</footer>
			</section>
		</div>
		<?php
	}
}
