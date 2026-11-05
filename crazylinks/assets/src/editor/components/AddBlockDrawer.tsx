/**
 * Add-block drawer: every block type in the schema, grouped by category, with icon and description.
 */
import { __ } from '@wordpress/i18n';
import { Modal, SearchControl } from '@wordpress/components';
import { useMemo, useState } from '@wordpress/element';
import { blockDef, categoryLabels, schemaBlockTypes } from '../registry/blocks';
import type { BlockCategory } from '../registry/blocks';
import { boot } from '../boot';

interface Props {
	parentId: string | null;
	onClose: () => void;
	onChoose: ( type: string ) => void;
}

export default function AddBlockDrawer( {
	parentId,
	onClose,
	onChoose,
}: Props ) {
	const [ search, setSearch ] = useState( '' );
	const data = boot();
	const catalog = useMemo(
		() => new Map( data.blockCatalog.map( ( c ) => [ c.type, c ] ) ),
		[ data.blockCatalog ]
	);
	const types = schemaBlockTypes( data.schema ).filter(
		( t ) => ! ( parentId && t === 'group' )
	);
	const q = search.trim().toLowerCase();
	const matches = types
		.map( ( t ) => blockDef( t ) )
		.filter(
			( d ) =>
				! q ||
				d.label.toLowerCase().includes( q ) ||
				d.description.toLowerCase().includes( q ) ||
				d.type.includes( q )
		);
	const labels = categoryLabels();
	const order = Object.keys( labels ) as BlockCategory[];

	return (
		<Modal
			title={
				parentId
					? __( 'Add a block to the section', 'crazylinks' )
					: __( 'Add a block', 'crazylinks' )
			}
			onRequestClose={ onClose }
			className="cl-drawer"
			overlayClassName="cl-drawer-overlay"
		>
			<SearchControl
				__nextHasNoMarginBottom
				label={ __( 'Search blocks', 'crazylinks' ) }
				value={ search }
				onChange={ setSearch }
			/>
			{ matches.length === 0 && (
				<p>{ __( 'No block matches your search.', 'crazylinks' ) }</p>
			) }
			{ order.map( ( cat ) => {
				const list = matches.filter( ( d ) => d.category === cat );
				if ( list.length === 0 ) {
					return null;
				}
				return (
					<section
						key={ cat }
						className="cl-drawer__section"
						aria-labelledby={ `cl-cat-${ cat }` }
					>
						<h2
							id={ `cl-cat-${ cat }` }
							className="cl-drawer__heading"
						>
							{ labels[ cat ] }
						</h2>
						<ul className="cl-drawer__list">
							{ list.map( ( d ) => {
								const entry = catalog.get( d.type );
								const available = entry
									? entry.available
									: true;
								return (
									<li key={ d.type }>
										<button
											type="button"
											className="cl-drawer__item"
											disabled={ ! available }
											aria-describedby={ `cl-desc-${ d.type }` }
											onClick={ () => onChoose( d.type ) }
										>
											<span
												className={ `dashicons dashicons-${ d.icon }` }
												aria-hidden="true"
											/>
											<span className="cl-drawer__label">
												{ d.label }
											</span>
											<span
												className="cl-drawer__desc"
												id={ `cl-desc-${ d.type }` }
											>
												{ available
													? d.description
													: entry?.reason ||
														d.description }
											</span>
										</button>
									</li>
								);
							} ) }
						</ul>
					</section>
				);
			} ) }
		</Modal>
	);
}
