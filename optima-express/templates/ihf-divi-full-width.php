<?php
/**
 * Template Name: Custom Blank (Full Width)
 * Template Post Type: page
 *
 * Full-width page template for Divi theme IDX pages.
 * Keeps the Divi header and footer, but renders the content
 * full-width without a sidebar.
 *
 * @package OptimaExpress
 */

if (! defined('ABSPATH')) {
    exit;
}

add_filter('body_class', function($classes) {
    $classes[] = 'et_no_sidebar';
    return $classes;
});

get_header();
?>

<div id="main-content">
    <div class="container">
        <div id="content-area" class="clearfix">
            <?php while ( have_posts() ) : the_post(); ?>
                <article id="post-<?php the_ID(); ?>" <?php post_class(); ?>>
                    <div class="entry-content">
                        <?php the_content(); ?>
                    </div>
                </article>
            <?php endwhile; ?>
        </div>
    </div>
</div>

<?php get_footer();
