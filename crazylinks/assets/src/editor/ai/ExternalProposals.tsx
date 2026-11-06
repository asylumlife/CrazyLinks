/**
 * Changes from external AI agents (Claude Desktop, Claude Code via MCP) that wait for confirmation
 * because the page requires it. Loaded once when the AI tab opens; each one is reviewed with the
 * normal proposal view (preview, Apply, Discard). Works without an AI provider on the site.
 */
import { __, _n, sprintf } from '@wordpress/i18n';
import { Button } from '@wordpress/components';
import { useDispatch } from '@wordpress/data';
import { useEffect } from '@wordpress/element';
import { useEditor } from '../hooks';
import { aiApi } from './api';
import { aiStore, aiSelectors } from './store';
import { useAi } from './hooks';
import type { AiState } from './types';

const selectState = ( s: AiState ) => s;

export default function ExternalProposals() {
	const state = useAi( selectState );
	const pageId = useEditor( ( s ) => s.getMeta().id, [] );
	const { external, selectEntry } = useDispatch( aiStore );

	useEffect( () => {
		if ( pageId <= 0 ) {
			return;
		}
		let live = true;
		aiApi
			.pending( pageId )
			.then( ( list ) => {
				if ( live && Array.isArray( list ) && list.length ) {
					external( list );
				}
			} )
			.catch( () => undefined );
		return () => {
			live = false;
		};
	}, [ pageId, external ] );

	const open = aiSelectors.getExternalOpen( state );
	if ( ! open.length ) {
		return null;
	}
	return (
		<div className="cl-ai-external" role="status">
			<p className="cl-ai-external__intro">
				{ sprintf(
					/* translators: %d: number of pending changes. */
					_n(
						'%d change from an external AI agent is waiting for your confirmation.',
						'%d changes from external AI agents are waiting for your confirmation.',
						open.length,
						'crazylinks'
					),
					open.length
				) }
			</p>
			<ul>
				{ open.map( ( e ) => (
					<li key={ e.key }>
						<Button
							variant="link"
							isPressed={ e.key === state.current }
							onClick={ () => selectEntry( e.key ) }
						>
							{ e.instruction ||
								__(
									'Change from an external AI agent',
									'crazylinks'
								) }
						</Button>
						{ e.proposal.author && (
							<span className="cl-ai-history__state">
								{ ' ' }
								{ sprintf(
									/* translators: %s: WordPress user name the agent used. */
									__( 'by %s', 'crazylinks' ),
									e.proposal.author
								) }
							</span>
						) }
					</li>
				) ) }
			</ul>
		</div>
	);
}
