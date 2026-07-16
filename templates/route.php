<?php
/**
 * Theme-contained shell for an active virtual Toolkit Route.
 */

use EIT\Blueprint\RouteRuntime;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

get_header();
?>
<main id="primary" class="site-main eit-route-runtime">
	<?php echo RouteRuntime::current_content(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Active presentation adapters return escaped runtime markup. ?>
</main>
<?php
get_footer();
