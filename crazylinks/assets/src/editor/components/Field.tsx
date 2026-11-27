/**
 * Renders one registry field (text, url, select, media, …) for a block's props.
 */
import { __ } from '@wordpress/i18n';
import {
	BaseControl,
	CheckboxControl,
	SelectControl,
	TextControl,
	TextareaControl,
	ToggleControl,
} from '@wordpress/components';
import { useInstanceId } from '@wordpress/compose';
import type { FieldDef, Option } from '../registry/blocks';
import type { JsonValue } from '../types';
import ColorField from './ColorField';
import FieldError from './FieldError';
import MediaField from './MediaField';
import ItemsField from './ItemsField';
import FocalPicker from './FocalPicker';
import ProductField from './ProductField';
import AudienceField from './AudienceField';
import { classes } from '../lib/classes';
import { isoToLocalInput, localInputToIso } from '../lib/datetime';

export interface FieldProps {
	def: FieldDef;
	value: JsonValue | undefined;
	onChange: ( value: JsonValue | undefined ) => void;
	/** Error message for this field. */
	error?: string;
	/** Errors for sub-paths (items fields), keyed `index/key`. */
	subErrors?: Record< string, string >;
	/** Non-blocking warning for this field. */
	warning?: string;
	/** Warnings for sub-paths (items fields), keyed `index/key`. */
	subWarnings?: Record< string, string >;
	/** focal: attachment id of the image the point refers to. */
	imageId?: number;
	/** Overrides `def.options` (dynamic option lists). */
	options?: Option[];
	/** Option lists for item sub-fields, keyed by sub-field key. */
	itemOptions?: Record< string, Option[] >;
}

/** Single-line text fields show a character counter up to this limit; longer ones do not. */
const TEXT_COUNTER_MAX = 300;

const TEXTAREA_ROWS = 3;
const HTML_ROWS = 5;

function asString( v: JsonValue | undefined ): string {
	if ( typeof v === 'number' ) {
		return String( v );
	}
	return typeof v === 'string' ? v : '';
}

/**
 * The value to store for a text input: an emptied optional field is removed from the props, unless
 * the schema wants the key kept.
 *
 * @param def  Field definition.
 * @param text Input content.
 */
function textValue( def: FieldDef, text: string ): string | undefined {
	return text === '' && ! def.required && ! def.keep ? undefined : text;
}

function Counter( { value, max }: { value: string; max?: number } ) {
	if ( ! max ) {
		return null;
	}
	const over = value.length > max;
	return (
		<span className={ classes( 'cl-counter', over && 'is-over' ) }>
			{ value.length } / { max }
		</span>
	);
}

export default function Field( {
	def,
	value,
	onChange,
	error,
	subErrors,
	warning,
	subWarnings,
	imageId,
	options,
	itemOptions,
}: FieldProps ) {
	const id = useInstanceId( Field, 'cl-field' ) as string;
	const errorId = `${ id }-error`;
	const a11y = error
		? { 'aria-invalid': true, 'aria-describedby': errorId }
		: {};
	const label = def.required ? `${ def.label } *` : def.label;
	const messages = (
		<>
			<FieldError message={ error } id={ errorId } />
			{ warning && ! error && (
				<p className="cl-field-warning" role="status">
					{ warning }
				</p>
			) }
		</>
	);

	switch ( def.kind ) {
		case 'text':
		case 'url': {
			const text = asString( value );
			const counted =
				def.kind === 'text' &&
				def.maxLength &&
				def.maxLength <= TEXT_COUNTER_MAX;
			return (
				<div className="cl-field">
					<TextControl
						__next40pxDefaultSize
						__nextHasNoMarginBottom
						label={ label }
						type={ def.kind === 'url' ? 'url' : 'text' }
						value={ text }
						help={ def.help }
						placeholder={ def.placeholder }
						onChange={ ( next: string ) =>
							onChange( textValue( def, next ) )
						}
						{ ...a11y }
					/>
					<Counter
						value={ text }
						max={ counted ? def.maxLength : undefined }
					/>
					{ messages }
				</div>
			);
		}
		case 'textarea':
		case 'html': {
			const text = asString( value );
			return (
				<div className="cl-field">
					<TextareaControl
						__nextHasNoMarginBottom
						label={ label }
						value={ text }
						help={ def.help }
						rows={ def.kind === 'html' ? HTML_ROWS : TEXTAREA_ROWS }
						onChange={ ( next: string ) =>
							onChange( textValue( def, next ) )
						}
						className={ def.key === 'html' ? 'cl-code' : undefined }
						{ ...a11y }
					/>
					<Counter value={ text } max={ def.maxLength } />
					{ messages }
				</div>
			);
		}
		case 'number':
			return (
				<div className="cl-field">
					<TextControl
						__next40pxDefaultSize
						__nextHasNoMarginBottom
						label={ label }
						type="number"
						min={ def.min }
						max={ def.max }
						help={ def.help }
						placeholder={ def.placeholder }
						value={ asString( value ) }
						onChange={ ( next: string ) => {
							const n = parseInt( next, 10 );
							onChange( Number.isNaN( n ) ? undefined : n );
						} }
						{ ...a11y }
					/>
					{ messages }
				</div>
			);
		case 'toggle':
			return (
				<div className="cl-field">
					<ToggleControl
						__nextHasNoMarginBottom
						label={ def.label }
						help={ def.help }
						checked={ value === true }
						onChange={ ( next: boolean ) => onChange( next ) }
					/>
					{ messages }
				</div>
			);
		case 'select':
		case 'icon': {
			const list = options ?? def.options ?? [];
			const selected = asString( value );
			// A stored value the list does not offer stays selectable instead of silently changing.
			const withCurrent =
				selected && ! list.some( ( o ) => o.value === selected )
					? [ ...list, { value: selected, label: selected } ]
					: list;
			return (
				<div className="cl-field">
					<SelectControl
						__next40pxDefaultSize
						__nextHasNoMarginBottom
						label={ label }
						help={ def.help }
						value={ selected }
						options={ withCurrent }
						onChange={ ( next: string ) =>
							onChange( next === '' ? undefined : next )
						}
						{ ...a11y }
					/>
					{ messages }
				</div>
			);
		}
		case 'media':
			return (
				<MediaField
					label={ label }
					type={ def.mediaType }
					value={ typeof value === 'number' ? value : undefined }
					onChange={ ( next ) => onChange( next ) }
					help={ def.help }
					error={ error }
				/>
			);
		case 'datetime':
			return (
				<div className="cl-field">
					<TextControl
						__next40pxDefaultSize
						__nextHasNoMarginBottom
						label={ label }
						type="datetime-local"
						help={
							def.help ?? __( 'Your local time.', 'crazylinks' )
						}
						value={ isoToLocalInput( asString( value ) ) }
						onChange={ ( next: string ) =>
							onChange( localInputToIso( next ) ?? undefined )
						}
						{ ...a11y }
					/>
					{ messages }
				</div>
			);
		case 'color':
			return (
				<ColorField
					label={ def.label }
					value={ asString( value ) || undefined }
					onChange={ ( next ) => onChange( next ) }
					help={ def.help }
					error={ error }
				/>
			);
		case 'tags':
		case 'numbers': {
			const list = Array.isArray( value )
				? value.map( String ).join( ', ' )
				: '';
			return (
				<div className="cl-field">
					<TextControl
						__next40pxDefaultSize
						__nextHasNoMarginBottom
						label={ label }
						help={ def.help }
						value={ list }
						onChange={ ( next: string ) => {
							const parts = next
								.split( ',' )
								.map( ( p ) => p.trim() )
								.filter( Boolean );
							if ( parts.length === 0 ) {
								onChange( undefined );
								return;
							}
							onChange(
								def.kind === 'numbers'
									? parts
											.map( ( p ) => parseInt( p, 10 ) )
											.filter(
												( n ) => ! Number.isNaN( n )
											)
									: parts
							);
						} }
						{ ...a11y }
					/>
					{ messages }
				</div>
			);
		}
		case 'multicheck': {
			const current = Array.isArray( value ) ? value.map( String ) : [];
			const labelId = `${ id }-label`;
			return (
				<BaseControl __nextHasNoMarginBottom>
					{ /* A set of checkboxes is named as a group, not through a label. */ }
					<BaseControl.VisualLabel id={ labelId }>
						{ def.label }
					</BaseControl.VisualLabel>
					<div
						className="cl-multicheck"
						role="group"
						aria-labelledby={ labelId }
					>
						{ ( def.options ?? [] ).map( ( o ) => (
							<CheckboxControl
								__nextHasNoMarginBottom
								key={ o.value }
								label={ o.label }
								checked={ current.includes( o.value ) }
								onChange={ ( on: boolean ) => {
									const next = on
										? [ ...current, o.value ]
										: current.filter(
												( x ) => x !== o.value
											);
									onChange( next.length ? next : undefined );
								} }
							/>
						) ) }
					</div>
					{ messages }
				</BaseControl>
			);
		}
		case 'focal':
			return (
				<FocalPicker
					label={ def.label }
					imageId={ imageId }
					value={ value }
					onChange={ onChange }
					help={ def.help }
				/>
			);
		case 'product':
			return (
				<ProductField
					label={ label }
					value={ typeof value === 'number' ? value : undefined }
					onChange={ ( next ) => onChange( next ) }
					help={ def.help }
					error={ error }
				/>
			);
		case 'audience':
			return (
				<AudienceField
					label={ label }
					value={ asString( value ) }
					onChange={ ( next ) => onChange( next ) }
					error={ error }
				/>
			);
		case 'items':
			return (
				<ItemsField
					def={ def }
					value={ Array.isArray( value ) ? value : [] }
					onChange={ onChange }
					error={ error }
					subErrors={ subErrors ?? {} }
					subWarnings={ subWarnings ?? {} }
					itemOptions={ itemOptions ?? {} }
				/>
			);
	}
	return null;
}
