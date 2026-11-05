/**
 * Thin wrapper around the core media modal (`wp.media`, loaded by wp_enqueue_media()).
 */
import { __ } from '@wordpress/i18n';
import type { MediaInfo } from '../types';

interface WpAttachment {
	id: number;
	url: string;
	alt?: string;
	mime?: string;
	width?: number;
	height?: number;
	sizes?: Record< string, { url: string } >;
}

interface WpMediaFrame {
	on: ( event: string, cb: () => void ) => void;
	open: () => void;
	state: () => {
		get: ( key: string ) => {
			toJSON: () => WpAttachment[];
			first: () => { toJSON: () => WpAttachment };
		};
	};
}

type WpMedia = ( options: Record< string, unknown > ) => WpMediaFrame;

declare global {
	interface Window {
		wp?: { media?: WpMedia };
	}
}

/**
 * Converts a wp.media attachment model to the editor's media shape.
 *
 * @param a Attachment JSON.
 */
export function toMediaInfo( a: WpAttachment ): MediaInfo {
	const sizes = a.sizes ?? {};
	const thumb =
		sizes[ 'cl-thumb' ]?.url ??
		sizes.thumbnail?.url ??
		sizes.medium?.url ??
		a.url;
	return {
		id: a.id,
		url: a.url,
		thumb,
		alt: a.alt ?? '',
		mime: a.mime ?? '',
		width: a.width,
		height: a.height,
	};
}

export function mediaAvailable(): boolean {
	return (
		typeof window !== 'undefined' && typeof window.wp?.media === 'function'
	);
}

/**
 * Opens the media modal.
 *
 * @param opts          Options.
 * @param opts.type     Library filter.
 * @param opts.multiple Allow several.
 * @param opts.title    Modal title.
 * @param opts.selected Preselected attachment id.
 * @return Chosen attachments (empty when the modal was closed).
 */
export function pickMedia( opts: {
	type?: 'image' | 'video';
	multiple?: boolean;
	title?: string;
	selected?: number;
} ): Promise< MediaInfo[] > {
	return new Promise( ( resolve ) => {
		const media = window.wp?.media;
		if ( ! media ) {
			resolve( [] );
			return;
		}
		const frame = media( {
			title: opts.title ?? __( 'Choose media', 'crazylinks' ),
			button: { text: __( 'Use this', 'crazylinks' ) },
			library: opts.type ? { type: opts.type } : {},
			multiple: opts.multiple ? 'add' : false,
		} );
		let done = false;
		frame.on( 'select', () => {
			done = true;
			const selection = frame.state().get( 'selection' );
			resolve( selection.toJSON().map( toMediaInfo ) );
		} );
		frame.on( 'close', () => {
			// 'close' fires after 'select'; resolve empty only when nothing was chosen.
			window.setTimeout( () => {
				if ( ! done ) {
					resolve( [] );
				}
			}, 0 );
		} );
		frame.open();
	} );
}
