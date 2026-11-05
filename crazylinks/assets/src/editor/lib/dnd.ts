/**
 * Drop targets for the block list: reorder within a list, or move a block into or out of a group.
 * One nesting level only: groups never go inside groups.
 */
import type { Block } from '../types';

/** Id prefix of the "end of group" drop zones. */
export const DROP_PREFIX = 'drop:';

export interface DropTarget {
	/** New parent: a group id, or null for the top level. */
	parentId: string | null;
	/** Position among the target list's blocks, with the moved block already removed from it. */
	index: number;
}

const siblingsOf = ( blocks: Block[], parentId: string | null ): Block[] =>
	blocks
		.filter( ( b ) => b.parent_id === parentId )
		.sort( ( a, b ) => a.order - b.order );

/**
 * Where a block lands when dropped on `overId`.
 *
 * - On a block: it takes that block's place, in that block's list. Moving down inside one list puts
 *   it after the block (like a sortable list), moving up or in from another list puts it before.
 * - On a group's drop zone (`drop:{groupId}`): at the end of the group.
 * - A group dropped on a child lands at the top level, right after that child's group.
 * - Returns null when nothing would change or the move is not allowed.
 *
 * @param blocks   Flat block list.
 * @param activeId Dragged block id.
 * @param overId   Id dropped on (a block id or `drop:{groupId}`).
 */
export function resolveDrop(
	blocks: Block[],
	activeId: string,
	overId: string
): DropTarget | null {
	const active = blocks.find( ( b ) => b.id === activeId );
	if ( ! active || activeId === overId ) {
		return null;
	}

	if ( overId.startsWith( DROP_PREFIX ) ) {
		const group = blocks.find(
			( b ) => b.id === overId.slice( DROP_PREFIX.length )
		);
		if ( ! group || group.type !== 'group' || active.type === 'group' ) {
			return null;
		}
		const rest = siblingsOf( blocks, group.id ).filter(
			( b ) => b.id !== activeId
		);
		const lastAlready =
			active.parent_id === group.id &&
			siblingsOf( blocks, group.id ).at( -1 )?.id === activeId;
		return lastAlready ? null : { parentId: group.id, index: rest.length };
	}

	const over = blocks.find( ( b ) => b.id === overId );
	if ( ! over ) {
		return null;
	}
	// A group dragged onto one of a group's children goes after that group; groups stay top level.
	let parentId = over.parent_id;
	let anchor: Block = over;
	if ( active.type === 'group' && parentId !== null ) {
		const parent = blocks.find( ( b ) => b.id === parentId );
		if ( ! parent ) {
			return null;
		}
		parentId = null;
		anchor = parent;
	}
	const list = siblingsOf( blocks, parentId );
	const rest = list.filter( ( b ) => b.id !== activeId );
	const at = rest.findIndex( ( b ) => b.id === anchor.id );
	if ( at < 0 ) {
		return null;
	}
	const from = list.findIndex( ( b ) => b.id === activeId );
	// Same list, moving down: after the anchor. Otherwise: before it.
	const index = from >= 0 && from < list.indexOf( anchor ) ? at + 1 : at;
	if ( from >= 0 && index === from ) {
		return null;
	}
	return { parentId, index };
}
