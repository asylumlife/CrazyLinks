import type { AiBoot } from './ai/types';
/**
 * Page document types (schemas/page.v1.json) and editor boot data.
 */

export type BlockType =
	| 'link'
	| 'socials'
	| 'heading'
	| 'text'
	| 'divider'
	| 'image'
	| 'gallery'
	| 'latest_posts'
	| 'embed'
	| 'video'
	| 'email_capture'
	| 'contact'
	| 'group'
	| 'woo_products'
	| 'payment'
	| 'html'
	| 'spacer'
	| 'countdown'
	| 'map';

export type Animation = 'none' | 'pulse' | 'shake' | 'wobble' | 'shimmer';

export type JsonValue =
	| string
	| number
	| boolean
	| null
	| JsonValue[]
	| { [ key: string ]: JsonValue };

export type JsonObject = { [ key: string ]: JsonValue };

/** Recurring weekly window in the site timezone (schema `schedule.weekly`). */
export interface WeeklyWindow {
	/** ISO weekdays, 1 = Monday ... 7 = Sunday. */
	days: number[];
	/** "HH:MM", 24-hour. */
	start: string;
	/** "HH:MM"; at or before `start` means the window runs past midnight. */
	end: string;
}

export interface Schedule {
	start: string | null;
	end: string | null;
	weekly?: WeeklyWindow | null;
}

export interface Block {
	id: string;
	type: BlockType;
	parent_id: string | null;
	order: number;
	visible: boolean;
	schedule: Schedule;
	pinned: boolean;
	animation: Animation;
	tracking: boolean;
	props: JsonObject;
}

export interface PageDoc {
	schema: 1;
	profile: JsonObject;
	design: JsonObject;
	blocks: Block[];
	settings: JsonObject;
}

export interface PageMeta {
	id: number;
	title: string;
	slug: string;
	status: string;
	link: string;
	modified: string;
}

/** One validation error as returned by PageValidator (JSON path + message). */
export interface ServerError {
	path: string;
	message: string;
}

/** A validation error resolved against the document it was produced for. */
export interface MappedError extends ServerError {
	/** Block id when the path points into /blocks/{i}. */
	blockId?: string;
	/** Path relative to the block (e.g. `props/url`), or the full path for non-block errors. */
	field: string;
}

export interface MediaInfo {
	id: number;
	url: string;
	thumb: string;
	alt: string;
	mime: string;
	width?: number;
	height?: number;
}

export interface ThemePreset {
	label: string;
	group?: string;
	background?: JsonObject;
	text?: JsonObject;
	button?: JsonObject;
	accent?: string;
	radius?: number;
	dark?: JsonObject;
}

export interface PaymentPreset {
	label: string;
	icon: string;
	hosts: string[];
	template: string;
}

export interface CatalogEntry {
	type: string;
	available: boolean;
	reason: string;
}

export interface PagePayload extends PageMeta {
	author: number;
	date: string;
	page: PageDoc;
}

export interface AutosaveInfo {
	id: number;
	modified: string;
	title: string;
	page: PageDoc;
}

/** Core post-lock state for the page being edited (src/PostLock.php). */
export interface LockBoot {
	locked: boolean;
	postId: number;
	nonce: string;
	activeLock: string;
	user: { name: string; avatar: string } | null;
	takeOverUrl: string;
}

export interface BootData {
	post: PagePayload & {
		isNew: boolean;
		viewUrl: string;
		classicUrl: string;
		listUrl: string;
		baseUrl: string;
	};
	autosave: AutosaveInfo | null;
	schema: JsonObject;
	themes: Record< string, ThemePreset >;
	icons: Record< string, { title: string; path: string } >;
	networks: Record< string, string >;
	payments: Record< string, PaymentPreset >;
	blockCatalog: CatalogEntry[];
	media: Record< string, MediaInfo >;
	caps: {
		unfiltered_html: boolean;
		publish_crazylinks_pages: boolean;
		view_crazylinks_analytics: boolean;
		use_crazylinks_ai: boolean;
	};
	ai: AiBoot;
	lock?: LockBoot;
	rest: { root: string; nonce: string; namespace: string };
	settings: {
		renderMode: string;
		analytics: boolean;
		autosaveInterval: number;
	};
	version: string;
}
