/**
 * Title suggestions for the selected link, and a bio rewrite.
 */
import { __ } from '@wordpress/i18n';
import { Button } from '@wordpress/components';
import { useEditor } from '../hooks';
import { useAi } from './hooks';
import { insertSuggestion, suggest } from './run';
import type { AiState } from './types';

const selectSuggestions = ( s: AiState ) => s.suggestions;
const selectStatus = ( s: AiState ) => s.status;

export default function Suggestions() {
	const selected = useEditor( ( s ) => s.getSelected(), [] );
	const block = useEditor(
		( s ) => ( selected ? s.getBlock( selected ) : undefined ),
		[ selected ]
	);
	const suggestions = useAi( selectSuggestions );
	const status = useAi( selectStatus );
	const isLink = block?.type === 'link';
	const busy = status !== 'idle';

	return (
		<div className="cl-ai-suggest">
			<h3 className="cl-ai-tab__heading">
				{ __( 'Suggestions', 'crazylinks' ) }
			</h3>
			<div className="cl-ai-suggest__buttons">
				<Button
					variant="secondary"
					size="compact"
					disabled={ ! isLink || busy }
					onClick={ () => block && suggest( 'titles', block.id ) }
					isBusy={
						status === 'suggesting' && suggestions?.kind !== 'bio'
					}
				>
					{ __(
						'Suggest titles for the selected link',
						'crazylinks'
					) }
				</Button>
				<Button
					variant="secondary"
					size="compact"
					disabled={ busy }
					onClick={ () => suggest( 'bio' ) }
				>
					{ __( 'Rewrite my bio', 'crazylinks' ) }
				</Button>
			</div>
			{ ! isLink && (
				<p className="cl-ai-tab__hint">
					{ __(
						'Select a link in the Links tab or the preview to get title ideas.',
						'crazylinks'
					) }
				</p>
			) }
			{ suggestions && suggestions.items.length > 0 && (
				<ul className="cl-ai-suggest__list">
					{ suggestions.items.map( ( text, i ) => (
						<li key={ i }>
							<Button
								variant="link"
								onClick={ () => insertSuggestion( text ) }
							>
								{ text }
							</Button>
						</li>
					) ) }
				</ul>
			) }
		</div>
	);
}
