/**
 * Analytics tab. Reads GET /crazylinks/v1/analytics/summary and degrades to "no data yet" for any
 * missing field.
 */
import { __, sprintf } from '@wordpress/i18n';
import { Notice, SelectControl, Spinner } from '@wordpress/components';
import { Fragment, useEffect, useState } from '@wordpress/element';
import { api } from '../lib/api';
import type { AnalyticsSummary } from '../lib/api';
import { useEditor, useEditorDispatch } from '../hooks';
import Sparkline from './Sparkline';
import { CLICKS_RANGE, clicksByBlock } from '../lib/analytics';
import { blockDef } from '../registry/blocks';
import { boot } from '../boot';

type BlockRow = NonNullable< AnalyticsSummary[ 'blocks' ] >[ number ];
type SourceRow = NonNullable< AnalyticsSummary[ 'sources' ] >[ number ];
type Totals = NonNullable< AnalyticsSummary[ 'totals' ] >;
type SeriesPoint = NonNullable< AnalyticsSummary[ 'timeseries' ] >[ number ];

const NO_VALUE = '—';

const formatCount = ( n: unknown ) =>
	typeof n === 'number' ? n.toLocaleString() : NO_VALUE;
const formatShare = ( n: unknown ) =>
	typeof n === 'number'
		? ( n * 100 ).toLocaleString( undefined, {
				maximumFractionDigits: 1,
			} ) + '%'
		: NO_VALUE;

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
						<span>{ formatCount( n ) }</span>
					</li>
				) ) }
			</ul>
		</div>
	);
}

function TotalsList( { totals }: { totals: Totals } ) {
	return (
		<dl className="cl-totals">
			<div>
				<dt>{ __( 'Views', 'crazylinks' ) }</dt>
				<dd>{ formatCount( totals.views ) }</dd>
			</div>
			<div>
				<dt>{ __( 'Unique visitors', 'crazylinks' ) }</dt>
				<dd>{ formatCount( totals.uniques ) }</dd>
			</div>
			<div>
				<dt>{ __( 'Clicks', 'crazylinks' ) }</dt>
				<dd>{ formatCount( totals.clicks ) }</dd>
			</div>
			<div>
				<dt>{ __( 'Click-through rate', 'crazylinks' ) }</dt>
				<dd>{ formatShare( totals.ctr ) }</dd>
			</div>
		</dl>
	);
}

function DailyChart( {
	series,
	totals,
}: {
	series: SeriesPoint[];
	totals: Totals;
} ) {
	return (
		<figure className="cl-spark-wrap">
			<Sparkline
				label={ sprintf(
					/* translators: 1: total views, 2: total clicks. */
					__(
						'Daily views and clicks. Views: %1$s, clicks: %2$s.',
						'crazylinks'
					),
					formatCount( totals.views ),
					formatCount( totals.clicks )
				) }
				series={ [
					{
						label: 'views',
						values: series.map( ( p ) => p.views ?? 0 ),
						className: 'cl-spark__views',
					},
					{
						label: 'clicks',
						values: series.map( ( p ) => p.clicks ?? 0 ),
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
	);
}

/**
 * Clicks per block, with one sub-row per item for blocks that report them.
 *
 * @param props         Props.
 * @param props.rows    Rows from the summary.
 * @param props.titleOf Current title of a block, given its id and the title the server recorded.
 */
function BlockClicksTable( {
	rows,
	titleOf,
}: {
	rows: BlockRow[];
	titleOf: ( blockId: string, fallback?: string ) => string;
} ) {
	return (
		<table className="widefat striped cl-block-table">
			<caption className="screen-reader-text">
				{ __( 'Clicks per block', 'crazylinks' ) }
			</caption>
			<thead>
				<tr>
					<th scope="col">{ __( 'Block', 'crazylinks' ) }</th>
					<th scope="col" className="num">
						{ __( 'Clicks', 'crazylinks' ) }
					</th>
					<th scope="col" className="num">
						{ __( 'Share', 'crazylinks' ) }
					</th>
				</tr>
			</thead>
			<tbody>
				{ rows.map( ( row ) => (
					<Fragment key={ row.block_id }>
						<tr>
							<td>{ titleOf( row.block_id, row.title ) }</td>
							<td className="num">
								{ formatCount( row.clicks ) }
							</td>
							<td className="num">
								{ formatShare( row.share ) }
							</td>
						</tr>
						{ ( row.items ?? [] ).map( ( item, i ) => (
							<tr
								key={ `${ item.item_id }-${ i }` }
								className="cl-block-table__sub"
							>
								<td>{ item.title }</td>
								<td className="num">
									{ formatCount( item.clicks ) }
								</td>
								<td className="num">
									{ formatShare( item.share ) }
								</td>
							</tr>
						) ) }
					</Fragment>
				) ) }
			</tbody>
		</table>
	);
}

/**
 * Views and clicks per traffic source, with each source's most clicked blocks.
 *
 * @param props      Props.
 * @param props.rows Rows from the summary.
 */
function SourcesTable( { rows }: { rows: SourceRow[] } ) {
	return (
		<div className="cl-sources">
			<h3>{ __( 'What each source clicks', 'crazylinks' ) }</h3>
			<table className="widefat striped cl-block-table">
				<thead>
					<tr>
						<th scope="col">{ __( 'Source', 'crazylinks' ) }</th>
						<th scope="col" className="num">
							{ __( 'Views', 'crazylinks' ) }
						</th>
						<th scope="col" className="num">
							{ __( 'Clicks', 'crazylinks' ) }
						</th>
					</tr>
				</thead>
				<tbody>
					{ rows.map( ( source ) => {
						const top = source.top ?? [];
						return (
							<tr key={ source.source }>
								<td>
									{ source.label }
									{ top.length > 0 && (
										<span className="cl-sources__top">
											{ top
												.map(
													( t ) =>
														`${
															t.title
														} (${ formatCount(
															t.clicks
														) })`
												)
												.join( ', ' ) }
										</span>
									) }
								</td>
								<td className="num">
									{ formatCount( source.views ) }
								</td>
								<td className="num">
									{ formatCount( source.clicks ) }
								</td>
							</tr>
						);
					} ) }
				</tbody>
			</table>
		</div>
	);
}

export default function AnalyticsTab() {
	const id = useEditor( ( s ) => s.getMeta().id, [] );
	const status = useEditor( ( s ) => s.getMeta().status, [] );
	const blocks = useEditor( ( s ) => s.getDoc().blocks, [] );
	const { setClicks } = useEditorDispatch();
	const [ range, setRange ] = useState< string >( CLICKS_RANGE );
	const [ data, setData ] = useState< AnalyticsSummary | null >( null );
	const [ state, setState ] = useState< 'loading' | 'ok' | 'error' >(
		'loading'
	);
	const allowed = boot().caps.view_crazylinks_analytics;

	useEffect( () => {
		if ( ! allowed || id <= 0 ) {
			return;
		}
		// A response that arrives after the range changed again is dropped.
		let live = true;
		setState( 'loading' );
		api.analytics( id, range )
			.then( ( res ) => {
				if ( ! live ) {
					return;
				}
				setData( res );
				setState( 'ok' );
				if ( range === CLICKS_RANGE ) {
					setClicks( clicksByBlock( res ) );
				}
			} )
			.catch( () => {
				if ( live ) {
					setState( 'error' );
				}
			} );
		return () => {
			live = false;
		};
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
	const blockRows = data?.blocks ?? [];
	const sourceRows = data?.sources ?? [];
	const breakdowns = data?.breakdowns;
	const hasData =
		!! totals &&
		( ( totals.views ?? 0 ) > 0 || ( totals.clicks ?? 0 ) > 0 );
	const titleOf = ( blockId: string, fallback?: string ) => {
		const block = blocks.find( ( b ) => b.id === blockId );
		if ( ! block ) {
			return fallback || __( '(deleted block)', 'crazylinks' );
		}
		const def = blockDef( block.type );
		return def.summary( block.props ) || fallback || def.label;
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
					<TotalsList totals={ totals } />
					{ series.length > 1 && (
						<DailyChart series={ series } totals={ totals } />
					) }
					{ blockRows.length > 0 && (
						<BlockClicksTable
							rows={ blockRows }
							titleOf={ titleOf }
						/>
					) }
					{ sourceRows.length > 0 && (
						<SourcesTable rows={ sourceRows } />
					) }
					<Breakdown
						title={ __( 'Devices', 'crazylinks' ) }
						data={ breakdowns?.device }
					/>
					<Breakdown
						title={ __( 'Operating systems', 'crazylinks' ) }
						data={ breakdowns?.os }
					/>
					<Breakdown
						title={ __( 'In-app browsers', 'crazylinks' ) }
						data={ breakdowns?.in_app }
					/>
					<Breakdown
						title={ __( 'Referrers', 'crazylinks' ) }
						data={ Object.fromEntries(
							( breakdowns?.referrer ?? [] ).map( ( r ) => [
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
