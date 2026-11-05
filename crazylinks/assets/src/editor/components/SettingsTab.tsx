/**
 * Settings tab: address, SEO, UTM defaults, in-app browser hint, rendering mode.
 */
import { __, sprintf } from '@wordpress/i18n';
import {
	PanelBody,
	SelectControl,
	TextControl,
	TextareaControl,
	ToggleControl,
} from '@wordpress/components';
import type { JsonValue } from '../types';
import { useEditor, useEditorDispatch } from '../hooks';
import { getPath } from '../lib/paths';
import MediaField from './MediaField';
import { boot } from '../boot';

export default function SettingsTab() {
	const settings = useEditor( ( s ) => s.getDoc().settings, [] );
	const meta = useEditor( ( s ) => s.getMeta(), [] );
	const errors = useEditor( ( s ) => s.getErrors(), [] );
	const { setSection, setMeta } = useEditorDispatch();
	const data = boot();
	const get = ( ...path: string[] ) => getPath( settings, path );
	const str = ( ...path: string[] ) =>
		typeof get( ...path ) === 'string' ? ( get( ...path ) as string ) : '';
	const set = ( path: string[], v: JsonValue | undefined ) =>
		setSection( 'settings', path, v );
	const err = ( path: string ) =>
		errors.find( ( e ) => e.field === `/settings/${ path }` )?.message;
	const text = (
		label: string,
		path: string[],
		max: number,
		placeholder?: string,
		help?: string
	) => (
		<TextControl
			__next40pxDefaultSize
			__nextHasNoMarginBottom
			label={ label }
			value={ str( ...path ) }
			maxLength={ max }
			placeholder={ placeholder }
			help={ err( path.join( '/' ) ) ?? help }
			onChange={ ( v: string ) => set( path, v === '' ? undefined : v ) }
		/>
	);
	const description = str( 'seo', 'description' );
	const modeLabels: Record< string, string > = {
		standalone: __( 'Standalone page', 'crazylinks' ),
		theme: __( 'Inside my theme', 'crazylinks' ),
	};

	return (
		<div className="cl-settings-tab">
			<PanelBody title={ __( 'Page address', 'crazylinks' ) }>
				<TextControl
					__next40pxDefaultSize
					__nextHasNoMarginBottom
					label={ __( 'Slug', 'crazylinks' ) }
					value={ meta.slug }
					help={ sprintf(
						/* translators: %s: page URL. */ __(
							'Address: %s',
							'crazylinks'
						),
						`${ data.post.baseUrl }${ meta.slug || '…' }/`
					) }
					onChange={ ( v: string ) =>
						setMeta( {
							slug: v
								.toLowerCase()
								.replace( /[^a-z0-9-]+/g, '-' ),
						} )
					}
				/>
			</PanelBody>
			<PanelBody title={ __( 'Search and sharing', 'crazylinks' ) }>
				{ text(
					__( 'SEO title', 'crazylinks' ),
					[ 'seo', 'title' ],
					120,
					meta.title
				) }
				<TextareaControl
					__nextHasNoMarginBottom
					label={ __( 'Description', 'crazylinks' ) }
					value={ description }
					rows={ 3 }
					help={ `${ description.length } / 320` }
					onChange={ ( v: string ) =>
						set(
							[ 'seo', 'description' ],
							v === '' ? undefined : v
						)
					}
				/>
				<MediaField
					label={ __( 'Social sharing image', 'crazylinks' ) }
					value={
						typeof get( 'seo', 'image' ) === 'number'
							? ( get( 'seo', 'image' ) as number )
							: undefined
					}
					onChange={ ( v ) => set( [ 'seo', 'image' ], v ) }
					help={ __(
						'Used for Open Graph previews. 1200 × 630 works best.',
						'crazylinks'
					) }
				/>
				<ToggleControl
					__nextHasNoMarginBottom
					label={ __(
						'Hide from search engines (noindex)',
						'crazylinks'
					) }
					checked={ get( 'seo', 'noindex' ) === true }
					onChange={ ( v: boolean ) =>
						set( [ 'seo', 'noindex' ], v )
					}
				/>
			</PanelBody>
			<PanelBody
				title={ __( 'UTM tags on outbound links', 'crazylinks' ) }
				initialOpen={ false }
			>
				<ToggleControl
					__nextHasNoMarginBottom
					label={ __(
						'Add UTM parameters to tracked links',
						'crazylinks'
					) }
					checked={ get( 'utm', 'enabled' ) === true }
					onChange={ ( v: boolean ) =>
						set( [ 'utm', 'enabled' ], v )
					}
				/>
				{ text(
					__( 'Source', 'crazylinks' ),
					[ 'utm', 'source' ],
					64,
					'crazylinks'
				) }
				{ text(
					__( 'Medium', 'crazylinks' ),
					[ 'utm', 'medium' ],
					64,
					'bio'
				) }
				{ text(
					__( 'Campaign', 'crazylinks' ),
					[ 'utm', 'campaign' ],
					64,
					meta.slug || '{slug}',
					__(
						'Existing UTM parameters on a link are never overwritten.',
						'crazylinks'
					)
				) }
			</PanelBody>
			<PanelBody
				title={ __( 'Display', 'crazylinks' ) }
				initialOpen={ false }
			>
				<ToggleControl
					__nextHasNoMarginBottom
					label={ __(
						'Suggest opening in the browser',
						'crazylinks'
					) }
					help={ __(
						'Shows a dismissible hint to visitors inside Instagram, TikTok and other in-app browsers.',
						'crazylinks'
					) }
					checked={ get( 'in_app_hint' ) === true }
					onChange={ ( v: boolean ) => set( [ 'in_app_hint' ], v ) }
				/>
				<ToggleControl
					__nextHasNoMarginBottom
					label={ __( 'Show "Made with CrazyLinks"', 'crazylinks' ) }
					help={ __(
						'Adds a small credit line under your links. Off by default.',
						'crazylinks'
					) }
					checked={ get( 'footer_credit' ) === true }
					onChange={ ( v: boolean ) =>
						set( [ 'footer_credit' ], v ? true : undefined )
					}
				/>
				<SelectControl< string >
					__next40pxDefaultSize
					__nextHasNoMarginBottom
					label={ __( 'Rendering', 'crazylinks' ) }
					value={ str( 'render_mode' ) || 'default' }
					options={ [
						{
							value: 'default',
							label: sprintf(
								/* translators: %s: site-wide rendering mode. */
								__( 'Site default (%s)', 'crazylinks' ),
								modeLabels[ data.settings.renderMode ] ??
									data.settings.renderMode
							),
						},
						{ value: 'standalone', label: modeLabels.standalone },
						{ value: 'theme', label: modeLabels.theme },
					] }
					onChange={ ( v: string ) =>
						set(
							[ 'render_mode' ],
							v === 'default' ? undefined : v
						)
					}
				/>
			</PanelBody>
		</div>
	);
}
