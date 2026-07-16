<?php
/**
 * Frontend authoring workspace generated from an active Entry contract.
 */

namespace EIT\Entry;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class EntryRenderer {

	private $resolver;
	private $storage;
	private $policy;
	private $presenter;
	private $fields;

	public function __construct() {
		$this->resolver = new EntrySurfaceResolver();
		$this->storage = new EntryStorageGateway();
		$this->policy = new EntryPolicyEngine( $this->storage );
		$this->presenter = new EntryContractPresenter( $this->policy );
		$this->fields = new EntryFieldRenderer();
	}

	public function shortcode( $attributes ) {
		$attributes = shortcode_atts( [ 'id' => '', 'item_id' => 0 ], $attributes, 'eit_entry_surface' );
		return $this->render( $attributes['id'], absint( $attributes['item_id'] ) );
	}

	public function render( $surface_id, $item_id = 0, $instance_id = '' ) {
		$contract = $this->resolver->get( $surface_id );
		if ( ! $contract ) {
			return $this->notice( __( 'This Entry Surface is unavailable.', 'elementor-implementation-toolkit' ), 'error' );
		}
		$operation = $item_id ? 'update' : 'create';
		$authorized = $this->policy->authorize( $contract, $operation, $item_id );
		if ( is_wp_error( $authorized ) ) {
			return $this->notice( $authorized->get_error_message(), 'error' );
		}
		$loaded = $item_id ? $this->storage->load_values( $contract, $item_id ) : null;
		if ( is_wp_error( $loaded ) ) {
			return $this->notice( $loaded->get_error_message(), 'error' );
		}
		$public = $this->presenter->present( $contract, $loaded );
		wp_enqueue_script( 'eit-frontend' );
		wp_enqueue_style( 'eit-frontend' );
		$instance_id = $this->instance_id( $surface_id, $instance_id );

		ob_start();
		$this->markup( $public, $instance_id );
		return ob_get_clean();
	}

	private function markup( array $contract, $instance_id ) {
		$fields = array_column( $contract['fields'], null, 'id' );
		$steps = $contract['steps'];
		?>
		<section id="eit-entry-<?php echo esc_attr( $instance_id ); ?>" class="eit-entry-workspace" data-eit-entry-workspace data-surface-id="<?php echo esc_attr( $contract['surface_id'] ); ?>" data-eit-entry-instance="<?php echo esc_attr( $instance_id ); ?>" data-item-id="<?php echo esc_attr( $contract['item']['id'] ?? 0 ); ?>">
			<header class="eit-entry-header">
				<p class="eit-entry-eyebrow"><?php echo esc_html( $contract['entity']['name'] ); ?></p>
				<h2><?php echo esc_html( $contract['name'] ); ?></h2>
				<?php if ( ! empty( $contract['item']['status'] ) ) : ?><span class="eit-entry-status"><?php echo esc_html( $this->status_label( $contract['item']['status'] ) ); ?></span><?php endif; ?>
			</header>
			<form class="eit-entry-form" data-eit-entry-form novalidate>
				<div class="eit-entry-progress" <?php echo count( $steps ) < 2 ? 'hidden' : ''; ?>>
					<span data-eit-step-label></span><progress max="<?php echo esc_attr( count( $steps ) ); ?>" value="1" data-eit-step-progress></progress>
				</div>
				<div class="eit-entry-form-message" data-eit-form-message role="status" aria-live="polite"></div>
				<?php foreach ( $steps as $index => $step ) : ?>
					<section class="eit-entry-step" data-eit-entry-step="<?php echo esc_attr( $index ); ?>" <?php echo 0 === $index ? '' : 'hidden'; ?>>
						<h3 tabindex="-1"><?php echo esc_html( $step['name'] ); ?></h3>
						<?php foreach ( $step['field_ids'] ?? [] as $field_id ) : ?>
							<?php if ( isset( $fields[ $field_id ] ) ) : ?>
								<?php $this->fields->render( $fields[ $field_id ], $contract['values'][ $field_id ] ?? null, $instance_id ); ?>
							<?php endif; ?>
						<?php endforeach; ?>
						<?php if ( 0 === $index && in_array( $contract['entity']['mode'], [ 'editorial', 'hybrid' ], true ) ) : ?>
							<div class="eit-entry-field" data-eit-editorial-field><label for="eit-entry-content-<?php echo esc_attr( $instance_id ); ?>"><?php esc_html_e( 'Editorial content', 'elementor-implementation-toolkit' ); ?></label><textarea id="eit-entry-content-<?php echo esc_attr( $instance_id ); ?>" rows="10" data-eit-editorial-content><?php echo esc_textarea( $contract['content'] ); ?></textarea></div>
						<?php endif; ?>
					</section>
				<?php endforeach; ?>
				<div class="eit-entry-honeypot" aria-hidden="true"><label>Website<input type="text" name="company_website" tabindex="-1" autocomplete="off" data-eit-honeypot></label></div>
				<input type="hidden" value="<?php echo esc_attr( $contract['form_token'] ); ?>" data-eit-form-token>
				<footer class="eit-entry-actions">
					<button type="button" class="eit-entry-secondary" data-eit-previous hidden><?php esc_html_e( 'Previous', 'elementor-implementation-toolkit' ); ?></button>
					<button type="button" class="eit-entry-secondary" data-eit-next <?php echo count( $steps ) < 2 ? 'hidden' : ''; ?>><?php esc_html_e( 'Continue', 'elementor-implementation-toolkit' ); ?></button>
					<div data-eit-submit-actions <?php echo count( $steps ) > 1 ? 'hidden' : ''; ?>><?php $this->submit_buttons( $contract ); ?></div>
				</footer>
			</form>
			<script type="application/json" data-eit-entry-contract><?php echo wp_json_encode( $contract, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></script>
		</section>
		<?php
	}

	private function instance_id( $surface_id, $instance_id ) {
		$surface = sanitize_html_class( (string) $surface_id );
		$instance = sanitize_html_class( (string) $instance_id );
		if ( '' === $instance ) {
			$instance = sanitize_html_class( wp_unique_id( 'shortcode-' ) );
		}
		return trim( $surface . '-' . $instance, '-' );
	}

	private function submit_buttons( array $contract ) {
		$permissions = $contract['permissions'];
		$status = $contract['item']['status'] ?? '';
		if ( ! empty( $permissions['create'] ) || ! empty( $permissions['update'] ) ) {
			$label = empty( $contract['item']['id'] ) && 'draft' === ( $contract['workflow']['initial_status'] ?? 'draft' ) ? __( 'Save draft', 'elementor-implementation-toolkit' ) : __( 'Save changes', 'elementor-implementation-toolkit' );
			printf( '<button type="submit" class="eit-entry-secondary" data-eit-intent="default">%s</button>', esc_html( $label ) );
		}
		if ( ! empty( $permissions['submit_review'] ) ) {
			printf( '<button type="submit" class="eit-entry-primary" data-eit-intent="submit_review">%s</button>', esc_html__( 'Submit for review', 'elementor-implementation-toolkit' ) );
		} elseif ( ! empty( $permissions['publish'] ) ) {
			printf( '<button type="submit" class="eit-entry-primary" data-eit-intent="publish">%s</button>', esc_html__( 'Publish', 'elementor-implementation-toolkit' ) );
		}
		if ( ! empty( $permissions['archive'] ) && $status && 'archived' !== $status ) {
			printf( '<button type="submit" class="eit-entry-link" data-eit-intent="archive">%s</button>', esc_html__( 'Archive', 'elementor-implementation-toolkit' ) );
		} elseif ( ! empty( $permissions['restore'] ) && 'archived' === $status ) {
			printf( '<button type="submit" class="eit-entry-secondary" data-eit-intent="restore">%s</button>', esc_html__( 'Restore', 'elementor-implementation-toolkit' ) );
		}
	}

	private function notice( $message, $type ) {
		return sprintf( '<div class="eit-entry-notice is-%1$s" role="alert">%2$s</div>', esc_attr( $type ), esc_html( $message ) );
	}

	private function status_label( $status ) {
		$labels = [ 'draft' => __( 'Draft', 'elementor-implementation-toolkit' ), 'review' => __( 'In review', 'elementor-implementation-toolkit' ), 'publish' => __( 'Published', 'elementor-implementation-toolkit' ), 'archived' => __( 'Archived', 'elementor-implementation-toolkit' ) ];
		return $labels[ $status ] ?? $status;
	}
}
