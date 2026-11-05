/**
 * Sortable block list (pointer and keyboard drag via dnd-kit), with group children nested one level.
 */
import { __, _n, sprintf } from '@wordpress/i18n';
import { Button } from '@wordpress/components';
import { useMemo, useState } from '@wordpress/element';
import {
	DndContext,
	KeyboardSensor,
	PointerSensor,
	closestCenter,
	useSensor,
	useDroppable,
	useSensors,
	type DragEndEvent,
	type DragStartEvent,
	type Announcements,
} from '@dnd-kit/core';
import {
	SortableContext,
	sortableKeyboardCoordinates,
	useSortable,
	verticalListSortingStrategy,
} from '@dnd-kit/sortable';
import { CSS } from '@dnd-kit/utilities';
import type { Block, MappedError } from '../types';
import { blockDef } from '../registry/blocks';
import BlockPanel from './BlockPanel';
import { useEditor, useEditorDispatch } from '../hooks';
import { blockErrors } from '../lib/errors';
import { blockWarnings, type MappedWarning } from '../lib/warnings';
import { prefersReducedMotion } from '../lib/motion';
import { DROP_PREFIX, resolveDrop } from '../lib/dnd';

interface Props {
	onAdd: ( parentId: string | null ) => void;
}

function labelOf( block: Block ): string {
	const def = blockDef( block.type );
	const summary = def.summary( block.props );
	return summary ? `${ def.label }: ${ summary }` : def.label;
}

/** End-of-group drop target, so a block can be dropped into an empty group or after the last child. */
function GroupDropZone( {
	groupId,
	active,
}: {
	groupId: string;
	active: boolean;
} ) {
	const { setNodeRef, isOver } = useDroppable( {
		id: DROP_PREFIX + groupId,
		disabled: ! active,
	} );
	return (
		<li
			ref={ setNodeRef }
			className={ [
				'cl-dropzone',
				active ? 'is-active' : '',
				isOver ? 'is-over' : '',
			].join( ' ' ) }
			aria-hidden="true"
		>
			{ active
				? __( 'Drop here to add to the section', 'crazylinks' )
				: '' }
		</li>
	);
}

function Row( {
	block,
	errors,
	warnings,
	clicks,
	selected,
	children,
}: {
	block: Block;
	errors: MappedError[];
	warnings: MappedWarning[];
	clicks: number | undefined;
	selected: boolean;
	children?: React.ReactNode;
} ) {
	const def = blockDef( block.type );
	const { selectBlock, updateBlock, togglePinned } = useEditorDispatch();
	const reduced = prefersReducedMotion();
	const {
		attributes,
		listeners,
		setNodeRef,
		setActivatorNodeRef,
		transform,
		transition,
		isDragging,
	} = useSortable( {
		id: block.id,
		transition: reduced ? null : undefined,
	} );
	const mine = blockErrors( errors, block.id );
	const notes = blockWarnings( warnings, block.id );
	const panelId = `cl-panel-${ block.id }`;
	const summary = def.summary( block.props );
	const scheduled = !! (
		block.schedule.start ||
		block.schedule.end ||
		block.schedule.weekly
	);
	const style = {
		transform: CSS.Translate.toString( transform ),
		transition: transition ?? undefined,
	};

	return (
		<li
			ref={ setNodeRef }
			style={ style }
			className={ [
				'cl-block',
				selected ? 'is-selected' : '',
				isDragging ? 'is-dragging' : '',
				block.visible ? '' : 'is-hidden',
				mine.length ? 'has-error' : '',
			].join( ' ' ) }
			data-block-id={ block.id }
		>
			<div className="cl-block__row">
				<button
					type="button"
					className="cl-block__handle"
					ref={ setActivatorNodeRef }
					{ ...attributes }
					{ ...listeners }
					aria-label={ sprintf(
						/* translators: %s: block label. */ __(
							'Reorder %s',
							'crazylinks'
						),
						labelOf( block )
					) }
					aria-describedby="cl-dnd-help"
				>
					<span
						className="dashicons dashicons-menu"
						aria-hidden="true"
					/>
				</button>
				<button
					type="button"
					className="cl-block__main"
					aria-expanded={ selected }
					aria-controls={ selected ? panelId : undefined }
					onClick={ () => selectBlock( selected ? null : block.id ) }
				>
					<span
						className={ `dashicons dashicons-${ def.icon }` }
						aria-hidden="true"
					/>
					<span className="cl-block__text">
						<span className="cl-block__type">{ def.label }</span>
						<span className="cl-block__summary">
							{ summary || __( '(empty)', 'crazylinks' ) }
						</span>
					</span>
				</button>
				<span className="cl-block__badges">
					{ mine.length > 0 && (
						<span className="cl-badge cl-badge--error">
							{ sprintf(
								/* translators: %d: number of problems. */ _n(
									'%d problem',
									'%d problems',
									mine.length,
									'crazylinks'
								),
								mine.length
							) }
						</span>
					) }
					{ notes.length > 0 && mine.length === 0 && (
						<span className="cl-badge cl-badge--warning">
							{ sprintf(
								/* translators: %d: number of warnings. */ _n(
									'%d warning',
									'%d warnings',
									notes.length,
									'crazylinks'
								),
								notes.length
							) }
						</span>
					) }
					{ scheduled && (
						<span className="cl-badge">
							{ __( 'Scheduled', 'crazylinks' ) }
						</span>
					) }
					{ typeof clicks === 'number' && (
						<span
							className="cl-badge cl-badge--clicks"
							title={ __(
								'Clicks in the last 30 days',
								'crazylinks'
							) }
						>
							{ sprintf(
								/* translators: %s: click count. */ _n(
									'%s click',
									'%s clicks',
									clicks,
									'crazylinks'
								),
								clicks.toLocaleString()
							) }
						</span>
					) }
				</span>
				{ block.parent_id === null && (
					<Button
						size="small"
						icon="sticky"
						className={ [
							'cl-block__pin',
							block.pinned ? 'is-pinned' : '',
						].join( ' ' ) }
						label={
							block.pinned
								? __( 'Unpin', 'crazylinks' )
								: __( 'Pin to top', 'crazylinks' )
						}
						isPressed={ block.pinned }
						onClick={ () => togglePinned( block.id ) }
					/>
				) }
				<Button
					size="small"
					icon={ block.visible ? 'visibility' : 'hidden' }
					label={
						block.visible
							? sprintf(
									/* translators: %s: block label. */ __(
										'Hide %s',
										'crazylinks'
									),
									labelOf( block )
								)
							: sprintf(
									/* translators: %s: block label. */ __(
										'Show %s',
										'crazylinks'
									),
									labelOf( block )
								)
					}
					isPressed={ ! block.visible }
					onClick={ () =>
						updateBlock( block.id, { visible: ! block.visible } )
					}
				/>
			</div>
			{ selected && (
				<BlockPanel
					block={ block }
					errors={ mine }
					panelId={ panelId }
				/>
			) }
			{ children }
		</li>
	);
}

export default function BlockList( { onAdd }: Props ) {
	const blocks = useEditor( ( s ) => s.getDoc().blocks, [] );
	const selected = useEditor( ( s ) => s.getSelected(), [] );
	const errors = useEditor( ( s ) => s.getAllErrors(), [] );
	const warnings = useEditor( ( s ) => s.getWarnings(), [] );
	const clicks = useEditor( ( s ) => s.getClicks(), [] );
	const { moveBlockTo } = useEditorDispatch();
	const [ dragging, setDragging ] = useState< string | null >( null );
	const sensors = useSensors(
		useSensor( PointerSensor, { activationConstraint: { distance: 4 } } ),
		useSensor( KeyboardSensor, {
			coordinateGetter: sortableKeyboardCoordinates,
		} )
	);
	const top = useMemo(
		() => blocks.filter( ( b ) => b.parent_id === null ),
		[ blocks ]
	);
	const byId = useMemo(
		() => new Map( blocks.map( ( b ) => [ b.id, b ] ) ),
		[ blocks ]
	);

	const onDragStart = ( { active }: DragStartEvent ) =>
		setDragging( String( active.id ) );

	const onDragEnd = ( { active, over }: DragEndEvent ) => {
		setDragging( null );
		if ( ! over ) {
			return;
		}
		// Reorder, or move into or out of a section (one level; "Section" in the block panel does the same by keyboard).
		const target = resolveDrop(
			blocks,
			String( active.id ),
			String( over.id )
		);
		if ( target ) {
			moveBlockTo( String( active.id ), target.parentId, target.index );
		}
	};
	const draggingBlock = dragging ? byId.get( dragging ) : undefined;
	const canDropInGroup = !! draggingBlock && draggingBlock.type !== 'group';

	const name = ( id: string | number ) => {
		const b = byId.get( String( id ) );
		return b ? labelOf( b ) : '';
	};
	const position = ( id: string | number ) => {
		const b = byId.get( String( id ) );
		return b ? b.order + 1 : 0;
	};
	const announcements: Announcements = {
		onDragStart: ( { active } ) =>
			sprintf(
				/* translators: %s: block label. */ __(
					'Picked up %s.',
					'crazylinks'
				),
				name( active.id )
			),
		onDragOver: ( { active, over } ) =>
			over
				? sprintf(
						/* translators: 1: block label, 2: position. */ __(
							'%1$s moved to position %2$d.',
							'crazylinks'
						),
						name( active.id ),
						position( over.id )
					)
				: '',
		onDragEnd: ( { active, over } ) =>
			over
				? sprintf(
						/* translators: 1: block label, 2: position. */ __(
							'%1$s dropped at position %2$d.',
							'crazylinks'
						),
						name( active.id ),
						position( over.id )
					)
				: '',
		onDragCancel: ( { active } ) =>
			sprintf(
				/* translators: %s: block label. */ __(
					'Moving %s was cancelled.',
					'crazylinks'
				),
				name( active.id )
			),
	};

	if ( blocks.length === 0 ) {
		return (
			<div className="cl-empty">
				<p>
					{ __(
						'Your page has no blocks yet. Add a link to get started.',
						'crazylinks'
					) }
				</p>
			</div>
		);
	}

	return (
		<DndContext
			sensors={ sensors }
			collisionDetection={ closestCenter }
			onDragStart={ onDragStart }
			onDragEnd={ onDragEnd }
			onDragCancel={ () => setDragging( null ) }
			accessibility={ {
				announcements,
				screenReaderInstructions: {
					draggable: __(
						'To reorder or move a block into or out of a section, press Space or Enter, use the arrow keys to move, then Space or Enter to drop. Escape cancels.',
						'crazylinks'
					),
				},
			} }
		>
			<p id="cl-dnd-help" className="screen-reader-text">
				{ __(
					'Press Space or Enter to pick up, arrow keys to move, Space or Enter to drop.',
					'crazylinks'
				) }
			</p>
			<SortableContext
				items={ top.map( ( b ) => b.id ) }
				strategy={ verticalListSortingStrategy }
			>
				<ul
					className="cl-blocks"
					aria-label={ __( 'Blocks', 'crazylinks' ) }
				>
					{ top.map( ( block ) => {
						const children =
							block.type === 'group'
								? blocks.filter(
										( b ) => b.parent_id === block.id
									)
								: [];
						return (
							<Row
								key={ block.id }
								block={ block }
								errors={ errors }
								warnings={ warnings }
								clicks={ clicks[ block.id ] }
								selected={ selected === block.id }
							>
								{ block.type === 'group' && (
									<div className="cl-group-children">
										<SortableContext
											items={ children.map(
												( b ) => b.id
											) }
											strategy={
												verticalListSortingStrategy
											}
										>
											<ul
												className="cl-blocks cl-blocks--nested"
												aria-label={ sprintf(
													/* translators: %s: section label. */ __(
														'Blocks in %s',
														'crazylinks'
													),
													labelOf( block )
												) }
											>
												{ children.map( ( child ) => (
													<Row
														key={ child.id }
														block={ child }
														errors={ errors }
														warnings={ warnings }
														clicks={
															clicks[ child.id ]
														}
														selected={
															selected ===
															child.id
														}
													/>
												) ) }
												<GroupDropZone
													groupId={ block.id }
													active={ canDropInGroup }
												/>
											</ul>
										</SortableContext>
										<Button
											variant="tertiary"
											size="compact"
											icon="plus"
											onClick={ () => onAdd( block.id ) }
										>
											{ __(
												'Add to section',
												'crazylinks'
											) }
										</Button>
									</div>
								) }
							</Row>
						);
					} ) }
				</ul>
			</SortableContext>
		</DndContext>
	);
}
