/**
 * Store product picker: an async select fed by GET /crazylinks/v1/store/products. Shows the
 * product id, name and price; stores the product id.
 */
import { __, sprintf } from '@wordpress/i18n';
import { useEffect, useMemo, useState } from '@wordpress/element';
import { ComboboxControl } from '@wordpress/components';
import FieldError from './FieldError';
import { api } from '../lib/api';
import type { StoreProduct } from '../lib/api';

interface Props {
	label: string;
	value: number | undefined;
	onChange: ( id: number | undefined ) => void;
	help?: string;
	error?: string;
}

const SEARCH_DEBOUNCE_MS = 300;
const SEARCH_PAGE_SIZE = 20;

/** Option label: "#12 Name (price)". */
function productLabel( p: StoreProduct ): string {
	return `#${ p.id } ${ p.name }${ p.price ? ` (${ p.price })` : '' }`;
}

export default function ProductField( {
	label,
	value,
	onChange,
	help,
	error,
}: Props ) {
	const [ found, setFound ] = useState< StoreProduct[] >( [] );
	const [ current, setCurrent ] = useState< StoreProduct | null >( null );
	// The id whose lookup by id has finished, whether or not the store knew it.
	const [ lookedUp, setLookedUp ] = useState< number | null >( null );
	const [ term, setTerm ] = useState( '' );
	const [ available, setAvailable ] = useState( true );
	const [ failed, setFailed ] = useState( false );

	// Search as the person types (debounced); an empty term lists the newest products.
	useEffect( () => {
		const controller = new AbortController();
		const timer = window.setTimeout( () => {
			api.storeProducts(
				{ search: term, perPage: SEARCH_PAGE_SIZE },
				controller.signal
			)
				.then( ( res ) => {
					setFound( res.products );
					setAvailable( res.available );
					setFailed( false );
				} )
				.catch( () => {
					if ( ! controller.signal.aborted ) {
						setFailed( true );
					}
				} );
		}, SEARCH_DEBOUNCE_MS );
		return () => {
			controller.abort();
			window.clearTimeout( timer );
		};
	}, [ term ] );

	// Resolve the saved id to a name, so the selection reads well before any search. Each id is
	// looked up once: an id the store does not know stays unresolved.
	useEffect( () => {
		if ( ! value ) {
			setCurrent( null );
			return;
		}
		if ( current && current.id === value ) {
			return;
		}
		const known = found.find( ( p ) => p.id === value );
		if ( known ) {
			setCurrent( known );
			return;
		}
		if ( lookedUp === value ) {
			return;
		}
		let live = true;
		api.storeProducts( { include: value } )
			.then( ( res ) => res.products[ 0 ] ?? null )
			.catch( () => null )
			.then( ( product ) => {
				if ( live ) {
					setCurrent( product );
					setLookedUp( value );
				}
			} );
		return () => {
			live = false;
		};
	}, [ value, found, current, lookedUp ] );

	const options = useMemo( () => {
		const list = [ ...found ];
		if ( current && ! list.some( ( p ) => p.id === current.id ) ) {
			list.unshift( current );
		}
		return list.map( ( p ) => ( {
			value: String( p.id ),
			label: productLabel( p ),
		} ) );
	}, [ found, current ] );

	let note = help;
	if ( ! available ) {
		note = __(
			'No store is connected. Add the store address under CrazyLinks → Settings → Store.',
			'crazylinks'
		);
	} else if ( failed ) {
		note = __( 'Could not load products.', 'crazylinks' );
	} else if ( value && ! current && lookedUp === value ) {
		note = sprintf(
			/* translators: %d: product id. */
			__( 'Product #%d is not in the store list.', 'crazylinks' ),
			value
		);
	}

	return (
		<div className="cl-field">
			<ComboboxControl
				__next40pxDefaultSize
				__nextHasNoMarginBottom
				label={ label }
				help={ note }
				value={ value ? String( value ) : null }
				options={ options }
				onFilterValueChange={ ( next: string ) => setTerm( next ) }
				onChange={ ( next?: string | null ) => {
					const id = next ? parseInt( next, 10 ) : NaN;
					onChange( Number.isNaN( id ) ? undefined : id );
					if ( next ) {
						setCurrent(
							found.find( ( p ) => String( p.id ) === next ) ??
								null
						);
					}
				} }
			/>
			<FieldError message={ error } />
		</div>
	);
}
