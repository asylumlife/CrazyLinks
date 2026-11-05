/**
 * Non-blocking problems found client-side (SPEC §5A.6): images without alternative text. Mirrors
 * PageValidator::warnings_for(), so the editor shows them as soon as they appear and the Publish
 * button can ask for confirmation. The effective alt text is the block's own text, else the Media
 * Library's, which the renderer falls back to as well.
 */
import { __ } from '@wordpress/i18n';
import type { JsonObject, MediaInfo, PageDoc } from '../types';

export interface MappedWarning {
	/** Block id when the warning belongs to a block. */
	blockId?: string;
	/** Path relative to the block (e.g. `props/alt`), or the document path for page-level ones. */
	field: string;
	message: string;
}

type MediaMap = Record< number, MediaInfo >;

const asObj = ( v: unknown ): JsonObject =>
	v && typeof v === 'object' && ! Array.isArray( v )
		? ( v as JsonObject )
		: {};

function lacksAlt(
	attachment: unknown,
	alt: unknown,
	decorative: unknown,
	media: MediaMap
): boolean {
	if (
		typeof attachment !== 'number' ||
		attachment <= 0 ||
		decorative === true ||
		( typeof alt === 'string' && alt.trim() !== '' )
	) {
		return false;
	}
	return ( media[ attachment ]?.alt ?? '' ).trim() === '';
}

/**
 * Alt-text warnings for a document.
 *
 * @param doc   Document.
 * @param media Media info by attachment id (carries the Media Library alt text).
 */
export function altWarnings( doc: PageDoc, media: MediaMap ): MappedWarning[] {
	const out: MappedWarning[] = [];
	const profile = doc.profile;
	if (
		typeof profile.avatar === 'number' &&
		profile.avatar > 0 &&
		( typeof profile.name !== 'string' || profile.name.trim() === '' )
	) {
		out.push( {
			field: '/profile/name',
			message: __(
				'The profile picture uses your name as its description. Add a name.',
				'crazylinks'
			),
		} );
	}
	for ( const block of doc.blocks ) {
		const p = block.props;
		if (
			block.type === 'image' &&
			lacksAlt( p.image, p.alt, p.decorative, media )
		) {
			out.push( {
				blockId: block.id,
				field: 'props/alt',
				message: __(
					'This image has no alternative text. Describe it, or mark it decorative.',
					'crazylinks'
				),
			} );
		} else if ( block.type === 'gallery' && Array.isArray( p.items ) ) {
			p.items.forEach( ( raw, n ) => {
				const item = asObj( raw );
				if (
					lacksAlt( item.image, item.alt, item.decorative, media )
				) {
					out.push( {
						blockId: block.id,
						field: `props/items/${ n }/alt`,
						message: __(
							'This gallery image has no alternative text. Describe it, or mark it decorative.',
							'crazylinks'
						),
					} );
				}
			} );
		} else if (
			block.type === 'link' &&
			lacksAlt( p.thumbnail, p.thumbnail_alt, p.decorative, media )
		) {
			out.push( {
				blockId: block.id,
				field: 'props/thumbnail_alt',
				message: __(
					'This link image has no alternative text. Describe it, or mark it decorative.',
					'crazylinks'
				),
			} );
		}
	}
	return out;
}

/**
 * Warnings for one block, optionally narrowed to a field (exact match or a sub-path).
 *
 * @param warnings Warnings.
 * @param blockId  Block id.
 * @param field    Field path relative to the block.
 */
export function blockWarnings(
	warnings: MappedWarning[],
	blockId: string,
	field?: string
): MappedWarning[] {
	return warnings.filter(
		( w ) =>
			w.blockId === blockId &&
			( field === undefined ||
				w.field === field ||
				w.field.startsWith( field + '/' ) )
	);
}
