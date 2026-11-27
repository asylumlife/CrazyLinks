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
import type { JsonValue } from '../types';
import { useEditor, useEditorDispatch } from '../hooks';
import { boot } from '../boot';
import ColorField from './ColorField';
import FieldError from './FieldError';
import MediaField from './MediaField';
import ThemePicker from './ThemePicker';
import {
	backgroundCss,
	effective,
	presetFor,
	resolveColorTokens,
} from '../lib/theme';
import { badgeIssue, contrastIssues } from '../lib/contrast';
import { errorFor } from '../lib/errors';
import { asObject, getPath } from '../lib/paths';
import {
	backgroundTypeOptions,
	buttonFillOptions,
	opts,
	patternOptions,
} from '../registry/options';
import type { Option } from '../registry/options';

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
	const { preset } = presetFor( design, boot().themes );
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
		const color = asObject( b.props.badge ).color;
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

/**
 * The page design with its theme preset, and readers and a writer for single tokens.
 */
function useDesign() {
	const design = useEditor( ( s ) => s.getDoc().design, [] );
	const errors = useEditor( ( s ) => s.getErrors(), [] );
	const { setSection, applyTheme } = useEditorDispatch();
	const { slug, preset } = presetFor( design, boot().themes );
	return {
		design,
		slug,
		applyTheme,
		/** The page's own value of a token (unset when it follows the theme). */
		own: ( path: string[] ) => getPath( design, path ),
		/** The value a token resolves to: the page's own, else the theme's. */
		resolved: ( path: string[] ) => effective( design, preset, path ),
		set: ( path: string[], value: JsonValue | undefined ) =>
			setSection( 'design', path, value ),
		errorAt: ( path: string[] ) =>
			errorFor( errors, `/design/${ path.join( '/' ) }` ),
	};
}

/**
 * Colour control for one design token; the theme's colour is the fallback.
 *
 * @param props            Props.
 * @param props.label      Control label.
 * @param props.path       Token path.
 * @param props.allowEmpty Whether the token accepts '' ("no colour").
 */
function DesignColor( {
	label,
	path,
	allowEmpty = false,
}: {
	label: string;
	path: string[];
	allowEmpty?: boolean;
} ) {
	const { own, resolved, set, errorAt } = useDesign();
	const value = own( path );
	const fallback = resolved( path );
	return (
		<ColorField
			label={ label }
			value={ typeof value === 'string' ? value : undefined }
			fallback={ typeof fallback === 'string' ? fallback : undefined }
			allowEmpty={ allowEmpty }
			onChange={ ( v ) => set( path, v ) }
			error={ errorAt( path ) }
		/>
	);
}

/**
 * Select for one design token; shows the first option while the token is unset everywhere.
 *
 * @param props         Props.
 * @param props.label   Control label.
 * @param props.path    Token path.
 * @param props.options Options.
 */
function DesignSelect( {
	label,
	path,
	options,
}: {
	label: string;
	path: string[];
	options: Option[];
} ) {
	const { resolved, set } = useDesign();
	return (
		<SelectControl
			__next40pxDefaultSize
			__nextHasNoMarginBottom
			label={ label }
			value={ String( resolved( path ) ?? options[ 0 ].value ) }
			options={ options }
			onChange={ ( v: string ) => set( path, v ) }
		/>
	);
}

/**
 * Slider for one numeric design token; resetting it removes the page's own value.
 *
 * @param props          Props.
 * @param props.label    Control label.
 * @param props.path     Token path.
 * @param props.min      Lowest value.
 * @param props.max      Highest value.
 * @param props.fallback Position shown while the token is unset everywhere.
 */
function DesignRange( {
	label,
	path,
	min,
	max,
	fallback,
}: {
	label: string;
	path: string[];
	min: number;
	max: number;
	fallback: number;
} ) {
	const { resolved, set } = useDesign();
	const value = resolved( path );
	return (
		<RangeControl
			__next40pxDefaultSize
			__nextHasNoMarginBottom
			label={ label }
			min={ min }
			max={ max }
			value={ typeof value === 'number' ? value : fallback }
			allowReset
			resetFallbackValue={ undefined }
			onChange={ ( v?: number ) =>
				set( path, typeof v === 'number' ? v : undefined )
			}
		/>
	);
}

function BackgroundPanel() {
	const { own, resolved, set } = useDesign();
	const type = String( resolved( [ 'background', 'type' ] ) ?? 'solid' );
	const image = own( [ 'background', 'image' ] );
	return (
		<PanelBody
			title={ __( 'Background', 'crazylinks' ) }
			initialOpen={ false }
		>
			<div
				className="cl-bg-sample"
				style={ {
					background: backgroundCss(
						asObject( resolved( [ 'background' ] ) )
					),
				} }
				aria-hidden="true"
			/>
			<DesignSelect
				label={ __( 'Type', 'crazylinks' ) }
				path={ [ 'background', 'type' ] }
				options={ backgroundTypeOptions() }
			/>
			{ [ 'solid', 'pattern', 'image' ].includes( type ) && (
				<DesignColor
					label={ __( 'Colour', 'crazylinks' ) }
					path={ [ 'background', 'color' ] }
				/>
			) }
			{ type === 'gradient' && (
				<>
					<DesignColor
						label={ __( 'From', 'crazylinks' ) }
						path={ [ 'background', 'from' ] }
					/>
					<DesignColor
						label={ __( 'To', 'crazylinks' ) }
						path={ [ 'background', 'to' ] }
					/>
					<DesignRange
						label={ __( 'Angle', 'crazylinks' ) }
						path={ [ 'background', 'angle' ] }
						min={ 0 }
						max={ 360 }
						fallback={ 180 }
					/>
					<ToggleControl
						__nextHasNoMarginBottom
						label={ __( 'Radial', 'crazylinks' ) }
						checked={
							resolved( [ 'background', 'radial' ] ) === true
						}
						onChange={ ( v: boolean ) =>
							set( [ 'background', 'radial' ], v )
						}
					/>
				</>
			) }
			{ type === 'image' && (
				<>
					<MediaField
						label={ __( 'Background image', 'crazylinks' ) }
						value={ typeof image === 'number' ? image : undefined }
						onChange={ ( v ) =>
							set( [ 'background', 'image' ], v )
						}
					/>
					<DesignColor
						label={ __( 'Overlay', 'crazylinks' ) }
						path={ [ 'background', 'overlay' ] }
						allowEmpty
					/>
					<DesignRange
						label={ __( 'Blur', 'crazylinks' ) }
						path={ [ 'background', 'blur' ] }
						min={ 0 }
						max={ 40 }
						fallback={ 0 }
					/>
				</>
			) }
			{ type === 'pattern' && (
				<>
					<DesignSelect
						label={ __( 'Pattern', 'crazylinks' ) }
						path={ [ 'background', 'pattern' ] }
						options={ patternOptions() }
					/>
					<DesignColor
						label={ __( 'Pattern colour', 'crazylinks' ) }
						path={ [ 'background', 'pattern_color' ] }
					/>
					<DesignRange
						label={ __( 'Pattern size', 'crazylinks' ) }
						path={ [ 'background', 'pattern_size' ] }
						min={ 8 }
						max={ 80 }
						fallback={ 24 }
					/>
				</>
			) }
		</PanelBody>
	);
}

function TextPanel() {
	return (
		<PanelBody title={ __( 'Text', 'crazylinks' ) } initialOpen={ false }>
			<DesignColor
				label={ __( 'Text colour', 'crazylinks' ) }
				path={ [ 'text', 'color' ] }
			/>
			<DesignColor
				label={ __( 'Muted text colour', 'crazylinks' ) }
				path={ [ 'text', 'muted' ] }
			/>
			<DesignSelect
				label={ __( 'Font', 'crazylinks' ) }
				path={ [ 'text', 'font' ] }
				options={ opts( [
					[
						'system-sans',
						__( 'Sans-serif (system)', 'crazylinks' ),
					],
					[ 'system-serif', __( 'Serif (system)', 'crazylinks' ) ],
					[
						'system-rounded',
						__( 'Rounded (system)', 'crazylinks' ),
					],
					[ 'system-mono', __( 'Monospace (system)', 'crazylinks' ) ],
				] ) }
			/>
			<DesignSelect
				label={ __( 'Text size', 'crazylinks' ) }
				path={ [ 'text', 'size' ] }
				options={ opts( [
					[ 'sm', __( 'Small', 'crazylinks' ) ],
					[ 'md', __( 'Medium', 'crazylinks' ) ],
					[ 'lg', __( 'Large', 'crazylinks' ) ],
				] ) }
			/>
		</PanelBody>
	);
}

function ButtonsPanel() {
	return (
		<PanelBody
			title={ __( 'Buttons', 'crazylinks' ) }
			initialOpen={ false }
		>
			<DesignSelect
				label={ __( 'Shape', 'crazylinks' ) }
				path={ [ 'button', 'shape' ] }
				options={ opts( [
					[ 'rounded', __( 'Rounded', 'crazylinks' ) ],
					[ 'pill', __( 'Pill', 'crazylinks' ) ],
					[ 'square', __( 'Square', 'crazylinks' ) ],
				] ) }
			/>
			<DesignSelect
				label={ __( 'Fill', 'crazylinks' ) }
				path={ [ 'button', 'fill' ] }
				options={ buttonFillOptions() }
			/>
			<DesignColor
				label={ __( 'Button colour', 'crazylinks' ) }
				path={ [ 'button', 'color' ] }
			/>
			<DesignColor
				label={ __( 'Button text colour', 'crazylinks' ) }
				path={ [ 'button', 'text_color' ] }
			/>
			<DesignSelect
				label={ __( 'Shadow', 'crazylinks' ) }
				path={ [ 'button', 'shadow' ] }
				options={ opts( [
					[ 'none', __( 'None', 'crazylinks' ) ],
					[ 'soft', __( 'Soft', 'crazylinks' ) ],
					[ 'hard', __( 'Hard', 'crazylinks' ) ],
				] ) }
			/>
			<DesignRange
				label={ __( 'Border width', 'crazylinks' ) }
				path={ [ 'button', 'border_width' ] }
				min={ 0 }
				max={ 8 }
				fallback={ 0 }
			/>
			<DesignSelect
				label={ __( 'Hover effect', 'crazylinks' ) }
				path={ [ 'button', 'hover' ] }
				options={ opts( [
					[ 'lift', __( 'Lift', 'crazylinks' ) ],
					[ 'darken', __( 'Darken', 'crazylinks' ) ],
					[ 'none', __( 'None', 'crazylinks' ) ],
				] ) }
			/>
			<DesignColor
				label={ __( 'Accent colour', 'crazylinks' ) }
				path={ [ 'accent' ] }
			/>
			<DesignRange
				label={ __( 'Corner radius', 'crazylinks' ) }
				path={ [ 'radius' ] }
				min={ 0 }
				max={ 48 }
				fallback={ 14 }
			/>
		</PanelBody>
	);
}

function LayoutPanel() {
	return (
		<PanelBody title={ __( 'Layout', 'crazylinks' ) } initialOpen={ false }>
			<DesignSelect
				label={ __( 'Link layout', 'crazylinks' ) }
				path={ [ 'layout' ] }
				options={ opts( [
					[ 'list', __( 'List', 'crazylinks' ) ],
					[ 'grid', __( 'Grid (2 columns)', 'crazylinks' ) ],
					[ 'featured', __( 'Featured first link', 'crazylinks' ) ],
				] ) }
			/>
			<DesignSelect
				label={ __( 'Dark mode', 'crazylinks' ) }
				path={ [ 'dark_mode' ] }
				options={ opts( [
					[
						'auto',
						__( "Follow the visitor's device", 'crazylinks' ),
					],
					[ 'light', __( 'Always light', 'crazylinks' ) ],
					[ 'dark', __( 'Always dark', 'crazylinks' ) ],
				] ) }
			/>
		</PanelBody>
	);
}

/** Custom CSS: editable with the unfiltered_html capability, otherwise only announced. */
function CustomCssPanel() {
	const { design, set, errorAt } = useDesign();
	const canEdit = boot().caps.unfiltered_html;
	if ( ! canEdit && ! design.custom_css ) {
		return null;
	}
	return (
		<PanelBody
			title={ __( 'Custom CSS', 'crazylinks' ) }
			initialOpen={ false }
		>
			{ canEdit ? (
				<TextareaControl
					__nextHasNoMarginBottom
					label={ __( 'CSS added to this page', 'crazylinks' ) }
					help={ __( 'Scope rules under .cl-page.', 'crazylinks' ) }
					className="cl-code"
					rows={ 8 }
					value={
						typeof design.custom_css === 'string'
							? design.custom_css
							: ''
					}
					onChange={ ( v: string ) =>
						set( [ 'custom_css' ], v === '' ? undefined : v )
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
			<FieldError message={ errorAt( [ 'custom_css' ] ) } />
		</PanelBody>
	);
}

export default function DesignTab() {
	const { slug, applyTheme } = useDesign();
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
			<BackgroundPanel />
			<TextPanel />
			<ButtonsPanel />
			<LayoutPanel />
			<CustomCssPanel />
		</div>
	);
}
