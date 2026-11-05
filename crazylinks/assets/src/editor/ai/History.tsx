/**
 * This session's proposals.
 */
import { __ } from '@wordpress/i18n';
import { Button } from '@wordpress/components';
import { useDispatch } from '@wordpress/data';
import { aiStore } from './store';
import { useAi } from './hooks';
import type { AiState, EntryState } from './types';

const selectHistory = ( s: AiState ) => s.history;
const selectCurrent = ( s: AiState ) => s.current;

function stateLabel( state: EntryState ): string {
	switch ( state ) {
		case 'applied':
			return __( 'applied', 'crazylinks' );
		case 'discarded':
			return __( 'discarded', 'crazylinks' );
		default:
			return __( 'open', 'crazylinks' );
	}
}

export default function History() {
	const history = useAi( selectHistory );
	const current = useAi( selectCurrent );
	const { selectEntry } = useDispatch( aiStore );
	if ( history.length < 2 ) {
		return null;
	}
	return (
		<details className="cl-ai-history">
			<summary>
				{ __( 'Earlier requests this session', 'crazylinks' ) }
			</summary>
			<ul>
				{ history.map( ( e ) => (
					<li key={ e.key }>
						<Button
							variant="link"
							isPressed={ e.key === current }
							onClick={ () => selectEntry( e.key ) }
						>
							{ e.instruction }
						</Button>{ ' ' }
						<span className="cl-ai-history__state">
							{ stateLabel( e.state ) }
						</span>
					</li>
				) ) }
			</ul>
		</details>
	);
}
