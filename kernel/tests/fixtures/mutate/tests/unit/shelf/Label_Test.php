<?php

use Fixture\Shelf\Label;
use PHPUnit\Framework\TestCase;

// A neighbour in Shelf's mirror folder that covers another class: a run mutating Shelf selects it too.
#[\PHPUnit\Framework\Attributes\CoversClass( Label::class )]
final class Label_Test extends TestCase {

	public function test_a_label_is_upper_case(): void {
		$this->assertSame( 'AB', Label::of( 'ab' ) );
	}
}
