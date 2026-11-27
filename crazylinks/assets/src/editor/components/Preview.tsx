/**
 * Live phone-frame preview rendered by POST /crazylinks/v1/render-preview into a sandboxed
 * <iframe srcdoc>. The iframe has an opaque origin; a small bridge script posts block clicks and
 * scroll position to the editor and receives highlight requests.
 */
import { __, _n, sprintf } from '@wordpress/i18n';
import { Button, Spinner } from '@wordpress/components';
import { useEffect, useMemo, useRef, useState } from '@wordpress/element';
import { useEditor, useEditorDispatch } from '../hooks';
import { withoutBlocks } from '../store/doc';
import { api } from '../lib/api';
import { errorsFromResponse, mapErrors } from '../lib/errors';
import { buildSrcDoc, PREVIEW_DEBOUNCE_MS } from '../lib/preview';

function previewNote(
	problems: number,
	skipped: number,
	incomplete: number
): string {
	if ( problems > 0 && skipped > 0 ) {
		return sprintf(
			/* translators: %d: number of blocks. */
			_n(
				'%d block has a problem and is hidden in the preview.',
				'%d blocks have problems and are hidden in the preview.',
				skipped,
				'crazylinks'
			),
			skipped
		);
	}
	if ( problems > 0 ) {
		return sprintf(
			/* translators: %d: number of problems. */
			_n(
				'Preview paused: fix %d problem.',
				'Preview paused: fix %d problems.',
				problems,
				'crazylinks'
			),
			problems
		);
	}
	return sprintf(
		/* translators: %d: number of blocks. */
		_n(
			'%d unfinished block is not shown yet.',
			'%d unfinished blocks are not shown yet.',
			incomplete,
			'crazylinks'
		),
		incomplete
	);
}

/** Time the block list needs to open the selected block before it can be scrolled into view. */
const SCROLL_TO_BLOCK_DELAY_MS = 50;

/** Shape of a block id as the preview bridge sends it (26 characters). */
const BLOCK_ID = /^[0-9A-Z]{26}$/;

/**
 * Ids of the blocks a list of errors points at.
 *
 * @param errors Mapped errors.
 */
function blockIdsOf( errors: Array< { blockId?: string } > ): Set< string > {
	return new Set(
		errors
			.map( ( e ) => e.blockId )
			.filter( ( blockId ): blockId is string => !! blockId )
	);
}

/**
 * Whether a preview request ended because a newer one replaced it (api-fetch reports an aborted
 * request as `fetch_error`).
 *
 * @param e Thrown value.
 */
function isAborted( e: unknown ): boolean {
	const err = e as { name?: string; code?: string } | null;
	return err?.name === 'AbortError' || err?.code === 'fetch_error';
}

export default function Preview() {
	const doc = useEditor( ( s ) => s.getDoc(), [] );
	const local = useEditor( ( s ) => s.getLocalErrors(), [] );
	const id = useEditor( ( s ) => s.getMeta().id, [] );
	const selected = useEditor( ( s ) => s.getSelected(), [] );
	const { setErrors, selectBlock } = useEditorDispatch();
	const [ srcDoc, setSrcDoc ] = useState( '' );
	const [ loading, setLoading ] = useState( false );
	const [ problems, setProblems ] = useState( 0 );
	const [ skipped, setSkipped ] = useState( 0 );
	const [ width, setWidth ] = useState< 'phone' | 'desktop' >( 'phone' );
	const frame = useRef< HTMLIFrameElement >( null );
	const scroll = useRef( 0 );
	const abort = useRef< AbortController | null >( null );
	// Blocks with missing required fields are left out of the preview until they are complete.
	const incomplete = useMemo( () => blockIdsOf( local ), [ local ] );

	useEffect( () => {
		const timer = window.setTimeout( () => {
			const sent = withoutBlocks( doc, incomplete );
			abort.current?.abort();
			const controller = new AbortController();
			abort.current = controller;
			setLoading( true );
			const { signal } = controller;
			( async () => {
				try {
					const res = await api.preview( sent, id, signal );
					setSrcDoc( buildSrcDoc( res, scroll.current ) );
					setProblems( 0 );
					setSkipped( 0 );
					setErrors( [] );
				} catch ( e ) {
					if ( isAborted( e ) ) {
						return;
					}
					const mapped = mapErrors( errorsFromResponse( e ), sent );
					setErrors( mapped );
					setProblems( mapped.length );
					// Keep the rest of the page visible: render again without the blocks that failed.
					const bad = blockIdsOf( mapped );
					if ( bad.size > 0 && mapped.every( ( m ) => m.blockId ) ) {
						try {
							const res = await api.preview(
								withoutBlocks( sent, bad ),
								id,
								signal
							);
							setSrcDoc( buildSrcDoc( res, scroll.current ) );
							setSkipped( bad.size );
						} catch {
							// A newer request replaced this one: its result is the one to show.
							if ( signal.aborted ) {
								return;
							}
							setSkipped( 0 );
						}
					} else {
						setSkipped( 0 );
					}
				} finally {
					if ( abort.current === controller ) {
						setLoading( false );
					}
				}
			} )();
		}, PREVIEW_DEBOUNCE_MS );
		return () => window.clearTimeout( timer );
		// eslint-disable-next-line react-hooks/exhaustive-deps -- `incomplete` is derived from `doc`, and the store's action creators are stable.
	}, [ doc, id ] );

	useEffect( () => {
		const onMessage = ( e: MessageEvent ) => {
			if ( ! frame.current || e.source !== frame.current.contentWindow ) {
				return;
			}
			const data = e.data as {
				source?: string;
				type?: string;
				y?: number;
				id?: string;
			};
			if ( ! data || data.source !== 'crazylinks-preview' ) {
				return;
			}
			if ( data.type === 'scroll' && typeof data.y === 'number' ) {
				scroll.current = data.y;
			} else if (
				data.type === 'select' &&
				typeof data.id === 'string' &&
				BLOCK_ID.test( data.id )
			) {
				selectBlock( data.id );
				window.setTimeout( () => {
					document
						.querySelector( `[data-block-id="${ data.id }"]` )
						?.scrollIntoView( { block: 'nearest' } );
				}, SCROLL_TO_BLOCK_DELAY_MS );
			}
		};
		window.addEventListener( 'message', onMessage );
		return () => window.removeEventListener( 'message', onMessage );
	}, [ selectBlock ] );

	const highlight = () => {
		frame.current?.contentWindow?.postMessage(
			{ source: 'crazylinks-editor', type: 'highlight', id: selected },
			'*'
		);
	};

	useEffect( highlight, [ selected ] );

	return (
		<div className="cl-preview">
			<div className="cl-preview__bar">
				<span className="cl-preview__title">
					{ __( 'Preview', 'crazylinks' ) }
				</span>
				<div
					className="cl-preview__sizes"
					role="group"
					aria-label={ __( 'Preview width', 'crazylinks' ) }
				>
					<Button
						size="compact"
						icon="smartphone"
						isPressed={ width === 'phone' }
						onClick={ () => setWidth( 'phone' ) }
						label={ __( 'Phone', 'crazylinks' ) }
					/>
					<Button
						size="compact"
						icon="desktop"
						isPressed={ width === 'desktop' }
						onClick={ () => setWidth( 'desktop' ) }
						label={ __( 'Desktop', 'crazylinks' ) }
					/>
				</div>
				<span className="cl-preview__status" aria-live="polite">
					{ loading && <Spinner /> }
				</span>
			</div>
			{ ( problems > 0 || incomplete.size > 0 ) && (
				<p className="cl-preview__note" role="status">
					{ previewNote( problems, skipped, incomplete.size ) }
				</p>
			) }
			<div className={ `cl-phone cl-phone--${ width }` }>
				<iframe
					ref={ frame }
					className="cl-phone__screen"
					title={ __( 'Page preview', 'crazylinks' ) }
					sandbox="allow-scripts"
					srcDoc={ srcDoc }
					onLoad={ highlight }
				/>
			</div>
		</div>
	);
}
