/**
 * One-line pointer shown instead of the AI tools when AI editing cannot run.
 */
import { __ } from '@wordpress/i18n';
import type { AiBoot } from './types';

export default function Unavailable( { ai }: { ai: AiBoot } ) {
	let link: { url: string; label: string } | null = null;
	if ( ai.reason === 'no_provider' && ai.connectorsUrl ) {
		link = {
			url: ai.connectorsUrl,
			label: __( 'Open Settings → Connectors', 'crazylinks' ),
		};
	} else if ( ai.reason === 'disabled' && ai.settingsUrl ) {
		link = {
			url: ai.settingsUrl,
			label: __( 'Open CrazyLinks settings', 'crazylinks' ),
		};
	}
	return (
		<p className="cl-ai-unavailable" role="status">
			{ ai.message ||
				__(
					'AI editing is not available. Connect an AI provider in Settings → Connectors.',
					'crazylinks'
				) }{ ' ' }
			{ link && <a href={ link.url }>{ link.label }</a> }
		</p>
	);
}
