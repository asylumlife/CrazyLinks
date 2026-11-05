/**
 * Editor shell: top bar, tabbed left column, live preview, modals, autosave and shortcuts.
 */
import { __, sprintf } from '@wordpress/i18n';
import { Button, Notice, TabPanel } from '@wordpress/components';
import { useEffect, useRef, useState } from '@wordpress/element';
import TopBar from './TopBar';
import LinksTab from './LinksTab';
import DesignTab from './DesignTab';
import SettingsTab from './SettingsTab';
import AnalyticsTab from './AnalyticsTab';
import { clicksByBlock } from '../lib/analytics';
import AiTab from './AiTab';
import Preview from './Preview';
import HistoryPanel from './HistoryPanel';
import JsonPanel from './JsonPanel';
import Wizard from './Wizard';
import LockBanner from './LockBanner';
import { startLock } from '../lib/lock';
import { useEditor, useEditorDispatch } from '../hooks';
import { boot } from '../boot';
import { autosave, savePage } from '../lib/save';
import { api } from '../lib/api';
import { coerceDoc, isEmptyDoc } from '../store/doc';

function isTextTarget( t: EventTarget | null ): boolean {
	const el = t as HTMLElement | null;
	return (
		!! el &&
		( el.tagName === 'INPUT' ||
			el.tagName === 'TEXTAREA' ||
			el.tagName === 'SELECT' ||
			el.isContentEditable )
	);
}

export default function App() {
	const data = boot();
	const docJson = useEditor( ( s ) => s.getDocJson(), [] );
	const doc = useEditor( ( s ) => s.getDoc(), [] );
	const title = useEditor( ( s ) => s.getMeta().title, [] );
	const id = useEditor( ( s ) => s.getMeta().id, [] );
	const dirty = useEditor( ( s ) => s.isDirty(), [] );
	const { undo, redo, replaceDoc, setMeta, setClicks } = useEditorDispatch();
	const [ modal, setModal ] = useState< 'history' | 'json' | null >( null );
	const [ wizard, setWizard ] = useState(
		() => data.post.isNew && isEmptyDoc( coerceDoc( data.post.page ) )
	);
	const [ autosaveNotice, setAutosaveNotice ] = useState( !! data.autosave );
	const dirtyRef = useRef( dirty );
	dirtyRef.current = dirty;

	// Core post lock: heartbeat keeps it alive and reports a takeover.
	useEffect( () => startLock(), [] );

	// Autosave after a period of inactivity.
	useEffect( () => {
		const ms = Math.max( 5, data.settings.autosaveInterval || 10 ) * 1000;
		const timer = window.setTimeout( () => {
			autosave();
		}, ms );
		return () => window.clearTimeout( timer );
	}, [ docJson, title, data.settings.autosaveInterval ] );

	// Warn before leaving with unsaved changes.
	useEffect( () => {
		const onBeforeUnload = ( e: BeforeUnloadEvent ) => {
			if ( dirtyRef.current ) {
				e.preventDefault();
				e.returnValue = '';
			}
		};
		window.addEventListener( 'beforeunload', onBeforeUnload );
		return () =>
			window.removeEventListener( 'beforeunload', onBeforeUnload );
	}, [] );

	// Keyboard shortcuts: undo/redo outside text fields, save anywhere.
	useEffect( () => {
		const onKey = ( e: KeyboardEvent ) => {
			const mod = e.metaKey || e.ctrlKey;
			if ( ! mod ) {
				return;
			}
			const key = e.key.toLowerCase();
			if ( key === 's' ) {
				e.preventDefault();
				savePage();
			} else if ( key === 'z' && ! isTextTarget( e.target ) ) {
				e.preventDefault();
				if ( e.shiftKey ) {
					redo();
				} else {
					undo();
				}
			} else if ( key === 'y' && ! isTextTarget( e.target ) ) {
				e.preventDefault();
				redo();
			}
		};
		document.addEventListener( 'keydown', onKey );
		return () => document.removeEventListener( 'keydown', onKey );
	}, [ undo, redo ] );

	// Per-block click counts for the block list.
	useEffect( () => {
		if (
			! data.caps.view_crazylinks_analytics ||
			id <= 0 ||
			! data.settings.analytics
		) {
			return;
		}
		api.analytics( id, '30d' )
			.then( ( res ) => setClicks( clicksByBlock( res ) ) )
			.catch( () => undefined );
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [ id ] );

	const tabs = [
		{ name: 'links', title: __( 'Links', 'crazylinks' ) },
		{ name: 'design', title: __( 'Design', 'crazylinks' ) },
		{ name: 'settings', title: __( 'Settings', 'crazylinks' ) },
		{ name: 'analytics', title: __( 'Analytics', 'crazylinks' ) },
		// The AI tab shows for anyone allowed to use AI, so the "connect a provider" pointer is reachable.
		...( data.ai.available || data.ai.tab
			? [ { name: 'ai', title: __( 'AI', 'crazylinks' ) } ]
			: [] ),
	];

	return (
		<div className="cl-editor">
			<TopBar
				onHistory={ () => setModal( 'history' ) }
				onJson={ () => setModal( 'json' ) }
			/>
			<LockBanner />
			{ autosaveNotice && data.autosave && (
				<Notice
					status="warning"
					onRemove={ () => setAutosaveNotice( false ) }
					className="cl-autosave-notice"
				>
					{ sprintf(
						/* translators: %s: date and time. */
						__(
							'There is an autosave of this page from %s that is newer than the saved version.',
							'crazylinks'
						),
						new Date( data.autosave.modified ).toLocaleString()
					) }{ ' ' }
					<Button
						variant="link"
						onClick={ () => {
							if ( data.autosave ) {
								replaceDoc( coerceDoc( data.autosave.page ) );
								if ( data.autosave.title ) {
									setMeta( { title: data.autosave.title } );
								}
							}
							setAutosaveNotice( false );
						} }
					>
						{ __( 'Restore the autosave', 'crazylinks' ) }
					</Button>
				</Notice>
			) }
			<div className="cl-editor__body">
				<section
					className="cl-editor__panel"
					aria-label={ __( 'Page settings', 'crazylinks' ) }
				>
					<TabPanel
						className="cl-tabs"
						tabs={ tabs }
						initialTabName="links"
					>
						{ ( tab ) => {
							switch ( tab.name ) {
								case 'design':
									return <DesignTab />;
								case 'settings':
									return <SettingsTab />;
								case 'analytics':
									return <AnalyticsTab />;
								case 'ai':
									return <AiTab />;
								default:
									return <LinksTab />;
							}
						} }
					</TabPanel>
				</section>
				<section
					className="cl-editor__preview"
					aria-label={ __( 'Live preview', 'crazylinks' ) }
				>
					<Preview />
				</section>
			</div>
			{ modal === 'history' && (
				<HistoryPanel onClose={ () => setModal( null ) } />
			) }
			{ modal === 'json' && (
				<JsonPanel onClose={ () => setModal( null ) } />
			) }
			{ wizard && (
				<Wizard
					onSkip={ () => setWizard( false ) }
					onDone={ ( next, name ) => {
						replaceDoc( {
							...next,
							blocks: [ ...next.blocks, ...doc.blocks ],
						} );
						if ( name && ! title ) {
							setMeta( { title: name } );
						}
						setWizard( false );
					} }
				/>
			) }
		</div>
	);
}
