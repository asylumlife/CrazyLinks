/**
 * "Someone else is editing" banner (core's post-lock behaviour): shown when another user holds the
 * page, either at load or after a takeover seen through the heartbeat.
 */
import { __, sprintf } from '@wordpress/i18n';
import { Button } from '@wordpress/components';
import { useEffect, useState } from '@wordpress/element';
import { boot } from '../boot';
import { getLock, subscribeLock, type LockState } from '../lib/lock';

export function useLock(): LockState {
	const [ state, setState ] = useState( getLock() );
	useEffect( () => {
		setState( getLock() );
		return subscribeLock( () => setState( getLock() ) );
	}, [] );
	return state;
}

export default function LockBanner() {
	const lock = useLock();
	if ( ! lock.lockedOut ) {
		return null;
	}
	const name = lock.user?.name || __( 'Someone else', 'crazylinks' );
	return (
		<div className="cl-lock" role="alert">
			{ lock.user?.avatar && <img src={ lock.user.avatar } alt="" /> }
			<p className="cl-lock__text">
				{ sprintf(
					/* translators: %s: user name. */
					__(
						'%s is editing this page. If you take over, they will be locked out and your changes replace theirs. Saving is off until you decide.',
						'crazylinks'
					),
					name
				) }
			</p>
			<div className="cl-lock__actions">
				<Button variant="primary" href={ lock.takeOverUrl }>
					{ __( 'Take over', 'crazylinks' ) }
				</Button>
				<Button variant="secondary" href={ boot().post.listUrl }>
					{ __( 'All link pages', 'crazylinks' ) }
				</Button>
			</div>
		</div>
	);
}
