/**
 * The action bar under a block's fields: move up or down, duplicate, and delete with confirmation.
 */
import { __, sprintf } from '@wordpress/i18n';
import { Button } from '@wordpress/components';
import { useState } from '@wordpress/element';
import type { Block } from '../types';
import { useEditor, useEditorDispatch } from '../hooks';

interface Props {
	block: Block;
	/** Label of the block's type, e.g. "Link". */
	typeLabel: string;
	/** The person may not change this block, so they may not copy it either. */
	locked: boolean;
}

export default function BlockActions( { block, typeLabel, locked }: Props ) {
	const siblingsCount = useEditor(
		( s ) => s.getChildren( block.parent_id ).length,
		[ block.parent_id ]
	);
	const { removeBlock, duplicateBlock, moveBlock } = useEditorDispatch();
	const [ confirmDelete, setConfirmDelete ] = useState( false );

	return (
		<div className="cl-block-panel__actions">
			<Button
				size="compact"
				icon="arrow-up-alt2"
				disabled={ block.order === 0 }
				onClick={ () => moveBlock( block.id, -1 ) }
			>
				{ __( 'Move up', 'crazylinks' ) }
			</Button>
			<Button
				size="compact"
				icon="arrow-down-alt2"
				disabled={ block.order >= siblingsCount - 1 }
				onClick={ () => moveBlock( block.id, 1 ) }
			>
				{ __( 'Move down', 'crazylinks' ) }
			</Button>
			<Button
				size="compact"
				icon="admin-page"
				disabled={ locked }
				onClick={ () => duplicateBlock( block.id ) }
			>
				{ __( 'Duplicate', 'crazylinks' ) }
			</Button>
			{ confirmDelete ? (
				<span
					className="cl-confirm"
					role="group"
					aria-label={ __( 'Confirm delete', 'crazylinks' ) }
				>
					<span>
						{ block.type === 'group'
							? __(
									'Delete this section and the blocks inside it?',
									'crazylinks'
								)
							: __( 'Delete this block?', 'crazylinks' ) }
					</span>
					<Button
						size="compact"
						variant="primary"
						isDestructive
						// Removing the block also clears the selection, which closes this panel.
						onClick={ () => removeBlock( block.id ) }
					>
						{ __( 'Delete', 'crazylinks' ) }
					</Button>
					<Button
						size="compact"
						variant="tertiary"
						onClick={ () => setConfirmDelete( false ) }
					>
						{ __( 'Cancel', 'crazylinks' ) }
					</Button>
				</span>
			) : (
				<Button
					size="compact"
					icon="trash"
					isDestructive
					onClick={ () => setConfirmDelete( true ) }
				>
					{ sprintf(
						/* translators: %s: block type label. */ __(
							'Delete %s',
							'crazylinks'
						),
						typeLabel.toLowerCase()
					) }
				</Button>
			) }
		</div>
	);
}
