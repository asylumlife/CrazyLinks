<?php
/**
 * Driver for the WordPress core AI Client (7.0+).
 *
 * @package CrazyLinks
 */

namespace CrazyLinks\Ai;

use CrazyLinks\Settings;
use WP_Error;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- Method names fixed by SPEC §10.2.

/**
 * Uses wp_ai_client_prompt() and whatever provider the owner connected in Settings → Connectors.
 *
 * Proposals: system prompt + the trimmed page, optional site facts or analytics totals, and the
 * instruction. The model is asked for a bare JSON object; the ops schema is sent to the provider as
 * structured output (as_json_response) only when structured_output() is switched on. The answer is
 * validated with OpsValidator (schema, business rules, PageValidator); on failure the model gets one
 * retry with the validation errors. No streaming (the builder has none). Respects
 * `wp_ai_client_prevent_prompt` (core returns `prompt_prevented`).
 */
final class WpAiClientDriver implements AiDriver {

	/**
	 * Output caps in tokens: a page edit, a whole page built from scratch, and a short answer
	 * (suggestions, the eval grader).
	 */
	private const MAX_TOKENS_EDIT = 4096;

	private const MAX_TOKENS_BUILD = 8192;

	private const MAX_TOKENS_SHORT = 1024;

	/**
	 * Model calls per proposal: the first answer and one retry that carries the validation errors.
	 */
	private const MAX_ATTEMPTS = 2;

	/**
	 * Validation errors returned with a rejected proposal.
	 */
	private const MAX_REPORTED_ERRORS = 20;

	/**
	 * Suggestions kept per request and their longest accepted length in characters.
	 */
	private const BIO_SUGGESTIONS = 3;

	private const TITLE_SUGGESTIONS = 5;

	private const MAX_BIO_LENGTH = 280;

	private const MAX_TITLE_LENGTH = 120;

	/**
	 * Nesting depth accepted when decoding model output.
	 */
	private const JSON_DEPTH = 64;

	/**
	 * Usage of the last call: provider, model, prompt/completion tokens, estimated flag.
	 *
	 * @var array{provider:string, model:string, prompt_tokens:int, completion_tokens:int, estimated:bool}
	 */
	private array $last_usage = [
		'provider'          => '',
		'model'             => '',
		'prompt_tokens'     => 0,
		'completion_tokens' => 0,
		'estimated'         => false,
	];

	/**
	 * Whether AI editing can run for the current user (client, site switch, plugin setting, capability,
	 * configured text-capable provider).
	 */
	public function isAvailable(): bool {
		return '' === Status::reason();
	}

	/**
	 * Asks the model for operations and validates them.
	 *
	 * @param ProposalRequest $r Request.
	 */
	public function propose( ProposalRequest $r ): ProposalResult|WP_Error {
		if ( ! Status::client_exists() ) {
			return new WP_Error( 'crazylinks_ai_unavailable', Status::message( 'no_client' ), [ 'status' => 503 ] );
		}
		$system     = Prompt::system( $r->mode );
		$message    = Prompt::user_message( $r );
		$max_tokens = in_array( $r->mode, [ 'build_from_site', 'build_from_description' ], true ) ? self::MAX_TOKENS_BUILD : self::MAX_TOKENS_EDIT;
		$schema     = self::structured_output() ? OpsSchema::for_provider() : null;
		$validator  = new OpsValidator();
		$usage      = [
			'provider'          => '',
			'model'             => '',
			'prompt_tokens'     => 0,
			'completion_tokens' => 0,
			'estimated'         => false,
		];
		$errors     = '';
		$text       = '';

		for ( $attempt = 1; $attempt <= self::MAX_ATTEMPTS; ++$attempt ) {
			$prompt = 1 === $attempt ? $message : $message . "\n\n" . Prompt::retry_message( $text, $errors );
			$out    = $this->generate( $system, $prompt, Status::models( 'edit' ), $max_tokens, $schema );
			if ( is_wp_error( $out ) ) {
				return $out;
			}
			$text  = $out['text'];
			$usage = [
				'provider'          => $out['provider'],
				'model'             => $out['model'],
				'prompt_tokens'     => $usage['prompt_tokens'] + $out['prompt_tokens'],
				'completion_tokens' => $usage['completion_tokens'] + $out['completion_tokens'],
				'estimated'         => $usage['estimated'] || $out['estimated'],
			];

			$payload = self::decode( $text );
			if ( null === $payload ) {
				$errors = '/: The output was not valid JSON.';
				continue;
			}
			$result = $validator->validate( $payload, $r->document, $r->user_id, $r->mode, $r->previous );
			if ( $result->is_valid() ) {
				return new ProposalResult(
					(string) $payload['summary'],
					array_values( (array) $payload['operations'] ),
					array_values( array_map( 'strval', (array) $payload['questions'] ) ),
					(array) $result->document(),
					$result->added(),
					$usage['provider'],
					$usage['model'],
					$usage['prompt_tokens'],
					$usage['completion_tokens'],
					$usage['estimated'],
					$attempt
				);
			}
			$errors = $result->error_text();
		}

		// Every attempt was rejected: report the usage of all of them, not only the last call.
		$this->last_usage = $usage;
		return new WP_Error(
			'crazylinks_ai_invalid_output',
			__( 'The AI returned changes that do not fit this page, even after a retry. Try rephrasing the request.', 'crazylinks' ),
			[
				'status' => 422,
				'errors' => array_slice( array_filter( explode( "\n", $errors ) ), 0, self::MAX_REPORTED_ERRORS ),
			]
		);
	}

	/**
	 * Title or bio suggestions with a fast, cheap model.
	 *
	 * @param string               $kind titles | bio.
	 * @param array<string, mixed> $ctx  Context (the link's title, URL and subtitle, or the profile).
	 * @return array<int, string>|WP_Error
	 */
	public function suggestText( string $kind, array $ctx ): array|WP_Error {
		$kind   = 'bio' === $kind ? 'bio' : 'titles';
		$schema = [
			'type'                 => 'object',
			'additionalProperties' => false,
			'required'             => [ 'suggestions' ],
			'properties'           => [
				'suggestions' => [
					'type'  => 'array',
					'items' => [ 'type' => 'string' ],
				],
			],
		];
		$out    = $this->generate( Prompt::suggest_system( $kind ), Prompt::suggest_message( $kind, $ctx ), Status::models( 'suggest' ), self::MAX_TOKENS_SHORT, $schema );
		if ( is_wp_error( $out ) ) {
			return $out;
		}
		$payload = self::decode( $out['text'] );
		$max     = 'bio' === $kind ? self::MAX_BIO_LENGTH : self::MAX_TITLE_LENGTH;
		$list    = [];
		foreach ( (array) ( $payload['suggestions'] ?? [] ) as $s ) {
			$s = trim( wp_strip_all_tags( is_string( $s ) ? $s : '' ) );
			if ( '' !== $s && mb_strlen( $s ) <= $max ) {
				$list[] = $s;
			}
		}
		$list = array_slice( array_values( array_unique( $list ) ), 0, 'bio' === $kind ? self::BIO_SUGGESTIONS : self::TITLE_SUGGESTIONS );
		if ( ! $list ) {
			return new WP_Error( 'crazylinks_ai_invalid_output', __( 'The AI did not return usable suggestions. Please try again.', 'crazylinks' ), [ 'status' => 422 ] );
		}
		return $list;
	}

	/**
	 * Free-form JSON generation (used by the eval grader). Returns the decoded object.
	 *
	 * @param string               $system  System instruction.
	 * @param string               $message User message.
	 * @param array<string, mixed> $schema  JSON schema.
	 * @param string               $purpose Model purpose (edit | suggest).
	 * @return array<string, mixed>|WP_Error
	 */
	public function json( string $system, string $message, array $schema, string $purpose = 'suggest' ): array|WP_Error {
		$out = $this->generate( $system, $message, Status::models( $purpose ), self::MAX_TOKENS_SHORT, $schema );
		if ( is_wp_error( $out ) ) {
			return $out;
		}
		$payload = self::decode( $out['text'] );
		return null === $payload ? new WP_Error( 'crazylinks_ai_invalid_output', __( 'The AI did not return JSON.', 'crazylinks' ), [ 'status' => 422 ] ) : $payload;
	}

	/**
	 * Usage of the last call.
	 *
	 * @return array{provider:string, model:string, prompt_tokens:int, completion_tokens:int, estimated:bool}
	 */
	public function last_usage(): array {
		return $this->last_usage;
	}

	/**
	 * Whether to ask the provider for schema-constrained output. Off by default: Anthropic's structured
	 * outputs reject both the full and the compact operations schema as too large a grammar, so the
	 * prompt asks for a bare JSON object and OpsValidator checks it, with one retry carrying the
	 * validation errors.
	 */
	public static function structured_output(): bool {
		/**
		 * Filters whether CrazyLinks requests schema-constrained output from the AI provider.
		 *
		 * @param bool $on Default false.
		 */
		return (bool) apply_filters( 'crazylinks_ai_structured_output', false );
	}

	/**
	 * One model call that should answer with JSON. The schema is sent to the provider, and counted in
	 * the token estimate, only when structured output is switched on.
	 *
	 * @param string                    $system     System instruction.
	 * @param string                    $message    User message.
	 * @param string[]                  $models     Model preference.
	 * @param int                       $max_tokens Output cap.
	 * @param array<string, mixed>|null $schema     JSON schema of the answer, or null for none.
	 * @return array{text:string, provider:string, model:string, prompt_tokens:int, completion_tokens:int, estimated:bool}|WP_Error
	 */
	private function generate( string $system, string $message, array $models, int $max_tokens, ?array $schema ): array|WP_Error {
		$timeout = static fn() => (float) max( 10, (int) Settings::get( 'ai_timeout' ) );
		add_filter( 'wp_ai_client_default_request_timeout', $timeout );
		try {
			$builder = wp_ai_client_prompt( $message );
		} finally {
			remove_filter( 'wp_ai_client_default_request_timeout', $timeout );
		}
		$builder = $builder->using_system_instruction( $system )->using_max_tokens( $max_tokens );
		$schema  = self::structured_output() ? $schema : null;
		if ( $schema ) {
			$builder = $builder->as_json_response( OpsSchema::close_objects( $schema ) );
		}
		if ( $models ) {
			$builder = $builder->using_model_preference( ...$models );
		}
		$result = $builder->generate_text_result();
		if ( is_wp_error( $result ) ) {
			return self::friendly( $result );
		}
		try {
			$text     = $result->toText();
			$tokens   = $result->getTokenUsage();
			$provider = (string) $result->getProviderMetadata()->getName();
			$model    = (string) $result->getModelMetadata()->getId();
			$in       = (int) $tokens->getPromptTokens();
			$out      = (int) $tokens->getCompletionTokens();
		} catch ( \Throwable $e ) {
			return new WP_Error( 'crazylinks_ai_invalid_output', __( 'The AI returned no text.', 'crazylinks' ), [ 'status' => 502 ] );
		}
		$estimated = 0 === $in + $out;
		if ( $estimated ) {
			$in  = Limits::estimate( $system . $message . ( $schema ? (string) wp_json_encode( $schema ) : '' ) );
			$out = Limits::estimate( $text );
		}
		$this->last_usage = [
			'provider'          => $provider,
			'model'             => $model,
			'prompt_tokens'     => $in,
			'completion_tokens' => $out,
			'estimated'         => $estimated,
		];
		return [
			'text'              => $text,
			'provider'          => $provider,
			'model'             => $model,
			'prompt_tokens'     => $in,
			'completion_tokens' => $out,
			'estimated'         => $estimated,
		];
	}

	/**
	 * Decodes model output, tolerating a Markdown code fence around the JSON.
	 *
	 * @param string $text Raw output.
	 * @return array<string, mixed>|null
	 */
	public static function decode( string $text ): ?array {
		$text = trim( $text );
		if ( preg_match( '/^```(?:json)?\s*(.*?)\s*```$/s', $text, $m ) ) {
			$text = $m[1];
		}
		$start = strpos( $text, '{' );
		$end   = strrpos( $text, '}' );
		if ( false === $start || false === $end || $end < $start ) {
			return null;
		}
		$decoded = json_decode( substr( $text, $start, $end - $start + 1 ), true, self::JSON_DEPTH );
		$decoded = OpsSchema::inflate_props( $decoded );
		return is_array( $decoded ) && ( [] === $decoded || ! array_is_list( $decoded ) ) ? $decoded : null;
	}

	/**
	 * Maps core AI client errors to messages a site owner can act on.
	 *
	 * @param WP_Error $error Core error.
	 */
	private static function friendly( WP_Error $error ): WP_Error {
		$code = (string) $error->get_error_code();
		switch ( $code ) {
			case 'prompt_prevented':
				return new WP_Error( 'crazylinks_ai_prevented', __( 'AI requests are turned off on this site.', 'crazylinks' ), [ 'status' => 503 ] );
			case 'prompt_invalid_argument':
				return new WP_Error( 'crazylinks_ai_no_provider', Status::message( 'no_provider' ), [ 'status' => 503 ] );
			case 'prompt_network_error':
				return new WP_Error( 'crazylinks_ai_network', __( 'Could not reach the AI provider. Please try again in a moment.', 'crazylinks' ), [ 'status' => 503 ] );
			case 'prompt_token_limit_reached':
				return new WP_Error( 'crazylinks_ai_too_long', __( 'This page is too large for the AI model. Try a smaller change.', 'crazylinks' ), [ 'status' => 413 ] );
		}
		$status = (int) ( $error->get_error_data()['status'] ?? 502 );
		if ( 429 === $status ) {
			return new WP_Error( 'crazylinks_ai_provider_busy', __( 'The AI provider is rate-limiting this site. Please try again shortly.', 'crazylinks' ), [ 'status' => 429 ] );
		}
		return new WP_Error(
			'crazylinks_ai_provider_error',
			/* translators: %s: provider error message. */
			sprintf( __( 'The AI provider returned an error: %s', 'crazylinks' ), wp_strip_all_tags( $error->get_error_message() ) ),
			[ 'status' => 502 ]
		);
	}
}
