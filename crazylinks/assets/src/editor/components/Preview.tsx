/**
 * Live phone-frame preview rendered by POST /crazylinks/v1/render-preview into a sandboxed
 * <iframe srcdoc>. The iframe has an opaque origin; a small bridge script posts block clicks and
 * scroll position to the editor and receives highlight requests.
 */
import { __, _n, sprintf } from '@wordpress/i18n';
import { Button, Spinner } from '@wordpress/components';
import { useEffect, useRef, useState } from '@wordpress/element';
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
	const incompleteCount = new Set( local.map( ( e ) => e.blockId ) ).size;

	useEffect( () => {
		const timer = window.setTimeout( () => {
			const incomplete = new Set(
				local
					.map( ( e ) => e.blockId )
					.filter( ( x ): x is string => !! x )
			);
			const sent = withoutBlocks( doc, incomplete );
			abort.current?.abort();
			const controller =
				typeof AbortController !== 'undefined'
					? new AbortController()
					: null;
			abort.current = controller;
			setLoading( true );
			const signal = controller?.signal;
			const isAbort = ( e: { name?: string; code?: string } ) =>
				e?.name === 'AbortError' || e?.code === 'fetch_error';
			( async () => {
				try {
					const res = await api.preview( sent, id, signal );
					setSrcDoc( buildSrcDoc( res, scroll.current ) );
					setProblems( 0 );
					setSkipped( 0 );
					setErrors( [] );
				} catch ( e ) {
					if ( isAbort( e as { name?: string } ) ) {
						return;
					}
					const mapped = mapErrors( errorsFromResponse( e ), sent );
					setErrors( mapped );
					setProblems( mapped.length );
					// Keep the rest of the page visible: render again without the blocks that failed.
					const bad = new Set(
						mapped
							.map( ( m ) => m.blockId )
							.filter( ( x ): x is string => !! x )
					);
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
		// eslint-disable-next-line react-hooks/exhaustive-deps
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
				/^[0-9A-Z]{26}$/.test( data.id )
			) {
				selectBlock( data.id );
				window.setTimeout( () => {
					document
						.querySelector( `[data-block-id="${ data.id }"]` )
						?.scrollIntoView( { block: 'nearest' } );
				}, 50 );
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
			{ ( problems > 0 || incompleteCount > 0 ) && (
				<p className="cl-preview__note" role="status">
					{ previewNote( problems, skipped, incompleteCount ) }
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
