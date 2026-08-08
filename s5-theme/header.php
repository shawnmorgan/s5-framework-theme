<?php
/**
 * S5 shell theme — minimal header partial.
 *
 * Step 0: the smallest valid header so `get_header()` in index.php resolves and
 * WordPress emits no "theme without header.php" deprecation. The real framework
 * document shell (serving canonical HTML, design-system enqueue) is Step 1+ and
 * is NOT built here.
 *
 * @package S5\Theme
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<?php wp_head(); ?>
</head>
<body <?php body_class(); ?>>
<?php wp_body_open(); ?>
