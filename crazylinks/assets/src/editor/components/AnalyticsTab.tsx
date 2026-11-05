/**
 * Analytics tab. Reads GET /crazylinks/v1/analytics/summary and degrades to "no data yet" for any
 * missing field.
 */
import { __, sprintf } from '@wordpress/i18n';
import { Notice, SelectControl, Spinner } from '@wordpress/components';
import { useEffect, useState } from '@wordpress/element';
import { api } from '../lib/api';
import type { AnalyticsSummary } from '../lib/api';
import { useEditor, useEditorDispatch } from '../hooks';
import Sparkline from './Sparkline';
import { clicksByBlock } from '../lib/analytics';
import { blockDef } from '../registry/blocks';
import { boot } from '../boot';

const fmt = ( n: unknown ) =>
	typeof n === 'number' ? n.toLocaleString() : '—';
const pct = ( n: unknown ) =>
	typeof n === 'number'
		? ( n * 100 ).toLocaleString( undefined, {
				maximumFractionDigits: 1,
			} ) + '%'
		: '—';

function Breakdown( {
	title,
	data,
}: {
	title: string;
	data?: Record< string, number >;
} ) {
	const rows = Object.entries( data ?? {} ).sort(
		( a, b ) => b[ 1 ] - a[ 1 ]
	);
	if ( rows.length === 0 ) {
		return null;
	}
	const total = rows.reduce( ( t, [ , n ] ) => t + n, 0 ) || 1;
	return (
		<div className="cl-breakdown">
			<h3>{ title }</h3>
			<ul>
				{ rows.map( ( [ k, n ] ) => (
					<li key={ k }>
						<span>{ k || __( 'Unknown', 'crazylinks' ) }</span>
						<span
							className="cl-breakdown__bar"
							style={ {
								width: `${ Math.round( ( n / total ) * 100 ) }%`,
							} }
							aria-hidden="true"
						/>
						<span>{ fmt( n ) }</span>
					</li>
				) ) }
			</ul>
		</div>
	);
}

export default function AnalyticsTab() {
	const id = useEditor( ( s ) => s.getMeta().id, [] );
	const status = useEditor( ( s ) => s.getMeta().status, [] );
	const blocks = useEditor( ( s ) => s.getDoc().blocks, [] );
	const { setClicks } = useEditorDispatch();
	const [ range, setRange ] = useState( '30d' );
	const [ data, setData ] = useState< AnalyticsSummary | null >( null );
	const [ state, setState ] = useState< 'loading' | 'ok' | 'error' >(
		'loading'
	);
	const allowed = boot().caps.view_crazylinks_analytics;

	useEffect( () => {
		if ( ! allowed || id <= 0 ) {
			return;
		}
		setState( 'loading' );
		api.analytics( id, range )
			.then( ( res ) => {
				setData( res );
				setState( 'ok' );
				if ( range === '30d' ) {
					setClicks( clicksByBlock( res ) );
				}
			} )
			.catch( () => setState( 'error' ) );
	}, [ id, range, allowed, setClicks ] );

	if ( ! allowed ) {
		return (
			<Notice status="info" isDismissible={ false }>
				{ __(
					'You do not have permission to view analytics.',
					'crazylinks'
				) }
			</Notice>
		);
	}

	const totals = data?.totals;
	const series = data?.timeseries ?? [];
	const hasData =
		!! totals &&
		( ( totals.views ?? 0 ) > 0 || ( totals.clicks ?? 0 ) > 0 );
	const titleOf = ( blockId: string, fallback?: string ) => {
		const b = blocks.find( ( x ) => x.id === blockId );
		if ( ! b ) {
			return fallback || __( '(deleted block)', 'crazylinks' );
		}
		return (
			blockDef( b.type ).summary( b.props ) ||
			fallback ||
			blockDef( b.type ).label
		);
	};

	return (
		<div className="cl-analytics-tab">
			<SelectControl< string >
				__next40pxDefaultSize
				__nextHasNoMarginBottom
				label={ __( 'Date range', 'crazylinks' ) }
				value={ range }
				options={ [
					{ value: '7d', label: __( 'Last 7 days', 'crazylinks' ) },
					{ value: '30d', label: __( 'Last 30 days', 'crazylinks' ) },
					{ value: '90d', label: __( 'Last 90 days', 'crazylinks' ) },
				] }
				onChange={ setRange }
			/>
			{ state === 'loading' && <Spinner /> }
			{ state === 'error' && (
				<Notice status="warning" isDismissible={ false }>
					{ __(
						'Analytics are not available right now.',
						'crazylinks'
					) }
				</Notice>
			) }
			{ state === 'ok' && ! hasData && (
				<div className="cl-empty" role="status">
					<p>
						{ status === 'publish'
							? __(
									'No data yet. Views and clicks appear here once people visit the page.',
									'crazylinks'
								)
							: __(
									'No data yet. Publish the page to start counting views and clicks.',
									'crazylinks'
								) }
					</p>
				</div>
			) }
			{ state === 'ok' && hasData && totals && (
				<>
					<dl className="cl-totals">
						<div>
							<dt>{ __( 'Views', 'crazylinks' ) }</dt>
							<dd>{ fmt( totals.views ) }</dd>
						</div>
						<div>
							<dt>{ __( 'Unique visitors', 'crazylinks' ) }</dt>
							<dd>{ fmt( totals.uniques ) }</dd>
						</div>
						<div>
							<dt>{ __( 'Clicks', 'crazylinks' ) }</dt>
							<dd>{ fmt( totals.clicks ) }</dd>
						</div>
						<div>
							<dt>
								{ __( 'Click-through rate', 'crazylinks' ) }
							</dt>
							<dd>{ pct( totals.ctr ) }</dd>
						</div>
					</dl>
					{ series.length > 1 && (
						<figure className="cl-spark-wrap">
							<Sparkline
								label={ sprintf(
									/* translators: 1: total views, 2: total clicks. */
									__(
										'Daily views and clicks. Views: %1$s, clicks: %2$s.',
										'crazylinks'
									),
									fmt( totals.views ),
									fmt( totals.clicks )
								) }
								series={ [
									{
										label: 'views',
										values: series.map(
											( p ) => p.views ?? 0
										),
										className: 'cl-spark__views',
									},
									{
										label: 'clicks',
										values: series.map(
											( p ) => p.clicks ?? 0
										),
										className: 'cl-spark__clicks',
									},
								] }
							/>
							<figcaption>
								<span className="cl-key cl-key--views">
									{ __( 'Views', 'crazylinks' ) }
								</span>
								<span className="cl-key cl-key--clicks">
									{ __( 'Clicks', 'crazylinks' ) }
								</span>
							</figcaption>
						</figure>
					) }
					{ ( data?.blocks ?? [] ).length > 0 && (
						<table className="widefat striped cl-block-table">
							<caption className="screen-reader-text">
								{ __( 'Clicks per block', 'crazylinks' ) }
							</caption>
							<thead>
								<tr>
									<th scope="col">
										{ __( 'Block', 'crazylinks' ) }
									</th>
									<th scope="col" className="num">
										{ __( 'Clicks', 'crazylinks' ) }
									</th>
									<th scope="col" className="num">
										{ __( 'Share', 'crazylinks' ) }
									</th>
								</tr>
							</thead>
							<tbody>
								{ ( data?.blocks ?? [] ).map( ( row ) => (
									<tr key={ row.block_id }>
										<td>
											{ titleOf(
												row.block_id,
												row.title
											) }
										</td>
										<td className="num">
											{ fmt( row.clicks ) }
										</td>
										<td className="num">
											{ pct( row.share ) }
										</td>
									</tr>
								) ) }
							</tbody>
						</table>
					) }
					<Breakdown
						title={ __( 'Devices', 'crazylinks' ) }
						data={ data?.breakdowns?.device }
					/>
					<Breakdown
						title={ __( 'Operating systems', 'crazylinks' ) }
						data={ data?.breakdowns?.os }
					/>
					<Breakdown
						title={ __( 'In-app browsers', 'crazylinks' ) }
						data={ data?.breakdowns?.in_app }
					/>
					<Breakdown
						title={ __( 'Referrers', 'crazylinks' ) }
						data={ Object.fromEntries(
							( data?.breakdowns?.referrer ?? [] ).map( ( r ) => [
								r.host,
								r.count,
							] )
						) }
					/>
					{ data?.country_available === false && (
						<p className="cl-muted">
							{ __(
								'Country data is not collected on this site.',
								'crazylinks'
							) }
						</p>
					) }
				</>
			) }
		</div>
	);
}
