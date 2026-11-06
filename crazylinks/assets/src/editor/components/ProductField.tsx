/**
 * Store product picker: an async select fed by GET /crazylinks/v1/store/products. Shows the
 * product id, name and price; stores the product id.
 */
import { __ } from '@wordpress/i18n';
import { useEffect, useMemo, useRef, useState } from '@wordpress/element';
import { ComboboxControl } from '@wordpress/components';
import { api } from '../lib/api';
import type { StoreProduct } from '../lib/api';

interface Props {
	label: string;
	value: number | undefined;
	onChange: ( id: number | undefined ) => void;
	help?: string;
	error?: string;
}

/** Option label: "#12 Name (price)". */
export function productLabel( p: StoreProduct ): string {
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
	const [ term, setTerm ] = useState( '' );
	const [ available, setAvailable ] = useState( true );
	const [ failed, setFailed ] = useState( false );
	const timer = useRef< number | undefined >();

	// Search as the person types (debounced); an empty term lists the newest products.
	useEffect( () => {
		const ctl = new AbortController();
		window.clearTimeout( timer.current );
		timer.current = window.setTimeout( () => {
			api.storeProducts( { search: term, perPage: 20 }, ctl.signal )
				.then( ( res ) => {
					setFound( res.products );
					setAvailable( res.available );
					setFailed( false );
				} )
				.catch( () => {
					if ( ! ctl.signal.aborted ) {
						setFailed( true );
					}
				} );
		}, 300 );
		return () => {
			ctl.abort();
			window.clearTimeout( timer.current );
		};
	}, [ term ] );

	// Resolve the saved id to a name, so the selection reads well before any search.
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
		let live = true;
		api.storeProducts( { include: value } )
			.then( ( res ) => {
				if ( live ) {
					setCurrent( res.products[ 0 ] ?? null );
				}
			} )
			.catch( () => undefined );
		return () => {
			live = false;
		};
	}, [ value, found, current ] );

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
	} else if ( value && ! current ) {
		/* translators: %d: product id. */
		note = __(
			'Product #%d is not in the store list.',
			'crazylinks'
		).replace( '%d', String( value ) );
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
			{ error && <p className="cl-field-error">{ error }</p> }
		</div>
	);
}
