/**
 * Editor store selectors.
 */
import type { Block, MappedError } from '../types';
import type { EditorState } from './reducer';
import { metaKey } from './reducer';
import { localErrors } from '../lib/errors';
import { altWarnings, type MappedWarning } from '../lib/warnings';

let lastDoc: unknown = null;
let lastJson = '';
function docJson( state: EditorState ): string {
	if ( state.doc !== lastDoc ) {
		lastDoc = state.doc;
		lastJson = JSON.stringify( state.doc );
	}
	return lastJson;
}

export const getDoc = ( state: EditorState ) => state.doc;
export const getDocJson = ( state: EditorState ) => docJson( state );
export const getMeta = ( state: EditorState ) => state.meta;
export const getSelected = ( state: EditorState ) => state.selected;
export const getSave = ( state: EditorState ) => state.save;
export const getErrors = ( state: EditorState ): MappedError[] => state.errors;
export const getMediaMap = ( state: EditorState ) => state.media;
export const getMedia = ( state: EditorState, id: number ) => state.media[ id ];
export const getClicks = ( state: EditorState ) => state.clicks;
export const canUndo = ( state: EditorState ) => state.past.length > 0;
export const canRedo = ( state: EditorState ) => state.future.length > 0;

export const isDocDirty = ( state: EditorState ) =>
	docJson( state ) !== state.savedDoc;
export const isDirty = ( state: EditorState ) =>
	isDocDirty( state ) || metaKey( state.meta ) !== state.savedMeta;

/** Whether the working copy differs from both the saved page and the last autosave. */
export const needsAutosave = ( state: EditorState ) =>
	isDocDirty( state ) && docJson( state ) !== state.save.autosavedDoc;

export const getBlock = ( state: EditorState, id: string ): Block | undefined =>
	state.doc.blocks.find( ( b ) => b.id === id );

export const getChildren = (
	state: EditorState,
	parentId: string | null
): Block[] => state.doc.blocks.filter( ( b ) => b.parent_id === parentId );

export const getGroups = ( state: EditorState ): Block[] =>
	state.doc.blocks.filter( ( b ) => b.type === 'group' );

let lastLocalDoc: unknown = null;
let lastLocal: MappedError[] = [];
/** Required-field problems found client-side (memoized per document). */
export const getLocalErrors = ( state: EditorState ): MappedError[] => {
	if ( state.doc !== lastLocalDoc ) {
		lastLocalDoc = state.doc;
		lastLocal = localErrors( state.doc );
	}
	return lastLocal;
};

/** Server errors plus local ones, de-duplicated by block + field. */
export const getAllErrors = ( state: EditorState ): MappedError[] => {
	const local = getLocalErrors( state );
	if ( local.length === 0 ) {
		return state.errors;
	}
	const seen = new Set(
		state.errors.map( ( e ) => `${ e.blockId ?? '' }|${ e.field }` )
	);
	return [
		...state.errors,
		...local.filter(
			( e ) => ! seen.has( `${ e.blockId ?? '' }|${ e.field }` )
		),
	];
};

let lastWarnDoc: unknown = null;
let lastWarnMedia: unknown = null;
let lastWarn: MappedWarning[] = [];
/** Missing-alt warnings (memoized per document and media cache). */
export const getWarnings = ( state: EditorState ): MappedWarning[] => {
	if ( state.doc !== lastWarnDoc || state.media !== lastWarnMedia ) {
		lastWarnDoc = state.doc;
		lastWarnMedia = state.media;
		lastWarn = altWarnings( state.doc, state.media );
	}
	return lastWarn;
};
