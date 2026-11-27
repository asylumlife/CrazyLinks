/**
 * Pure operations on the page document. Every function returns a new document whose `blocks` array is
 * in canonical order (top-level blocks by order, each group followed by its children, `order`
 * renumbered per parent), the same order PageValidator::reorder() stores.
 */
import type {
	Animation,
	Block,
	BlockType,
	JsonObject,
	PageDoc,
	WeeklyWindow,
} from '../types';
import { blockDef } from '../registry/blocks';
import { asObject, isPlainObject } from '../lib/paths';
import { ulid } from '../lib/ulid';

export const emptyDoc = (): PageDoc => ( {
	schema: 1,
	profile: {},
	design: {},
	blocks: [],
	settings: {},
} );

/**
 * Coerces whatever the server or a pasted JSON gave us into the document shape.
 *
 * @param input Anything.
 */
export function coerceDoc( input: unknown ): PageDoc {
	const src: Record< string, unknown > = isPlainObject( input ) ? input : {};
	const blocks = Array.isArray( src.blocks ) ? src.blocks : [];
	return normalize( {
		schema: 1,
		profile: asObject( src.profile ),
		design: asObject( src.design ),
		settings: asObject( src.settings ),
		blocks: blocks
			.filter(
				( b ): b is Record< string, unknown > =>
					!! b && typeof b === 'object'
			)
			.map( ( b, i ) => fillBlock( b, i ) ),
	} );
}

function fillBlock( b: Record< string, unknown >, i: number ): Block {
	const schedule: Record< string, unknown > =
		b.schedule && typeof b.schedule === 'object'
			? ( b.schedule as Record< string, unknown > )
			: {};
	// Only the window's shape is checked here; the server validates its days and times.
	const weekly = schedule.weekly as WeeklyWindow | undefined;
	return {
		...( b as unknown as Block ),
		id: typeof b.id === 'string' && b.id ? b.id : ulid(),
		type: String( b.type ) as BlockType,
		parent_id:
			typeof b.parent_id === 'string' && b.parent_id ? b.parent_id : null,
		order: typeof b.order === 'number' ? b.order : i,
		visible: b.visible !== false,
		schedule: {
			start: typeof schedule.start === 'string' ? schedule.start : null,
			end: typeof schedule.end === 'string' ? schedule.end : null,
			...( isPlainObject( weekly ) ? { weekly } : {} ),
		},
		pinned: b.pinned === true,
		animation: ( typeof b.animation === 'string'
			? b.animation
			: 'none' ) as Animation,
		tracking: b.tracking !== false,
		props: asObject( b.props ),
	};
}

/**
 * Canonical order. Children whose parent is missing are promoted to the top level.
 *
 * @param doc Document.
 */
export function normalize( doc: PageDoc ): PageDoc {
	const groups = new Set(
		doc.blocks.filter( ( b ) => b.type === 'group' ).map( ( b ) => b.id )
	);
	const blocks = doc.blocks.map( ( b ) =>
		b.parent_id && ( ! groups.has( b.parent_id ) || b.type === 'group' )
			? { ...b, parent_id: null }
			: b
	);
	const byParent = new Map< string, Array< [ number, Block ] > >();
	blocks.forEach( ( b, i ) => {
		const key = b.parent_id ?? '';
		if ( ! byParent.has( key ) ) {
			byParent.set( key, [] );
		}
		byParent.get( key )!.push( [ i, b ] );
	} );
	const sorted = ( key: string ) =>
		( byParent.get( key ) ?? [] )
			.slice()
			.sort( ( a, b ) => a[ 1 ].order - b[ 1 ].order || a[ 0 ] - b[ 0 ] )
			.map( ( x ) => x[ 1 ] );
	const out: Block[] = [];
	sorted( '' ).forEach( ( b, n ) => {
		out.push( b.order === n ? b : { ...b, order: n } );
		if ( b.type === 'group' ) {
			sorted( b.id ).forEach( ( c, m ) =>
				out.push( c.order === m ? c : { ...c, order: m } )
			);
		}
	} );
	return { ...doc, blocks: out };
}

/**
 * A new block of a type with registry defaults.
 *
 * @param type     Block type.
 * @param parentId Group id or null.
 */
export function createBlock(
	type: string,
	parentId: string | null = null
): Block {
	return {
		id: ulid(),
		type: type as BlockType,
		parent_id: type === 'group' ? null : parentId,
		order: Number.MAX_SAFE_INTEGER,
		visible: true,
		schedule: { start: null, end: null },
		pinned: false,
		animation: 'none',
		tracking: true,
		props: blockDef( type ).defaults(),
	};
}

/**
 * The blocks under one parent, in order.
 *
 * @param blocks   Flat block list.
 * @param parentId Group id, or null for the top level.
 */
export function siblingsIn(
	blocks: Block[],
	parentId: string | null
): Block[] {
	return blocks
		.filter( ( b ) => b.parent_id === parentId )
		.sort( ( a, b ) => a.order - b.order );
}

/** Siblings of a block (same parent), in order. */
export function siblings( doc: PageDoc, parentId: string | null ): Block[] {
	return siblingsIn( doc.blocks, parentId );
}

/** Whether a block has any schedule: a start, an end or a weekly window. */
export function isScheduled( block: Block ): boolean {
	return !! (
		block.schedule.start ||
		block.schedule.end ||
		block.schedule.weekly
	);
}

/**
 * Inserts a block at the end of its parent, or right after `afterId` when given (same parent).
 *
 * @param doc     Document.
 * @param block   Block.
 * @param afterId Sibling to insert after.
 */
export function insertBlock(
	doc: PageDoc,
	block: Block,
	afterId?: string | null
): PageDoc {
	const sibs = siblings( doc, block.parent_id );
	const after = afterId ? sibs.findIndex( ( b ) => b.id === afterId ) : -1;
	const ids = sibs.map( ( b ) => b.id );
	ids.splice( after >= 0 ? after + 1 : ids.length, 0, block.id );
	return applyOrder(
		{ ...doc, blocks: [ ...doc.blocks, block ] },
		block.parent_id,
		ids
	);
}

/**
 * Sets `order` for the siblings under `parentId` to match `ids`.
 *
 * @param doc      Document.
 * @param parentId Parent.
 * @param ids      Sibling ids in the new order.
 */
export function applyOrder(
	doc: PageDoc,
	parentId: string | null,
	ids: string[]
): PageDoc {
	const pos = new Map( ids.map( ( id, i ) => [ id, i ] ) );
	return normalize( {
		...doc,
		blocks: doc.blocks.map( ( b ) =>
			b.parent_id === parentId && pos.has( b.id )
				? { ...b, order: pos.get( b.id )! }
				: b
		),
	} );
}

/**
 * Moves a block one step up (-1) or down (+1) among its siblings.
 *
 * @param doc   Document.
 * @param id    Block id.
 * @param delta -1 or +1.
 */
export function moveBlock( doc: PageDoc, id: string, delta: number ): PageDoc {
	const block = doc.blocks.find( ( b ) => b.id === id );
	if ( ! block ) {
		return doc;
	}
	const ids = siblings( doc, block.parent_id ).map( ( b ) => b.id );
	const from = ids.indexOf( id );
	const to = Math.max( 0, Math.min( ids.length - 1, from + delta ) );
	if ( from === to ) {
		return doc;
	}
	ids.splice( from, 1 );
	ids.splice( to, 0, id );
	return applyOrder( doc, block.parent_id, ids );
}

/**
 * Moves a block to a position in a list: a group's children or the top level. Groups cannot nest.
 *
 * @param doc      Document.
 * @param id       Block id.
 * @param parentId Group id or null.
 * @param index    Position among that list's blocks, with the moved block removed from it first.
 */
export function moveTo(
	doc: PageDoc,
	id: string,
	parentId: string | null,
	index: number
): PageDoc {
	const block = doc.blocks.find( ( b ) => b.id === id );
	if ( ! block ) {
		return doc;
	}
	if ( parentId !== null ) {
		const isGroup = doc.blocks.some(
			( b ) => b.id === parentId && b.type === 'group'
		);
		if ( block.type === 'group' || ! isGroup ) {
			return doc;
		}
	}
	const ids = siblings( doc, parentId )
		.map( ( b ) => b.id )
		.filter( ( x ) => x !== id );
	ids.splice( Math.max( 0, Math.min( ids.length, index ) ), 0, id );
	const moved: PageDoc = {
		...doc,
		blocks: doc.blocks.map( ( b ) =>
			b.id === id ? { ...b, parent_id: parentId } : b
		),
	};
	return applyOrder( moved, parentId, ids );
}

/**
 * Moves a block into a group (or to the top level with null), at the end. Groups cannot nest.
 *
 * @param doc      Document.
 * @param id       Block id.
 * @param parentId Group id or null.
 */
export function setParent(
	doc: PageDoc,
	id: string,
	parentId: string | null
): PageDoc {
	const block = doc.blocks.find( ( b ) => b.id === id );
	if ( ! block || block.parent_id === parentId ) {
		return doc;
	}
	if ( block.type === 'group' && parentId !== null ) {
		return doc;
	}
	if (
		parentId !== null &&
		! doc.blocks.some( ( b ) => b.id === parentId && b.type === 'group' )
	) {
		return doc;
	}
	return normalize( {
		...doc,
		blocks: doc.blocks.map( ( b ) =>
			b.id === id
				? { ...b, parent_id: parentId, order: Number.MAX_SAFE_INTEGER }
				: b
		),
	} );
}

/**
 * Merges top-level block fields (visible, pinned, schedule, …).
 *
 * @param doc   Document.
 * @param id    Block id.
 * @param patch Fields.
 */
export function updateBlock(
	doc: PageDoc,
	id: string,
	patch: Partial< Omit< Block, 'id' | 'type' > >
): PageDoc {
	return {
		...doc,
		blocks: doc.blocks.map( ( b ) =>
			b.id === id ? { ...b, ...patch } : b
		),
	};
}

/**
 * Flips a block's `pinned` flag (pinned top-level blocks float to the top of the page).
 *
 * @param doc Document.
 * @param id  Block id.
 */
export function togglePinned( doc: PageDoc, id: string ): PageDoc {
	const block = doc.blocks.find( ( b ) => b.id === id );
	return block ? updateBlock( doc, id, { pinned: ! block.pinned } ) : doc;
}

/**
 * Replaces a block's props.
 *
 * @param doc   Document.
 * @param id    Block id.
 * @param props Props.
 */
export function setProps(
	doc: PageDoc,
	id: string,
	props: JsonObject
): PageDoc {
	return updateBlock( doc, id, { props } );
}

/**
 * Removes a block; removing a group removes its children too.
 *
 * @param doc Document.
 * @param id  Block id.
 */
export function removeBlock( doc: PageDoc, id: string ): PageDoc {
	return normalize( {
		...doc,
		blocks: doc.blocks.filter( ( b ) => b.id !== id && b.parent_id !== id ),
	} );
}

/**
 * Duplicates a block (and a group's children) right after the original.
 *
 * @param doc Document.
 * @param id  Block id.
 * @return The new document and the copy's id.
 */
export function duplicateBlock(
	doc: PageDoc,
	id: string
): { doc: PageDoc; id: string | null } {
	const block = doc.blocks.find( ( b ) => b.id === id );
	if ( ! block ) {
		return { doc, id: null };
	}
	const copy: Block = {
		...block,
		id: ulid(),
		props: deepCopy( block.props ),
	};
	let next = insertBlock( doc, copy, block.id );
	if ( block.type === 'group' ) {
		const children = siblings( doc, block.id ).map( ( c ) => ( {
			...c,
			id: ulid(),
			parent_id: copy.id,
			props: deepCopy( c.props ),
		} ) );
		next = normalize( {
			...next,
			blocks: [ ...next.blocks, ...children ],
		} );
	}
	return { doc: next, id: copy.id };
}

function deepCopy< T >( v: T ): T {
	return JSON.parse( JSON.stringify( v ) ) as T;
}

/**
 * Whether a document has no content yet (drives the onboarding wizard).
 *
 * @param doc Document.
 */
export function isEmptyDoc( doc: PageDoc ): boolean {
	return (
		doc.blocks.length === 0 &&
		! doc.profile.name &&
		! doc.profile.bio &&
		! doc.profile.avatar
	);
}

/**
 * The document with incomplete blocks (missing required fields) left out, for live previews.
 *
 * @param doc        Document.
 * @param incomplete Ids to drop.
 */
export function withoutBlocks(
	doc: PageDoc,
	incomplete: Set< string >
): PageDoc {
	if ( incomplete.size === 0 ) {
		return doc;
	}
	return normalize( {
		...doc,
		blocks: doc.blocks.filter(
			( b ) =>
				! incomplete.has( b.id ) &&
				! ( b.parent_id && incomplete.has( b.parent_id ) )
		),
	} );
}
