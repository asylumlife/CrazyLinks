/**
 * Repeatable item list (social profiles, gallery images) with add, remove and reorder.
 */
import { __, sprintf } from '@wordpress/i18n';
import { Button, TextareaControl } from '@wordpress/components';
import { useState } from '@wordpress/element';
import type { FieldDef, Option } from '../registry/blocks';
import type { JsonObject, JsonValue } from '../types';
import Field from './Field';
import { useEditorDispatch } from '../hooks';
import { pickMedia, mediaAvailable } from '../lib/media';
import { parseSocialList } from '../lib/social';
import { ulid } from '../lib/ulid';

interface Props {
	def: FieldDef;
	value: JsonValue[];
	onChange: ( value: JsonValue | undefined ) => void;
	error?: string;
	subErrors: Record< string, string >;
	subWarnings?: Record< string, string >;
	itemOptions: Record< string, Option[] >;
}

const asObj = ( v: JsonValue ): JsonObject =>
	v && typeof v === 'object' && ! Array.isArray( v ) ? v : {};

export default function ItemsField( {
	def,
	value,
	onChange,
	error,
	subErrors,
	subWarnings = {},
	itemOptions,
}: Props ) {
	const { addMedia } = useEditorDispatch();
	const [ paste, setPaste ] = useState( '' );
	const [ pasteNote, setPasteNote ] = useState( '' );
	const isGallery = ( def.itemFields ?? [] ).some(
		( f ) => f.kind === 'media'
	);
	const isSocial = ( def.itemFields ?? [] ).some(
		( f ) => f.key === 'network'
	);
	const max = def.maxItems ?? 50;

	const update = ( i: number, key: string, v: JsonValue | undefined ) => {
		const next = value.map( ( item, n ) => {
			if ( n !== i ) {
				return item;
			}
			const obj = { ...asObj( item ) };
			if ( v === undefined ) {
				delete obj[ key ];
			} else {
				obj[ key ] = v;
			}
			return obj;
		} );
		onChange( next );
	};
	const move = ( i: number, delta: number ) => {
		const j = i + delta;
		if ( j < 0 || j >= value.length ) {
			return;
		}
		const next = value.slice();
		[ next[ i ], next[ j ] ] = [ next[ j ], next[ i ] ];
		onChange( next );
	};
	const remove = ( i: number ) =>
		onChange( value.filter( ( _, n ) => n !== i ) );

	const addImages = async () => {
		const picked = await pickMedia( {
			type: 'image',
			multiple: true,
			title: def.label,
		} );
		if ( picked.length ) {
			addMedia( picked );
			onChange(
				[
					...value,
					...picked.map( ( m ): JsonObject =>
						m.alt ? { image: m.id, alt: m.alt } : { image: m.id }
					),
				].slice( 0, max )
			);
		}
	};
	const addPasted = () => {
		const { items, rejected } = parseSocialList( paste );
		const existing = new Set(
			value.map( ( v ) => String( asObj( v ).url ?? '' ) )
		);
		const fresh = items
			.filter( ( it ) => ! existing.has( it.url ) )
			.map( ( it ) => ( {
				id: ulid(),
				network: it.network,
				url: it.url,
			} ) );
		onChange( [ ...value, ...fresh ].slice( 0, max ) );
		setPaste( '' );
		setPasteNote(
			rejected.length
				? sprintf(
						/* translators: %s: rejected entries. */ __(
							'Not recognised as URLs: %s',
							'crazylinks'
						),
						rejected.join( ', ' )
					)
				: sprintf(
						/* translators: %d: number of profiles added. */ __(
							'Added %d profiles.',
							'crazylinks'
						),
						fresh.length
					)
		);
	};

	return (
		<fieldset className="cl-items">
			<legend className="cl-items__legend">{ def.label }</legend>
			{ value.length === 0 && (
				<p className="cl-muted">
					{ __( 'Nothing here yet.', 'crazylinks' ) }
				</p>
			) }
			<ol className="cl-items__list">
				{ value.map( ( item, i ) => {
					const obj = asObj( item );
					const name = sprintf(
						/* translators: 1: item kind, 2: position. */ __(
							'%1$s %2$d',
							'crazylinks'
						),
						def.itemLabel ?? '',
						i + 1
					);
					return (
						<li key={ i } className="cl-items__item">
							<div className="cl-items__fields">
								{ ( def.itemFields ?? [] )
									.filter(
										( sub ) =>
											! sub.showIf || sub.showIf( obj )
									)
									.map( ( sub ) => (
										<Field
											key={ sub.key }
											def={ sub }
											value={ obj[ sub.key ] }
											options={ itemOptions[ sub.key ] }
											error={
												subErrors[
													`${ i }/${ sub.key }`
												]
											}
											warning={
												subWarnings[
													`${ i }/${ sub.key }`
												]
											}
											onChange={ ( v ) =>
												update( i, sub.key, v )
											}
										/>
									) ) }
							</div>
							<div className="cl-items__actions">
								<Button
									size="small"
									icon="arrow-up-alt2"
									label={ sprintf(
										/* translators: %s: item name. */ __(
											'Move %s up',
											'crazylinks'
										),
										name
									) }
									disabled={ i === 0 }
									onClick={ () => move( i, -1 ) }
								/>
								<Button
									size="small"
									icon="arrow-down-alt2"
									label={ sprintf(
										/* translators: %s: item name. */ __(
											'Move %s down',
											'crazylinks'
										),
										name
									) }
									disabled={ i === value.length - 1 }
									onClick={ () => move( i, 1 ) }
								/>
								<Button
									size="small"
									icon="trash"
									isDestructive
									label={ sprintf(
										/* translators: %s: item name. */ __(
											'Remove %s',
											'crazylinks'
										),
										name
									) }
									onClick={ () => remove( i ) }
								/>
							</div>
						</li>
					);
				} ) }
			</ol>
			{ error && <p className="cl-field-error">{ error }</p> }
			<div className="cl-items__add">
				{ isGallery ? (
					<Button
						variant="secondary"
						onClick={ addImages }
						disabled={ ! mediaAvailable() || value.length >= max }
					>
						{ __( 'Add images', 'crazylinks' ) }
					</Button>
				) : (
					<Button
						variant="secondary"
						disabled={ value.length >= max }
						onClick={ () =>
							onChange( [
								...value,
								isSocial
									? {
											id: ulid(),
											network: 'website',
											url: '',
										}
									: {},
							] )
						}
					>
						{ sprintf(
							/* translators: %s: item kind. */ __(
								'Add %s',
								'crazylinks'
							),
							def.itemLabel ?? ''
						) }
					</Button>
				) }
			</div>
			{ isSocial && (
				<div className="cl-items__paste">
					<TextareaControl
						__nextHasNoMarginBottom
						label={ __(
							'Or paste profile URLs (one per line)',
							'crazylinks'
						) }
						help={ __(
							'The network is detected from each address.',
							'crazylinks'
						) }
						value={ paste }
						rows={ 3 }
						onChange={ setPaste }
					/>
					<Button
						variant="secondary"
						size="compact"
						disabled={ ! paste.trim() }
						onClick={ addPasted }
					>
						{ __( 'Add pasted profiles', 'crazylinks' ) }
					</Button>
					{ pasteNote && (
						<p className="cl-muted" role="status">
							{ pasteNote }
						</p>
					) }
				</div>
			) }
		</fieldset>
	);
}
