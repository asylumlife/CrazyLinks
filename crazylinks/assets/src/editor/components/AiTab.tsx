/**
 * AI tab: describe a change, review the proposal (summary, change list, before/after preview),
 * then apply or discard. When AI cannot run, a one-line pointer explains why.
 */
import { __ } from '@wordpress/i18n';
import {
	Button,
	Notice,
	TextareaControl,
	ToggleControl,
} from '@wordpress/components';
import { useDispatch } from '@wordpress/data';
import { useState } from '@wordpress/element';
import { boot } from '../boot';
import { useEditor } from '../hooks';
import { registerAiStore, aiStore, aiSelectors } from '../ai/store';
import { useAi } from '../ai/hooks';
import { modes, modeInfo } from '../ai/modes';
import { aiApi } from '../ai/api';
import { propose } from '../ai/run';
import ProposalView from '../ai/ProposalView';
import History from '../ai/History';
import Suggestions from '../ai/Suggestions';
import Unavailable from '../ai/Unavailable';
import type { AiBoot, AiState } from '../ai/types';
import '../ai/style.scss';

registerAiStore();

const selectState = ( s: AiState ) => s;

export default function AiTab() {
	const ai = boot().ai as AiBoot;
	const ready = ai.ready ?? ai.available;
	if ( ! ready ) {
		return (
			<div className="cl-ai-tab" data-cl-ai-slot>
				<Unavailable ai={ ai } />
			</div>
		);
	}
	return <AiTools ai={ ai } />;
}

function AiTools( { ai }: { ai: AiBoot } ) {
	const state = useAi( selectState );
	const pageId = useEditor( ( s ) => s.getMeta().id, [] );
	const { setMode, setInstruction } = useDispatch( aiStore );
	const [ confirm, setConfirm ] = useState( !! ai.requireConfirmation );
	const current = aiSelectors.getCurrent( state );
	const info = modeInfo( state.mode );
	const busy = state.status !== 'idle';

	return (
		<div className="cl-ai-tab" data-cl-ai-slot>
			<div>
				<div
					className="cl-ai-modes"
					role="group"
					aria-label={ __( 'What do you want to do?', 'crazylinks' ) }
				>
					{ modes().map( ( m ) => (
						<Button
							key={ m.mode }
							size="compact"
							variant={
								m.mode === state.mode ? 'primary' : 'secondary'
							}
							isPressed={ m.mode === state.mode }
							onClick={ () => setMode( m.mode ) }
						>
							{ m.label }
						</Button>
					) ) }
				</div>
				<form
					onSubmit={ ( e ) => {
						e.preventDefault();
						propose();
					} }
				>
					<TextareaControl
						label={ __( 'Ask AI', 'crazylinks' ) }
						hideLabelFromVision
						placeholder={ info.placeholder }
						value={ state.instruction }
						rows={ 3 }
						maxLength={ 2000 }
						onChange={ ( v: string ) => setInstruction( v ) }
						onKeyDown={ ( e: {
							key: string;
							metaKey: boolean;
							ctrlKey: boolean;
							preventDefault: () => void;
						} ) => {
							if (
								e.key === 'Enter' &&
								( e.metaKey || e.ctrlKey )
							) {
								e.preventDefault();
								propose();
							}
						} }
						__nextHasNoMarginBottom
					/>
					<div className="cl-ai-proposal__actions">
						<Button
							type="submit"
							variant="primary"
							disabled={
								busy ||
								! state.instruction.trim() ||
								pageId <= 0
							}
							isBusy={ state.status === 'proposing' }
						>
							{ state.status === 'proposing'
								? __( 'Thinking…', 'crazylinks' )
								: __( 'Propose changes', 'crazylinks' ) }
						</Button>
					</div>
				</form>
				<ul
					className="cl-ai-tab__examples"
					aria-label={ __( 'Examples', 'crazylinks' ) }
				>
					{ info.examples.map( ( ex ) => (
						<li key={ ex }>
							<Button
								variant="link"
								onClick={ () => setInstruction( ex ) }
							>
								{ ex }
							</Button>
						</li>
					) ) }
				</ul>
				<p className="cl-ai-tab__hint">
					{ __(
						'Nothing changes until you press Apply. Every applied change can be undone and is kept in the page history.',
						'crazylinks'
					) }
				</p>
			</div>

			{ state.error && (
				<Notice status="error" isDismissible={ false }>
					{ state.error }
					{ state.errors.length > 0 && (
						<ul>
							{ state.errors.slice( 0, 5 ).map( ( e, i ) => (
								<li key={ i }>{ e.message }</li>
							) ) }
						</ul>
					) }
				</Notice>
			) }

			{ current && <ProposalView entry={ current } /> }
			<History />
			<Suggestions />

			<ToggleControl
				label={ __(
					'External AI agents need my confirmation for this page',
					'crazylinks'
				) }
				help={ __(
					'Changes proposed by assistants connected through the MCP Adapter can only be applied here, in the editor.',
					'crazylinks'
				) }
				checked={ confirm }
				onChange={ ( v: boolean ) => {
					setConfirm( v );
					aiApi
						.pageSettings( pageId, v )
						.catch( () => setConfirm( ! v ) );
				} }
				__nextHasNoMarginBottom
			/>
		</div>
	);
}
