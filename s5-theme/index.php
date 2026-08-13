<?php
/**
 * S5 theme — styled fallback template for non-framework content.
 *
 * Framework pages (those with canonical HTML) are served by {@see \S5\Render} through
 * the plugin-owned canvas template and never reach here. Everything else — ordinary
 * `post_content` pages and posts, archives, the blog index — renders through this
 * template.
 *
 * The design-system CSS bundle (site/dist/s5.css) is enqueued for every front-end
 * view by functions.php, so the wrappers below inherit the design system's base
 * typography and spacing. A non-adopted page therefore looks on-brand, not
 * browser-default. Existing menus, widgets, and content are untouched — this only
 * changes how they are framed and styled.
 *
 * @package S5\Theme
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

get_header();
?>
<main class="s5-fallback" data-s5="fallback">
	<div class="s5-fallback__inner">
		<?php
		if ( have_posts() ) {
			while ( have_posts() ) {
				the_post();
				?>
				<article <?php post_class( 's5-fallback__article' ); ?>>
					<?php the_title( '<h1 class="s5-fallback__title">', '</h1>' ); ?>
					<div class="s5-fallback__content prose">
						<?php the_content(); ?>
					</div>
				</article>
				<?php
			}

			the_posts_pagination();
		} else {
			?>
			<article class="s5-fallback__article">
				<h1 class="s5-fallback__title"><?php esc_html_e( 'Nothing here yet', 's5' ); ?></h1>
				<div class="s5-fallback__content prose">
					<p><?php esc_html_e( 'This content has not been published.', 's5' ); ?></p>
				</div>
			</article>
			<?php
		}
		?>
	</div>
</main>
<?php
get_footer();
