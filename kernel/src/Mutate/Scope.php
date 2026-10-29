<?php

namespace Uncanny_Owl\Wp_Test_Kernel\Mutate;

use RuntimeException;

/**
 * What mutation testing covers in a plugin, and the bar each file is held to.
 *
 * Ported from the Automator platform's tools/mutate discipline (RB27): core files only; the critical
 * path is held to 90 overall, every other core file to 75 overall and 80 covered. Never 100 —
 * equivalent mutants and defensive branches make 100 a target for tests of the implementation.
 * Read from the plugin's own config file (tests/kernel/mutate.php), the one place its scope is written.
 */
final class Scope {

	const CRITICAL_SCORE       = 90.0;
	const TARGET_SCORE         = 75.0;
	const TARGET_COVERED_SCORE = 80.0;

	/** @var string */
	private $root;

	/** @var array<string, mixed> */
	private $config;

	/**
	 * @param string               $root   Plugin root.
	 * @param array<string, mixed> $config
	 */
	public function __construct( $root, array $config ) {
		$this->root   = rtrim( $root, '/' );
		$this->config = $config + array(
			'paths'          => array(),
			'ignored'        => array(),
			'critical'       => array(),
			'mirrors'        => array(),
			'also_tested_by' => array(),
			'core_abstracts' => array(),
		);
	}

	/**
	 * @param string $root
	 * @param string $file Plugin-relative config path.
	 *
	 * @return self
	 */
	public static function load( $root, $file ) {
		$path = rtrim( $root, '/' ) . '/' . $file;
		if ( ! is_readable( $path ) ) {
			throw new RuntimeException( 'Mutation config not found: ' . $path );
		}

		return new self( $root, (array) require $path );
	}

	/**
	 * @param string $key
	 *
	 * @return mixed
	 */
	public function get( $key ) {
		return $this->config[ $key ] ?? null;
	}

	/**
	 * @return string
	 */
	public function root() {
		return $this->root;
	}

	/**
	 * Every PHP file in scope, plugin-relative, sorted.
	 *
	 * @return string[]
	 */
	public function files() {
		$files = array();
		foreach ( (array) $this->config['paths'] as $path ) {
			$files = array_merge( $files, $this->expand( $path ) );
		}

		return $this->filter( $files );
	}

	/**
	 * The given plugin-relative files or folders, limited to the scope.
	 *
	 * @param string[] $paths
	 *
	 * @return string[]
	 */
	public function select( array $paths ) {
		$files = array();
		foreach ( $paths as $path ) {
			$files = array_merge( $files, $this->expand( $path ) );
		}

		return $this->filter( $files );
	}

	/**
	 * @param string $file Plugin-relative.
	 *
	 * @return bool
	 */
	public function holds( $file ) {
		return in_array( $file, $this->filter( array( $file ) ), true );
	}

	/**
	 * @param string $file
	 *
	 * @return bool
	 */
	public function is_critical( $file ) {
		return $this->matches( $file, (array) $this->config['critical'] );
	}

	/**
	 * @param string $file
	 *
	 * @return bool
	 */
	public function is_core_abstract( $file ) {
		return $this->matches( $file, (array) $this->config['core_abstracts'] );
	}

	/**
	 * @param string $file
	 * @param Tally  $tally
	 *
	 * @return bool
	 */
	public function meets_target( $file, Tally $tally ) {
		if ( $this->is_critical( $file ) ) {
			return $tally->score() >= self::CRITICAL_SCORE;
		}

		return $tally->score() >= self::TARGET_SCORE && $tally->covered_score() >= self::TARGET_COVERED_SCORE;
	}

	/**
	 * @param string $file
	 *
	 * @return string
	 */
	public function target_label( $file ) {
		return $this->is_critical( $file )
			? sprintf( '%.0f%%', self::CRITICAL_SCORE )
			: sprintf( '%.0f%%, covered %.0f%%', self::TARGET_SCORE, self::TARGET_COVERED_SCORE );
	}

	/**
	 * @param string $path Plugin-relative file or folder.
	 *
	 * @return string[]
	 */
	private function expand( $path ) {
		$path     = trim( $path, '/' );
		$absolute = $this->root . '/' . $path;

		if ( is_file( $absolute ) ) {
			return array( $path );
		}
		if ( ! is_dir( $absolute ) ) {
			return array();
		}

		$files    = array();
		$iterator = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $absolute, \FilesystemIterator::SKIP_DOTS ) );
		foreach ( $iterator as $file ) {
			if ( 'php' === $file->getExtension() ) {
				$files[] = $path . '/' . substr( $file->getPathname(), strlen( $absolute ) + 1 );
			}
		}

		return $files;
	}

	/**
	 * @param string[] $files
	 *
	 * @return string[]
	 */
	private function filter( array $files ) {
		$held = array();
		foreach ( $files as $file ) {
			if ( 'php' !== pathinfo( $file, PATHINFO_EXTENSION ) ) {
				continue;
			}
			if ( ! $this->matches( $file, (array) $this->config['paths'] ) || $this->matches( $file, (array) $this->config['ignored'] ) ) {
				continue;
			}
			$held[ $file ] = true;
		}
		$held = array_keys( $held );
		sort( $held );

		return $held;
	}

	/**
	 * Whether a file is one of, or inside one of, the given plugin-relative paths.
	 *
	 * @param string   $file
	 * @param string[] $paths
	 *
	 * @return bool
	 */
	private function matches( $file, array $paths ) {
		foreach ( $paths as $path ) {
			$path = trim( $path, '/' );
			if ( $file === $path || 0 === strpos( $file, $path . '/' ) ) {
				return true;
			}
		}

		return false;
	}
}
