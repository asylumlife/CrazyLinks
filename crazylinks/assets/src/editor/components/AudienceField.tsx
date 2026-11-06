/**
 * Mailchimp audience picker for Email sign-up blocks, fed by GET /crazylinks/v1/integrations/audiences
 * (site managers only). Anyone else, or a site where Mailchimp does not answer, gets a text field for
 * the audience id instead. Empty means "the site default audience".
 */
import { __, sprintf } from '@wordpress/i18n';
import { useEffect, useState } from '@wordpress/element';
import { SelectControl, TextControl } from '@wordpress/components';
import { api } from '../lib/api';
import type { Audiences } from '../lib/api';

interface Props {
	label: string;
	value: string;
	onChange: ( id: string | undefined ) => void;
	error?: string;
}

/** Option list: the site default first, then every audience (and the saved id if it is unknown). */
export function audienceOptions(
	res: Audiences,
	value: string
): { value: string; label: string }[] {
	const def = res.audiences.find( ( a ) => a.id === res.default );
	const out = [
		{
			value: '',
			label: def
				? /* translators: %s: audience name. */
					sprintf( __( 'Site default (%s)', 'crazylinks' ), def.name )
				: __( 'Site default', 'crazylinks' ),
		},
		...res.audiences.map( ( a ) => ( { value: a.id, label: a.name } ) ),
	];
	if ( value && ! res.audiences.some( ( a ) => a.id === value ) ) {
		out.push( {
			value,
			/* translators: %s: audience id. */
			label: sprintf( __( '%s (not found)', 'crazylinks' ), value ),
		} );
	}
	return out;
}

export default function AudienceField( {
	label,
	value,
	onChange,
	error,
}: Props ) {
	const [ res, setRes ] = useState< Audiences | null >( null );
	const [ failed, setFailed ] = useState( false );

	useEffect( () => {
		let live = true;
		api.audiences()
			.then( ( r ) => {
				if ( live ) {
					setRes( r );
				}
			} )
			.catch( () => {
				if ( live ) {
					setFailed( true );
				}
			} );
		return () => {
			live = false;
		};
	}, [] );

	const errorNode = error && <p className="cl-field-error">{ error }</p>;

	if ( failed || ( res && ! res.available ) ) {
		return (
			<div className="cl-field">
				<TextControl
					__next40pxDefaultSize
					__nextHasNoMarginBottom
					label={ label }
					value={ value }
					placeholder={ __( 'Site default', 'crazylinks' ) }
					help={
						res?.error ||
						__(
							'Paste the Mailchimp Audience ID (Audience → Settings → Audience name and defaults), or leave empty for the site default.',
							'crazylinks'
						)
					}
					onChange={ ( next: string ) => {
						const id = next.trim().toLowerCase();
						onChange( id === '' ? undefined : id );
					} }
				/>
				{ errorNode }
			</div>
		);
	}

	return (
		<div className="cl-field">
			<SelectControl
				__next40pxDefaultSize
				__nextHasNoMarginBottom
				label={ label }
				value={ value }
				disabled={ ! res }
				options={
					res
						? audienceOptions( res, value )
						: [
								{
									value,
									label: __(
										'Loading audiences…',
										'crazylinks'
									),
								},
							]
				}
				onChange={ ( next: string ) =>
					onChange( next === '' ? undefined : next )
				}
			/>
			{ errorNode }
		</div>
	);
}
