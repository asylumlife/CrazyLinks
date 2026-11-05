<?php
/**
 * WP-CLI: wp crazylinks ai.
 *
 * @package CrazyLinks
 */

namespace CrazyLinks\Cli;

use CrazyLinks\Ai\Evals;
use CrazyLinks\Ai\Limits;
use CrazyLinks\Ai\Status;
use WP_CLI;

defined( 'ABSPATH' ) || exit;

/**
 * AI editing status and the eval set.
 */
final class AiCommand {

	/**
	 * Shows whether AI editing can run and which provider and models it would use.
	 *
	 * ## EXAMPLES
	 *
	 *     wp crazylinks ai status --user=admin
	 *
	 * @param string[]              $args       Positional args.
	 * @param array<string, string> $assoc_args Options.
	 */
	public function status( $args, $assoc_args ): void {
		unset( $args, $assoc_args );
		$reason = Status::reason();
		$budget = Limits::budget_state();
		WP_CLI::line( 'WordPress AI client: ' . ( Status::client_exists() ? 'yes' : 'no' ) );
		WP_CLI::line( 'Provider ready:      ' . ( Status::provider_ready() ? 'yes' : 'no' ) );
		WP_CLI::line( 'Providers:           ' . ( implode( ', ', Status::providers() ) ? implode( ', ', Status::providers() ) : '(none)' ) );
		WP_CLI::line( 'Plugin setting:      ' . ( Status::enabled() ? 'on' : 'off' ) );
		WP_CLI::line( 'Edit models:         ' . implode( ', ', Status::models( 'edit' ) ) );
		WP_CLI::line( 'Suggestion models:   ' . implode( ', ', Status::models( 'suggest' ) ) );
		WP_CLI::line( sprintf( 'Budget:              %s / %s tokens this month', number_format( $budget['used'] ), $budget['budget'] ? number_format( $budget['budget'] ) : 'unlimited' ) );
		WP_CLI::line( 'Available for user:  ' . ( '' === $reason ? 'yes' : 'no (' . $reason . ': ' . Status::message( $reason ) . ')' ) );
	}

	/**
	 * Runs the AI eval set.
	 *
	 * Without --offline, each instruction is sent live to the configured AI provider (this costs
	 * tokens and counts against the monthly budget). With no provider configured it prints a notice
	 * and exits successfully.
	 *
	 * ## OPTIONS
	 *
	 * [--grade]
	 * : Ask a fast model (Claude Haiku class) to grade each result against the case's rubric.
	 *
	 * [--offline]
	 * : Replay the recorded responses through the validator and applier instead (no provider needed).
	 *
	 * [--case=<id>]
	 * : Run one case.
	 *
	 * [--limit=<n>]
	 * : Run at most n cases.
	 *
	 * ## EXAMPLES
	 *
	 *     wp crazylinks ai eval --offline
	 *     wp crazylinks ai eval --grade --user=admin
	 *
	 * @subcommand eval
	 *
	 * @param string[]              $args       Positional args.
	 * @param array<string, string> $assoc_args Options.
	 */
	public function run_eval( $args, $assoc_args ): void {
		unset( $args );
		$set = Evals::load();
		if ( null === $set ) {
			WP_CLI::warning( 'The eval set (tests/fixtures/ai/evals.json) is not installed; it ships only with the development repository.' );
			return;
		}
		$offline = ! empty( $assoc_args['offline'] );
		$grade   = ! empty( $assoc_args['grade'] );
		if ( ! $offline && ! Status::provider_ready() ) {
			WP_CLI::line( 'No AI provider is configured (Settings → Connectors), so the live eval was skipped. Use --offline to replay the recorded responses.' );
			return;
		}
		$cases = (array) $set['cases'];
		if ( ! empty( $assoc_args['case'] ) ) {
			$cases = array_values( array_filter( $cases, static fn( $c ) => ( $c['id'] ?? '' ) === $assoc_args['case'] ) );
		}
		if ( ! empty( $assoc_args['limit'] ) ) {
			$cases = array_slice( $cases, 0, max( 1, (int) $assoc_args['limit'] ) );
		}
		$pass   = 0;
		$tokens = 0;
		foreach ( $cases as $item ) {
			if ( $offline ) {
				$r = Evals::replay( $set, $item );
			} else {
				$r       = Evals::live( $set, $item, $grade );
				$tokens += $r['tokens'];
			}
			$pass += $r['pass'] ? 1 : 0;
			WP_CLI::line( sprintf( '%s  %-28s %s', $r['pass'] ? 'PASS' : 'FAIL', (string) $item['id'], $r['pass'] ? '' : $r['message'] ) );
		}
		$total = count( $cases );
		$rate  = $total ? round( 100 * $pass / $total, 1 ) : 0;
		$line  = sprintf( '%d/%d passed (%s%%)%s', $pass, $total, $rate, $offline ? ' [offline replay]' : sprintf( ' [live%s, %s tokens]', $grade ? ' + graded' : '', number_format( $tokens ) ) );
		if ( $pass === $total ) {
			WP_CLI::success( $line );
		} else {
			WP_CLI::warning( $line );
		}
	}
}
