<?php
/**
 * Latest posts: the newest published items of a public post type (optionally one category) as cards.
 *
 * @package CrazyLinks
 *
 * @var array<string, mixed>             $props
 * @var \CrazyLinks\Render\RenderContext $ctx
 * @var \CrazyLinks\Render\Renderer      $renderer
 */

defined( 'ABSPATH' ) || exit;

$crazylinks_count = max( 1, min( 12, (int) ( $props['count'] ?? 3 ) ) );
$crazylinks_type  = sanitize_key( (string) ( $props['post_type'] ?? 'post' ) );
$crazylinks_obj   = '' !== $crazylinks_type ? get_post_type_object( $crazylinks_type ) : null;
if ( ! $crazylinks_obj || ! $crazylinks_obj->public || in_array( $crazylinks_type, [ 'attachment', \CrazyLinks\PostType::NAME ], true ) ) {
	$crazylinks_type = 'post';
}
$crazylinks_args = [
	'post_type'           => $crazylinks_type,
	'post_status'         => 'publish',
	'posts_per_page'      => $crazylinks_count,
	'ignore_sticky_posts' => true,
	'has_password'        => false,
	'orderby'             => 'date',
	'order'               => 'DESC',
];
if ( ! empty( $props['category'] ) && is_object_in_taxonomy( $crazylinks_type, 'category' ) ) {
	$crazylinks_args['cat'] = (int) $props['category'];
}
$crazylinks_posts = get_posts( $crazylinks_args );
// The list changes without the page being saved, so a cached copy lives at most 15 minutes.
$ctx->note_boundary( $ctx->now + 15 * MINUTE_IN_SECONDS );
if ( ! $crazylinks_posts ) {
	return;
}
$crazylinks_featured = 'featured' === ( $props['display'] ?? 'classic' );
?>
<ul class="cl-posts cl-posts--<?php echo $crazylinks_featured ? 'featured' : 'classic'; ?>" aria-label="<?php esc_attr_e( 'Latest posts', 'crazylinks' ); ?>">
	<?php foreach ( $crazylinks_posts as $crazylinks_post ) : ?>
		<?php
		$crazylinks_thumb = $renderer->image(
			(int) get_post_thumbnail_id( $crazylinks_post ),
			$crazylinks_featured ? 'cl-featured' : 'cl-thumb',
			$ctx,
			[
				'class' => 'cl-post__img',
				'alt'   => '',
				'sizes' => $crazylinks_featured ? '(max-width: 480px) 100vw, 480px' : '64px',
			]
		);
		?>
		<li class="cl-posts__item">
			<a class="cl-post" href="<?php echo esc_url( (string) get_permalink( $crazylinks_post ) ); ?>">
				<?php echo $crazylinks_thumb; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wp_get_attachment_image output. ?>
				<span class="cl-post__body">
					<span class="cl-post__title"><?php echo esc_html( wp_strip_all_tags( get_the_title( $crazylinks_post ) ) ); ?></span>
					<time class="cl-post__date" datetime="<?php echo esc_attr( (string) get_the_date( 'c', $crazylinks_post ) ); ?>"><?php echo esc_html( (string) get_the_date( '', $crazylinks_post ) ); ?></time>
				</span>
			</a>
		</li>
	<?php endforeach; ?>
</ul>
