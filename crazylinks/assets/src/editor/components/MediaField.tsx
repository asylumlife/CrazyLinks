/**
 * Attachment picker using the core media modal; stores the attachment id.
 */
import { __ } from '@wordpress/i18n';
import { BaseControl, Button } from '@wordpress/components';
import { useInstanceId } from '@wordpress/compose';
import { useEditor, useEditorDispatch } from '../hooks';
import { mediaAvailable, pickMedia } from '../lib/media';

interface Props {
	label: string;
	value: number | undefined;
	onChange: ( id: number | undefined ) => void;
	type?: 'image' | 'video';
	help?: string;
	error?: string;
}

export default function MediaField( {
	label,
	value,
	onChange,
	type = 'image',
	help,
	error,
}: Props ) {
	const id = useInstanceId( MediaField, 'cl-media' ) as string;
	const media = useEditor(
		( s ) => ( value ? s.getMedia( value ) : undefined ),
		[ value ]
	);
	const { addMedia } = useEditorDispatch();
	const choose = async () => {
		const picked = await pickMedia( {
			type,
			title: label,
			selected: value,
		} );
		if ( picked.length ) {
			addMedia( picked );
			onChange( picked[ 0 ].id );
		}
	};
	return (
		<BaseControl
			id={ id }
			label={ label }
			help={ help }
			__nextHasNoMarginBottom
		>
			<div className="cl-media" id={ id }>
				{ value ? (
					<span className="cl-media__thumb">
						{ media && media.mime.startsWith( 'image' ) ? (
							<img src={ media.thumb } alt={ media.alt } />
						) : (
							<span
								className="dashicons dashicons-media-default"
								aria-hidden="true"
							/>
						) }
					</span>
				) : null }
				<Button
					variant="secondary"
					size="compact"
					onClick={ choose }
					disabled={ ! mediaAvailable() }
				>
					{ value
						? __( 'Replace', 'crazylinks' )
						: __( 'Select', 'crazylinks' ) }
				</Button>
				{ value ? (
					<Button
						variant="tertiary"
						size="compact"
						isDestructive
						onClick={ () => onChange( undefined ) }
					>
						{ __( 'Remove', 'crazylinks' ) }
					</Button>
				) : null }
				{ value && ! media ? (
					<span className="cl-muted">
						{
							/* translators: %d: attachment id. */ __(
								'Attachment #%d',
								'crazylinks'
							).replace( '%d', String( value ) )
						}
					</span>
				) : null }
			</div>
			{ error && (
				<p className="cl-field-error" role="alert">
					{ error }
				</p>
			) }
		</BaseControl>
	);
}
