/**
 * Block registry: one entry per block type in schemas/page.v1.json. Field definitions drive the
 * generated edit panels; `defaults` seed new blocks; `required` drives client-side completeness checks.
 */
import { __ } from '@wordpress/i18n';
import type { BlockType, JsonObject, JsonValue } from '../types';

export type FieldKind =
	| 'text'
	| 'textarea'
	| 'html'
	| 'url'
	| 'number'
	| 'toggle'
	| 'select'
	| 'media'
	| 'datetime'
	| 'color'
	| 'icon'
	| 'tags'
	| 'numbers'
	| 'multicheck'
	| 'focal'
	| 'items';

export interface Option {
	value: string;
	label: string;
}

export interface FieldDef {
	/** Prop key inside block.props. */
	key: string;
	label: string;
	kind: FieldKind;
	help?: string;
	placeholder?: string;
	options?: Option[];
	/** Must be present and non-empty (schema `required` plus `minLength`/`minimum`). */
	required?: boolean;
	/** Schema requires the key but allows an empty value: never remove it when cleared. */
	keep?: boolean;
	maxLength?: number;
	min?: number;
	max?: number;
	/** media: library type. */
	mediaType?: 'image' | 'video';
	/** focal: key of the media prop the focal point belongs to. */
	focalOf?: string;
	/** items: sub-fields of each item. */
	itemFields?: FieldDef[];
	itemLabel?: string;
	maxItems?: number;
	/** Hide the field unless this returns true for the current props. */
	showIf?: ( props: JsonObject ) => boolean;
	/** Advanced fields are folded into a "More options" section. */
	advanced?: boolean;
	/** For a nested key (`background/type`): clearing this field removes the whole parent object. */
	clearsParent?: boolean;
}

export type BlockCategory =
	'links' | 'content' | 'media' | 'commerce' | 'layout' | 'advanced';

export interface BlockDef {
	type: BlockType;
	label: string;
	description: string;
	/** Dashicon slug (without the `dashicons-` prefix). */
	icon: string;
	category: BlockCategory;
	fields: FieldDef[];
	defaults: () => JsonObject;
	/** One-line summary for the block list. */
	summary: ( props: JsonObject ) => string;
	/** Extra completeness rule beyond `required` fields; returns a [field, message] pair or null. */
	incomplete?: ( props: JsonObject ) => [ string, string ] | null;
	/** Clicks can be tracked for this type (routed through /go/). */
	trackable?: boolean;
}

const str = ( v: JsonValue | undefined ): string =>
	typeof v === 'string' ? v : '';

const opts = ( pairs: Array< [ string, string ] > ): Option[] =>
	pairs.map( ( [ value, label ] ) => ( { value, label } ) );

const align = (): FieldDef => ( {
	key: 'align',
	label: __( 'Alignment', 'crazylinks' ),
	kind: 'select',
	options: opts( [
		[ '', __( 'Default', 'crazylinks' ) ],
		[ 'left', __( 'Left', 'crazylinks' ) ],
		[ 'center', __( 'Center', 'crazylinks' ) ],
		[ 'right', __( 'Right', 'crazylinks' ) ],
	] ),
} );

/** A section's background type, or ''. */
const bgType = ( p: JsonObject ): string =>
	str( ( p.background as JsonObject | undefined )?.type );

const hasThumb = ( p: JsonObject ): boolean =>
	typeof p.thumbnail === 'number' && p.thumbnail > 0;

/** Social networks accepted by the schema, in picker order. */
export const SOCIAL_NETWORKS = [
	'instagram',
	'tiktok',
	'youtube',
	'x',
	'twitter',
	'facebook',
	'threads',
	'bluesky',
	'mastodon',
	'linkedin',
	'pinterest',
	'snapchat',
	'twitch',
	'discord',
	'whatsapp',
	'telegram',
	'signal',
	'spotify',
	'apple_music',
	'soundcloud',
	'bandcamp',
	'github',
	'dribbble',
	'behance',
	'medium',
	'substack',
	'patreon',
	'kofi',
	'reddit',
	'tumblr',
	'vimeo',
	'etsy',
	'amazon',
	'email',
	'phone',
	'website',
	'rss',
] as const;

export type SocialNetwork = ( typeof SOCIAL_NETWORKS )[ number ];

function inDays( days: number ): string {
	const d = new Date( Date.now() + days * 86400000 );
	d.setUTCMinutes( 0, 0, 0 );
	return d.toISOString().replace( /\.\d{3}Z$/, 'Z' );
}

let cache: Record< string, BlockDef > | null = null;

/**
 * Every block definition keyed by type.
 */
export function blockDefs(): Record< string, BlockDef > {
	if ( cache ) {
		return cache;
	}
	const defs: BlockDef[] = [
		{
			type: 'link',
			label: __( 'Link', 'crazylinks' ),
			description: __(
				'A button that opens a URL. Click-tracked.',
				'crazylinks'
			),
			icon: 'admin-links',
			category: 'links',
			trackable: true,
			defaults: () => ( { title: '', url: '' } ),
			summary: ( p ) => str( p.title ) || str( p.url ),
			fields: [
				{
					key: 'title',
					label: __( 'Title', 'crazylinks' ),
					kind: 'text',
					required: true,
					maxLength: 120,
				},
				{
					key: 'url',
					label: __( 'URL', 'crazylinks' ),
					kind: 'url',
					required: true,
					maxLength: 2048,
					placeholder: 'https://',
					help: __(
						'http, https, mailto, tel or sms.',
						'crazylinks'
					),
				},
				{
					key: 'subtitle',
					label: __( 'Subtitle', 'crazylinks' ),
					kind: 'text',
					maxLength: 160,
				},
				{
					key: 'display',
					label: __( 'Display', 'crazylinks' ),
					kind: 'select',
					options: opts( [
						[ 'classic', __( 'Classic button', 'crazylinks' ) ],
						[
							'thumbnail',
							__( 'Button with thumbnail', 'crazylinks' ),
						],
						[
							'featured',
							__( 'Featured card (16:9)', 'crazylinks' ),
						],
					] ),
				},
				{
					key: 'thumbnail',
					label: __( 'Thumbnail image', 'crazylinks' ),
					kind: 'media',
					mediaType: 'image',
					showIf: ( p ) =>
						p.display === 'thumbnail' || p.display === 'featured',
				},
				{
					key: 'thumbnail_alt',
					label: __( 'Thumbnail alternative text', 'crazylinks' ),
					kind: 'text',
					maxLength: 250,
					help: __(
						'Describe the image. Leave empty to use the Media Library text.',
						'crazylinks'
					),
					showIf: hasThumb,
				},
				{
					key: 'decorative',
					label: __( 'Thumbnail is decorative', 'crazylinks' ),
					kind: 'toggle',
					help: __(
						'Screen readers skip it; no alternative text is needed.',
						'crazylinks'
					),
					showIf: hasThumb,
				},
				{
					key: 'focal_point',
					label: __( 'Thumbnail focal point', 'crazylinks' ),
					kind: 'focal',
					focalOf: 'thumbnail',
					help: __(
						'The part of the image that stays in view when it is cropped.',
						'crazylinks'
					),
					showIf: hasThumb,
				},
				{
					key: 'thumb_side',
					label: __( 'Thumbnail side', 'crazylinks' ),
					kind: 'select',
					options: opts( [
						[ 'left', __( 'Left', 'crazylinks' ) ],
						[ 'right', __( 'Right', 'crazylinks' ) ],
					] ),
					showIf: ( p ) => p.display === 'thumbnail',
				},
				{
					key: 'tile_size',
					label: __( 'Tile size (Grid layout)', 'crazylinks' ),
					kind: 'select',
					options: opts( [
						[ '', __( '1 × 1 (default)', 'crazylinks' ) ],
						[ '2x1', __( '2 × 1 (full width)', 'crazylinks' ) ],
						[
							'2x2',
							__( '2 × 2 (full width, taller)', 'crazylinks' ),
						],
					] ),
					help: __(
						'Only applies when the page uses the Grid layout.',
						'crazylinks'
					),
				},
				{
					key: 'icon',
					label: __( 'Icon', 'crazylinks' ),
					kind: 'icon',
				},
				{
					key: 'emoji',
					label: __( 'Emoji', 'crazylinks' ),
					kind: 'text',
					maxLength: 16,
				},
				{
					key: 'badge/text',
					label: __( 'Badge', 'crazylinks' ),
					kind: 'text',
					maxLength: 24,
					help: __(
						'Short label such as "New" or "Sale".',
						'crazylinks'
					),
				},
				{
					key: 'badge/color',
					label: __( 'Badge colour', 'crazylinks' ),
					kind: 'color',
					advanced: true,
				},
				{
					key: 'style/fill',
					label: __( 'Button fill (this link only)', 'crazylinks' ),
					kind: 'select',
					options: opts( [
						[ '', __( 'Page default', 'crazylinks' ) ],
						[ 'solid', __( 'Solid', 'crazylinks' ) ],
						[ 'outline', __( 'Outline', 'crazylinks' ) ],
						[ 'soft', __( 'Soft', 'crazylinks' ) ],
						[ 'glass', __( 'Glass', 'crazylinks' ) ],
					] ),
					advanced: true,
				},
				{
					key: 'style/color',
					label: __( 'Button colour (this link only)', 'crazylinks' ),
					kind: 'color',
					advanced: true,
				},
				{
					key: 'style/text_color',
					label: __( 'Text colour (this link only)', 'crazylinks' ),
					kind: 'color',
					advanced: true,
				},
				{
					key: 'new_until',
					label: __( 'Show a NEW flag until', 'crazylinks' ),
					kind: 'datetime',
					advanced: true,
				},
				{
					key: 'open_new_tab',
					label: __( 'Open in a new tab', 'crazylinks' ),
					kind: 'toggle',
					advanced: true,
				},
				{
					key: 'rel',
					label: __( 'Link relationship', 'crazylinks' ),
					kind: 'multicheck',
					options: opts( [
						[ 'nofollow', 'nofollow' ],
						[ 'sponsored', 'sponsored' ],
					] ),
					advanced: true,
				},
				{
					key: 'deep_link',
					label: __( 'App deep link', 'crazylinks' ),
					kind: 'text',
					maxLength: 512,
					placeholder: 'spotify://…',
					advanced: true,
				},
			],
		},
		{
			type: 'socials',
			label: __( 'Social icons', 'crazylinks' ),
			description: __(
				'A row of brand icons linking to your profiles.',
				'crazylinks'
			),
			icon: 'share',
			category: 'links',
			trackable: true,
			defaults: () => ( { items: [], style: 'icons' } ),
			summary: ( p ) =>
				Array.isArray( p.items )
					? p.items
							.map( ( i ) =>
								i &&
								typeof i === 'object' &&
								! Array.isArray( i )
									? str( i.network )
									: ''
							)
							.filter( Boolean )
							.join( ', ' )
					: '',
			fields: [
				{
					key: 'items',
					label: __( 'Profiles', 'crazylinks' ),
					kind: 'items',
					keep: true,
					itemLabel: __( 'profile', 'crazylinks' ),
					maxItems: 40,
					itemFields: [
						{
							key: 'network',
							label: __( 'Network', 'crazylinks' ),
							kind: 'select',
							required: true,
						},
						{
							key: 'url',
							label: __( 'URL', 'crazylinks' ),
							kind: 'url',
							required: true,
							maxLength: 2048,
						},
					],
				},
				{
					key: 'style',
					label: __( 'Style', 'crazylinks' ),
					kind: 'select',
					options: opts( [
						[ 'icons', __( 'Icons', 'crazylinks' ) ],
						[ 'buttons', __( 'Buttons', 'crazylinks' ) ],
					] ),
				},
			],
		},
		{
			type: 'heading',
			label: __( 'Heading', 'crazylinks' ),
			description: __(
				'A title for a part of the page, with size, weight, colour, subtext and an optional divider.',
				'crazylinks'
			),
			icon: 'heading',
			category: 'content',
			defaults: () => ( { text: '', level: 'h2' } ),
			summary: ( p ) => str( p.text ),
			fields: [
				{
					key: 'text',
					label: __( 'Text', 'crazylinks' ),
					kind: 'text',
					required: true,
					maxLength: 200,
				},
				{
					key: 'subtext',
					label: __( 'Subtext', 'crazylinks' ),
					kind: 'text',
					maxLength: 160,
					help: __( 'A short line under the heading.', 'crazylinks' ),
				},
				{
					key: 'size',
					label: __( 'Size', 'crazylinks' ),
					kind: 'select',
					options: opts( [
						[ '', __( 'Default (medium)', 'crazylinks' ) ],
						[ 'sm', __( 'Small', 'crazylinks' ) ],
						[ 'md', __( 'Medium', 'crazylinks' ) ],
						[ 'lg', __( 'Large', 'crazylinks' ) ],
						[ 'xl', __( 'Extra large', 'crazylinks' ) ],
					] ),
				},
				align(),
				{
					key: 'weight',
					label: __( 'Weight', 'crazylinks' ),
					kind: 'select',
					options: opts( [
						[ '', __( 'Default (bold)', 'crazylinks' ) ],
						[ 'normal', __( 'Normal', 'crazylinks' ) ],
						[ 'bold', __( 'Bold', 'crazylinks' ) ],
						[ 'black', __( 'Black', 'crazylinks' ) ],
					] ),
				},
				{
					key: 'divider',
					label: __( 'Divider', 'crazylinks' ),
					kind: 'select',
					options: opts( [
						[ '', __( 'None', 'crazylinks' ) ],
						[ 'line', __( 'Line', 'crazylinks' ) ],
						[ 'dots', __( 'Dots', 'crazylinks' ) ],
					] ),
				},
				{
					key: 'icon',
					label: __( 'Icon', 'crazylinks' ),
					kind: 'icon',
					showIf: ( p ) => ! str( p.emoji ),
				},
				{
					key: 'emoji',
					label: __( 'Emoji', 'crazylinks' ),
					kind: 'text',
					maxLength: 16,
					help: __(
						'Shown before the text instead of an icon.',
						'crazylinks'
					),
				},
				{
					key: 'level',
					label: __( 'Level', 'crazylinks' ),
					kind: 'select',
					options: opts( [
						[ 'h2', __( 'Heading 2', 'crazylinks' ) ],
						[ 'h3', __( 'Heading 3', 'crazylinks' ) ],
					] ),
					advanced: true,
				},
				{
					key: 'color',
					label: __( 'Colour (this heading only)', 'crazylinks' ),
					kind: 'color',
					advanced: true,
				},
				{
					key: 'uppercase',
					label: __( 'Uppercase', 'crazylinks' ),
					kind: 'toggle',
					advanced: true,
				},
				{
					key: 'spacing',
					label: __( 'Space above', 'crazylinks' ),
					kind: 'select',
					options: opts( [
						[ '', __( 'Normal', 'crazylinks' ) ],
						[ 'tight', __( 'Tight', 'crazylinks' ) ],
						[ 'normal', __( 'Normal', 'crazylinks' ) ],
						[ 'loose', __( 'Loose', 'crazylinks' ) ],
					] ),
					advanced: true,
				},
			],
		},
		{
			type: 'text',
			label: __( 'Text', 'crazylinks' ),
			description: __(
				'A paragraph with basic formatting.',
				'crazylinks'
			),
			icon: 'editor-paragraph',
			category: 'content',
			defaults: () => ( { content: '' } ),
			summary: ( p ) => str( p.content ).replace( /<[^>]*>/g, '' ),
			fields: [
				{
					key: 'content',
					label: __( 'Content', 'crazylinks' ),
					kind: 'html',
					keep: true,
					maxLength: 4000,
					help: __(
						'Allowed HTML: p, strong, em, a, br.',
						'crazylinks'
					),
				},
				align(),
			],
		},
		{
			type: 'divider',
			label: __( 'Divider', 'crazylinks' ),
			description: __(
				'A line, dots or blank space between sections.',
				'crazylinks'
			),
			icon: 'minus',
			category: 'layout',
			defaults: () => ( { style: 'line' } ),
			summary: ( p ) => str( p.style ),
			fields: [
				{
					key: 'style',
					label: __( 'Style', 'crazylinks' ),
					kind: 'select',
					options: opts( [
						[ 'line', __( 'Line', 'crazylinks' ) ],
						[ 'dots', __( 'Dots', 'crazylinks' ) ],
						[ 'space', __( 'Space', 'crazylinks' ) ],
					] ),
				},
			],
		},
		{
			type: 'image',
			label: __( 'Image', 'crazylinks' ),
			description: __(
				'One image from the Media Library, optionally linked.',
				'crazylinks'
			),
			icon: 'format-image',
			category: 'media',
			trackable: true,
			defaults: () => ( {} ),
			summary: ( p ) => str( p.alt ) || str( p.caption ),
			fields: [
				{
					key: 'image',
					label: __( 'Image', 'crazylinks' ),
					kind: 'media',
					mediaType: 'image',
					required: true,
				},
				{
					key: 'alt',
					label: __( 'Alternative text', 'crazylinks' ),
					kind: 'text',
					maxLength: 250,
					help: __(
						'Describe the image for people who cannot see it.',
						'crazylinks'
					),
					showIf: ( p ) => p.decorative !== true,
				},
				{
					key: 'decorative',
					label: __( 'Decorative image', 'crazylinks' ),
					kind: 'toggle',
					help: __(
						'Screen readers skip it; no alternative text is needed.',
						'crazylinks'
					),
				},
				{
					key: 'url',
					label: __( 'Link URL', 'crazylinks' ),
					kind: 'url',
					maxLength: 2048,
				},
				{
					key: 'caption',
					label: __( 'Caption', 'crazylinks' ),
					kind: 'text',
					maxLength: 300,
				},
			],
		},
		{
			type: 'gallery',
			label: __( 'Gallery', 'crazylinks' ),
			description: __(
				'A grid of images, each optionally linked.',
				'crazylinks'
			),
			icon: 'format-gallery',
			category: 'media',
			defaults: () => ( { items: [], columns: 2, mode: 'grid' } ),
			summary: ( p ) =>
				Array.isArray( p.items )
					? /* translators: %d: number of images. */ __(
							'%d images',
							'crazylinks'
						).replace( '%d', String( p.items.length ) )
					: '',
			fields: [
				{
					key: 'items',
					label: __( 'Images', 'crazylinks' ),
					kind: 'items',
					keep: true,
					itemLabel: __( 'image', 'crazylinks' ),
					maxItems: 24,
					itemFields: [
						{
							key: 'image',
							label: __( 'Image', 'crazylinks' ),
							kind: 'media',
							mediaType: 'image',
							required: true,
						},
						{
							key: 'alt',
							label: __( 'Alternative text', 'crazylinks' ),
							kind: 'text',
							maxLength: 250,
							showIf: ( p ) => p.decorative !== true,
						},
						{
							key: 'decorative',
							label: __( 'Decorative image', 'crazylinks' ),
							kind: 'toggle',
						},
						{
							key: 'url',
							label: __( 'Link URL', 'crazylinks' ),
							kind: 'url',
							maxLength: 2048,
						},
						{
							key: 'caption',
							label: __( 'Caption', 'crazylinks' ),
							kind: 'text',
							maxLength: 300,
						},
					],
				},
				{
					key: 'columns',
					label: __( 'Columns', 'crazylinks' ),
					kind: 'number',
					min: 2,
					max: 3,
				},
				{
					key: 'mode',
					label: __( 'Mode', 'crazylinks' ),
					kind: 'select',
					options: opts( [
						[ 'grid', __( 'Grid', 'crazylinks' ) ],
						[
							'carousel',
							__(
								'Carousel (renders as a grid for now)',
								'crazylinks'
							),
						],
					] ),
				},
			],
		},
		{
			type: 'latest_posts',
			label: __( 'Latest posts', 'crazylinks' ),
			description: __(
				'Your newest blog posts as cards, updated automatically.',
				'crazylinks'
			),
			icon: 'admin-post',
			category: 'content',
			defaults: () => ( { count: 3, display: 'featured' } ),
			summary: ( p ) =>
				/* translators: %d: number of posts. */ __(
					'%d newest posts',
					'crazylinks'
				).replace(
					'%d',
					String( typeof p.count === 'number' ? p.count : 3 )
				),
			fields: [
				{
					key: 'count',
					label: __( 'Number of posts', 'crazylinks' ),
					kind: 'number',
					min: 1,
					max: 12,
				},
				{
					key: 'category',
					label: __( 'Category ID', 'crazylinks' ),
					kind: 'number',
					min: 0,
					help: __( '0 or empty for all categories.', 'crazylinks' ),
				},
				{
					key: 'display',
					label: __( 'Display', 'crazylinks' ),
					kind: 'select',
					options: opts( [
						[ 'featured', __( 'Featured cards', 'crazylinks' ) ],
						[ 'classic', __( 'Classic buttons', 'crazylinks' ) ],
					] ),
				},
				{
					key: 'post_type',
					label: __( 'Post type', 'crazylinks' ),
					kind: 'text',
					maxLength: 40,
					advanced: true,
					placeholder: 'post',
				},
			],
		},
		{
			type: 'embed',
			label: __( 'Embed', 'crazylinks' ),
			description: __(
				'YouTube, Spotify, Vimeo, SoundCloud and other oEmbed URLs.',
				'crazylinks'
			),
			icon: 'embed-generic',
			category: 'media',
			defaults: () => ( { url: '' } ),
			summary: ( p ) => str( p.title ) || str( p.url ),
			fields: [
				{
					key: 'url',
					label: __( 'URL', 'crazylinks' ),
					kind: 'url',
					required: true,
					maxLength: 2048,
					placeholder: 'https://',
				},
				{
					key: 'title',
					label: __( 'Accessible title', 'crazylinks' ),
					kind: 'text',
					maxLength: 200,
				},
			],
		},
		{
			type: 'video',
			label: __( 'Video', 'crazylinks' ),
			description: __(
				'A video from the Media Library or a URL.',
				'crazylinks'
			),
			icon: 'format-video',
			category: 'media',
			defaults: () => ( {} ),
			summary: ( p ) => str( p.title ) || str( p.url ),
			incomplete: ( p ) =>
				! p.video && ! str( p.url )
					? [
							'url',
							__(
								'Choose a video or enter a URL.',
								'crazylinks'
							),
						]
					: null,
			fields: [
				{
					key: 'video',
					label: __( 'Video file', 'crazylinks' ),
					kind: 'media',
					mediaType: 'video',
				},
				{
					key: 'url',
					label: __( 'Or video URL', 'crazylinks' ),
					kind: 'url',
					maxLength: 2048,
				},
				{
					key: 'poster',
					label: __( 'Poster image', 'crazylinks' ),
					kind: 'media',
					mediaType: 'image',
				},
				{
					key: 'title',
					label: __( 'Title', 'crazylinks' ),
					kind: 'text',
					maxLength: 200,
				},
				{
					key: 'autoplay',
					label: __( 'Autoplay (muted)', 'crazylinks' ),
					kind: 'toggle',
					advanced: true,
				},
				{
					key: 'loop',
					label: __( 'Loop', 'crazylinks' ),
					kind: 'toggle',
					advanced: true,
				},
				{
					key: 'muted',
					label: __( 'Muted', 'crazylinks' ),
					kind: 'toggle',
					advanced: true,
				},
			],
		},
		{
			type: 'email_capture',
			label: __( 'Email sign-up', 'crazylinks' ),
			description: __(
				'Collect email addresses with a consent line.',
				'crazylinks'
			),
			icon: 'email',
			category: 'content',
			defaults: () => ( {
				heading: __( 'Join my list', 'crazylinks' ),
				button_text: __( 'Subscribe', 'crazylinks' ),
				destination: 'local',
			} ),
			summary: ( p ) => str( p.heading ),
			fields: [
				{
					key: 'heading',
					label: __( 'Heading', 'crazylinks' ),
					kind: 'text',
					maxLength: 120,
				},
				{
					key: 'placeholder',
					label: __( 'Field placeholder', 'crazylinks' ),
					kind: 'text',
					maxLength: 80,
				},
				{
					key: 'button_text',
					label: __( 'Button text', 'crazylinks' ),
					kind: 'text',
					maxLength: 40,
				},
				{
					key: 'consent_text',
					label: __( 'Consent text', 'crazylinks' ),
					kind: 'textarea',
					maxLength: 500,
				},
				{
					key: 'pending_text',
					label: __(
						'Message after sign-up (check your inbox)',
						'crazylinks'
					),
					kind: 'text',
					maxLength: 200,
					placeholder: __(
						'Check your inbox and click the link we sent to confirm your email.',
						'crazylinks'
					),
				},
				{
					key: 'success_text',
					label: __( 'Message after confirming', 'crazylinks' ),
					kind: 'text',
					maxLength: 200,
					placeholder: __(
						'You are in. Thanks for subscribing!',
						'crazylinks'
					),
				},
				{
					key: 'tags',
					label: __( 'Tags', 'crazylinks' ),
					kind: 'tags',
					help: __( 'Comma-separated.', 'crazylinks' ),
					advanced: true,
				},
			],
		},
		{
			type: 'contact',
			label: __( 'Contact', 'crazylinks' ),
			description: __(
				'Phone, email and address, with an optional vCard download.',
				'crazylinks'
			),
			icon: 'phone',
			category: 'content',
			defaults: () => ( { vcard: false } ),
			summary: ( p ) =>
				[ str( p.email ), str( p.phone ) ]
					.filter( Boolean )
					.join( ' · ' ),
			fields: [
				{
					key: 'phone',
					label: __( 'Phone', 'crazylinks' ),
					kind: 'text',
					maxLength: 40,
				},
				{
					key: 'email',
					label: __( 'Email', 'crazylinks' ),
					kind: 'text',
					maxLength: 254,
				},
				{
					key: 'address',
					label: __( 'Address', 'crazylinks' ),
					kind: 'textarea',
					maxLength: 300,
				},
				{
					key: 'vcard',
					label: __( 'Offer a vCard download', 'crazylinks' ),
					kind: 'toggle',
				},
			],
		},
		{
			type: 'group',
			label: __( 'Section', 'crazylinks' ),
			description: __(
				'Holds other blocks, with its own heading, background and layout. Hide or schedule it to hide everything inside.',
				'crazylinks'
			),
			icon: 'category',
			category: 'layout',
			defaults: () => ( { heading: '' } ),
			summary: ( p ) => str( p.heading ) || str( p.title ),
			fields: [
				{
					key: 'heading',
					label: __( 'Heading', 'crazylinks' ),
					kind: 'text',
					maxLength: 120,
				},
				{
					key: 'description',
					label: __( 'Description', 'crazylinks' ),
					kind: 'textarea',
					maxLength: 280,
				},
				{
					key: 'title',
					label: __( 'Title (older groups)', 'crazylinks' ),
					kind: 'text',
					maxLength: 120,
					help: __(
						'Used when the heading is empty. Clear it and use Heading instead.',
						'crazylinks'
					),
					showIf: ( p ) => !! str( p.title ),
				},
				{
					key: 'collapsible',
					label: __( 'Collapsible', 'crazylinks' ),
					kind: 'toggle',
					help: __(
						'Visitors tap the heading to open or close it. Needs a heading.',
						'crazylinks'
					),
				},
				{
					key: 'collapsed',
					label: __( 'Start closed', 'crazylinks' ),
					kind: 'toggle',
					showIf: ( p ) =>
						p.collapsible === true ||
						( p.collapsible === undefined && !! str( p.title ) ),
				},
				{
					key: 'layout',
					label: __( 'Layout', 'crazylinks' ),
					kind: 'select',
					options: opts( [
						[ '', __( 'List', 'crazylinks' ) ],
						[ 'grid', __( 'Grid (two columns)', 'crazylinks' ) ],
					] ),
				},
				{
					key: 'background/type',
					label: __( 'Background', 'crazylinks' ),
					kind: 'select',
					clearsParent: true,
					options: opts( [
						[ '', __( 'None (page background)', 'crazylinks' ) ],
						[ 'solid', __( 'Solid colour', 'crazylinks' ) ],
						[ 'gradient', __( 'Gradient', 'crazylinks' ) ],
						[ 'image', __( 'Image', 'crazylinks' ) ],
						[ 'pattern', __( 'Pattern', 'crazylinks' ) ],
					] ),
				},
				{
					key: 'background/color',
					label: __( 'Background colour', 'crazylinks' ),
					kind: 'color',
					showIf: ( p ) =>
						[ 'solid', 'image', 'pattern' ].includes( bgType( p ) ),
				},
				{
					key: 'background/from',
					label: __( 'Gradient from', 'crazylinks' ),
					kind: 'color',
					showIf: ( p ) => bgType( p ) === 'gradient',
				},
				{
					key: 'background/to',
					label: __( 'Gradient to', 'crazylinks' ),
					kind: 'color',
					showIf: ( p ) => bgType( p ) === 'gradient',
				},
				{
					key: 'background/angle',
					label: __( 'Gradient angle', 'crazylinks' ),
					kind: 'number',
					min: 0,
					max: 360,
					showIf: ( p ) => bgType( p ) === 'gradient',
				},
				{
					key: 'background/radial',
					label: __( 'Radial gradient', 'crazylinks' ),
					kind: 'toggle',
					showIf: ( p ) => bgType( p ) === 'gradient',
				},
				{
					key: 'background/image',
					label: __( 'Background image', 'crazylinks' ),
					kind: 'media',
					mediaType: 'image',
					showIf: ( p ) => bgType( p ) === 'image',
				},
				{
					key: 'background/overlay',
					label: __( 'Overlay colour', 'crazylinks' ),
					kind: 'color',
					help: __(
						'Darkens or tints the image so text stays readable.',
						'crazylinks'
					),
					showIf: ( p ) => bgType( p ) === 'image',
				},
				{
					key: 'background/pattern',
					label: __( 'Pattern', 'crazylinks' ),
					kind: 'select',
					options: opts( [
						[ '', __( 'Dots', 'crazylinks' ) ],
						[ 'grid', __( 'Grid', 'crazylinks' ) ],
						[ 'stripes', __( 'Stripes', 'crazylinks' ) ],
						[ 'lines', __( 'Lines', 'crazylinks' ) ],
						[ 'checks', __( 'Checks', 'crazylinks' ) ],
						[ 'noise', __( 'Noise', 'crazylinks' ) ],
					] ),
					showIf: ( p ) => bgType( p ) === 'pattern',
				},
				{
					key: 'background/pattern_color',
					label: __( 'Pattern colour', 'crazylinks' ),
					kind: 'color',
					showIf: ( p ) => bgType( p ) === 'pattern',
				},
				{
					key: 'text_color',
					label: __( 'Text colour', 'crazylinks' ),
					kind: 'color',
					help: __(
						'Blocks inside use this colour. Left empty, a section with a background picks a readable colour.',
						'crazylinks'
					),
				},
				{
					key: 'padding',
					label: __( 'Padding', 'crazylinks' ),
					kind: 'select',
					options: opts( [
						[ '', __( 'Automatic', 'crazylinks' ) ],
						[ 'none', __( 'None', 'crazylinks' ) ],
						[ 'sm', __( 'Small', 'crazylinks' ) ],
						[ 'md', __( 'Medium', 'crazylinks' ) ],
						[ 'lg', __( 'Large', 'crazylinks' ) ],
					] ),
					advanced: true,
				},
				{
					key: 'radius',
					label: __( 'Corners', 'crazylinks' ),
					kind: 'select',
					options: opts( [
						[ '', __( 'Same as buttons', 'crazylinks' ) ],
						[ 'none', __( 'Square', 'crazylinks' ) ],
						[ 'sm', __( 'Slightly rounded', 'crazylinks' ) ],
						[ 'md', __( 'Rounded', 'crazylinks' ) ],
						[ 'lg', __( 'Very rounded', 'crazylinks' ) ],
					] ),
					advanced: true,
				},
			],
		},
		{
			type: 'woo_products',
			label: __( 'Products', 'crazylinks' ),
			description: __(
				'WooCommerce products by id or category.',
				'crazylinks'
			),
			icon: 'cart',
			category: 'commerce',
			defaults: () => ( { layout: 'grid' } ),
			summary: ( p ) =>
				Array.isArray( p.product_ids )
					? p.product_ids.join( ', ' )
					: '',
			fields: [
				{
					key: 'product_ids',
					label: __( 'Product IDs', 'crazylinks' ),
					kind: 'numbers',
					help: __( 'Comma-separated.', 'crazylinks' ),
				},
				{
					key: 'category',
					label: __( 'Or product category ID', 'crazylinks' ),
					kind: 'number',
					min: 0,
				},
				{
					key: 'layout',
					label: __( 'Layout', 'crazylinks' ),
					kind: 'select',
					options: opts( [
						[ 'grid', __( 'Grid', 'crazylinks' ) ],
						[ 'list', __( 'List', 'crazylinks' ) ],
					] ),
				},
			],
		},
		{
			type: 'payment',
			label: __( 'Payment or tip', 'crazylinks' ),
			description: __(
				'Buy Me a Coffee, Ko-fi, PayPal, Venmo, Cash App, Stripe and more.',
				'crazylinks'
			),
			icon: 'money-alt',
			category: 'commerce',
			trackable: true,
			defaults: () => ( { service: 'buymeacoffee' } ),
			summary: ( p ) => str( p.label ) || str( p.service ),
			incomplete: ( p ) =>
				! str( p.handle ) && ! str( p.url )
					? [
							'url',
							__( 'Enter your handle or a URL.', 'crazylinks' ),
						]
					: null,
			fields: [
				{
					key: 'service',
					label: __( 'Service', 'crazylinks' ),
					kind: 'select',
					required: true,
				},
				{
					key: 'handle',
					label: __( 'Handle', 'crazylinks' ),
					kind: 'text',
					maxLength: 80,
					showIf: ( p ) =>
						p.service !== 'custom' && p.service !== 'stripe_link',
				},
				{
					key: 'url',
					label: __( 'Or full URL', 'crazylinks' ),
					kind: 'url',
					maxLength: 2048,
				},
				{
					key: 'label',
					label: __( 'Button label', 'crazylinks' ),
					kind: 'text',
					maxLength: 80,
				},
				{
					key: 'amount_hint',
					label: __( 'Amount hint', 'crazylinks' ),
					kind: 'text',
					maxLength: 40,
					placeholder: '$5',
				},
			],
		},
		{
			type: 'html',
			label: __( 'Custom HTML', 'crazylinks' ),
			description: __(
				'Raw HTML. Only for users allowed to post unfiltered HTML.',
				'crazylinks'
			),
			icon: 'editor-code',
			category: 'advanced',
			defaults: () => ( { html: '' } ),
			summary: ( p ) => str( p.html ).slice( 0, 60 ),
			fields: [
				{
					key: 'html',
					label: __( 'HTML', 'crazylinks' ),
					kind: 'textarea',
					keep: true,
					maxLength: 20000,
				},
			],
		},
		{
			type: 'spacer',
			label: __( 'Spacer', 'crazylinks' ),
			description: __( 'Empty vertical space.', 'crazylinks' ),
			icon: 'image-flip-vertical',
			category: 'layout',
			defaults: () => ( { height: 24 } ),
			summary: ( p ) =>
				typeof p.height === 'number' ? `${ p.height }px` : '',
			fields: [
				{
					key: 'height',
					label: __( 'Height (px)', 'crazylinks' ),
					kind: 'number',
					min: 4,
					max: 200,
				},
			],
		},
		{
			type: 'countdown',
			label: __( 'Countdown', 'crazylinks' ),
			description: __(
				'Counts down to a date, then shows a link.',
				'crazylinks'
			),
			icon: 'clock',
			category: 'content',
			defaults: () => ( { target: inDays( 7 ) } ),
			summary: ( p ) => str( p.label ) || str( p.target ),
			fields: [
				{
					key: 'target',
					label: __( 'Counts down to', 'crazylinks' ),
					kind: 'datetime',
					required: true,
				},
				{
					key: 'label',
					label: __( 'Label', 'crazylinks' ),
					kind: 'text',
					maxLength: 120,
				},
				{
					key: 'expired_text',
					label: __( 'Text after the end', 'crazylinks' ),
					kind: 'text',
					maxLength: 120,
				},
				{
					key: 'url',
					label: __( 'Link after the end', 'crazylinks' ),
					kind: 'url',
					maxLength: 2048,
				},
				{
					key: 'link_label',
					label: __( 'Link label', 'crazylinks' ),
					kind: 'text',
					maxLength: 80,
				},
			],
		},
		{
			type: 'map',
			label: __( 'Map link', 'crazylinks' ),
			description: __(
				'An address that opens in Google or Apple Maps.',
				'crazylinks'
			),
			icon: 'location',
			category: 'content',
			defaults: () => ( { address: '', provider: 'both' } ),
			summary: ( p ) => str( p.label ) || str( p.address ),
			fields: [
				{
					key: 'address',
					label: __( 'Address', 'crazylinks' ),
					kind: 'textarea',
					required: true,
					maxLength: 300,
				},
				{
					key: 'label',
					label: __( 'Label', 'crazylinks' ),
					kind: 'text',
					maxLength: 120,
				},
				{
					key: 'provider',
					label: __( 'Open in', 'crazylinks' ),
					kind: 'select',
					options: opts( [
						[ 'both', __( 'Google and Apple Maps', 'crazylinks' ) ],
						[ 'google', __( 'Google Maps', 'crazylinks' ) ],
						[ 'apple', __( 'Apple Maps', 'crazylinks' ) ],
					] ),
				},
			],
		},
	];
	cache = {};
	for ( const def of defs ) {
		cache[ def.type ] = def;
	}
	return cache;
}

/**
 * Definition for a type, or a generic fallback for types added to the schema later.
 *
 * @param type Block type.
 */
export function blockDef( type: string ): BlockDef {
	const def = blockDefs()[ type ];
	if ( def ) {
		return def;
	}
	return {
		type: type as BlockType,
		label: type,
		description: __( 'Edit this block in the JSON panel.', 'crazylinks' ),
		icon: 'block-default',
		category: 'advanced',
		fields: [],
		defaults: () => ( {} ),
		summary: () => '',
	};
}

/**
 * Category labels for the add-block drawer.
 */
export function categoryLabels(): Record< BlockCategory, string > {
	return {
		links: __( 'Links', 'crazylinks' ),
		content: __( 'Content', 'crazylinks' ),
		media: __( 'Media', 'crazylinks' ),
		commerce: __( 'Commerce', 'crazylinks' ),
		layout: __( 'Layout', 'crazylinks' ),
		advanced: __( 'Advanced', 'crazylinks' ),
	};
}

/**
 * Block types listed by the schema (single source of truth), in schema order.
 *
 * @param schema Decoded page.v1.json.
 */
export function schemaBlockTypes( schema: JsonObject ): string[] {
	const defs = schema.$defs as JsonObject | undefined;
	const block = defs?.block as JsonObject | undefined;
	const props = block?.properties as JsonObject | undefined;
	const type = props?.type as JsonObject | undefined;
	const list = type?.enum;
	return Array.isArray( list )
		? list.filter( ( v ): v is string => typeof v === 'string' )
		: Object.keys( blockDefs() );
}

/**
 * Missing required fields for one block: [field path relative to the block, message][].
 *
 * @param type  Block type.
 * @param props Props.
 */
export function missingFields(
	type: string,
	props: JsonObject
): Array< [ string, string ] > {
	const def = blockDef( type );
	const out: Array< [ string, string ] > = [];
	for ( const field of def.fields ) {
		const v = props[ field.key ];
		const empty =
			v === undefined ||
			v === null ||
			( typeof v === 'string' && v.trim() === '' ) ||
			( field.kind === 'media' && ! v );
		if ( field.required && empty ) {
			out.push( [
				`props/${ field.key }`,
				__( 'This field is required.', 'crazylinks' ),
			] );
		}
		if (
			field.kind === 'items' &&
			Array.isArray( v ) &&
			field.itemFields
		) {
			v.forEach( ( item, i ) => {
				const obj =
					item && typeof item === 'object' && ! Array.isArray( item )
						? ( item as JsonObject )
						: {};
				for ( const sub of field.itemFields ?? [] ) {
					const sv = obj[ sub.key ];
					if (
						sub.required &&
						( sv === undefined || sv === '' || sv === 0 )
					) {
						out.push( [
							`props/${ field.key }/${ i }/${ sub.key }`,
							__( 'This field is required.', 'crazylinks' ),
						] );
					}
				}
			} );
		}
	}
	const extra = def.incomplete?.( props );
	if ( extra ) {
		out.push( [ `props/${ extra[ 0 ] }`, extra[ 1 ] ] );
	}
	return out;
}
