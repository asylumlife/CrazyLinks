/**
 * Boot data printed by src/Admin/EditorPage.php as `window.crazylinks`.
 */
import type { BootData } from './types';

declare global {
	interface Window {
		crazylinks?: BootData;
	}
}

const empty: BootData = {
	post: {
		id: 0,
		title: '',
		slug: '',
		status: 'auto-draft',
		link: '',
		modified: '',
		author: 0,
		date: '',
		page: { schema: 1, profile: {}, design: {}, blocks: [], settings: {} },
		isNew: true,
		viewUrl: '',
		classicUrl: '',
		listUrl: '',
		baseUrl: '',
	},
	autosave: null,
	schema: {},
	themes: {},
	icons: {},
	networks: {},
	payments: {},
	blockCatalog: [],
	media: {},
	caps: {
		unfiltered_html: false,
		publish_crazylinks_pages: false,
		view_crazylinks_analytics: false,
		use_crazylinks_ai: false,
	},
	ai: { available: false },
	rest: { root: '', nonce: '', namespace: 'crazylinks/v1' },
	settings: {
		renderMode: 'standalone',
		analytics: true,
		autosaveInterval: 10,
	},
	version: '',
};

/**
 * The boot data, with safe defaults for anything missing (tests, partial filters).
 */
export function boot(): BootData {
	const data = typeof window !== 'undefined' ? window.crazylinks : undefined;
	if ( ! data ) {
		return empty;
	}
	return {
		...empty,
		...data,
		post: { ...empty.post, ...data.post },
		caps: { ...empty.caps, ...data.caps },
		settings: { ...empty.settings, ...data.settings },
		ai: { ...empty.ai, ...data.ai },
	};
}
