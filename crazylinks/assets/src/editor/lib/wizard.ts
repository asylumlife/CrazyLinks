/**
 * Onboarding wizard: answers to a starting document.
 */
import { parseSocialList } from './social';
import { createBlock, emptyDoc, normalize } from '../store/doc';
import type { PageDoc } from '../types';
import { DEFAULT_THEME } from './theme';
import { PROFILE_BIO_MAX, PROFILE_NAME_MAX } from './limits';

export interface WizardResult {
	name: string;
	bio: string;
	avatar?: number;
	theme: string;
	socials: string;
}

/**
 * Builds the starting document from the wizard answers.
 *
 * @param r Answers.
 */
export function buildDocument( r: WizardResult ): PageDoc {
	const doc = emptyDoc();
	doc.profile = {
		...( r.name.trim()
			? { name: r.name.trim().slice( 0, PROFILE_NAME_MAX ) }
			: {} ),
		...( r.bio.trim()
			? { bio: r.bio.trim().slice( 0, PROFILE_BIO_MAX ) }
			: {} ),
		...( r.avatar ? { avatar: r.avatar } : {} ),
	};
	doc.design = { theme: r.theme || DEFAULT_THEME };
	const { items } = parseSocialList( r.socials );
	if ( items.length ) {
		const block = createBlock( 'socials' );
		block.props = {
			items: items
				.slice( 0, 40 )
				.map( ( i ) => ( { network: i.network, url: i.url } ) ),
			style: 'icons',
		};
		block.order = 0;
		doc.blocks = [ block ];
	}
	return normalize( doc );
}
