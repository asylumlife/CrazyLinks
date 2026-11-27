/**
 * Page header (profile) fields.
 */
import { __ } from '@wordpress/i18n';
import {
	PanelBody,
	SelectControl,
	TextControl,
	TextareaControl,
	ToggleControl,
} from '@wordpress/components';
import type { JsonValue } from '../types';
import FieldError from './FieldError';
import MediaField from './MediaField';
import FocalPicker from './FocalPicker';
import { useEditor, useEditorDispatch } from '../hooks';
import { errorFor } from '../lib/errors';
import { PROFILE_BIO_MAX, PROFILE_NAME_MAX } from '../lib/limits';

export default function ProfileCard() {
	const profile = useEditor( ( s ) => s.getDoc().profile, [] );
	const errors = useEditor( ( s ) => s.getErrors(), [] );
	const warnings = useEditor( ( s ) => s.getWarnings(), [] );
	const { setSection } = useEditorDispatch();
	const set = ( key: string, value: JsonValue | undefined ) =>
		setSection( 'profile', [ key ], value );
	const str = ( key: string ) =>
		typeof profile[ key ] === 'string' ? ( profile[ key ] as string ) : '';
	const errorAt = ( key: string ) => errorFor( errors, `/profile/${ key }` );
	const nameError = errorAt( 'name' );
	const nameWarning = warnings.find(
		( w ) => w.field === '/profile/name'
	)?.message;
	const style = str( 'avatar_style' ) || 'circle';
	const layout = str( 'header_layout' ) || 'centered';
	const bio = str( 'bio' );

	return (
		<PanelBody
			title={ __( 'Profile', 'crazylinks' ) }
			initialOpen={ ! profile.name }
			className="cl-profile"
		>
			<div className="cl-field">
				<TextControl
					__next40pxDefaultSize
					__nextHasNoMarginBottom
					label={ __( 'Name', 'crazylinks' ) }
					value={ str( 'name' ) }
					maxLength={ PROFILE_NAME_MAX }
					onChange={ ( v: string ) => set( 'name', v || undefined ) }
					help={ nameError ? undefined : nameWarning }
				/>
				<FieldError message={ nameError } />
			</div>
			<div className="cl-field">
				<TextareaControl
					__nextHasNoMarginBottom
					label={ __( 'Bio', 'crazylinks' ) }
					value={ bio }
					rows={ 3 }
					onChange={ ( v: string ) => set( 'bio', v || undefined ) }
					help={ `${ bio.length } / ${ PROFILE_BIO_MAX }` }
				/>
				<FieldError message={ errorAt( 'bio' ) } />
			</div>
			<MediaField
				label={ __( 'Avatar', 'crazylinks' ) }
				value={
					typeof profile.avatar === 'number'
						? profile.avatar
						: undefined
				}
				onChange={ ( v ) => set( 'avatar', v ) }
			/>
			<SelectControl< string >
				__next40pxDefaultSize
				__nextHasNoMarginBottom
				label={ __( 'Avatar style', 'crazylinks' ) }
				value={ style }
				options={ [
					{ value: 'circle', label: __( 'Circle', 'crazylinks' ) },
					{
						value: 'square',
						label: __( 'Rounded square', 'crazylinks' ),
					},
					{ value: 'shape', label: __( 'Shape mask', 'crazylinks' ) },
					{
						value: 'hero',
						label: __( 'Hero (full width)', 'crazylinks' ),
					},
					{
						value: 'banner',
						label: __( 'Banner with avatar', 'crazylinks' ),
					},
				] }
				onChange={ ( v: string ) => set( 'avatar_style', v ) }
			/>
			{ style === 'shape' && (
				<SelectControl< string >
					__next40pxDefaultSize
					__nextHasNoMarginBottom
					label={ __( 'Shape', 'crazylinks' ) }
					value={ str( 'shape_mask' ) || 'squircle' }
					options={ [
						{
							value: 'circle',
							label: __( 'Circle', 'crazylinks' ),
						},
						{
							value: 'squircle',
							label: __( 'Squircle', 'crazylinks' ),
						},
						{ value: 'blob', label: __( 'Blob', 'crazylinks' ) },
					] }
					onChange={ ( v: string ) => set( 'shape_mask', v ) }
				/>
			) }
			<SelectControl< string >
				__next40pxDefaultSize
				__nextHasNoMarginBottom
				label={ __( 'Header layout', 'crazylinks' ) }
				value={ layout }
				options={ [
					{
						value: 'centered',
						label: __( 'Centered', 'crazylinks' ),
					},
					{
						value: 'left',
						label: __( 'Left-aligned', 'crazylinks' ),
					},
					{ value: 'hero', label: __( 'Hero', 'crazylinks' ) },
					{ value: 'banner', label: __( 'Banner', 'crazylinks' ) },
				] }
				onChange={ ( v: string ) => set( 'header_layout', v ) }
			/>
			{ ( layout === 'banner' ||
				style === 'banner' ||
				layout === 'hero' ||
				style === 'hero' ) && (
				<MediaField
					label={ __( 'Banner image', 'crazylinks' ) }
					value={
						typeof profile.banner === 'number'
							? profile.banner
							: undefined
					}
					onChange={ ( v ) => set( 'banner', v ) }
				/>
			) }
			{ typeof profile.banner === 'number' && profile.banner > 0 && (
				<FocalPicker
					label={ __( 'Banner focal point', 'crazylinks' ) }
					imageId={ profile.banner }
					value={ profile.banner_focal_point }
					onChange={ ( v ) => set( 'banner_focal_point', v ) }
					help={ __(
						'The part of the banner that stays in view when it is cropped.',
						'crazylinks'
					) }
				/>
			) }
			<TextControl
				__next40pxDefaultSize
				__nextHasNoMarginBottom
				label={ __( 'Location', 'crazylinks' ) }
				value={ str( 'location' ) }
				maxLength={ 80 }
				onChange={ ( v: string ) => set( 'location', v || undefined ) }
			/>
			<TextControl
				__next40pxDefaultSize
				__nextHasNoMarginBottom
				label={ __( 'Pronouns', 'crazylinks' ) }
				value={ str( 'pronouns' ) }
				maxLength={ 40 }
				onChange={ ( v: string ) => set( 'pronouns', v || undefined ) }
			/>
			<ToggleControl
				__nextHasNoMarginBottom
				label={ __( 'Show a verified badge', 'crazylinks' ) }
				checked={ profile.verified_badge === true }
				onChange={ ( v: boolean ) => set( 'verified_badge', v ) }
			/>
		</PanelBody>
	);
}
