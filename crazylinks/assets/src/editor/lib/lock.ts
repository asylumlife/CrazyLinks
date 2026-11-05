/**
 * Post locking on top of core's: the server sets or checks `_edit_lock` when the editor boots
 * (src/PostLock.php); core's `wp-refresh-post-lock` heartbeat keeps it alive and reports when
 * someone else took the page over. While locked out the editor does not save.
 */
import type { LockBoot } from '../types';
import { boot } from '../boot';

export interface LockUser {
	name: string;
	avatar: string;
}

export interface LockState {
	lockedOut: boolean;
	user: LockUser | null;
	takeOverUrl: string;
	postId: number;
	nonce: string;
	activeLock: string;
}

/** Shape of core's heartbeat response for `wp-refresh-post-lock`. */
export interface LockResponse {
	lock_error?: { name?: string; avatar_src?: string };
	new_lock?: string;
}

/**
 * Initial state from the boot data (no lock data means no locking).
 *
 * @param lock Boot lock data.
 */
export function lockFromBoot( lock: LockBoot | undefined ): LockState {
	return {
		lockedOut: !! lock?.locked,
		user: lock?.locked && lock.user ? lock.user : null,
		takeOverUrl: lock?.takeOverUrl ?? '',
		postId: lock?.postId ?? 0,
		nonce: lock?.nonce ?? '',
		activeLock: lock?.activeLock ?? '',
	};
}

/**
 * Applies a heartbeat response: a `lock_error` means someone else holds the page now; a `new_lock`
 * is our refreshed lock string.
 *
 * @param state    Current state.
 * @param response Response for `wp-refresh-post-lock`.
 */
export function applyHeartbeat(
	state: LockState,
	response: LockResponse | undefined
): LockState {
	if ( ! response ) {
		return state;
	}
	if ( response.lock_error ) {
		return {
			...state,
			lockedOut: true,
			user: {
				name: response.lock_error.name ?? '',
				avatar: response.lock_error.avatar_src ?? '',
			},
		};
	}
	if ( response.new_lock ) {
		return { ...state, lockedOut: false, activeLock: response.new_lock };
	}
	return state;
}

/**
 * What the editor sends with each heartbeat, or null when there is nothing to keep alive.
 *
 * @param state Current state.
 */
export function heartbeatPayload(
	state: LockState
): { post_id: number; lock?: string } | null {
	if ( state.postId <= 0 ) {
		return null;
	}
	return state.activeLock
		? { post_id: state.postId, lock: state.activeLock }
		: { post_id: state.postId };
}

let current: LockState = lockFromBoot( undefined );
const listeners = new Set< () => void >();

const set = ( next: LockState ) => {
	current = next;
	listeners.forEach( ( fn ) => fn() );
};

export const getLock = (): LockState => current;
export const isLockedOut = (): boolean => current.lockedOut;
export const subscribeLock = ( fn: () => void ): ( () => void ) => {
	listeners.add( fn );
	return () => {
		listeners.delete( fn );
	};
};

interface HeartbeatEvents {
	on: ( ev: string, fn: ( e: unknown, data: any ) => void ) => void;
	off: ( ev: string ) => void;
}

/**
 * Wires the lock into core's heartbeat and releases the lock when the page is left. Returns a
 * cleanup function.
 */
export function startLock(): () => void {
	set( lockFromBoot( boot().lock ) );
	const w = window as unknown as {
		jQuery?: ( target: Document ) => HeartbeatEvents;
		ajaxurl?: string;
	};
	const cleanups: Array< () => void > = [];
	if ( w.jQuery ) {
		const $doc = w.jQuery( document );
		$doc.on( 'heartbeat-send.crazylinks-lock', ( _e, data ) => {
			const payload = heartbeatPayload( current );
			if ( payload ) {
				data[ 'wp-refresh-post-lock' ] = payload;
			}
		} );
		$doc.on( 'heartbeat-tick.crazylinks-lock', ( _e, data ) => {
			set( applyHeartbeat( current, data?.[ 'wp-refresh-post-lock' ] ) );
		} );
		cleanups.push( () => {
			$doc.off( 'heartbeat-send.crazylinks-lock' );
			$doc.off( 'heartbeat-tick.crazylinks-lock' );
		} );
	}
	// Release our lock on the way out, as core's edit screen does.
	const release = () => {
		if (
			current.lockedOut ||
			! current.activeLock ||
			! w.ajaxurl ||
			! navigator.sendBeacon
		) {
			return;
		}
		const body = new FormData();
		body.append( 'action', 'wp-remove-post-lock' );
		body.append( '_wpnonce', current.nonce );
		body.append( 'post_ID', String( current.postId ) );
		body.append( 'active_post_lock', current.activeLock );
		navigator.sendBeacon( w.ajaxurl, body );
	};
	window.addEventListener( 'pagehide', release );
	cleanups.push( () => window.removeEventListener( 'pagehide', release ) );
	return () => cleanups.forEach( ( fn ) => fn() );
}
