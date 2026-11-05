/**
 * AI editing types (REST /crazylinks/v1/pages/{id}/ai/* and window.crazylinks.ai).
 */
import type { JsonObject, PageDoc, PagePayload, ServerError } from '../types';

export type AiMode =
	| 'edit'
	| 'build_from_site'
	| 'build_from_description'
	| 'restyle'
	| 'analytics_qa';

export type ChangeKind =
	'add' | 'update' | 'move' | 'remove' | 'design' | 'profile';

export interface ChangeItem {
	kind: ChangeKind;
	text: string;
	block_id: string | null;
}

export interface BudgetState {
	budget: number;
	used: number;
	share: number;
	warning: boolean;
	exhausted: boolean;
}

export interface Proposal {
	proposal_id: string | null;
	summary: string;
	operations: JsonObject[];
	questions: string[];
	preview_document: PageDoc;
	change_list: ChangeItem[];
	mode: AiMode;
	expires_at: string | null;
	model: string;
	usage?: { tokens: number; estimated: boolean; attempts: number };
	budget?: BudgetState;
}

export interface ApplyResult {
	applied: boolean;
	already_applied?: boolean;
	revision_id: number | null;
	source: 'admin' | 'mcp';
	rebased: boolean;
	edited: boolean;
	change_list: ChangeItem[];
	page: PagePayload;
}

/** Extra keys of window.crazylinks.ai added by Ai\Status::editor_data(). */
export interface AiBoot {
	available: boolean;
	ready?: boolean;
	tab?: boolean;
	reason?: string;
	message?: string;
	connectorsUrl?: string;
	settingsUrl?: string;
	providers?: string[];
	budget?: BudgetState;
	requireConfirmation?: boolean;
	modes?: AiMode[];
}

export type EntryState = 'open' | 'applied' | 'discarded';

export interface HistoryEntry {
	key: string;
	instruction: string;
	mode: AiMode;
	proposal: Proposal;
	state: EntryState;
	createdAt: number;
	revisionId?: number | null;
}

export interface Suggestions {
	kind: 'titles' | 'bio';
	blockId: string | null;
	items: string[];
}

export type AiStatus = 'idle' | 'proposing' | 'applying' | 'suggesting';

export interface AiState {
	mode: AiMode;
	instruction: string;
	status: AiStatus;
	error: string | null;
	errors: ServerError[];
	history: HistoryEntry[];
	current: string | null;
	answers: Record< number, string >;
	view: 'after' | 'before';
	suggestions: Suggestions | null;
}
