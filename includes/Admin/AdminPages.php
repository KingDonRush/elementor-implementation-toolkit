<?php
/**
 * Clean WordPress admin entry points for the implementation toolkit.
 */

namespace EIT\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AdminPages {

	const CAPABILITY = 'manage_options';
	const DASHBOARD_SLUG = 'eit-toolkit';
	const FILTERS_SLUG = 'eit-filter-presets';
	const CPT_SLUG = 'eit-cpt-manager';
	const INTEGRATIONS_SLUG = 'eit-integrations';

	public function init_hooks() {
		add_action( 'admin_menu', [ $this, 'register_menu' ] );
	}

	public function register_menu() {
		add_menu_page(
			__( 'Implementation Toolkit', 'elementor-implementation-toolkit' ),
			__( 'Implementation Toolkit', 'elementor-implementation-toolkit' ),
			self::CAPABILITY,
			self::DASHBOARD_SLUG,
			[ $this, 'render_dashboard' ],
			'dashicons-superhero',
			58
		);

		add_submenu_page(
			self::DASHBOARD_SLUG,
			__( 'Toolkit Dashboard', 'elementor-implementation-toolkit' ),
			__( 'Dashboard', 'elementor-implementation-toolkit' ),
			self::CAPABILITY,
			self::DASHBOARD_SLUG,
			[ $this, 'render_dashboard' ]
		);

		add_submenu_page(
			self::DASHBOARD_SLUG,
			__( 'Filter Presets', 'elementor-implementation-toolkit' ),
			__( 'Filter Presets', 'elementor-implementation-toolkit' ),
			self::CAPABILITY,
			self::FILTERS_SLUG,
			[ $this, 'render_filters' ]
		);

		add_submenu_page(
			self::DASHBOARD_SLUG,
			__( 'CPT Manager', 'elementor-implementation-toolkit' ),
			__( 'CPT Manager', 'elementor-implementation-toolkit' ),
			self::CAPABILITY,
			self::CPT_SLUG,
			[ $this, 'render_cpts' ]
		);

		add_submenu_page(
			self::DASHBOARD_SLUG,
			__( 'Integrations', 'elementor-implementation-toolkit' ),
			__( 'Integrations', 'elementor-implementation-toolkit' ),
			self::CAPABILITY,
			self::INTEGRATIONS_SLUG,
			[ $this, 'render_integrations' ]
		);
	}

	public function render_dashboard() {
		$this->render_page(
			self::DASHBOARD_SLUG,
			__( 'Admin rebuild checkpoint', 'elementor-implementation-toolkit' ),
			__( 'The previous admin panel was intentionally removed. This clean shell is the new base for rebuilding one frame-driven task at a time.', 'elementor-implementation-toolkit' ),
			[
				__( 'No legacy admin forms are rendered.', 'elementor-implementation-toolkit' ),
				__( 'Runtime widget, REST, CPT registration, frontend and editor scripts remain outside this reset.', 'elementor-implementation-toolkit' ),
				__( 'Next task should rebuild Filter Presets from the approved frames with screenshot QA in the same task.', 'elementor-implementation-toolkit' ),
			]
		);
	}

	public function render_filters() {
		$this->render_page(
			self::FILTERS_SLUG,
			__( 'Filter Presets reset', 'elementor-implementation-toolkit' ),
			__( 'This screen is ready for a fresh frame-based rebuild. The old Filter Presets builder is no longer present.', 'elementor-implementation-toolkit' ),
			[
				__( 'Target source frames: filter overview, provider contract, price module, controller output and preview modal.', 'elementor-implementation-toolkit' ),
				__( 'No filter preset save UI is active in this reset checkpoint.', 'elementor-implementation-toolkit' ),
				__( 'Existing stored presets are not deleted from the database.', 'elementor-implementation-toolkit' ),
			]
		);
	}

	public function render_cpts() {
		$this->render_page(
			self::CPT_SLUG,
			__( 'CPT Manager reset', 'elementor-implementation-toolkit' ),
			__( 'This screen is intentionally empty until the content-model frames are rebuilt from scratch.', 'elementor-implementation-toolkit' ),
			[
				__( 'No CPT admin form is active in this reset checkpoint.', 'elementor-implementation-toolkit' ),
				__( 'Existing stored CPT definitions are not deleted from the database.', 'elementor-implementation-toolkit' ),
				__( 'CPT runtime registration remains handled by the existing CPT manager service.', 'elementor-implementation-toolkit' ),
			]
		);
	}

	public function render_integrations() {
		$this->render_page(
			self::INTEGRATIONS_SLUG,
			__( 'Integrations reset', 'elementor-implementation-toolkit' ),
			__( 'This screen is ready for a new Superpowers architecture pass after Filters and CPTs are stable.', 'elementor-implementation-toolkit' ),
			[
				__( 'No integration settings UI is active in this reset checkpoint.', 'elementor-implementation-toolkit' ),
				__( 'No runtime adapters are changed by this reset.', 'elementor-implementation-toolkit' ),
				__( 'Future modules should be rebuilt as small, testable admin contracts.', 'elementor-implementation-toolkit' ),
			]
		);
	}

	private function render_page( $active_slug, $title, $description, array $facts ) {
		?>
		<div class="wrap eit-admin-reset">
			<header class="eit-reset-topbar">
				<div class="eit-reset-brand">
					<span class="eit-reset-mark" aria-hidden="true"></span>
					<div>
						<h1><?php esc_html_e( 'Elementor Implementation Toolkit', 'elementor-implementation-toolkit' ); ?></h1>
						<p><?php esc_html_e( 'Clean admin rebuild base', 'elementor-implementation-toolkit' ); ?></p>
					</div>
				</div>

				<nav class="eit-reset-tabs" aria-label="<?php echo esc_attr__( 'Toolkit admin sections', 'elementor-implementation-toolkit' ); ?>">
					<?php $this->render_tab( self::DASHBOARD_SLUG, __( 'Dashboard', 'elementor-implementation-toolkit' ), $active_slug ); ?>
					<?php $this->render_tab( self::FILTERS_SLUG, __( 'Filters', 'elementor-implementation-toolkit' ), $active_slug ); ?>
					<?php $this->render_tab( self::CPT_SLUG, __( 'CPTs', 'elementor-implementation-toolkit' ), $active_slug ); ?>
					<?php $this->render_tab( self::INTEGRATIONS_SLUG, __( 'Integrations', 'elementor-implementation-toolkit' ), $active_slug ); ?>
				</nav>
			</header>

			<main class="eit-reset-stage">
				<section class="eit-reset-card">
					<p class="eit-reset-kicker"><?php esc_html_e( 'Admin reset', 'elementor-implementation-toolkit' ); ?></p>
					<h2><?php echo esc_html( $title ); ?></h2>
					<p><?php echo esc_html( $description ); ?></p>
					<ul>
						<?php foreach ( $facts as $fact ) : ?>
							<li><?php echo esc_html( $fact ); ?></li>
						<?php endforeach; ?>
					</ul>
				</section>
			</main>
		</div>
		<?php
	}

	private function render_tab( $slug, $label, $active_slug ) {
		$class = $slug === $active_slug ? 'eit-reset-tab is-active' : 'eit-reset-tab';
		printf(
			'<a class="%1$s" href="%2$s">%3$s</a>',
			esc_attr( $class ),
			esc_url( admin_url( 'admin.php?page=' . $slug ) ),
			esc_html( $label )
		);
	}
}
