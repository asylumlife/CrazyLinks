/**
 * Top bar: title, status, undo/redo, history, JSON, view, save/publish/update.
 */
import { __ } from '@wordpress/i18n';
import { Button, Modal, TextControl } from '@wordpress/components';
import { useState } from '@wordpress/element';
import { useEditor, useEditorDispatch } from '../hooks';
import { classes } from '../lib/classes';
import { savePage } from '../lib/save';
import { primaryShortcut } from '../lib/shortcut';
import { boot } from '../boot';

/** Statuses under which the page has a public address to view and "Update" replaces "Publish". */
const LIVE_STATUSES = [ 'publish', 'future', 'private' ];

function statusLabel( status: string ): string {
	switch ( status ) {
		case 'publish':
			return __( 'Published', 'crazylinks' );
		case 'future':
			return __( 'Scheduled', 'crazylinks' );
		case 'private':
			return __( 'Private', 'crazylinks' );
		case 'pending':
			return __( 'Pending review', 'crazylinks' );
		case 'auto-draft':
			return __( 'New', 'crazylinks' );
		default:
			return __( 'Draft', 'crazylinks' );
	}
}

export default function TopBar( {
	onHistory,
	onJson,
}: {
	onHistory: () => void;
	onJson: () => void;
} ) {
	const meta = useEditor( ( s ) => s.getMeta(), [] );
	const save = useEditor( ( s ) => s.getSave(), [] );
	const dirty = useEditor( ( s ) => s.isDirty(), [] );
	const undoable = useEditor( ( s ) => s.canUndo(), [] );
	const redoable = useEditor( ( s ) => s.canRedo(), [] );
	const warnings = useEditor( ( s ) => s.getWarnings(), [] );
	const { setMeta, undo, redo } = useEditorDispatch();
	// The save waiting for the person to confirm the warnings, or null when none is.
	const [ awaitingConfirm, setAwaitingConfirm ] = useState< {
		status?: string;
	} | null >( null );
	const data = boot();
	const live = LIVE_STATUSES.includes( meta.status );
	const busy = save.status === 'saving';
	const canPublish = data.caps.publish_crazylinks_pages;

	let confirmLabel: string = __( 'Submit anyway', 'crazylinks' );
	if ( live ) {
		confirmLabel = __( 'Update anyway', 'crazylinks' );
	} else if ( canPublish ) {
		confirmLabel = __( 'Publish anyway', 'crazylinks' );
	}

	// Publishing or updating a live page with warnings asks first (drafts save without a prompt).
	const publish = ( status?: string ) => {
		if ( warnings.length > 0 ) {
			setAwaitingConfirm( { status } );
			return;
		}
		savePage( status );
	};

	let note = save.message;
	if ( ! note ) {
		if ( save.status === 'autosaving' ) {
			note = __( 'Autosaving…', 'crazylinks' );
		} else if ( dirty && save.autosavedAt ) {
			note = __( 'Unsaved changes (autosaved)', 'crazylinks' );
		} else if ( dirty ) {
			note = __( 'Unsaved changes', 'crazylinks' );
		} else {
			note = __( 'All changes saved', 'crazylinks' );
		}
	}

	return (
		<header className="cl-topbar">
			<a className="cl-topbar__back" href={ data.post.listUrl }>
				<span
					className="dashicons dashicons-arrow-left-alt2"
					aria-hidden="true"
				/>
				<span className="screen-reader-text">
					{ __( 'All link pages', 'crazylinks' ) }
				</span>
			</a>
			<div className="cl-topbar__title">
				<TextControl
					__next40pxDefaultSize
					__nextHasNoMarginBottom
					label={ __( 'Page name', 'crazylinks' ) }
					hideLabelFromVision
					placeholder={ __( 'Page name', 'crazylinks' ) }
					value={ meta.title }
					onChange={ ( title: string ) => setMeta( { title } ) }
				/>
				<span className={ `cl-status cl-status--${ meta.status }` }>
					{ statusLabel( meta.status ) }
				</span>
				{ meta.slug && (
					<span className="cl-topbar__slug">/{ meta.slug }/</span>
				) }
			</div>
			<div className="cl-topbar__tools">
				<Button
					icon="undo"
					label={ __( 'Undo', 'crazylinks' ) }
					shortcut={ primaryShortcut( 'Z' ) }
					disabled={ ! undoable }
					accessibleWhenDisabled
					onClick={ () => undo() }
				/>
				<Button
					icon="redo"
					label={ __( 'Redo', 'crazylinks' ) }
					shortcut={ primaryShortcut( 'Z', true ) }
					disabled={ ! redoable }
					accessibleWhenDisabled
					onClick={ () => redo() }
				/>
				<Button
					icon="backup"
					label={ __( 'History', 'crazylinks' ) }
					onClick={ onHistory }
					disabled={ meta.id <= 0 }
				/>
				<Button
					icon="editor-code"
					label={ __( 'Advanced: edit JSON', 'crazylinks' ) }
					onClick={ onJson }
				/>
			</div>
			<span
				className={ classes(
					'cl-topbar__note',
					save.status === 'error' && 'is-error'
				) }
				role="status"
				aria-live="polite"
			>
				{ note }
			</span>
			<div className="cl-topbar__actions">
				{ live ? (
					<Button
						variant="secondary"
						href={ meta.link }
						target="_blank"
						rel="noopener noreferrer"
					>
						{ __( 'View page', 'crazylinks' ) }
						<span className="screen-reader-text">
							{ __( '(opens in a new tab)', 'crazylinks' ) }
						</span>
					</Button>
				) : (
					<Button
						variant="secondary"
						disabled
						accessibleWhenDisabled
						label={ __(
							'Publish the page to view it',
							'crazylinks'
						) }
						showTooltip
					>
						{ __( 'View page', 'crazylinks' ) }
					</Button>
				) }
				{ live ? (
					<Button
						variant="primary"
						isBusy={ busy }
						disabled={ busy }
						onClick={ () => publish() }
					>
						{ __( 'Update', 'crazylinks' ) }
					</Button>
				) : (
					<>
						<Button
							variant="secondary"
							isBusy={ busy }
							disabled={ busy }
							onClick={ () =>
								savePage(
									meta.status === 'pending'
										? 'pending'
										: 'draft'
								)
							}
						>
							{ __( 'Save draft', 'crazylinks' ) }
						</Button>
						<Button
							variant="primary"
							isBusy={ busy }
							disabled={ busy }
							onClick={ () =>
								publish( canPublish ? 'publish' : 'pending' )
							}
						>
							{ canPublish
								? __( 'Publish', 'crazylinks' )
								: __( 'Submit for review', 'crazylinks' ) }
						</Button>
					</>
				) }
			</div>
			{ awaitingConfirm && (
				<Modal
					title={ __( 'Some things need a look', 'crazylinks' ) }
					onRequestClose={ () => setAwaitingConfirm( null ) }
					className="cl-publish-confirm"
				>
					<p>
						{ __(
							'People using a screen reader may miss parts of this page:',
							'crazylinks'
						) }
					</p>
					<ul className="cl-publish-confirm__list">
						{ warnings.map( ( w, i ) => (
							<li key={ i }>{ w.message }</li>
						) ) }
					</ul>
					<div className="cl-publish-confirm__actions">
						<Button
							variant="tertiary"
							onClick={ () => setAwaitingConfirm( null ) }
						>
							{ __( 'Go back and fix', 'crazylinks' ) }
						</Button>
						<Button
							variant="primary"
							onClick={ () => {
								setAwaitingConfirm( null );
								savePage( awaitingConfirm.status );
							} }
						>
							{ confirmLabel }
						</Button>
					</div>
				</Modal>
			) }
		</header>
	);
}
