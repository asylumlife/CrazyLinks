/**
 * Option lists that come from the boot data instead of the registry: social networks, payment
 * services and icons.
 */
import { __ } from '@wordpress/i18n';
import { boot } from '../boot';
import { SOCIAL_NETWORKS } from './blocks';
import type { Option } from './options';

export interface DynamicOptions {
	/** Options by field key. */
	options: Record< string, Option[] >;
	/** Options by item sub-field key. */
	itemOptions: Record< string, Option[] >;
}

/**
 * The boot-data option lists for one block type.
 *
 * @param type Block type.
 */
export function dynamicOptions( type: string ): DynamicOptions {
	const data = boot();
	if ( type === 'socials' ) {
		return {
			options: {},
			itemOptions: {
				network: SOCIAL_NETWORKS.map( ( n ) => ( {
					value: n,
					label: data.networks[ n ] ?? n,
				} ) ),
			},
		};
	}
	if ( type === 'payment' ) {
		const services = Object.entries( data.payments ).map(
			( [ value, p ] ) => ( { value, label: p.label } )
		);
		return {
			options: {
				service: [
					...services,
					{
						value: 'custom',
						label: __( 'Other (custom URL)', 'crazylinks' ),
					},
				],
			},
			itemOptions: {},
		};
	}
	if ( type === 'link' || type === 'heading' ) {
		return {
			options: {
				icon: [
					{ value: '', label: __( 'None', 'crazylinks' ) },
					...Object.entries( data.icons )
						.map( ( [ value, i ] ) => ( {
							value,
							label: i.title,
						} ) )
						.sort( ( a, b ) => a.label.localeCompare( b.label ) ),
				],
			},
			itemOptions: {},
		};
	}
	return { options: {}, itemOptions: {} };
}
