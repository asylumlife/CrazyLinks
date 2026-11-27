/**
 * Analytics summary helpers.
 */
import type { AnalyticsSummary } from './api';

/** The date range the click counts in the block list cover. */
export const CLICKS_RANGE = '30d';

/**
 * Per-block click counts from a summary.
 *
 * @param summary Summary.
 */
export function clicksByBlock(
	summary: AnalyticsSummary | null
): Record< string, number > {
	const out: Record< string, number > = {};
	for ( const row of summary?.blocks ?? [] ) {
		if (
			row &&
			typeof row.block_id === 'string' &&
			typeof row.clicks === 'number'
		) {
			out[ row.block_id ] = row.clicks;
		}
	}
	return out;
}
