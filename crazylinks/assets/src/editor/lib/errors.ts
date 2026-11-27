/**
 * Maps PageValidator error paths (`/blocks/3/props/url`) onto blocks and fields of the document
 * the errors were produced for, so the UI can show them inline.
 */
import { __ } from '@wordpress/i18n';
import type { MappedError, PageDoc, ServerError } from '../types';
import { missingFields } from '../registry/blocks';

/**
 * Resolves errors against the document that was submitted.
 *
 * @param errors Server errors.
 * @param doc    Document that produced them (indices refer to its `blocks` array).
 */
export function mapErrors(
	errors: ServerError[],
	doc: PageDoc
): MappedError[] {
	return errors.map( ( error ) => {
		const path = typeof error.path === 'string' ? error.path : '/';
		const m = /^\/blocks\/(\d+)(?:\/(.*))?$/.exec( path );
		if ( m ) {
			const block = doc.blocks[ Number( m[ 1 ] ) ];
			if ( block ) {
				return {
					...error,
					path,
					blockId: block.id,
					field: m[ 2 ] ?? '',
				};
			}
		}
		return { ...error, path, field: path };
	} );
}

/**
 * Client-side completeness errors (required fields), keyed like mapped server errors.
 *
 * @param doc Document.
 */
export function localErrors( doc: PageDoc ): MappedError[] {
	const out: MappedError[] = [];
	doc.blocks.forEach( ( block, i ) => {
		for ( const [ field, message ] of missingFields(
			block.type,
			block.props
		) ) {
			out.push( {
				path: `/blocks/${ i }/${ field }`,
				message,
				blockId: block.id,
				field,
			} );
		}
	} );
	return out;
}

/**
 * Error payload of a failed apiFetch call: `data.errors` from crazylinks_invalid_page, or a single
 * message error at `/`.
 *
 * @param e Thrown value.
 */
export function errorsFromResponse( e: unknown ): ServerError[] {
	const err = e as {
		code?: string;
		message?: string;
		data?: { errors?: unknown };
	} | null;
	const list = err?.data?.errors;
	if ( Array.isArray( list ) ) {
		return list
			.filter(
				( x ): x is ServerError =>
					!! x &&
					typeof x === 'object' &&
					'path' in x &&
					'message' in x
			)
			.map( ( x ) => ( {
				path: String( x.path ),
				message: String( x.message ),
			} ) );
	}
	return [
		{
			path: '/',
			message: err?.message || __( 'The request failed.', 'crazylinks' ),
		},
	];
}

/**
 * Whether a failed request was rejected by the validator, as opposed to a network or server error.
 *
 * @param e Thrown value.
 */
export function isValidationError( e: unknown ): boolean {
	const err = e as { data?: { errors?: unknown } } | null;
	return Array.isArray( err?.data?.errors );
}

/**
 * Entries that belong to one block, optionally narrowed to a field (exact match or a sub-path).
 *
 * @param list    Mapped errors or warnings.
 * @param blockId Block id.
 * @param field   Field path relative to the block, e.g. `props/url`.
 */
export function forBlock< T extends { blockId?: string; field: string } >(
	list: T[],
	blockId: string,
	field?: string
): T[] {
	return list.filter(
		( entry ) =>
			entry.blockId === blockId &&
			( field === undefined ||
				entry.field === field ||
				entry.field.startsWith( field + '/' ) )
	);
}

/**
 * Errors for one block, optionally narrowed to a field (exact match or a sub-path of it).
 *
 * @param errors  Mapped errors.
 * @param blockId Block id.
 * @param field   Field path relative to the block, e.g. `props/url`.
 */
export function blockErrors(
	errors: MappedError[],
	blockId: string,
	field?: string
): MappedError[] {
	return forBlock( errors, blockId, field );
}

/**
 * Message of the first page-level error at a document path or below it.
 *
 * @param errors Mapped errors.
 * @param path   Document path, e.g. `/design/text/color`.
 */
export function errorFor(
	errors: MappedError[],
	path: string
): string | undefined {
	return errors.find(
		( e ) =>
			! e.blockId &&
			( e.field === path || e.field.startsWith( path + '/' ) )
	)?.message;
}

/**
 * Messages for the sub-paths of one block field, keyed by the path below it (`0/url`).
 *
 * @param list  Mapped errors or warnings of one block.
 * @param field Field path relative to the block, e.g. `props/items`.
 */
export function subMessages(
	list: Array< { field: string; message: string } >,
	field: string
): Record< string, string > {
	const prefix = `${ field }/`;
	const out: Record< string, string > = {};
	for ( const entry of list ) {
		if ( entry.field.startsWith( prefix ) ) {
			out[ entry.field.slice( prefix.length ) ] = entry.message;
		}
	}
	return out;
}
