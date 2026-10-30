<?php

use Fixture\Picker;
use PHPUnit\Framework\TestCase;

#[\PHPUnit\Framework\Attributes\CoversClass( Picker::class )]
final class Picker_Test extends TestCase {

	public function test_the_first_of_nothing_is_null(): void {
		$this->assertNull( Picker::first( array() ) );
	}

	public function test_the_first_item_is_returned(): void {
		$this->assertSame( 'a', Picker::first( array( 'a', 'b' ) ) );
	}

	// Under `<=` this test only warns; the next one fails. Their order matters to the mutant run.
	public function test_past_the_end_is_empty(): void {
		$this->assertSame( '', Picker::at( array( 'a' ), 1 ) );
	}

	public function test_a_key_past_the_count_is_never_read(): void {
		$this->assertSame( '', Picker::at( array( 1 => 'x' ), 1 ) );
	}

	public function test_an_item_is_read_by_its_index(): void {
		$this->assertSame( 'b', Picker::at( array( 'a', 'b' ), 1 ) );
	}
}
