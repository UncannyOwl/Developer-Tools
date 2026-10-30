<?php

use Fixture\Shelf\Shelf;
use PHPUnit\Framework\TestCase;

#[\PHPUnit\Framework\Attributes\CoversClass( Shelf::class )]
final class Shelf_Test extends TestCase {

	public function test_blank_names_are_not_counted(): void {
		$this->assertSame( 2, ( new Shelf() )->count_named( array( 'a', ' ', 'b' ) ) );
	}
}
