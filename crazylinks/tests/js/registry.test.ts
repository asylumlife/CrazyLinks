/**
 * Block registry against schemas/page.v1.json (the single source of truth).
 */
import schema from '../../schemas/page.v1.json';
import {
	blockDef,
	blockDefs,
	missingFields,
	schemaBlockTypes,
} from '../../assets/src/editor/registry/blocks';
import type { JsonObject } from '../../assets/src/editor/types';

const defs = ( schema as unknown as { $defs: Record< string, JsonObject > } )
	.$defs;
const types = schemaBlockTypes( schema as unknown as JsonObject );

describe( 'block registry', () => {
	it( 'reads the type list from the schema', () => {
		expect( types ).toContain( 'link' );
		expect( types.length ).toBeGreaterThanOrEqual( 19 );
	} );

	it.each( types )( '%s has a definition', ( type ) => {
		const def = blockDefs()[ type ];
		expect( def ).toBeDefined();
		expect( def.label ).not.toBe( '' );
		expect( def.description ).not.toBe( '' );
		expect( def.icon ).toMatch( /^[a-z-]+$/ );
	} );

	it.each( types )( '%s fields exist in the schema props', ( type ) => {
		const props = ( defs[ `props_${ type }` ]?.properties ??
			{} ) as JsonObject;
		for ( const field of blockDef( type ).fields ) {
			const root = field.key.split( '/' )[ 0 ];
			expect( Object.keys( props ) ).toContain( root );
		}
	} );

	it.each( types )( '%s never drops a schema-required prop', ( type ) => {
		const schemaProps = defs[ `props_${ type }` ] ?? {};
		const required = ( schemaProps.required ?? [] ) as string[];
		const props = ( schemaProps.properties ?? {} ) as Record<
			string,
			JsonObject
		>;
		const fields = blockDef( type ).fields;
		for ( const key of required ) {
			const field = fields.find( ( f ) => f.key === key );
			expect( field ).toBeDefined();
			// Non-empty constraints make it `required`; otherwise the key is kept even when emptied.
			const nonEmpty =
				typeof props[ key ]?.minLength === 'number' ||
				typeof props[ key ]?.minimum === 'number';
			expect(
				nonEmpty ? field?.required : field?.required || field?.keep
			).toBe( true );
		}
	} );

	it.each( types )( '%s defaults only use schema props', ( type ) => {
		const props = ( defs[ `props_${ type }` ]?.properties ??
			{} ) as JsonObject;
		for ( const key of Object.keys( blockDef( type ).defaults() ) ) {
			expect( Object.keys( props ) ).toContain( key );
		}
	} );

	it( 'falls back for unknown types', () => {
		expect( blockDef( 'future_type' ).fields ).toEqual( [] );
	} );

	it( 'reports missing required fields', () => {
		expect(
			missingFields( 'link', { title: '', url: 'https://x.test' } )
		).toEqual( [ [ 'props/title', expect.any( String ) ] ] );
		expect(
			missingFields( 'link', { title: 'A', url: 'https://x.test' } )
		).toEqual( [] );
		expect( missingFields( 'image', {} ) ).toHaveLength( 1 );
		expect( missingFields( 'video', {} ) ).toEqual( [
			[ 'props/url', expect.any( String ) ],
		] );
		expect(
			missingFields( 'payment', { service: 'kofi', handle: 'me' } )
		).toEqual( [] );
		expect(
			missingFields( 'socials', { items: [ { network: 'x', url: '' } ] } )
		).toEqual( [ [ 'props/items/0/url', expect.any( String ) ] ] );
		expect(
			missingFields( 'countdown', blockDef( 'countdown' ).defaults() )
		).toEqual( [] );
	} );

	it( 'summarizes blocks for the list', () => {
		expect(
			blockDef( 'link' ).summary( {
				title: 'Shop',
				url: 'https://x.test',
			} )
		).toBe( 'Shop' );
		expect(
			blockDef( 'text' ).summary( {
				content: '<p>Hi <strong>there</strong></p>',
			} )
		).toBe( 'Hi there' );
	} );
} );
