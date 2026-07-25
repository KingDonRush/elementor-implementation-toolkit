<?php
/**
 * Theme-contained shell for an active virtual Toolkit Route.
 */

use EIT\Blueprint\RouteRuntime;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

get_header();
$context = RouteRuntime::current_context();
?>
<main
	id="primary"
	class="site-main eit-route-runtime"
	data-eit-route-id="<?php echo esc_attr( $context['route_id'] ); ?>"
	<?php if ( $context['entity_id'] ) : ?>data-eit-entity-id="<?php echo esc_attr( $context['entity_id'] ); ?>"<?php endif; ?>
	<?php if ( $context['item_id'] ) : ?>data-eit-item-id="<?php echo esc_attr( $context['item_id'] ); ?>"<?php endif; ?>
>
	<?php echo RouteRuntime::current_content(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Active presentation adapters return escaped runtime markup. ?>
</main>
<?php
get_footer();
