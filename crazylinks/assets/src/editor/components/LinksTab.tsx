/**
 * Links tab: profile, block list, add-block drawer.
 */
import { __ } from '@wordpress/i18n';
import { Button, Panel } from '@wordpress/components';
import { useState } from '@wordpress/element';
import ProfileCard from './ProfileCard';
import BlockList from './BlockList';
import AddBlockDrawer from './AddBlockDrawer';
import { useEditor, useEditorDispatch } from '../hooks';

export default function LinksTab() {
	const [ drawer, setDrawer ] = useState< {
		parentId: string | null;
	} | null >( null );
	const { addBlock } = useEditorDispatch();
	const selected = useEditor( ( s ) => s.getSelected(), [] );
	const selectedBlock = useEditor(
		( s ) => ( selected ? s.getBlock( selected ) : undefined ),
		[ selected ]
	);

	return (
		<div className="cl-links-tab">
			<Panel>
				<ProfileCard />
			</Panel>
			<div className="cl-links-tab__head">
				<h2 className="cl-section-title">
					{ __( 'Blocks', 'crazylinks' ) }
				</h2>
				<Button
					variant="primary"
					icon="plus"
					onClick={ () => setDrawer( { parentId: null } ) }
				>
					{ __( 'Add block', 'crazylinks' ) }
				</Button>
			</div>
			<BlockList onAdd={ ( parentId ) => setDrawer( { parentId } ) } />
			{ drawer && (
				<AddBlockDrawer
					parentId={ drawer.parentId }
					onClose={ () => setDrawer( null ) }
					onChoose={ ( type ) => {
						// New top-level blocks go after the selected top-level block, else at the end.
						const after =
							drawer.parentId === null &&
							selectedBlock &&
							selectedBlock.parent_id === null
								? selectedBlock.id
								: null;
						addBlock( type, drawer.parentId, after );
						setDrawer( null );
					} }
				/>
			) }
		</div>
	);
}
