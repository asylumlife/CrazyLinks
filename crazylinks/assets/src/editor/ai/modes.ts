/**
 * Mode chips and example prompts.
 */
import { __ } from '@wordpress/i18n';
import type { AiMode } from './types';

export interface ModeInfo {
	mode: AiMode;
	label: string;
	placeholder: string;
	examples: string[];
}

export function modes(): ModeInfo[] {
	return [
		{
			mode: 'edit',
			label: __( 'Edit', 'crazylinks' ),
			placeholder: __( 'Describe the change…', 'crazylinks' ),
			examples: [
				__(
					'Move the podcast link under the merch link',
					'crazylinks'
				),
				__( 'Pin my newest link to the top', 'crazylinks' ),
				__( 'Hide the tour link after December 31', 'crazylinks' ),
			],
		},
		{
			mode: 'build_from_site',
			label: __( 'Build from this site', 'crazylinks' ),
			placeholder: __(
				'Anything to include or leave out?',
				'crazylinks'
			),
			examples: [
				__( 'Build my link page from this site', 'crazylinks' ),
				__(
					'Use my latest posts and my social profiles',
					'crazylinks'
				),
			],
		},
		{
			mode: 'build_from_description',
			label: __( 'Build from description', 'crazylinks' ),
			placeholder: __(
				'Who are you and what should visitors find?',
				'crazylinks'
			),
			examples: [
				__(
					"I'm a wedding photographer in Austin. I want booking, portfolio and Instagram.",
					'crazylinks'
				),
			],
		},
		{
			mode: 'restyle',
			label: __( 'Restyle', 'crazylinks' ),
			placeholder: __( 'Describe a look or a mood…', 'crazylinks' ),
			examples: [
				__( 'Make it feel like a 90s skate brand', 'crazylinks' ),
				__( 'Calm and minimal, black text on white', 'crazylinks' ),
			],
		},
		{
			mode: 'analytics_qa',
			label: __( 'Ask about analytics', 'crazylinks' ),
			placeholder: __( 'Ask a question about clicks…', 'crazylinks' ),
			examples: [
				__(
					'Which link did best this month and what should I pin?',
					'crazylinks'
				),
			],
		},
	];
}

export function modeInfo( mode: AiMode ): ModeInfo {
	return modes().find( ( m ) => m.mode === mode ) ?? modes()[ 0 ];
}
