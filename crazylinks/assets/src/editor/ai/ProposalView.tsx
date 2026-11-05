/**
 * A proposal: summary, change list, questions to answer, before/after preview, Apply / Discard.
 */
import { __, sprintf } from '@wordpress/i18n';
import {
	Button,
	Dashicon,
	Notice,
	TextareaControl,
} from '@wordpress/components';
import { useDispatch } from '@wordpress/data';
import { useEditor } from '../hooks';
import AiPreview from './AiPreview';
import { aiStore } from './store';
import { useAi } from './hooks';
import { applyProposal, propose } from './run';
import { changeIcon, sortChanges, summarizeChanges } from './changes';
import { coerceDoc } from '../store/doc';
import type { HistoryEntry } from './types';

const selectAnswers = ( s: { answers: Record< number, string > } ) => s.answers;
const selectView = ( s: { view: 'after' | 'before' } ) => s.view;
const selectStatus = ( s: { status: string } ) => s.status;

export default function ProposalView( { entry }: { entry: HistoryEntry } ) {
	const answers = useAi( selectAnswers );
	const view = useAi( selectView );
	const status = useAi( selectStatus );
	const doc = useEditor( ( s ) => s.getDoc(), [] );
	const pageId = useEditor( ( s ) => s.getMeta().id, [] );
	const { setAnswer, setView, discard } = useDispatch( aiStore );
	const { proposal } = entry;
	const open = entry.state === 'open';
	const hasOps = proposal.operations.length > 0 && !! proposal.proposal_id;
	const busy = status !== 'idle';

	return (
		<div className="cl-ai-proposal" aria-live="polite">
			<p className="cl-ai-proposal__summary">{ proposal.summary }</p>
			{ proposal.change_list.length > 0 && (
				<>
					<p className="cl-ai-proposal__counts">
						{ summarizeChanges( proposal.change_list ) }
					</p>
					<ul className="cl-ai-changes">
						{ sortChanges( proposal.change_list ).map(
							( item, i ) => (
								<li
									key={ i }
									className={ `cl-ai-changes__item is-${ item.kind }` }
								>
									<Dashicon
										icon={
											changeIcon( item.kind ) as never
										}
									/>
									<span>{ item.text }</span>
								</li>
							)
						) }
					</ul>
				</>
			) }
			{ proposal.questions.length > 0 && open && (
				<div className="cl-ai-questions">
					<p className="cl-ai-questions__intro">
						{ __(
							'The AI needs a little more detail:',
							'crazylinks'
						) }
					</p>
					{ proposal.questions.map( ( q, i ) => (
						<TextareaControl
							key={ i }
							label={ q }
							rows={ 2 }
							value={ answers[ i ] ?? '' }
							onChange={ ( v: string ) => setAnswer( i, v ) }
							__nextHasNoMarginBottom
						/>
					) ) }
					<Button
						variant="secondary"
						onClick={ () => propose() }
						disabled={
							busy ||
							! Object.values( answers ).some( ( a ) => a.trim() )
						}
						isBusy={ status === 'proposing' }
					>
						{ __( 'Send answers', 'crazylinks' ) }
					</Button>
				</div>
			) }
			{ proposal.budget?.warning && (
				<Notice status="warning" isDismissible={ false }>
					{ sprintf(
						/* translators: %d: percentage of the monthly AI budget used. */
						__(
							'This site has used %d%% of its monthly AI budget.',
							'crazylinks'
						),
						Math.round( proposal.budget.share * 100 )
					) }
				</Notice>
			) }
			{ hasOps && (
				<AiPreview
					before={ doc }
					after={ coerceDoc( proposal.preview_document ) }
					pageId={ pageId }
					view={ view }
					onView={ ( v ) => setView( v ) }
				/>
			) }
			{ hasOps && open && (
				<div className="cl-ai-proposal__actions">
					<Button
						variant="primary"
						onClick={ () => applyProposal( entry ) }
						disabled={ busy }
						isBusy={ status === 'applying' }
					>
						{ __( 'Apply', 'crazylinks' ) }
					</Button>
					<Button
						variant="tertiary"
						onClick={ () => discard( entry.key ) }
						disabled={ busy }
					>
						{ __( 'Discard', 'crazylinks' ) }
					</Button>
				</div>
			) }
			{ entry.state === 'applied' && (
				<p className="cl-ai-proposal__done">
					<Dashicon icon="yes" />{ ' ' }
					{ __(
						'Applied and saved. Use Undo or the page history to go back.',
						'crazylinks'
					) }
				</p>
			) }
		</div>
	);
}
