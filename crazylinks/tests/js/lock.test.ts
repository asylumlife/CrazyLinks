/**
 * Post lock: boot state, heartbeat payload and takeover handling.
 */
import {
	applyHeartbeat,
	heartbeatPayload,
	lockFromBoot,
} from '../../assets/src/editor/lib/lock';

describe( 'post lock', () => {
	const boot = {
		locked: true,
		postId: 7,
		nonce: 'n',
		activeLock: '',
		user: { name: 'Alice', avatar: 'a.png' },
		takeOverUrl: '/take',
	};
	it( 'starts locked out when someone else holds the page', () => {
		const s = lockFromBoot( boot );
		expect( s.lockedOut ).toBe( true );
		expect( s.user?.name ).toBe( 'Alice' );
		expect( lockFromBoot( undefined ).lockedOut ).toBe( false );
		expect(
			lockFromBoot( { ...boot, locked: false, activeLock: '1:2' } )
				.lockedOut
		).toBe( false );
	} );
	it( 'sends the lock with each heartbeat', () => {
		const mine = lockFromBoot( {
			...boot,
			locked: false,
			user: null,
			activeLock: '1:2',
		} );
		expect( heartbeatPayload( mine ) ).toEqual( {
			post_id: 7,
			lock: '1:2',
		} );
		expect( heartbeatPayload( lockFromBoot( undefined ) ) ).toBeNull();
	} );
	it( 'locks the editor out when the heartbeat reports a takeover, and adopts a refreshed lock', () => {
		const mine = lockFromBoot( {
			...boot,
			locked: false,
			user: null,
			activeLock: '1:2',
		} );
		const taken = applyHeartbeat( mine, {
			lock_error: { name: 'Bob', avatar_src: 'b.png' },
		} );
		expect( taken.lockedOut ).toBe( true );
		expect( taken.user ).toEqual( { name: 'Bob', avatar: 'b.png' } );
		expect( applyHeartbeat( mine, { new_lock: '9:2' } ).activeLock ).toBe(
			'9:2'
		);
		expect( applyHeartbeat( mine, undefined ) ).toBe( mine );
	} );
} );
