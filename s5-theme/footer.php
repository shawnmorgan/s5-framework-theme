<?php
/**
 * S5 shell theme — minimal footer partial.
 *
 * Step 0: the smallest valid footer so `get_footer()` in index.php resolves and
 * WordPress emits no "theme without footer.php" deprecation. Real footer output
 * is Step 1+ and is NOT built here.
 *
 * @package S5\Theme
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<?php wp_footer(); ?>
</body>
</html>
