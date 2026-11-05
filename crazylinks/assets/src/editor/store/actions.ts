/**
 * Editor store action creators.
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
import type { Action, DocOp, SaveState, Section } from './reducer';
import { createBlock } from './doc';

const edit = ( op: DocOp, key?: string ): Action => ( {
	type: 'EDIT',
	op,
	key,
} );

export const reset = ( doc: PageDoc, meta: PageMeta ): Action => ( {
	type: 'RESET',
	doc,
	meta,
} );
export const addBlock = (
	type: string,
	parentId: string | null = null,
	afterId: string | null = null
): Action =>
	edit( { kind: 'insert', block: createBlock( type, parentId ), afterId } );
export const insertBlock = (
	block: Block,
	afterId: string | null = null
): Action => edit( { kind: 'insert', block, afterId } );
export const removeBlock = ( id: string ): Action =>
	edit( { kind: 'remove', id } );
export const duplicateBlock = ( id: string ): Action =>
	edit( { kind: 'duplicate', id } );
export const moveBlock = ( id: string, delta: number ): Action =>
	edit( { kind: 'move', id, delta } );
export const reorderBlocks = (
	parentId: string | null,
	ids: string[]
): Action => edit( { kind: 'order', parentId, ids } );
export const setParent = ( id: string, parentId: string | null ): Action =>
	edit( { kind: 'parent', id, parentId } );
export const moveBlockTo = (
	id: string,
	parentId: string | null,
	index: number
): Action => edit( { kind: 'moveTo', id, parentId, index } );
export const updateBlock = (
	id: string,
	patch: Partial< Omit< Block, 'id' | 'type' > >,
	key?: string
): Action => edit( { kind: 'block', id, patch }, key );
export const togglePinned = ( id: string ): Action =>
	edit( { kind: 'pin', id } );
export const setBlockProps = (
	id: string,
	props: JsonObject,
	key?: string
): Action => edit( { kind: 'props', id, props }, key );
export const setSection = (
	section: Section,
	path: string[],
	value: JsonValue | undefined,
	key?: string
): Action =>
	edit(
		{ kind: 'section', section, path, value },
		key ?? `${ section }:${ path.join( '/' ) }`
	);
export const applyTheme = ( slug: string ): Action =>
	edit( { kind: 'theme', slug } );
export const replaceDoc = ( doc: PageDoc ): Action =>
	edit( { kind: 'replace', doc } );
export const undo = (): Action => ( { type: 'UNDO' } );
export const redo = (): Action => ( { type: 'REDO' } );
export const selectBlock = ( id: string | null ): Action => ( {
	type: 'SELECT',
	id,
} );
export const setMeta = ( patch: Partial< PageMeta > ): Action => ( {
	type: 'SET_META',
	patch,
} );
export const saved = (
	meta: PageMeta,
	serverDoc: PageDoc,
	sentDoc: PageDoc
): Action => ( {
	type: 'SAVED',
	meta,
	serverDoc,
	sentDoc,
} );
export const setErrors = ( errors: MappedError[] ): Action => ( {
	type: 'SET_ERRORS',
	errors,
} );
export const setSave = ( patch: Partial< SaveState > ): Action => ( {
	type: 'SET_SAVE',
	patch,
} );
export const addMedia = ( items: MediaInfo[] ): Action => ( {
	type: 'ADD_MEDIA',
	items,
} );
export const setClicks = ( clicks: Record< string, number > ): Action => ( {
	type: 'SET_CLICKS',
	clicks,
} );
