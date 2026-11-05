/**
 * Design tab: theme picker with live swatches, background, text, buttons, layout, custom CSS.
 */
import { __, sprintf } from '@wordpress/i18n';
import {
	Notice,
	PanelBody,
	RangeControl,
	SelectControl,
	TextareaControl,
	ToggleControl,
} from '@wordpress/components';
import type { JsonObject, JsonValue } from '../types';
import { useEditor, useEditorDispatch } from '../hooks';
import { boot } from '../boot';
import ColorField from './ColorField';
import MediaField from './MediaField';
import { backgroundCss, effective, resolveColorTokens } from '../lib/theme';
import { badgeIssue, contrastIssues } from '../lib/contrast';
import { getPath } from '../lib/paths';

function radiusFor( shape: unknown ): number {
	if ( shape === 'pill' ) {
		return 999;
	}
	return shape === 'square' ? 0 : 6;
}

function ThemePicker( {
	current,
	onPick,
}: {
	current: string;
	onPick: ( slug: string ) => void;
} ) {
	const themes = boot().themes;
	return (
		<div
			className="cl-themes"
			role="radiogroup"
			aria-label={ __( 'Theme', 'crazylinks' ) }
		>
			{ Object.entries( themes ).map( ( [ slug, t ] ) => {
				const button = t.button ?? {};
				const text = t.text ?? {};
				const checked = current === slug;
				return (
					<button
						type="button"
						role="radio"
						aria-checked={ checked }
						key={ slug }
						className={
							checked ? 'cl-theme is-current' : 'cl-theme'
						}
						onClick={ () => onPick( slug ) }
					>
						<span
							className="cl-theme__swatch"
							style={ {
								background: backgroundCss( t.background ),
							} }
							aria-hidden="true"
						>
							<span
								className="cl-theme__aa"
								style={ {
									color: String( text.color ?? '#111' ),
								} }
							>
								Aa
							</span>
							<span
								className="cl-theme__btn"
								style={ {
									background:
										button.fill === 'outline'
											? 'transparent'
											: String( button.color ?? '#111' ),
									color: String(
										button.text_color ?? '#fff'
									),
									border: `2px solid ${ String( button.color ?? '#111' ) }`,
									borderRadius: radiusFor( button.shape ),
								} }
							/>
							<span
								className="cl-theme__btn"
								style={ {
									background:
										button.fill === 'outline'
											? 'transparent'
											: String( button.color ?? '#111' ),
									border: `2px solid ${ String( button.color ?? '#111' ) }`,
									borderRadius: radiusFor( button.shape ),
								} }
							/>
						</span>
						<span className="cl-theme__label">{ t.label }</span>
					</button>
				);
			} ) }
		</div>
	);
}

function contrastLabels(): Record< string, string > {
	return {
		text: __( 'Body text on the background', 'crazylinks' ),
		headings: __( 'Headings on the background', 'crazylinks' ),
		muted: __( 'Muted text on the background', 'crazylinks' ),
		'muted-card': __( 'Muted text on cards', 'crazylinks' ),
		accent: __( 'Accent colour on the background', 'crazylinks' ),
		button: __( 'Button text on the button', 'crazylinks' ),
		'button-solid': __( 'Sign-up button text on the button', 'crazylinks' ),
		badge: __( 'Badge text on the badge', 'crazylinks' ),
	};
}

/**
 * Warning chips for every text/background pair of the resolved design (light and, where the theme
 * has one, dark mode) and for link badges with a custom colour, with the failing ratio.
 */
function ContrastChips() {
	const design = useEditor( ( s ) => s.getDoc().design, [] );
	const blocks = useEditor( ( s ) => s.getDoc().blocks, [] );
	const slug =
		typeof design.theme === 'string' && design.theme
			? design.theme
			: 'default';
	const themes = boot().themes;
	const preset = themes[ slug ] ?? themes.default;
	const labels = contrastLabels();
	const light = contrastIssues(
		resolveColorTokens( design, preset, false ),
		labels
	);
	const dark =
		design.dark_mode !== 'light' && preset?.dark
			? contrastIssues(
					resolveColorTokens( design, preset, true ),
					labels
				)
			: [];
	const chips: Array< { key: string; text: string } > = [
		...light.map( ( i ) => ( { key: `l-${ i.id }`, issue: i, mode: '' } ) ),
		...dark.map( ( i ) => ( {
			key: `d-${ i.id }`,
			issue: i,
			mode: ' ' + __( '(dark mode)', 'crazylinks' ),
		} ) ),
	].map( ( { key, issue, mode } ) => ( {
		key,
		text: sprintf(
			/* translators: 1: pair of colours, 2: dark mode marker, 3: ratio, 4: required ratio. */
			__( '%1$s%2$s: %3$s:1, needs %4$s:1', 'crazylinks' ),
			issue.label,
			mode,
			issue.ratio.toFixed( 1 ),
			String( issue.min )
		),
	} ) );
	for ( const b of blocks ) {
		const color = ( b.props.badge as JsonObject | undefined )?.color;
		const ratio =
			b.type === 'link' && typeof color === 'string'
				? badgeIssue( color )
				: null;
		if ( ratio !== null ) {
			chips.push( {
				key: `b-${ b.id }`,
				text: sprintf(
					/* translators: 1: link title, 2: ratio. */
					__(
						'Badge on "%1$s": white text on the badge colour is %2$s:1, needs 4.5:1',
						'crazylinks'
					),
					typeof b.props.title === 'string' ? b.props.title : '',
					ratio.toFixed( 1 )
				),
			} );
		}
	}
	if ( chips.length === 0 ) {
		return null;
	}
	return (
		<div className="cl-contrast-chips">
			<p className="cl-contrast-chips__title">
				{ __(
					'Low contrast. Aim for 4.5:1 (3:1 for headings) so everyone can read the page.',
					'crazylinks'
				) }
			</p>
			<ul>
				{ chips.map( ( c ) => (
					<li key={ c.key } className="cl-chip cl-chip--warning">
						<span
							className="dashicons dashicons-warning"
							aria-hidden="true"
						/>
						{ c.text }
					</li>
				) ) }
			</ul>
		</div>
	);
}

export default function DesignTab() {
	const design = useEditor( ( s ) => s.getDoc().design, [] );
	const errors = useEditor( ( s ) => s.getErrors(), [] );
	const { setSection, applyTheme } = useEditorDispatch();
	const data = boot();
	const slug =
		typeof design.theme === 'string' && design.theme
			? design.theme
			: 'default';
	const preset = data.themes[ slug ] ?? data.themes.default;
	const eff = ( ...path: string[] ) => effective( design, preset, path );
	const own = ( ...path: string[] ) => getPath( design, path );
	const set = ( path: string[], v: JsonValue | undefined ) =>
		setSection( 'design', path, v );
	const err = ( path: string ) =>
		errors.find(
			( e ) =>
				e.field === `/design/${ path }` ||
				e.field.startsWith( `/design/${ path }/` )
		)?.message;
	const color = ( label: string, path: string[], allowEmpty = false ) => (
		<ColorField
			label={ label }
			value={
				typeof own( ...path ) === 'string'
					? ( own( ...path ) as string )
					: undefined
			}
			fallback={
				typeof eff( ...path ) === 'string'
					? ( eff( ...path ) as string )
					: undefined
			}
			allowEmpty={ allowEmpty }
			onChange={ ( v ) => set( path, v ) }
			error={ err( path.join( '/' ) ) }
		/>
	);
	const select = (
		label: string,
		path: string[],
		options: Array< [ string, string ] >
	) => (
		<SelectControl
			__next40pxDefaultSize
			__nextHasNoMarginBottom
			label={ label }
			value={ String( eff( ...path ) ?? options[ 0 ][ 0 ] ) }
			options={ options.map( ( [ value, l ] ) => ( {
				value,
				label: l,
			} ) ) }
			onChange={ ( v: string ) => set( path, v ) }
		/>
	);
	const range = (
		label: string,
		path: string[],
		min: number,
		max: number,
		fallback: number
	) => (
		<RangeControl
			__next40pxDefaultSize
			__nextHasNoMarginBottom
			label={ label }
			min={ min }
			max={ max }
			value={
				typeof eff( ...path ) === 'number'
					? ( eff( ...path ) as number )
					: fallback
			}
			allowReset
			resetFallbackValue={ undefined }
			onChange={ ( v?: number ) =>
				set( path, typeof v === 'number' ? v : undefined )
			}
		/>
	);
	const bgType = String( eff( 'background', 'type' ) ?? 'solid' );
	const bg = ( eff( 'background' ) ?? {} ) as JsonObject;

	return (
		<div className="cl-design-tab">
			<ContrastChips />
			<PanelBody title={ __( 'Theme', 'crazylinks' ) }>
				<p className="cl-muted">
					{ __(
						'Picking a theme resets colours, background and button style to the theme. Layout and custom CSS are kept.',
						'crazylinks'
					) }
				</p>
				<ThemePicker current={ slug } onPick={ applyTheme } />
			</PanelBody>
			<PanelBody
				title={ __( 'Background', 'crazylinks' ) }
				initialOpen={ false }
			>
				<div
					className="cl-bg-sample"
					style={ { background: backgroundCss( bg ) } }
					aria-hidden="true"
				/>
				{ select(
					__( 'Type', 'crazylinks' ),
					[ 'background', 'type' ],
					[
						[ 'solid', __( 'Solid colour', 'crazylinks' ) ],
						[ 'gradient', __( 'Gradient', 'crazylinks' ) ],
						[ 'image', __( 'Image', 'crazylinks' ) ],
						[ 'pattern', __( 'Pattern', 'crazylinks' ) ],
					]
				) }
				{ ( bgType === 'solid' ||
					bgType === 'pattern' ||
					bgType === 'image' ) &&
					color( __( 'Colour', 'crazylinks' ), [
						'background',
						'color',
					] ) }
				{ bgType === 'gradient' && (
					<>
						{ color( __( 'From', 'crazylinks' ), [
							'background',
							'from',
						] ) }
						{ color( __( 'To', 'crazylinks' ), [
							'background',
							'to',
						] ) }
						{ range(
							__( 'Angle', 'crazylinks' ),
							[ 'background', 'angle' ],
							0,
							360,
							180
						) }
						<ToggleControl
							__nextHasNoMarginBottom
							label={ __( 'Radial', 'crazylinks' ) }
							checked={ eff( 'background', 'radial' ) === true }
							onChange={ ( v: boolean ) =>
								set( [ 'background', 'radial' ], v )
							}
						/>
					</>
				) }
				{ bgType === 'image' && (
					<>
						<MediaField
							label={ __( 'Background image', 'crazylinks' ) }
							value={
								typeof own( 'background', 'image' ) === 'number'
									? ( own( 'background', 'image' ) as number )
									: undefined
							}
							onChange={ ( v ) =>
								set( [ 'background', 'image' ], v )
							}
						/>
						{ color(
							__( 'Overlay', 'crazylinks' ),
							[ 'background', 'overlay' ],
							true
						) }
						{ range(
							__( 'Blur', 'crazylinks' ),
							[ 'background', 'blur' ],
							0,
							40,
							0
						) }
					</>
				) }
				{ bgType === 'pattern' && (
					<>
						{ select(
							__( 'Pattern', 'crazylinks' ),
							[ 'background', 'pattern' ],
							[
								[ 'dots', __( 'Dots', 'crazylinks' ) ],
								[ 'grid', __( 'Grid', 'crazylinks' ) ],
								[ 'stripes', __( 'Stripes', 'crazylinks' ) ],
								[ 'lines', __( 'Lines', 'crazylinks' ) ],
								[ 'checks', __( 'Checks', 'crazylinks' ) ],
								[ 'noise', __( 'Noise', 'crazylinks' ) ],
							]
						) }
						{ color( __( 'Pattern colour', 'crazylinks' ), [
							'background',
							'pattern_color',
						] ) }
						{ range(
							__( 'Pattern size', 'crazylinks' ),
							[ 'background', 'pattern_size' ],
							8,
							80,
							24
						) }
					</>
				) }
			</PanelBody>
			<PanelBody
				title={ __( 'Text', 'crazylinks' ) }
				initialOpen={ false }
			>
				{ color( __( 'Text colour', 'crazylinks' ), [
					'text',
					'color',
				] ) }
				{ color( __( 'Muted text colour', 'crazylinks' ), [
					'text',
					'muted',
				] ) }
				{ select(
					__( 'Font', 'crazylinks' ),
					[ 'text', 'font' ],
					[
						[
							'system-sans',
							__( 'Sans-serif (system)', 'crazylinks' ),
						],
						[
							'system-serif',
							__( 'Serif (system)', 'crazylinks' ),
						],
						[
							'system-rounded',
							__( 'Rounded (system)', 'crazylinks' ),
						],
						[
							'system-mono',
							__( 'Monospace (system)', 'crazylinks' ),
						],
					]
				) }
				{ select(
					__( 'Text size', 'crazylinks' ),
					[ 'text', 'size' ],
					[
						[ 'sm', __( 'Small', 'crazylinks' ) ],
						[ 'md', __( 'Medium', 'crazylinks' ) ],
						[ 'lg', __( 'Large', 'crazylinks' ) ],
					]
				) }
			</PanelBody>
			<PanelBody
				title={ __( 'Buttons', 'crazylinks' ) }
				initialOpen={ false }
			>
				{ select(
					__( 'Shape', 'crazylinks' ),
					[ 'button', 'shape' ],
					[
						[ 'rounded', __( 'Rounded', 'crazylinks' ) ],
						[ 'pill', __( 'Pill', 'crazylinks' ) ],
						[ 'square', __( 'Square', 'crazylinks' ) ],
					]
				) }
				{ select(
					__( 'Fill', 'crazylinks' ),
					[ 'button', 'fill' ],
					[
						[ 'solid', __( 'Solid', 'crazylinks' ) ],
						[ 'outline', __( 'Outline', 'crazylinks' ) ],
						[ 'soft', __( 'Soft', 'crazylinks' ) ],
						[ 'glass', __( 'Glass', 'crazylinks' ) ],
					]
				) }
				{ color( __( 'Button colour', 'crazylinks' ), [
					'button',
					'color',
				] ) }
				{ color( __( 'Button text colour', 'crazylinks' ), [
					'button',
					'text_color',
				] ) }
				{ select(
					__( 'Shadow', 'crazylinks' ),
					[ 'button', 'shadow' ],
					[
						[ 'none', __( 'None', 'crazylinks' ) ],
						[ 'soft', __( 'Soft', 'crazylinks' ) ],
						[ 'hard', __( 'Hard', 'crazylinks' ) ],
					]
				) }
				{ range(
					__( 'Border width', 'crazylinks' ),
					[ 'button', 'border_width' ],
					0,
					8,
					0
				) }
				{ select(
					__( 'Hover effect', 'crazylinks' ),
					[ 'button', 'hover' ],
					[
						[ 'lift', __( 'Lift', 'crazylinks' ) ],
						[ 'darken', __( 'Darken', 'crazylinks' ) ],
						[ 'none', __( 'None', 'crazylinks' ) ],
					]
				) }
				{ color( __( 'Accent colour', 'crazylinks' ), [ 'accent' ] ) }
				{ range(
					__( 'Corner radius', 'crazylinks' ),
					[ 'radius' ],
					0,
					48,
					14
				) }
			</PanelBody>
			<PanelBody
				title={ __( 'Layout', 'crazylinks' ) }
				initialOpen={ false }
			>
				{ select(
					__( 'Link layout', 'crazylinks' ),
					[ 'layout' ],
					[
						[ 'list', __( 'List', 'crazylinks' ) ],
						[ 'grid', __( 'Grid (2 columns)', 'crazylinks' ) ],
						[
							'featured',
							__( 'Featured first link', 'crazylinks' ),
						],
					]
				) }
				{ select(
					__( 'Dark mode', 'crazylinks' ),
					[ 'dark_mode' ],
					[
						[
							'auto',
							__( "Follow the visitor's device", 'crazylinks' ),
						],
						[ 'light', __( 'Always light', 'crazylinks' ) ],
						[ 'dark', __( 'Always dark', 'crazylinks' ) ],
					]
				) }
			</PanelBody>
			{ ( data.caps.unfiltered_html || design.custom_css ) && (
				<PanelBody
					title={ __( 'Custom CSS', 'crazylinks' ) }
					initialOpen={ false }
				>
					{ data.caps.unfiltered_html ? (
						<TextareaControl
							__nextHasNoMarginBottom
							label={ __(
								'CSS added to this page',
								'crazylinks'
							) }
							help={ __(
								'Scope rules under .cl-page.',
								'crazylinks'
							) }
							className="cl-code"
							rows={ 8 }
							value={
								typeof design.custom_css === 'string'
									? design.custom_css
									: ''
							}
							onChange={ ( v: string ) =>
								set(
									[ 'custom_css' ],
									v === '' ? undefined : v
								)
							}
						/>
					) : (
						<Notice status="info" isDismissible={ false }>
							{ __(
								'This page has custom CSS. Only users allowed to post unfiltered HTML can change it.',
								'crazylinks'
							) }
						</Notice>
					) }
					{ err( 'custom_css' ) && (
						<p className="cl-field-error">
							{ err( 'custom_css' ) }
						</p>
					) }
				</PanelBody>
			) }
		</div>
	);
}
