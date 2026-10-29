<?php

namespace Uncanny_Owl\Wp_Test_Kernel\Mutate;

use RuntimeException;

/**
 * Each in-scope file's floor: its counts when last recorded. Floors only rise.
 *
 * A file under its target passes while it holds its floor (listed as debt); it fails below its floor,
 * or when it is new to the baseline and under its target. A floor is removed only when its file leaves
 * the scope; lowering one is an edit by hand with the reason in the commit.
 */
final class Baseline {

	/** @var string */
	private $file;

	/** @var array<string, array{caught: int, escaped: int, uncovered: int}> */
	private $floors;

	/**
	 * @param string $file Absolute path of the baseline PHP file.
	 */
	public function __construct( $file ) {
		$this->file   = $file;
		$this->floors = is_readable( $file ) ? (array) require $file : array();
	}

	/**
	 * @return bool
	 */
	public function exists() {
		return is_readable( $this->file );
	}

	/**
	 * @param string $path
	 *
	 * @return Tally|null
	 */
	public function floor( $path ) {
		if ( ! isset( $this->floors[ $path ] ) ) {
			return null;
		}
		$floor = $this->floors[ $path ];

		return Tally::of( $floor['caught'], $floor['escaped'], $floor['uncovered'] );
	}

	/**
	 * Whether a run holds a floor: neither score below it.
	 *
	 * @param Tally $run
	 * @param Tally $floor
	 *
	 * @return bool
	 */
	public static function holds( Tally $run, Tally $floor ) {
		return $run->score() >= $floor->score() && $run->covered_score() >= $floor->covered_score();
	}

	/**
	 * Record a file's run as its floor when it rose (or the file is new and allowed in).
	 *
	 * @param string $path
	 * @param Tally  $run
	 *
	 * @return bool Whether the floor changed.
	 */
	public function raise( $path, Tally $run ) {
		$floor = $this->floor( $path );
		if ( null !== $floor && ( $run->score() < $floor->score() || $run->covered_score() < $floor->covered_score() || ( $run->score() === $floor->score() && $run->covered_score() === $floor->covered_score() ) ) ) {
			return false;
		}

		$this->floors[ $path ] = $run->to_array();

		return true;
	}

	/**
	 * Drop floors whose files the scope no longer holds or that no longer exist.
	 *
	 * @param Scope $scope
	 *
	 * @return string[] Dropped paths.
	 */
	public function prune( Scope $scope ) {
		$dropped = array();
		foreach ( array_keys( $this->floors ) as $path ) {
			if ( ! is_file( $scope->root() . '/' . $path ) || ! $scope->holds( $path ) ) {
				unset( $this->floors[ $path ] );
				$dropped[] = $path;
			}
		}

		return $dropped;
	}

	/**
	 * Write the baseline under a lock (shards may finish at the same time).
	 *
	 * @return void
	 */
	public function save() {
		$lock = fopen( $this->file . '.lock', 'c' );
		if ( false === $lock || ! flock( $lock, LOCK_EX ) ) {
			throw new RuntimeException( 'Cannot lock ' . $this->file );
		}

		try {
			// Merge with what another shard may have written since this one started.
			$current = is_readable( $this->file ) ? (array) require $this->file : array();
			foreach ( $this->floors as $path => $floor ) {
				$current[ $path ] = $floor;
			}
			ksort( $current );

			$body = "<?php\n/**\n * Mutation-testing floors: each file's caught / escaped / uncovered counts when last recorded.\n * Written by `bin/mutate --update-baseline`; floors only rise. Lower one only by hand, with the reason in the commit.\n */\n\nreturn " . var_export( $current, true ) . ";\n";
			file_put_contents( $this->file, $body );
			$this->floors = $current;
		} finally {
			flock( $lock, LOCK_UN );
			fclose( $lock );
		}
	}
}
