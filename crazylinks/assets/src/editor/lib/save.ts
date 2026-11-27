/**
 * Save model: explicit saves through PagesController, background autosaves through
 * RevisionsController, validation errors mapped back onto blocks and fields.
 */
import { __, _n, sprintf } from '@wordpress/i18n';
import { dispatch } from '@wordpress/data';
import { editorSelect, store } from '../store';
import { api } from './api';
import { errorsFromResponse, isValidationError, mapErrors } from './errors';
import { coerceDoc } from '../store/doc';
import type { PageMeta, PagePayload } from '../types';
import { isLockedOut } from './lock';

const act = () => dispatch( store );

export function metaFrom( res: PagePayload ): PageMeta {
	return {
		id: res.id,
		title: res.title,
		slug: res.slug,
		status: res.status,
		link: res.link,
		modified: res.modified,
	};
}

/** The save or autosave request that is running, if any. Only one runs at a time. */
let inFlight: Promise< boolean > | null = null;

/**
 * Runs a save task as the single request in flight.
 *
 * @param task The request; it resolves to whether it succeeded and never rejects.
 */
async function exclusive( task: () => Promise< boolean > ): Promise< boolean > {
	const run = task();
	inFlight = run;
	try {
		return await run;
	} finally {
		if ( inFlight === run ) {
			inFlight = null;
		}
	}
}

/**
 * Saves the working copy. `status` publishes, drafts or submits; omit it to keep the current status.
 *
 * @param status Target status.
 * @return Whether the save succeeded.
 */
export async function savePage( status?: string ): Promise< boolean > {
	// Several callers can be queued behind one request; each re-checks after waking.
	while ( inFlight ) {
		await inFlight;
	}
	if ( isLockedOut() ) {
		act().setSave( {
			status: 'error',
			message: __(
				'Not saved: someone else is editing this page.',
				'crazylinks'
			),
		} );
		return false;
	}
	const s = editorSelect();
	const d = act();
	const local = s.getLocalErrors();
	if ( local.length ) {
		d.setErrors( local );
		d.selectBlock( local[ 0 ].blockId ?? null );
		d.setSave( {
			status: 'error',
			message: sprintf(
				/* translators: %d: number of problems. */
				_n(
					'Not saved: %d field needs attention.',
					'Not saved: %d fields need attention.',
					local.length,
					'crazylinks'
				),
				local.length
			),
		} );
		return false;
	}
	const doc = s.getDoc();
	const meta = s.getMeta();
	d.setSave( { status: 'saving', message: __( 'Saving…', 'crazylinks' ) } );
	return exclusive( async () => {
		try {
			const res = await api.savePage( meta.id, {
				page: doc,
				title: meta.title,
				slug: meta.slug,
				...( status ? { status } : {} ),
			} );
			d.saved( metaFrom( res ), coerceDoc( res.page ), doc );
			d.setSave( {
				status: 'idle',
				savedAt: res.modified,
				autosavedDoc: '',
				message:
					res.status === 'publish'
						? __( 'Published.', 'crazylinks' )
						: __( 'Saved.', 'crazylinks' ),
			} );
			return true;
		} catch ( e ) {
			const errors = errorsFromResponse( e );
			const mapped = mapErrors( errors, doc );
			d.setErrors( mapped );
			const firstBlock = mapped.find( ( m ) => m.blockId )?.blockId;
			if ( firstBlock ) {
				d.selectBlock( firstBlock );
			}
			d.setSave( {
				status: 'error',
				message:
					mapped.length > 1 || firstBlock
						? sprintf(
								/* translators: %d: number of problems. */
								_n(
									'Not saved: %d problem.',
									'Not saved: %d problems.',
									mapped.length,
									'crazylinks'
								),
								mapped.length
							)
						: sprintf(
								/* translators: %s: error message. */ __(
									'Not saved: %s',
									'crazylinks'
								),
								mapped[ 0 ]?.message ?? ''
							),
			} );
			return false;
		}
	} );
}

/**
 * Autosaves when the working copy changed since the last save or autosave.
 *
 * @return Whether an autosave was stored.
 */
export async function autosave(): Promise< boolean > {
	const s = editorSelect();
	const meta = s.getMeta();
	if (
		inFlight ||
		isLockedOut() ||
		meta.id <= 0 ||
		! s.needsAutosave() ||
		s.getSave().status === 'saving'
	) {
		return false;
	}
	const d = act();
	const doc = s.getDoc();
	const json = s.getDocJson();
	d.setSave( { status: 'autosaving' } );
	return exclusive( async () => {
		try {
			const res = await api.autosave( meta.id, doc, meta.title );
			if ( res.mode === 'draft' ) {
				// A brand-new page: the autosave became its first draft.
				d.saved(
					{ ...meta, status: 'draft', modified: res.modified },
					coerceDoc( res.page ),
					doc
				);
			}
			d.setErrors( [] );
			d.setSave( {
				status: 'idle',
				autosavedAt: res.modified,
				autosavedDoc: json,
				message: '',
			} );
			return true;
		} catch ( e ) {
			if ( ! isValidationError( e ) ) {
				// A network or server failure says nothing about the document: leave it marked as
				// not autosaved, so the next autosave sends it again.
				d.setSave( {
					status: 'idle',
					message: __(
						'Autosave failed. It will be tried again after your next change.',
						'crazylinks'
					),
				} );
				return false;
			}
			d.setErrors( mapErrors( errorsFromResponse( e ), doc ) );
			// Remember the rejected document so it is not resent every interval.
			d.setSave( {
				status: 'idle',
				autosavedDoc: json,
				message: __(
					'Autosave skipped: the page has problems.',
					'crazylinks'
				),
			} );
			return false;
		}
	} );
}
