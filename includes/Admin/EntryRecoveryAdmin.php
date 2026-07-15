<?php
/**
 * Hidden wp-admin recovery surface for failed Entry action jobs.
 */

namespace EIT\Admin;

use EIT\Entry\EntryActionDispatcher;
use EIT\Infrastructure\EntryActionStore;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class EntryRecoveryAdmin {

	const RETRY_ACTION = 'eit_retry_entry_action_now';

	private $jobs;

	public function __construct() {
		$this->jobs = new EntryActionStore();
	}

	public function render() {
		$jobs = $this->jobs->recent( 100 );
		?>
		<section class="eit-panel eit-panel--table">
			<div class="eit-panel__header"><div><h2><?php esc_html_e( 'Entry action recovery', 'elementor-implementation-toolkit' ); ?></h2><p><?php esc_html_e( 'Content is already safe. Retry only failed notification or integration jobs from this factual queue.', 'elementor-implementation-toolkit' ); ?></p></div></div>
			<?php if ( ! $jobs ) : ?>
				<div class="eit-panel__body"><div class="eit-empty-panel"><h3><?php esc_html_e( 'No Entry action jobs', 'elementor-implementation-toolkit' ); ?></h3><p><?php esc_html_e( 'Jobs appear after a published Entry Surface triggers a configured action.', 'elementor-implementation-toolkit' ); ?></p></div></div>
			<?php else : ?>
				<table class="widefat striped eit-admin-table"><thead><tr><th><?php esc_html_e( 'Action', 'elementor-implementation-toolkit' ); ?></th><th><?php esc_html_e( 'Observed status', 'elementor-implementation-toolkit' ); ?></th><th><?php esc_html_e( 'Attempts', 'elementor-implementation-toolkit' ); ?></th><th><?php esc_html_e( 'Last observation', 'elementor-implementation-toolkit' ); ?></th><th><?php esc_html_e( 'Recovery', 'elementor-implementation-toolkit' ); ?></th></tr></thead><tbody>
				<?php foreach ( $jobs as $job ) : ?>
					<tr><td><strong><?php echo esc_html( $job['action_type'] ); ?></strong><span class="eit-row-sub"><code><?php echo esc_html( $job['id'] ); ?></code></span></td><td><span class="eit-status-pill <?php echo 'failed' === $job['status'] ? 'is-warning' : ''; ?>"><?php echo esc_html( $job['status'] ); ?></span><?php if ( $job['error_message'] ) : ?><span class="eit-row-sub"><?php echo esc_html( $job['error_message'] ); ?></span><?php endif; ?></td><td><?php echo esc_html( $job['attempts'] ); ?> / 5</td><td><?php echo esc_html( get_date_from_gmt( $job['updated_at'], 'Y-m-d H:i:s' ) ); ?></td><td><?php $this->retry_form( $job ); ?></td></tr>
				<?php endforeach; ?>
				</tbody></table>
			<?php endif; ?>
		</section>
		<?php
	}

	public function handle_retry() {
		if ( ! current_user_can( AdminPages::CAPABILITY ) ) {
			wp_die( esc_html__( 'You are not allowed to recover Entry actions.', 'elementor-implementation-toolkit' ), 403 );
		}
		$job_id = sanitize_text_field( wp_unslash( $_POST['job_id'] ?? '' ) );
		check_admin_referer( self::RETRY_ACTION . '_' . $job_id );
		( new EntryActionDispatcher() )->retry( $job_id );
		wp_safe_redirect( admin_url( 'admin.php?page=' . AdminPages::ENTRY_RECOVERY_SLUG ) );
		exit;
	}

	private function retry_form( array $job ) {
		if ( 'failed' !== $job['status'] || $job['attempts'] >= 5 ) {
			echo '<span aria-hidden="true">—</span>';
			return;
		}
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="<?php echo esc_attr( self::RETRY_ACTION ); ?>">
			<input type="hidden" name="job_id" value="<?php echo esc_attr( $job['id'] ); ?>">
			<?php wp_nonce_field( self::RETRY_ACTION . '_' . $job['id'] ); ?>
			<button type="submit" class="button"><?php esc_html_e( 'Retry now', 'elementor-implementation-toolkit' ); ?></button>
		</form>
		<?php
	}
}
