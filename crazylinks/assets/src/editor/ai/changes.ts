/**
 * Change-list helpers: the server writes one plain-English line per operation; these group and
 * count them for the proposal card.
 */
import { __, _n, sprintf } from '@wordpress/i18n';
import type { ChangeItem, ChangeKind } from './types';

export const CHANGE_ORDER: ChangeKind[] = [
	'add',
	'update',
	'move',
	'remove',
	'design',
	'profile',
];

/** Dashicon per kind. */
export function changeIcon( kind: ChangeKind ): string {
	switch ( kind ) {
		case 'add':
			return 'plus-alt2';
		case 'remove':
			return 'trash';
		case 'move':
			return 'move';
		case 'design':
			return 'art';
		case 'profile':
			return 'admin-users';
		default:
			return 'edit';
	}
}

/** Counts per kind, in display order, zero kinds dropped. */
export function countChanges(
	items: ChangeItem[]
): Array< { kind: ChangeKind; count: number } > {
	const counts = new Map< ChangeKind, number >();
	for ( const item of items ) {
		counts.set( item.kind, ( counts.get( item.kind ) ?? 0 ) + 1 );
	}
	return CHANGE_ORDER.filter( ( k ) => counts.has( k ) ).map( ( kind ) => ( {
		kind,
		count: counts.get( kind ) ?? 0,
	} ) );
}

/** "2 added, 1 changed" style summary line. */
export function summarizeChanges( items: ChangeItem[] ): string {
	if ( items.length === 0 ) {
		return __( 'No changes', 'crazylinks' );
	}
	return countChanges( items )
		.map( ( { kind, count } ) => {
			switch ( kind ) {
				case 'add':
					return sprintf(
						/* translators: %d: number of blocks. */
						_n( '%d added', '%d added', count, 'crazylinks' ),
						count
					);
				case 'update':
					return sprintf(
						/* translators: %d: number of blocks. */
						_n( '%d changed', '%d changed', count, 'crazylinks' ),
						count
					);
				case 'move':
					return sprintf(
						/* translators: %d: number of blocks. */
						_n( '%d moved', '%d moved', count, 'crazylinks' ),
						count
					);
				case 'remove':
					return sprintf(
						/* translators: %d: number of blocks. */
						_n( '%d removed', '%d removed', count, 'crazylinks' ),
						count
					);
				case 'design':
					return __( 'design updated', 'crazylinks' );
				default:
					return __( 'profile updated', 'crazylinks' );
			}
		} )
		.join( ', ' );
}

/** Items sorted by kind (stable within a kind), for a tidy list. */
export function sortChanges( items: ChangeItem[] ): ChangeItem[] {
	return items
		.map( ( item, i ) => ( { item, i } ) )
		.sort(
			( a, b ) =>
				CHANGE_ORDER.indexOf( a.item.kind ) -
					CHANGE_ORDER.indexOf( b.item.kind ) || a.i - b.i
		)
		.map( ( x ) => x.item );
}
