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
import MediaField from './MediaField';
import ItemsField from './ItemsField';
import FocalPicker from './FocalPicker';
import ProductField from './ProductField';
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

function asString( v: JsonValue | undefined ): string {
	if ( typeof v === 'number' ) {
		return String( v );
	}
	return typeof v === 'string' ? v : '';
}

function Counter( { value, max }: { value: string; max?: number } ) {
	if ( ! max ) {
		return null;
	}
	const over = value.length > max;
	return (
		<span className={ over ? 'cl-counter is-over' : 'cl-counter' }>
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
	const errorNode = (
		<>
			{ error && (
				<p className="cl-field-error" id={ errorId }>
					{ error }
				</p>
			) }
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
			const v = asString( value );
			return (
				<div className="cl-field">
					<TextControl
						__next40pxDefaultSize
						__nextHasNoMarginBottom
						label={ label }
						type={ def.kind === 'url' ? 'url' : 'text' }
						value={ v }
						help={ def.help }
						placeholder={ def.placeholder }
						onChange={ ( next: string ) =>
							onChange(
								next === '' && ! def.required && ! def.keep
									? undefined
									: next
							)
						}
						{ ...a11y }
					/>
					<Counter
						value={ v }
						max={
							def.kind === 'text' &&
							def.maxLength &&
							def.maxLength <= 300
								? def.maxLength
								: undefined
						}
					/>
					{ errorNode }
				</div>
			);
		}
		case 'textarea':
		case 'html': {
			const v = asString( value );
			return (
				<div className="cl-field">
					<TextareaControl
						__nextHasNoMarginBottom
						label={ label }
						value={ v }
						help={ def.help }
						rows={ def.kind === 'html' ? 5 : 3 }
						onChange={ ( next: string ) =>
							onChange(
								next === '' && ! def.required && ! def.keep
									? undefined
									: next
							)
						}
						className={ def.key === 'html' ? 'cl-code' : undefined }
						{ ...a11y }
					/>
					<Counter value={ v } max={ def.maxLength } />
					{ errorNode }
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
						value={ asString( value ) }
						onChange={ ( next: string ) => {
							const n = parseInt( next, 10 );
							onChange( Number.isNaN( n ) ? undefined : n );
						} }
						{ ...a11y }
					/>
					{ errorNode }
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
					{ errorNode }
				</div>
			);
		case 'select':
		case 'icon': {
			const list = options ?? def.options ?? [];
			const v = asString( value );
			const withCurrent =
				v && ! list.some( ( o ) => o.value === v )
					? [ ...list, { value: v, label: v } ]
					: list;
			return (
				<div className="cl-field">
					<SelectControl
						__next40pxDefaultSize
						__nextHasNoMarginBottom
						label={ label }
						help={ def.help }
						value={ v }
						options={ withCurrent }
						onChange={ ( next: string ) =>
							onChange( next === '' ? undefined : next )
						}
						{ ...a11y }
					/>
					{ errorNode }
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
					{ errorNode }
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
					{ errorNode }
				</div>
			);
		}
		case 'multicheck': {
			const current = Array.isArray( value ) ? value.map( String ) : [];
			return (
				<BaseControl
					id={ id }
					label={ def.label }
					__nextHasNoMarginBottom
				>
					<div className="cl-multicheck">
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
					{ errorNode }
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
