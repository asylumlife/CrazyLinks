/**
 * Theme picker: one button per theme preset, each with a live swatch of its background, text and
 * button style.
 */
import { __ } from '@wordpress/i18n';
import type { CSSProperties, KeyboardEvent } from 'react';
import type { ThemePreset } from '../types';
import { boot } from '../boot';
import { classes } from '../lib/classes';
import { backgroundCss } from '../lib/theme';

interface Props {
	/** Slug of the selected theme. */
	current: string;
	onPick: ( slug: string ) => void;
	/** Extra class for the list. */
	className?: string;
}

/** Colour of swatch text and buttons when a theme sets none. */
const SWATCH_INK = '#111';
const SWATCH_BUTTON_TEXT = '#fff';

const PILL_RADIUS = 999;
const ROUNDED_RADIUS = 6;

function radiusFor( shape: unknown ): number {
	if ( shape === 'pill' ) {
		return PILL_RADIUS;
	}
	return shape === 'square' ? 0 : ROUNDED_RADIUS;
}

/** Style of the sample buttons in a theme's swatch. */
function buttonStyle( button: NonNullable< ThemePreset[ 'button' ] > ) {
	const color = String( button.color ?? SWATCH_INK );
	return {
		background: button.fill === 'outline' ? 'transparent' : color,
		border: `2px solid ${ color }`,
		borderRadius: radiusFor( button.shape ),
	} satisfies CSSProperties;
}

/** Steps through the radios for each key that moves focus inside the group. */
const FOCUS_STEPS: Record< string, number > = {
	ArrowRight: 1,
	ArrowDown: 1,
	ArrowLeft: -1,
	ArrowUp: -1,
};

export default function ThemePicker( { current, onPick, className }: Props ) {
	const themes = Object.entries( boot().themes );
	// The group is one tab stop: the selected theme, or the first when none is selected.
	const tabStop = themes.some( ( [ slug ] ) => slug === current )
		? current
		: themes[ 0 ]?.[ 0 ];

	// Arrow keys, Home and End move focus between themes. Picking stays an explicit click, Space or
	// Enter, because applying a theme resets the page's colours.
	const onKeyDown = ( e: KeyboardEvent< HTMLDivElement > ) => {
		const radios = Array.from(
			e.currentTarget.querySelectorAll< HTMLElement >( '[role="radio"]' )
		);
		const from = radios.indexOf( e.target as HTMLElement );
		if ( from < 0 ) {
			return;
		}
		let to: number;
		if ( e.key === 'Home' ) {
			to = 0;
		} else if ( e.key === 'End' ) {
			to = radios.length - 1;
		} else if ( e.key in FOCUS_STEPS ) {
			to =
				( from + FOCUS_STEPS[ e.key ] + radios.length ) % radios.length;
		} else {
			return;
		}
		e.preventDefault();
		radios[ to ].focus();
	};

	return (
		// eslint-disable-next-line jsx-a11y/interactive-supports-focus -- focus goes to the radios inside (roving tabindex), not to the group.
		<div
			className={ classes( className, 'cl-themes' ) }
			role="radiogroup"
			aria-label={ __( 'Theme', 'crazylinks' ) }
			onKeyDown={ onKeyDown }
		>
			{ themes.map( ( [ slug, theme ] ) => {
				const button = theme.button ?? {};
				const sample = buttonStyle( button );
				const checked = current === slug;
				return (
					<button
						type="button"
						role="radio"
						aria-checked={ checked }
						tabIndex={ slug === tabStop ? 0 : -1 }
						key={ slug }
						className={ classes(
							'cl-theme',
							checked && 'is-current'
						) }
						onClick={ () => onPick( slug ) }
					>
						<span
							className="cl-theme__swatch"
							style={ {
								background: backgroundCss( theme.background ),
							} }
							aria-hidden="true"
						>
							<span
								className="cl-theme__aa"
								style={ {
									color: String(
										theme.text?.color ?? SWATCH_INK
									),
								} }
							>
								Aa
							</span>
							<span
								className="cl-theme__btn"
								style={ {
									...sample,
									color: String(
										button.text_color ?? SWATCH_BUTTON_TEXT
									),
								} }
							/>
							<span className="cl-theme__btn" style={ sample } />
						</span>
						<span className="cl-theme__label">{ theme.label }</span>
					</button>
				);
			} ) }
		</div>
	);
}
