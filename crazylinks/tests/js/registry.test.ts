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

describe( 'product block', () => {
	it( 'defaults to a one-product card with a Buy button', () => {
		expect( blockDef( 'product' ).defaults() ).toMatchObject( {
			mode: 'single',
			count: 3,
			display: 'card',
			show_price: true,
			button_text: 'Buy',
		} );
		expect( blockDef( 'product' ).icon ).toBe( 'cart' );
		expect( blockDef( 'product' ).category ).toBe( 'content' );
		expect( blockDef( 'product' ).trackable ).toBe( true );
	} );

	it( 'uses the product picker field and keeps defaults inside the schema', () => {
		const kinds = blockDef( 'product' ).fields.map( ( f ) => f.key );
		expect( kinds ).toEqual( [
			'mode',
			'product_id',
			'count',
			'category',
			'display',
			'show_price',
			'button_text',
			'title',
			'subtitle',
		] );
		expect(
			blockDef( 'product' ).fields.find( ( f ) => f.key === 'product_id' )
				?.kind
		).toBe( 'product' );
	} );

	it( 'summarizes both modes', () => {
		expect(
			blockDef( 'product' ).summary( { mode: 'single', product_id: 42 } )
		).toBe( '#42' );
		expect(
			blockDef( 'product' ).summary( {
				mode: 'single',
				product_id: 42,
				title: 'Tee',
			} )
		).toBe( 'Tee' );
		expect(
			blockDef( 'product' ).summary( { mode: 'latest', count: 5 } )
		).toBe( '5 newest products' );
	} );
} );

describe( 'email sign-up block and Mailchimp', () => {
	const def = blockDef( 'email_capture' );
	const visible = ( props: JsonObject ) =>
		def.fields
			.filter( ( f ) => ! f.showIf || f.showIf( props ) )
			.map( ( f ) => f.key );

	it( 'defaults to the local list', () => {
		expect( def.defaults() ).toMatchObject( { destination: 'local' } );
	} );

	it( 'shows the audience and tags only for Mailchimp blocks', () => {
		expect( visible( { destination: 'local' } ) ).not.toContain(
			'audience_id'
		);
		expect( visible( { destination: 'local' } ) ).not.toContain( 'tags' );
		expect( visible( { destination: 'mailchimp' } ) ).toEqual(
			expect.arrayContaining( [ 'destination', 'audience_id', 'tags' ] )
		);
		expect(
			def.fields.find( ( f ) => f.key === 'audience_id' )?.kind
		).toBe( 'audience' );
	} );

	it( 'offers both destinations from the schema enum', () => {
		const enumValues = (
			( defs.props_email_capture.properties as JsonObject )
				.destination as JsonObject
		 ).enum as string[];
		const options =
			def.fields
				.find( ( f ) => f.key === 'destination' )
				?.options?.map( ( o ) => o.value ) ?? [];
		expect( options ).toEqual( enumValues );
	} );

	it( 'uses the Mailchimp wording for the pending message', () => {
		const pending = ( props: JsonObject ) =>
			def.fields.filter(
				( f ) =>
					f.key === 'pending_text' &&
					( ! f.showIf || f.showIf( props ) )
			);
		expect( pending( { destination: 'local' } ) ).toHaveLength( 1 );
		expect( pending( { destination: 'mailchimp' } ) ).toHaveLength( 1 );
		expect( pending( { destination: 'mailchimp' } )[ 0 ].placeholder ).toBe(
			'Check your email to confirm your subscription.'
		);
		expect(
			pending( { destination: 'local' } )[ 0 ].placeholder
		).toContain( 'Check your inbox' );
	} );
} );
