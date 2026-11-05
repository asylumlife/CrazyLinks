/**
 * Before/after phone preview of a proposal, rendered by the same endpoint as the main preview.
 */
import { __ } from '@wordpress/i18n';
import { Button, ButtonGroup, Spinner } from '@wordpress/components';
import { useEffect, useState } from '@wordpress/element';
import { api } from '../lib/api';
import { buildSrcDoc } from '../lib/preview';
import type { PageDoc } from '../types';

interface Props {
	before: PageDoc;
	after: PageDoc;
	pageId: number;
	view: 'after' | 'before';
	onView: ( view: 'after' | 'before' ) => void;
}

export default function AiPreview( {
	before,
	after,
	pageId,
	view,
	onView,
}: Props ) {
	const [ srcDoc, setSrcDoc ] = useState( '' );
	const [ loading, setLoading ] = useState( false );
	const [ failed, setFailed ] = useState( false );
	const doc = view === 'after' ? after : before;

	useEffect( () => {
		let live = true;
		setLoading( true );
		setFailed( false );
		api.preview( doc, pageId )
			.then( ( res ) => {
				if ( live ) {
					setSrcDoc( buildSrcDoc( res, 0 ) );
				}
			} )
			.catch( () => live && setFailed( true ) )
			.finally( () => live && setLoading( false ) );
		return () => {
			live = false;
		};
	}, [ doc, pageId ] );

	return (
		<div className="cl-ai-preview">
			<div className="cl-ai-preview__bar">
				<ButtonGroup aria-label={ __( 'Compare', 'crazylinks' ) }>
					<Button
						size="compact"
						isPressed={ view === 'before' }
						onClick={ () => onView( 'before' ) }
					>
						{ __( 'Before', 'crazylinks' ) }
					</Button>
					<Button
						size="compact"
						isPressed={ view === 'after' }
						onClick={ () => onView( 'after' ) }
					>
						{ __( 'After', 'crazylinks' ) }
					</Button>
				</ButtonGroup>
				<span aria-live="polite">{ loading && <Spinner /> }</span>
			</div>
			{ failed ? (
				<p className="cl-ai-preview__note">
					{ __( 'The preview could not be rendered.', 'crazylinks' ) }
				</p>
			) : (
				<div className="cl-phone cl-ai-preview__phone">
					<iframe
						className="cl-phone__screen"
						title={
							view === 'after'
								? __(
										'Preview with the AI changes',
										'crazylinks'
									)
								: __(
										'Preview without the AI changes',
										'crazylinks'
									)
						}
						sandbox="allow-scripts"
						srcDoc={ srcDoc }
					/>
				</div>
			) }
		</div>
	);
}
