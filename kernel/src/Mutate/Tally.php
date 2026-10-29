<?php

namespace Uncanny_Owl\Wp_Test_Kernel\Mutate;

/**
 * One file's (or a run's) mutant counts and scores.
 *
 * Score = caught ÷ every mutant made, uncovered included (Infection's MSI). Covered score leaves the
 * uncovered out. Timed-out and fatal (errored) mutants count as caught: the tests noticed the change.
 */
final class Tally {

	/** @var int */
	public $caught = 0;

	/** @var int */
	public $escaped = 0;

	/** @var int */
	public $uncovered = 0;

	/** @var array<int, array{line: int, mutator: string}> */
	public $escapes = array();

	/**
	 * @param int $caught
	 * @param int $escaped
	 * @param int $uncovered
	 *
	 * @return self
	 */
	public static function of( $caught, $escaped, $uncovered ) {
		$tally            = new self();
		$tally->caught    = (int) $caught;
		$tally->escaped   = (int) $escaped;
		$tally->uncovered = (int) $uncovered;

		return $tally;
	}

	/**
	 * Per-file tallies from an Infection JSON log, keyed by plugin-relative path.
	 *
	 * @param string $json_file
	 * @param string $root Plugin root, to make paths relative.
	 *
	 * @return array<string, self>
	 */
	public static function per_file_from_infection( $json_file, $root ) {

		$log    = json_decode( (string) file_get_contents( $json_file ), true );
		$files  = array();
		$groups = array(
			'killed'                 => 'caught',
			'killedByStaticAnalysis' => 'caught',
			'errored'                => 'caught',
			'timeouted'              => 'caught',
			'escaped'                => 'escaped',
			'uncovered'              => 'uncovered',
		);

		foreach ( $groups as $key => $bucket ) {
			foreach ( (array) ( $log[ $key ] ?? array() ) as $mutant ) {
				$path = self::relative( (string) ( $mutant['mutator']['originalFilePath'] ?? '' ), $root );
				if ( ! isset( $files[ $path ] ) ) {
					$files[ $path ] = new self();
				}
				++$files[ $path ]->{$bucket};
				if ( 'escaped' === $bucket ) {
					$files[ $path ]->escapes[] = array(
						'line'    => (int) ( $mutant['mutator']['originalStartLine'] ?? 0 ),
						'mutator' => (string) ( $mutant['mutator']['mutatorName'] ?? '' ),
					);
				}
			}
		}

		ksort( $files );

		return $files;
	}

	/**
	 * @return int
	 */
	public function total() {
		return $this->caught + $this->escaped + $this->uncovered;
	}

	/**
	 * @return float
	 */
	public function score() {
		return 0 === $this->total() ? 100.0 : round( 100 * $this->caught / $this->total(), 1 );
	}

	/**
	 * @return float
	 */
	public function covered_score() {
		$covered = $this->caught + $this->escaped;

		return 0 === $covered ? 100.0 : round( 100 * $this->caught / $covered, 1 );
	}

	/**
	 * @param Tally $other
	 *
	 * @return void
	 */
	public function add( Tally $other ) {
		$this->caught    += $other->caught;
		$this->escaped   += $other->escaped;
		$this->uncovered += $other->uncovered;
	}

	/**
	 * @return array{caught: int, escaped: int, uncovered: int}
	 */
	public function to_array() {
		return array(
			'caught'    => $this->caught,
			'escaped'   => $this->escaped,
			'uncovered' => $this->uncovered,
		);
	}

	/**
	 * @param string $path
	 * @param string $root
	 *
	 * @return string
	 */
	private static function relative( $path, $root ) {
		$real_root = (string) realpath( $root );
		$real_path = (string) ( realpath( $path ) ?: $path );

		foreach ( array( $real_root, rtrim( $root, '/' ) ) as $prefix ) {
			if ( '' !== $prefix && 0 === strpos( $real_path, $prefix . '/' ) ) {
				return substr( $real_path, strlen( $prefix ) + 1 );
			}
		}

		return $real_path;
	}
}
