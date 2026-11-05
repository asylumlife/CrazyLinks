/**
 * Editor store reducer: working copy, client-side history, save state, validation errors, media cache.
 */
import type {
	Block,
	JsonObject,
	JsonValue,
	MappedError,
	MediaInfo,
	PageDoc,
	PageMeta,
} from '../types';
import * as D from './doc';
import { setPath } from '../lib/paths';

export type Section = 'profile' | 'design' | 'settings';

export type DocOp =
	| { kind: 'insert'; block: Block; afterId?: string | null }
	| { kind: 'remove'; id: string }
	| { kind: 'duplicate'; id: string }
	| { kind: 'move'; id: string; delta: number }
	| { kind: 'order'; parentId: string | null; ids: string[] }
	| { kind: 'parent'; id: string; parentId: string | null }
	| { kind: 'moveTo'; id: string; parentId: string | null; index: number }
	| {
			kind: 'block';
			id: string;
			patch: Partial< Omit< Block, 'id' | 'type' > >;
	  }
	| { kind: 'props'; id: string; props: JsonObject }
	| { kind: 'pin'; id: string }
	| {
			kind: 'section';
			section: Section;
			path: string[];
			value: JsonValue | undefined;
	  }
	| { kind: 'theme'; slug: string }
	| { kind: 'replace'; doc: PageDoc };

export type SaveStatus = 'idle' | 'saving' | 'autosaving' | 'error';

export interface SaveState {
	status: SaveStatus;
	message: string;
	savedAt: string | null;
	autosavedAt: string | null;
	/** JSON of the document last sent to the autosave endpoint successfully. */
	autosavedDoc: string;
}

export interface EditorState {
	doc: PageDoc;
	meta: PageMeta;
	savedDoc: string;
	savedMeta: string;
	past: PageDoc[];
	future: PageDoc[];
	lastKey: string | null;
	selected: string | null;
	errors: MappedError[];
	save: SaveState;
	media: Record< number, MediaInfo >;
	clicks: Record< string, number >;
}

export type Action =
	| { type: 'RESET'; doc: PageDoc; meta: PageMeta }
	| { type: 'EDIT'; op: DocOp; key?: string }
	| { type: 'UNDO' }
	| { type: 'REDO' }
	| { type: 'SELECT'; id: string | null }
	| { type: 'SET_META'; patch: Partial< PageMeta > }
	| { type: 'SAVED'; meta: PageMeta; serverDoc: PageDoc; sentDoc: PageDoc }
	| { type: 'SET_ERRORS'; errors: MappedError[] }
	| { type: 'SET_SAVE'; patch: Partial< SaveState > }
	| { type: 'ADD_MEDIA'; items: MediaInfo[] }
	| { type: 'SET_CLICKS'; clicks: Record< string, number > };

export const HISTORY_LIMIT = 100;

/** Design keys a theme owns; picking a theme clears page overrides of them. */
export const THEME_KEYS = [
	'background',
	'text',
	'button',
	'accent',
	'radius',
];

const metaKey = ( m: PageMeta ) => JSON.stringify( [ m.title, m.slug ] );

export const emptyMeta = (): PageMeta => ( {
	id: 0,
	title: '',
	slug: '',
	status: 'auto-draft',
	link: '',
	modified: '',
} );

export function initialState(
	doc: PageDoc = D.emptyDoc(),
	meta: PageMeta = emptyMeta()
): EditorState {
	const normalized = D.normalize( doc );
	return {
		doc: normalized,
		meta,
		savedDoc: JSON.stringify( normalized ),
		savedMeta: metaKey( meta ),
		past: [],
		future: [],
		lastKey: null,
		selected: null,
		errors: [],
		save: {
			status: 'idle',
			message: '',
			savedAt: null,
			autosavedAt: null,
			autosavedDoc: '',
		},
		media: {},
		clicks: {},
	};
}

/**
 * Applies one document operation.
 *
 * @param doc Document.
 * @param op  Operation.
 * @return New document and the block to select afterwards (undefined = keep selection).
 */
export function applyOp(
	doc: PageDoc,
	op: DocOp
): { doc: PageDoc; select?: string | null } {
	switch ( op.kind ) {
		case 'insert':
			return {
				doc: D.insertBlock( doc, op.block, op.afterId ),
				select: op.block.id,
			};
		case 'remove':
			return { doc: D.removeBlock( doc, op.id ), select: null };
		case 'duplicate': {
			const r = D.duplicateBlock( doc, op.id );
			return { doc: r.doc, select: r.id };
		}
		case 'move':
			return { doc: D.moveBlock( doc, op.id, op.delta ) };
		case 'order':
			return { doc: D.applyOrder( doc, op.parentId, op.ids ) };
		case 'parent':
			return { doc: D.setParent( doc, op.id, op.parentId ) };
		case 'moveTo':
			return { doc: D.moveTo( doc, op.id, op.parentId, op.index ) };
		case 'block':
			return { doc: D.updateBlock( doc, op.id, op.patch ) };
		case 'props':
			return { doc: D.setProps( doc, op.id, op.props ) };
		case 'pin':
			return { doc: D.togglePinned( doc, op.id ) };
		case 'section':
			return {
				doc: {
					...doc,
					[ op.section ]: setPath(
						doc[ op.section ],
						op.path,
						op.value
					),
				},
			};
		case 'theme': {
			const design: JsonObject = { ...doc.design, theme: op.slug };
			for ( const k of THEME_KEYS ) {
				delete design[ k ];
			}
			return { doc: { ...doc, design } };
		}
		case 'replace':
			return { doc: D.normalize( op.doc ) };
	}
	return { doc };
}

export function reducer(
	state: EditorState = initialState(),
	action: Action
): EditorState {
	switch ( action.type ) {
		case 'RESET': {
			const fresh = initialState( action.doc, action.meta );
			return { ...fresh, media: state.media, clicks: state.clicks };
		}
		case 'EDIT': {
			const { doc, select } = applyOp( state.doc, action.op );
			if ( doc === state.doc ) {
				return state;
			}
			// Consecutive edits with the same key (typing in one field) collapse into one undo step.
			const coalesce =
				!! action.key &&
				action.key === state.lastKey &&
				state.past.length > 0;
			const past = coalesce
				? state.past
				: [ ...state.past, state.doc ].slice( -HISTORY_LIMIT );
			const selected = select === undefined ? state.selected : select;
			return {
				...state,
				doc,
				past,
				future: [],
				lastKey: action.key ?? null,
				selected:
					selected && doc.blocks.some( ( b ) => b.id === selected )
						? selected
						: null,
			};
		}
		case 'UNDO': {
			if ( state.past.length === 0 ) {
				return state;
			}
			const prev = state.past[ state.past.length - 1 ];
			return {
				...state,
				doc: prev,
				past: state.past.slice( 0, -1 ),
				future: [ state.doc, ...state.future ].slice(
					0,
					HISTORY_LIMIT
				),
				lastKey: null,
				selected:
					state.selected &&
					prev.blocks.some( ( b ) => b.id === state.selected )
						? state.selected
						: null,
			};
		}
		case 'REDO': {
			if ( state.future.length === 0 ) {
				return state;
			}
			const [ next, ...rest ] = state.future;
			return {
				...state,
				doc: next,
				past: [ ...state.past, state.doc ].slice( -HISTORY_LIMIT ),
				future: rest,
				lastKey: null,
				selected:
					state.selected &&
					next.blocks.some( ( b ) => b.id === state.selected )
						? state.selected
						: null,
			};
		}
		case 'SELECT':
			return { ...state, selected: action.id };
		case 'SET_META':
			return { ...state, meta: { ...state.meta, ...action.patch } };
		case 'SAVED': {
			// Adopt the server's normalized document only if nothing changed while the request ran.
			const unchanged =
				JSON.stringify( state.doc ) ===
				JSON.stringify( D.normalize( action.sentDoc ) );
			const serverDoc = D.normalize( action.serverDoc );
			return {
				...state,
				doc: unchanged ? serverDoc : state.doc,
				meta: unchanged
					? action.meta
					: {
							...action.meta,
							title: state.meta.title,
							slug: state.meta.slug,
						},
				savedDoc: JSON.stringify( serverDoc ),
				savedMeta: metaKey( action.meta ),
				errors: [],
			};
		}
		case 'SET_ERRORS':
			return { ...state, errors: action.errors };
		case 'SET_SAVE':
			return { ...state, save: { ...state.save, ...action.patch } };
		case 'ADD_MEDIA': {
			const media = { ...state.media };
			for ( const m of action.items ) {
				if ( m && m.id > 0 ) {
					media[ m.id ] = m;
				}
			}
			return { ...state, media };
		}
		case 'SET_CLICKS':
			return { ...state, clicks: action.clicks };
	}
	return state;
}

export { metaKey };
