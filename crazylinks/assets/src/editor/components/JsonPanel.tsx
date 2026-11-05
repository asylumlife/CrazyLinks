/**
 * "Advanced: edit JSON" panel. Applies a pasted document to the working copy (undoable).
 */
import { __, sprintf } from '@wordpress/i18n';
import {
	Button,
	ExternalLink,
	Modal,
	Notice,
	TextareaControl,
} from '@wordpress/components';
import { useState } from '@wordpress/element';
import { useEditor, useEditorDispatch } from '../hooks';
import { coerceDoc } from '../store/doc';
import { boot } from '../boot';

export default function JsonPanel( { onClose }: { onClose: () => void } ) {
	const doc = useEditor( ( s ) => s.getDoc(), [] );
	const { replaceDoc } = useEditorDispatch();
	const [ text, setText ] = useState( () => JSON.stringify( doc, null, 2 ) );
	const [ error, setError ] = useState( '' );

	const apply = () => {
		try {
			const parsed: unknown = JSON.parse( text );
			if (
				! parsed ||
				typeof parsed !== 'object' ||
				Array.isArray( parsed )
			) {
				throw new Error(
					__( 'The document must be a JSON object.', 'crazylinks' )
				);
			}
			replaceDoc( coerceDoc( parsed ) );
			onClose();
		} catch ( e ) {
			setError(
				sprintf(
					/* translators: %s: parser message. */ __(
						'Not applied: %s',
						'crazylinks'
					),
					( e as Error ).message
				)
			);
		}
	};

	return (
		<Modal
			title={ __( 'Advanced: edit JSON', 'crazylinks' ) }
			onRequestClose={ onClose }
			className="cl-json"
			size="large"
		>
			<p>
				{ __(
					'The page document (schemas/page.v1.json). Changes apply to the editor; the server validates them on preview and save.',
					'crazylinks'
				) }
			</p>
			{ error && (
				<Notice status="error" isDismissible={ false }>
					{ error }
				</Notice>
			) }
			<TextareaControl
				__nextHasNoMarginBottom
				label={ __( 'Page JSON', 'crazylinks' ) }
				className="cl-code"
				rows={ 24 }
				value={ text }
				onChange={ ( v: string ) => {
					setText( v );
					setError( '' );
				} }
				spellCheck={ false }
			/>
			<div className="cl-modal-actions">
				<Button variant="primary" onClick={ apply }>
					{ __( 'Apply', 'crazylinks' ) }
				</Button>
				<Button variant="tertiary" onClick={ onClose }>
					{ __( 'Cancel', 'crazylinks' ) }
				</Button>
				{ boot().post.classicUrl && (
					<ExternalLink href={ boot().post.classicUrl }>
						{ __( 'Open the plain JSON screen', 'crazylinks' ) }
					</ExternalLink>
				) }
			</div>
		</Modal>
	);
}
