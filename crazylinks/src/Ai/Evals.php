<?php
/**
 * The AI eval set (tests/fixtures/ai): replay recorded responses or run live.
 *
 * @package CrazyLinks
 */

namespace CrazyLinks\Ai;

use CrazyLinks\Schema\PageValidator;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Each case: a starting document, an instruction and mode, an expectation (operations of given kinds,
 * or clarifying questions, plus a rubric) and, for the offline replay, a recorded model response with
 * asserts on the resulting document. The fixtures ship only in the development repository.
 */
final class Evals {

	/**
	 * Fixture directory.
	 */
	public static function dir(): string {
		return CRAZYLINKS_DIR . 'tests/fixtures/ai/';
	}

	/**
	 * The decoded eval set, or null when the fixtures are not installed.
	 *
	 * @return array<string, mixed>|null
	 */
	public static function load(): ?array {
		$file = self::dir() . 'evals.json';
		if ( ! is_readable( $file ) ) {
			return null;
		}
		$data = json_decode( (string) file_get_contents( $file ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local file.
		return is_array( $data ) ? $data : null;
	}

	/**
	 * A case's starting document, validated like a save.
	 *
	 * @param array<string, mixed> $set  Eval set.
	 * @param array<string, mixed> $item Case.
	 * @return array<string, mixed>
	 */
	public static function document( array $set, array $item ): array {
		$doc    = (array) ( $set['documents'][ (string) $item['document'] ] ?? PageValidator::empty_document() );
		$result = ( new PageValidator() )->validate( $doc, get_current_user_id(), $doc );
		return $result->is_valid() ? $result->document() : $doc;
	}

	/**
	 * Replays a recorded response: validates and applies it, then checks the case's asserts.
	 *
	 * @param array<string, mixed> $set  Eval set.
	 * @param array<string, mixed> $item Case.
	 * @return array{pass:bool, message:string, result:OpsResult, payload:array<string, mixed>|null}
	 */
	public static function replay( array $set, array $item ): array {
		$file    = self::dir() . 'responses/' . basename( (string) $item['response'] ) . '.json';
		$payload = is_readable( $file ) ? WpAiClientDriver::decode( (string) file_get_contents( $file ) ) : null; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local file.
		$doc     = self::document( $set, $item );
		$n       = 0;
		$ids     = static function () use ( &$n ): string {
			++$n;
			return '01JN' . str_pad( (string) $n, 22, '0', STR_PAD_LEFT );
		};
		$result  = ( new OpsValidator( new OpsApplier( $ids ) ) )->validate( $payload, $doc, get_current_user_id(), (string) $item['mode'], $doc );
		$asserts = (array) ( $item['assert'] ?? [] );
		$fail    = self::check( $asserts, $result, (array) $payload );
		return [
			'pass'    => '' === $fail,
			'message' => $fail,
			'result'  => $result,
			'payload' => $payload,
		];
	}

	/**
	 * Checks asserts; returns '' on success or the first failure.
	 *
	 * @param array<string, mixed> $asserts Asserts.
	 * @param OpsResult            $result  Validation result.
	 * @param array<string, mixed> $payload Response.
	 */
	public static function check( array $asserts, OpsResult $result, array $payload ): string {
		$valid = (bool) ( $asserts['valid'] ?? true );
		if ( $result->is_valid() !== $valid ) {
			return $valid ? 'Expected valid, got: ' . $result->error_text() : 'Expected the validator to reject the response.';
		}
		if ( ! $valid ) {
			$needle = (string) ( $asserts['error'] ?? '' );
			return '' === $needle || str_contains( $result->error_text(), $needle ) ? '' : 'Expected an error containing "' . $needle . '", got: ' . $result->error_text();
		}
		$doc   = (array) $result->document();
		$added = $result->added();
		if ( ! empty( $asserts['questions'] ) && ( empty( $payload['questions'] ) || ! empty( $payload['operations'] ) ) ) {
			return 'Expected questions and no operations.';
		}
		if ( isset( $asserts['count'] ) && count( (array) $doc['blocks'] ) !== (int) $asserts['count'] ) {
			return sprintf( 'Expected %d blocks, got %d.', (int) $asserts['count'], count( (array) $doc['blocks'] ) );
		}
		foreach ( (array) ( $asserts['absent'] ?? [] ) as $id ) {
			if ( OpsApplier::index_of( $doc, (string) $id ) >= 0 ) {
				return 'Block ' . $id . ' should be gone.';
			}
		}
		if ( isset( $asserts['top_order'] ) ) {
			$want = array_map( static fn( $v ) => self::value( $v, $added ), (array) $asserts['top_order'] );
			$have = array_column( self::top( $doc ), 'id' );
			if ( $want !== $have ) {
				return 'Top-level order ' . implode( ',', $have ) . ' expected ' . implode( ',', $want );
			}
		}
		foreach ( (array) ( $asserts['equals'] ?? [] ) as $path => $want ) {
			$have = self::path( $doc, (string) $path, $added );
			$want = self::value( $want, $added );
			if ( $have !== $want ) {
				return sprintf( '%s is %s, expected %s', $path, wp_json_encode( $have ), wp_json_encode( $want ) );
			}
		}
		foreach ( (array) ( $asserts['contains'] ?? [] ) as $path => $needle ) {
			$have = self::path( $doc, (string) $path, $added );
			if ( ! is_string( $have ) || ! str_contains( $have, (string) $needle ) ) {
				return sprintf( '%s does not contain "%s"', $path, $needle );
			}
		}
		return '';
	}

	/**
	 * Resolves "$0" style references to added block ids.
	 *
	 * @param mixed    $value Value.
	 * @param string[] $added Added ids.
	 * @return mixed
	 */
	private static function value( $value, array $added ) {
		if ( is_string( $value ) && preg_match( '/^\$(\d+)$/', $value, $m ) ) {
			return $added[ (int) $m[1] ] ?? $value;
		}
		return $value;
	}

	/**
	 * Top-level blocks in order.
	 *
	 * @param array<string, mixed> $doc Document.
	 * @return array<int, array<string, mixed>>
	 */
	private static function top( array $doc ): array {
		$top = array_values( array_filter( (array) $doc['blocks'], static fn( $b ) => null === $b['parent_id'] ) );
		usort( $top, static fn( $a, $b ) => $a['order'] <=> $b['order'] );
		return $top;
	}

	/**
	 * Reads a dotted path: `#ID` (or `#$0`) selects a block, `topN` the Nth top-level block.
	 *
	 * @param array<string, mixed> $doc   Document.
	 * @param string               $path  Path.
	 * @param string[]             $added Added ids.
	 * @return mixed
	 */
	public static function path( array $doc, string $path, array $added = [] ) {
		$node = $doc;
		foreach ( explode( '.', $path ) as $seg ) {
			if ( str_starts_with( $seg, '#' ) ) {
				$i    = OpsApplier::index_of( $doc, (string) self::value( substr( $seg, 1 ), $added ) );
				$node = $i >= 0 ? $doc['blocks'][ $i ] : null;
			} elseif ( preg_match( '/^top(\d+)$/', $seg, $m ) ) {
				$node = self::top( $doc )[ (int) $m[1] ] ?? null;
			} elseif ( is_array( $node ) && array_key_exists( ctype_digit( $seg ) ? (int) $seg : $seg, $node ) ) {
				$node = $node[ ctype_digit( $seg ) ? (int) $seg : $seg ];
			} else {
				return null;
			}
		}
		return $node;
	}

	/**
	 * Runs one case live through the configured provider. With $grade, a fast model grades the result.
	 *
	 * @param array<string, mixed> $set   Eval set.
	 * @param array<string, mixed> $item  Case.
	 * @param bool                 $grade Ask a grader model.
	 * @return array{pass:bool, message:string, tokens:int}
	 */
	public static function live( array $set, array $item, bool $grade ): array {
		$doc    = self::document( $set, $item );
		$mode   = (string) $item['mode'];
		$req    = new ProposalRequest(
			(string) $item['instruction'],
			$doc,
			get_current_user_id(),
			$mode,
			0,
			(array) ( $item['questions_answers'] ?? [] ),
			'build_from_site' === $mode ? Context::site_facts( get_current_user_id() ) : null,
			'analytics_qa' === $mode ? self::sample_analytics( $doc ) : null,
			$doc
		);
		$driver = new WpAiClientDriver();
		$res    = $driver->propose( $req );
		$expect = (array) ( $item['expect'] ?? [] );
		if ( is_wp_error( $res ) ) {
			$usage = $driver->last_usage();
			Limits::record( $usage['prompt_tokens'] + $usage['completion_tokens'] );
			return [
				'pass'    => 'ops' !== ( $expect['type'] ?? '' ) && 'crazylinks_ai_invalid_output' === $res->get_error_code() && empty( $expect['ops'] ),
				'message' => $res->get_error_message(),
				'tokens'  => $usage['prompt_tokens'] + $usage['completion_tokens'],
			];
		}
		Limits::record( $res->tokens() );
		$tokens = $res->tokens();
		$fail   = '';
		if ( 'questions' === ( $expect['type'] ?? '' ) && ! $res->questions && $res->operations ) {
			$fail = 'Expected questions, got operations.';
		} elseif ( 'ops' === ( $expect['type'] ?? '' ) ) {
			$kinds = array_column( $res->operations, 'op' );
			foreach ( (array) ( $expect['ops'] ?? [] ) as $kind ) {
				if ( ! in_array( $kind, $kinds, true ) ) {
					$fail = 'Missing a ' . $kind . ' operation.';
					break;
				}
			}
			if ( '' === $fail && ! $res->operations && $expect['ops'] ) {
				$fail = 'Expected operations, got questions: ' . implode( ' ', $res->questions );
			}
		}
		if ( '' === $fail && $grade ) {
			$verdict = self::grade( $driver, $item, $res );
			if ( is_wp_error( $verdict ) ) {
				$fail = 'Grader error: ' . $verdict->get_error_message();
			} elseif ( empty( $verdict['pass'] ) ) {
				$fail = 'Grader: ' . (string) ( $verdict['reason'] ?? '' );
			}
			$usage   = $driver->last_usage();
			$tokens += $usage['prompt_tokens'] + $usage['completion_tokens'];
			Limits::record( $usage['prompt_tokens'] + $usage['completion_tokens'] );
		}
		return [
			'pass'    => '' === $fail,
			'message' => '' === $fail ? $res->summary : $fail,
			'tokens'  => $tokens,
		];
	}

	/**
	 * Asks a fast model whether the result meets the rubric.
	 *
	 * @param WpAiClientDriver     $driver Driver.
	 * @param array<string, mixed> $item   Case.
	 * @param ProposalResult       $res    Result.
	 * @return array<string, mixed>|WP_Error
	 */
	private static function grade( WpAiClientDriver $driver, array $item, ProposalResult $res ) {
		$system  = 'You grade an AI that edits link-in-bio pages. Compare its answer with the rubric. Be strict about the rubric and lenient about wording. Return JSON {"pass": boolean, "reason": string}.';
		$message = Prompt::json(
			[
				'instruction' => $item['instruction'],
				'mode'        => $item['mode'],
				'rubric'      => $item['expect']['rubric'] ?? '',
				'answer'      => [
					'summary'    => $res->summary,
					'operations' => $res->operations,
					'questions'  => $res->questions,
				],
			]
		);
		$schema  = [
			'type'                 => 'object',
			'additionalProperties' => false,
			'required'             => [ 'pass', 'reason' ],
			'properties'           => [
				'pass'   => [ 'type' => 'boolean' ],
				'reason' => [ 'type' => 'string' ],
			],
		];
		return $driver->json( $system, $message, $schema, 'suggest' );
	}

	/**
	 * Deterministic analytics totals for analytics cases (the eval pages have no real traffic).
	 *
	 * @param array<string, mixed> $doc Document.
	 * @return array<string, mixed>
	 */
	private static function sample_analytics( array $doc ): array {
		$blocks = [];
		$clicks = [ 412, 230, 150, 96, 0, 0, 0 ];
		foreach ( array_values( (array) $doc['blocks'] ) as $i => $block ) {
			if ( 'link' === $block['type'] ) {
				$blocks[] = [
					'block_id' => $block['id'],
					'title'    => (string) ( $block['props']['title'] ?? '' ),
					'clicks'   => $clicks[ count( $blocks ) ] ?? 0,
				];
			}
		}
		return [
			'range'  => [ 'days' => 30 ],
			'views'  => 4100,
			'clicks' => array_sum( array_column( $blocks, 'clicks' ) ),
			'blocks' => $blocks,
		];
	}
}
