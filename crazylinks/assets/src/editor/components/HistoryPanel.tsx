/**
 * Revision history: list, load a revision into the editor (undoable), or restore it on the server.
 */
import { __, sprintf } from '@wordpress/i18n';
import { Button, Modal, Notice, Spinner } from '@wordpress/components';
import { useEffect, useState } from '@wordpress/element';
import { api } from '../lib/api';
import type { RevisionSummary } from '../lib/api';
import { useEditor, useEditorDispatch } from '../hooks';
import { coerceDoc } from '../store/doc';
import { metaFrom } from '../lib/save';
import { errorsFromResponse } from '../lib/errors';

const when = ( iso: string ) => {
	const d = new Date( iso );
	return Number.isNaN( d.getTime() ) ? iso : d.toLocaleString();
};

export default function HistoryPanel( { onClose }: { onClose: () => void } ) {
	const id = useEditor( ( s ) => s.getMeta().id, [] );
	const dirty = useEditor( ( s ) => s.isDirty(), [] );
	const { replaceDoc, reset, setSave } = useEditorDispatch();
	const [ list, setList ] = useState< RevisionSummary[] | null >( null );
	const [ busy, setBusy ] = useState< number | null >( null );
	const [ message, setMessage ] = useState< {
		status: 'error' | 'success';
		text: string;
	} | null >( null );
	const [ confirm, setConfirm ] = useState< number | null >( null );

	const load = () => {
		api.revisions( id )
			.then( setList )
			.catch( ( e ) => {
				setList( [] );
				setMessage( {
					status: 'error',
					text: errorsFromResponse( e )[ 0 ].message,
				} );
			} );
	};

	useEffect( load, [ id ] );

	const loadIntoEditor = async ( revId: number ) => {
		setBusy( revId );
		try {
			const rev = await api.revision( id, revId );
			replaceDoc( coerceDoc( rev.page ) );
			setMessage( {
				status: 'success',
				text: __(
					'Revision loaded into the editor. Save to keep it, or undo.',
					'crazylinks'
				),
			} );
		} catch ( e ) {
			setMessage( {
				status: 'error',
				text: errorsFromResponse( e )[ 0 ].message,
			} );
		}
		setBusy( null );
	};

	const restore = async ( revId: number ) => {
		setBusy( revId );
		setConfirm( null );
		try {
			const res = await api.restore( id, revId );
			reset( coerceDoc( res.page ), metaFrom( res ) );
			setSave( {
				status: 'idle',
				message: __( 'Revision restored.', 'crazylinks' ),
				savedAt: res.modified,
			} );
			setMessage( {
				status: 'success',
				text: __(
					'Revision restored. The previous version was kept as a revision.',
					'crazylinks'
				),
			} );
			load();
		} catch ( e ) {
			const errs = errorsFromResponse( e );
			setMessage( {
				status: 'error',
				text: errs.map( ( x ) => x.message ).join( ' ' ),
			} );
		}
		setBusy( null );
	};

	return (
		<Modal
			title={ __( 'Page history', 'crazylinks' ) }
			onRequestClose={ onClose }
			className="cl-history"
		>
			{ message && (
				<Notice
					status={ message.status }
					onRemove={ () => setMessage( null ) }
				>
					{ message.text }
				</Notice>
			) }
			{ list === null && <Spinner /> }
			{ list && list.length === 0 && (
				<p>
					{ __(
						'No revisions yet. Each save keeps one.',
						'crazylinks'
					) }
				</p>
			) }
			{ list && list.length > 0 && (
				<ol className="cl-history__list">
					{ list.map( ( r ) => (
						<li key={ r.id }>
							<div className="cl-history__meta">
								<strong>{ when( r.date ) }</strong>
								<span>
									{ sprintf(
										/* translators: 1: author, 2: number of blocks. */ __(
											'%1$s · %2$d blocks',
											'crazylinks'
										),
										r.author.name,
										r.blocks
									) }
								</span>
								{ r.autosave && (
									<span className="cl-badge">
										{ __( 'Autosave', 'crazylinks' ) }
									</span>
								) }
							</div>
							<div className="cl-history__actions">
								<Button
									variant="secondary"
									size="compact"
									isBusy={ busy === r.id }
									disabled={ busy !== null }
									onClick={ () => loadIntoEditor( r.id ) }
								>
									{ __( 'Load into editor', 'crazylinks' ) }
								</Button>
								{ confirm === r.id ? (
									<>
										<Button
											variant="primary"
											size="compact"
											onClick={ () => restore( r.id ) }
										>
											{ __(
												'Confirm restore',
												'crazylinks'
											) }
										</Button>
										<Button
											variant="tertiary"
											size="compact"
											onClick={ () => setConfirm( null ) }
										>
											{ __( 'Cancel', 'crazylinks' ) }
										</Button>
									</>
								) : (
									<Button
										variant="tertiary"
										size="compact"
										disabled={ busy !== null }
										onClick={ () => setConfirm( r.id ) }
									>
										{ __( 'Restore', 'crazylinks' ) }
									</Button>
								) }
							</div>
							{ confirm === r.id && dirty && (
								<p className="cl-muted">
									{ __(
										'Restoring replaces your unsaved changes.',
										'crazylinks'
									) }
								</p>
							) }
						</li>
					) ) }
				</ol>
			) }
		</Modal>
	);
}
