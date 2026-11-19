<?php
/**
 * Tests for the soft links to other Axle plugins.
 *
 * @package AryanX\Axle\SmokeTest
 */

namespace AryanX\Axle\ReadOnlyMode {

	/** A stand-in for Read-Only Mode's State. */
	class FakeState {
		public static $scope = 'none';

		public function freezes( string $area ): bool {
			return 'all' === self::$scope || self::$scope === $area;
		}
	}

	/** A stand-in for Read-Only Mode's Plugin. */
	class Plugin {
		public static function instance() {
			return new self();
		}

		public function state() {
			return new FakeState();
		}
	}
}

namespace AryanX\Axle\SmokeTest\Tests\Unit {

	use AryanX\Axle\ReadOnlyMode\FakeState;
	use AryanX\Axle\SmokeTest\Family;

	final class FamilyTest extends SmokeTestCase {

		protected function tearDown(): void {
			FakeState::$scope = 'none';
			parent::tearDown();
		}

		public function test_a_content_only_freeze_leaves_rollback_open(): void {
			FakeState::$scope = 'content';
			$this->assertFalse( Family::frozen() );
		}

		public function test_a_code_freeze_blocks_rollback(): void {
			FakeState::$scope = 'code';
			$this->assertTrue( Family::frozen() );
			FakeState::$scope = 'all';
			$this->assertTrue( Family::frozen() );
		}
	}
}
