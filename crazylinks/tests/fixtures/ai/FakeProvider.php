<?php
/**
 * A fake AI provider registered in WordPress's AI Client registry, for tests.
 *
 * `generate_text*()` on a real WP_AI_Client_Prompt_Builder resolves to FakeModel, which returns the
 * next canned response from FakeProvider::$responses and records what it was sent.
 *
 * @package CrazyLinks
 */

namespace CrazyLinks\Tests\Fixtures;

use WordPress\AiClient\AiClient;
use WordPress\AiClient\Messages\DTO\MessagePart;
use WordPress\AiClient\Messages\DTO\ModelMessage;
use WordPress\AiClient\Providers\Contracts\ModelMetadataDirectoryInterface;
use WordPress\AiClient\Providers\Contracts\ProviderAvailabilityInterface;
use WordPress\AiClient\Providers\Contracts\ProviderInterface;
use WordPress\AiClient\Providers\DTO\ProviderMetadata;
use WordPress\AiClient\Providers\Enums\ProviderTypeEnum;
use WordPress\AiClient\Providers\Models\Contracts\ModelInterface;
use WordPress\AiClient\Providers\Models\DTO\ModelConfig;
use WordPress\AiClient\Providers\Models\DTO\ModelMetadata;
use WordPress\AiClient\Providers\Models\DTO\SupportedOption;
use WordPress\AiClient\Providers\Models\Enums\CapabilityEnum;
use WordPress\AiClient\Providers\Models\Enums\OptionEnum;
use WordPress\AiClient\Providers\Models\TextGeneration\Contracts\TextGenerationModelInterface;
use WordPress\AiClient\Results\DTO\Candidate;
use WordPress\AiClient\Results\DTO\GenerativeAiResult;
use WordPress\AiClient\Results\DTO\TokenUsage;
use WordPress\AiClient\Results\Enums\FinishReasonEnum;

/**
 * Provider.
 */
final class FakeProvider implements ProviderInterface {

	/**
	 * Whether the provider reports itself configured (a "key" is set).
	 *
	 * @var bool
	 */
	public static bool $configured = false;

	/**
	 * Canned responses, consumed in order.
	 *
	 * @var string[]
	 */
	public static array $responses = [];

	/**
	 * Recorded calls: model, system, prompt, schema, max tokens.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	public static array $calls = [];

	/**
	 * Token usage each call reports (0, 0 = provider does not report usage).
	 *
	 * @var int[]
	 */
	public static array $usage = [ 1200, 300 ];

	/**
	 * Registers once in the default registry.
	 */
	public static function register(): void {
		$registry = AiClient::defaultRegistry();
		if ( ! $registry->hasProvider( self::class ) ) {
			$registry->registerProvider( self::class );
		}
	}

	/**
	 * Resets state.
	 *
	 * @param bool $configured Configured.
	 */
	public static function reset( bool $configured = true ): void {
		self::$configured = $configured;
		self::$responses  = [];
		self::$calls      = [];
		self::$usage      = [ 1200, 300 ];
	}

	public static function metadata(): ProviderMetadata {
		return new ProviderMetadata( 'crazylinks-fake', 'Fake AI', ProviderTypeEnum::cloud() );
	}

	public static function model( string $modelId, ?ModelConfig $modelConfig = null ): ModelInterface {
		$model = new FakeModel( FakeDirectory::meta( $modelId ) );
		if ( $modelConfig ) {
			$model->setConfig( $modelConfig );
		}
		return $model;
	}

	public static function availability(): ProviderAvailabilityInterface {
		return new FakeAvailability();
	}

	public static function modelMetadataDirectory(): ModelMetadataDirectoryInterface {
		return new FakeDirectory();
	}
}

/**
 * Availability.
 */
final class FakeAvailability implements ProviderAvailabilityInterface {
	public function isConfigured(): bool {
		return FakeProvider::$configured;
	}
}

/**
 * Two models: a Sonnet-class and a Haiku-class id, so model preference can be asserted.
 */
final class FakeDirectory implements ModelMetadataDirectoryInterface {

	public const MODELS = [ 'claude-sonnet-5-5', 'claude-haiku-5-5' ];

	public static function meta( string $id ): ModelMetadata {
		$options = [];
		foreach ( [ 'systemInstruction', 'maxTokens', 'outputMimeType', 'outputSchema', 'inputModalities', 'outputModalities', 'temperature' ] as $name ) {
			$options[] = new SupportedOption( call_user_func( [ OptionEnum::class, $name ] ) );
		}
		return new ModelMetadata( $id, 'Fake ' . $id, [ CapabilityEnum::textGeneration(), CapabilityEnum::chatHistory() ], $options );
	}

	public function listModelMetadata(): array {
		return array_map( [ self::class, 'meta' ], self::MODELS );
	}

	public function hasModelMetadata( string $modelId ): bool {
		return in_array( $modelId, self::MODELS, true );
	}

	public function getModelMetadata( string $modelId ): ModelMetadata {
		return self::meta( $modelId );
	}
}

/**
 * Model.
 */
final class FakeModel implements ModelInterface, TextGenerationModelInterface {

	private ModelConfig $config;

	public function __construct( private ModelMetadata $meta ) {
		$this->config = new ModelConfig();
	}

	public function metadata(): ModelMetadata {
		return $this->meta;
	}

	public function providerMetadata(): ProviderMetadata {
		return FakeProvider::metadata();
	}

	public function setConfig( ModelConfig $config ): void {
		$this->config = $config;
	}

	public function getConfig(): ModelConfig {
		return $this->config;
	}

	public function generateTextResult( array $prompt ): GenerativeAiResult {
		$text = '';
		foreach ( $prompt as $message ) {
			foreach ( $message->getParts() as $part ) {
				$text .= (string) $part->getText();
			}
		}
		FakeProvider::$calls[] = [
			'model'      => $this->meta->getId(),
			'system'     => (string) $this->config->getSystemInstruction(),
			'prompt'     => $text,
			'schema'     => $this->config->getOutputSchema(),
			'max_tokens' => $this->config->getMaxTokens(),
		];
		$out = array_shift( FakeProvider::$responses ) ?? '{"summary":"","operations":[],"questions":[]}';
		[ $in, $completion ] = FakeProvider::$usage;
		return new GenerativeAiResult(
			'fake-' . count( FakeProvider::$calls ),
			[ new Candidate( new ModelMessage( [ new MessagePart( $out ) ] ), FinishReasonEnum::stop() ) ],
			new TokenUsage( $in, $completion, $in + $completion ),
			FakeProvider::metadata(),
			$this->meta
		);
	}
}
