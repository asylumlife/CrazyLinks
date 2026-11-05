<?php
/**
 * Replays the recorded model responses of the eval set.
 *
 * @package CrazyLinks
 */

namespace CrazyLinks\Tests;

use CrazyLinks\Ai\ChangeList;
use CrazyLinks\Ai\Evals;
use CrazyLinks\Ai\OpsValidator;

require_once dirname( __DIR__ ) . '/fixtures/ai/AiHelpers.php';

/**
 * tests/fixtures/ai/evals.json + responses/*.json through OpsValidator and OpsApplier.
 */
class AiEvalFixturesTest extends TestCase {

	/**
	 * Cases.
	 *
	 * @return array<string, array{0: string}>
	 */
	public function case_provider(): array {
		$set = json_decode( (string) file_get_contents( dirname( __DIR__ ) . '/fixtures/ai/evals.json' ), true );
		$out = [];
		foreach ( $set['cases'] as $case ) {
			$out[ $case['id'] ] = [ $case['id'] ];
		}
		return $out;
	}

	public function test_eval_set_shape(): void {
		$set = Evals::load();
		$this->assertNotNull( $set );
		$this->assertGreaterThanOrEqual( 25, count( $set['cases'] ) );
		$this->assertLessThanOrEqual( 40, count( $set['cases'] ) );
		$modes = array_unique( array_column( $set['cases'], 'mode' ) );
		$this->assertEqualsCanonicalizing( OpsValidator::MODES, $modes, 'Every mode is covered' );
		$kinds = array_count_values( array_column( array_column( $set['cases'], 'expect' ), 'type' ) );
		$this->assertGreaterThanOrEqual( 5, $kinds['questions'], 'Ambiguous instructions expect questions' );
		foreach ( $set['cases'] as $case ) {
			$this->assertNotEmpty( $case['expect']['rubric'], $case['id'] );
			$this->assertFileExists( dirname( __DIR__ ) . '/fixtures/ai/responses/' . $case['id'] . '.json' );
		}
	}

	/**
	 * @dataProvider case_provider
	 */
	public function test_recorded_response( string $id ): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$set  = Evals::load();
		$case = current( array_filter( $set['cases'], static fn( $c ) => $c['id'] === $id ) );
		$run  = Evals::replay( $set, $case );
		$this->assertTrue( $run['pass'], $id . ': ' . $run['message'] );
		if ( $run['result']->is_valid() ) {
			$items = ChangeList::build( (array) $run['payload']['operations'], Evals::document( $set, $case ), $run['result']->added() );
			$this->assertCount( count( (array) $run['payload']['operations'] ), $items, 'One change-list line per operation' );
			foreach ( $items as $item ) {
				$this->assertNotSame( '', $item['text'] );
			}
		}
	}
}
