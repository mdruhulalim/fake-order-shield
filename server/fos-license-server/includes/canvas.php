<?php
/**
 * Full-width page template for the landing and account pages.
 *
 * @package FOSLicenseServer
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
$fos_account = FOS_Store::account_url();
?><!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php bloginfo( 'charset' ); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="description" content="Fake Order Shield — WooCommerce plugin that stops fake and risky orders for Bangladeshi online shops.">
<?php wp_head(); ?>
</head>
<body <?php body_class( 'fos-site' ); ?>>
<?php wp_body_open(); ?>
<header class="fos-nav">
	<div class="fos-container fos-nav-in">
		<a class="fos-logo" href="<?php echo esc_url( home_url( '/' ) ); ?>"><span class="fos-logo-mark"></span>Fake Order Shield</a>
		<nav>
			<a href="<?php echo esc_url( home_url( '/#features' ) ); ?>">ফিচার</a>
			<a href="<?php echo esc_url( home_url( '/#pricing' ) ); ?>">প্যাকেজ</a>
			<a class="fos-btn fos-btn-primary fos-btn-sm" href="<?php echo esc_url( $fos_account ); ?>"><?php echo is_user_logged_in() ? 'অ্যাকাউন্ট' : 'লগইন / রেজিস্টার'; ?></a>
		</nav>
	</div>
</header>
<main>
	<?php
	while ( have_posts() ) {
		the_post();
		the_content();
	}
	?>
</main>
<footer class="fos-footer">
	<div class="fos-container">© <?php echo esc_html( gmdate( 'Y' ) ); ?> Fake Order Shield · Branding Solutions BD</div>
</footer>
<?php wp_footer(); ?>
</body>
</html>
