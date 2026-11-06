/**
 * REST client for crazylinks/v1 (cookie auth; core's api-fetch adds the nonce and root URL).
 */
import apiFetch from '@wordpress/api-fetch';
import type { PageDoc, PagePayload } from '../types';

const NS = '/crazylinks/v1';

export interface PreviewResponse {
	html: string;
	document?: string;
}

export interface AutosaveResponse {
	mode: 'autosave' | 'draft' | 'unchanged';
	autosave_id: number;
	modified: string;
	page: PageDoc;
}

export interface RevisionSummary {
	id: number;
	date: string;
	author: { id: number; name: string };
	autosave: boolean;
	title: string;
	blocks: number;
}

export interface RevisionDetail extends RevisionSummary {
	parent: number;
	page: PageDoc;
}

export interface AnalyticsSummary {
	totals?: {
		views?: number;
		uniques?: number;
		clicks?: number;
		ctr?: number;
	};
	timeseries?: Array< { date: string; views?: number; clicks?: number } >;
	blocks?: Array< {
		block_id: string;
		title?: string;
		type?: string;
		clicks?: number;
		share?: number;
	} >;
	breakdowns?: {
		device?: Record< string, number >;
		os?: Record< string, number >;
		in_app?: Record< string, number >;
		referrer?: Array< { host: string; count: number } >;
	};
	country_available?: boolean;
}

export interface StoreProduct {
	id: number;
	name: string;
	price: string;
	url: string;
	image: string;
	on_sale: boolean;
}

export interface StoreProducts {
	available: boolean;
	products: StoreProduct[];
}

export interface Audience {
	id: string;
	name: string;
	member_count: number;
}

export interface Audiences {
	adapter: string;
	available: boolean;
	default: string;
	audiences: Audience[];
	error: string;
}

export const api = {
	preview( page: PageDoc, id: number, signal?: AbortSignal ) {
		return apiFetch< PreviewResponse >( {
			path: `${ NS }/render-preview`,
			method: 'POST',
			data: { page, id },
			signal,
		} );
	},
	savePage(
		id: number,
		data: { page?: PageDoc; title?: string; slug?: string; status?: string }
	) {
		return apiFetch< PagePayload >( {
			path: `${ NS }/pages/${ id }`,
			method: 'PATCH',
			data,
		} );
	},
	getPage( id: number ) {
		return apiFetch< PagePayload >( {
			path: `${ NS }/pages/${ id }?context=edit`,
		} );
	},
	autosave( id: number, page: PageDoc, title: string ) {
		return apiFetch< AutosaveResponse >( {
			path: `${ NS }/pages/${ id }/autosave`,
			method: 'POST',
			data: { page, title },
		} );
	},
	revisions( id: number ) {
		return apiFetch< RevisionSummary[] >( {
			path: `${ NS }/pages/${ id }/revisions`,
		} );
	},
	revision( id: number, revId: number ) {
		return apiFetch< RevisionDetail >( {
			path: `${ NS }/pages/${ id }/revisions/${ revId }`,
		} );
	},
	restore( id: number, revId: number ) {
		return apiFetch< PagePayload >( {
			path: `${ NS }/pages/${ id }/revisions/${ revId }/restore`,
			method: 'POST',
		} );
	},
	/** Store products for the Product block picker (`include` resolves one id). */
	storeProducts(
		params: { search?: string; perPage?: number; include?: number },
		signal?: AbortSignal
	) {
		const q = new URLSearchParams();
		if ( params.search ) {
			q.set( 'search', params.search );
		}
		q.set( 'per_page', String( params.perPage ?? 10 ) );
		if ( params.include ) {
			q.set( 'include', String( params.include ) );
		}
		return apiFetch< StoreProducts >( {
			path: `${ NS }/store/products?${ q.toString() }`,
			signal,
		} );
	},
	/** Mailchimp audiences for the Email sign-up block (site managers only). */
	audiences() {
		return apiFetch< Audiences >( {
			path: `${ NS }/integrations/audiences`,
		} );
	},
	analytics( id: number, range: string ) {
		return apiFetch< AnalyticsSummary >( {
			path: `${ NS }/analytics/summary?page_id=${ id }&range=${ encodeURIComponent( range ) }`,
		} );
	},
};
