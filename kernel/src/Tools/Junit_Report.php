<?php

namespace Uncanny_Owl\Wp_Test_Kernel\Tools;

use RuntimeException;
use SimpleXMLElement;

/**
 * Reads a JUnit XML file (Codeception 4 / PHPUnit 9, PHPUnit 13 or Pest 5) into normalised test outcomes.
 *
 * A test's id is its fully qualified class and its normalised method name, "Ns\Foo_Test::rows are refused [0]".
 * All three runners write the class with its namespace (class_name() mends the few Pest 5 cuts short), so one
 * test has one id in each of their reports. The namespace stays in the id: two test classes may share a short
 * name, and their tests must not merge. A report in which two test cases still share an id is refused.
 */
final class Junit_Report {

	const PASS = 'pass';
	const FAIL = 'fail';
	const SKIP = 'skip';

	/**
	 * @param string                $file
	 * @param array<string, string> $renames Old class (or test file) => new class, applied before the ids are made. See rename().
	 *
	 * @return array{tests: array<string, array{outcome: string, file: string, message: string}>, files: string[]}
	 *
	 * @throws RuntimeException When the file cannot be read, or two of its test cases have one id.
	 */
	public static function read( $file, array $renames = array() ) {

		if ( ! is_readable( $file ) ) {
			throw new RuntimeException( 'JUnit file not readable: ' . $file );
		}

		$xml = simplexml_load_file( $file );
		if ( false === $xml ) {
			throw new RuntimeException( 'Invalid JUnit XML: ' . $file );
		}

		$tests   = array();
		$sources = array();
		$files   = array();

		foreach ( $xml->xpath( '//testcase' ) as $case ) {
			$path   = (string) ( $case['file'] ?? '' );
			$class  = self::class_name( $case );
			$id     = self::rename( $class, $path, $renames ) . '::' . self::method( $case );
			$source = $class . '::' . $case['name'] . ( '' === $path ? '' : ' in ' . $path );

			if ( isset( $tests[ $id ] ) ) {
				throw new RuntimeException(
					sprintf(
						'%s: two test cases have the id "%s": %s, and %s. Rename one of the tests, or tell them apart with a "@renamed" entry.',
						$file,
						$id,
						$sources[ $id ],
						$source
					)
				);
			}

			$tests[ $id ]   = array(
				'outcome' => self::outcome( $case ),
				'file'    => $path,
				'message' => self::message( $case ),
			);
			$sources[ $id ] = $source;
			$files[ $path ] = true;
		}

		return array(
			'tests' => $tests,
			'files' => array_keys( array_filter( $files, 'strlen', ARRAY_FILTER_USE_KEY ) ),
		);
	}

	/**
	 * The test case's class with its namespace, from "class", or else from the dotted "classname".
	 *
	 * @param SimpleXMLElement $case
	 *
	 * @return string
	 */
	private static function class_name( SimpleXMLElement $case ) {

		$class = (string) ( $case['class'] ?? '' );

		// "classname" separates namespaces with dots, which a PHP class name cannot contain.
		if ( '' === $class ) {
			$class = str_replace( '.', '\\', (string) ( $case['classname'] ?? '' ) );
		}
		$class = ltrim( $class, '\\' );

		// Pest 5 writes a test case's class cut after the first "P\" in it, wherever that falls: a test in
		// "Ns\SliceWP\Helpers" is reported in "Helpers". Its class's test suite keeps the whole name.
		foreach ( (array) $case->xpath( 'ancestor::testsuite/@name' ) as $suite ) {
			$suite = ltrim( (string) $suite, '\\' );
			$cut   = strpos( $suite, 'P\\' );
			if ( false !== $cut && substr( $suite, $cut + 2 ) === $class ) {
				return $suite;
			}
		}

		return $class;
	}

	/**
	 * A class after the "@renamed" entries, so a renamed or moved test compares with its old self.
	 *
	 * The most specific key wins:
	 * - "path/to/Foo_Test.php" names a test file: the test cases whose file is that path, or ends in "/" and it.
	 *   It tells apart classes that an old report names only by their short name.
	 * - "Ns\Foo_Test" (any key with a backslash) names that class exactly; "\Foo_Test" is the global Foo_Test.
	 * - "Foo_Test" names every class with that short name, in any namespace.
	 * A value with a backslash is the new class with its namespace; one without replaces only the short name.
	 *
	 * @param string                $class
	 * @param string                $path
	 * @param array<string, string> $renames
	 *
	 * @return string
	 */
	private static function rename( $class, $path, array $renames ) {

		$short = (string) substr( (string) strrchr( '\\' . $class, '\\' ), 1 );
		$to    = self::renamed_file( $path, $renames ) ?? $renames[ '\\' . $class ] ?? $renames[ $class ] ?? $renames[ $short ] ?? null;

		if ( null === $to ) {
			return $class;
		}

		$to = (string) $to;
		if ( false !== strpos( $to, '\\' ) ) {
			return ltrim( $to, '\\' );
		}

		return substr( $class, 0, strlen( $class ) - strlen( $short ) ) . $to;
	}

	/**
	 * @param string                $path
	 * @param array<string, string> $renames
	 *
	 * @return string|null The new class a test-file key gives the path, or null.
	 */
	private static function renamed_file( $path, array $renames ) {

		if ( '' === $path ) {
			return null;
		}

		foreach ( $renames as $from => $to ) {
			$from = (string) $from;
			if ( '.php' === substr( $from, -4 ) && ( $path === $from || str_ends_with( $path, '/' . ltrim( $from, '/' ) ) ) ) {
				return (string) $to;
			}
		}

		return null;
	}

	/**
	 * The method name plus a normalised data-set suffix, the same for every runner.
	 *
	 * @param SimpleXMLElement $case
	 *
	 * @return string
	 */
	private static function method( SimpleXMLElement $case ) {

		$name = (string) $case['name'];

		// PHPUnit: "test with data set #0" / "test with data set "name"". Codeception: "test | #0" / "test | "name"".
		$set = '';
		if ( preg_match( '/^(.*?) with data set (.+)$/', $name, $m ) || preg_match( '/^(.*?) \| (.+)$/', $name, $m ) ) {
			$set  = ' [' . ltrim( (string) preg_replace( '/^"(.*)"$/', '$1', trim( $m[2] ) ), '#' ) . ']';
			$name = $m[1];
		}

		// Pest reports `test_foo_bar` as "Foo bar", `testFooAt1pm` as "Foo at 1 pm", and drops a doubled
		// `test_test_` prefix: compare everything as lower-case words split at case and digit changes.
		$name = (string) preg_replace( '/^(test_?)+/i', '', trim( $name ) );
		$name = (string) preg_replace( '/(?<=[a-z])(?=[A-Z])|(?<=[A-Za-z])(?=[0-9])|(?<=[0-9])(?=[A-Za-z])/', ' ', $name );
		$name = strtolower( trim( (string) preg_replace( '/[^A-Za-z0-9]+/', ' ', $name ) ) );

		return $name . $set;
	}

	/**
	 * @param SimpleXMLElement $case
	 *
	 * @return string
	 */
	private static function outcome( SimpleXMLElement $case ) {

		if ( isset( $case->skipped ) ) {
			return self::SKIP;
		}

		foreach ( array( 'failure', 'error' ) as $element ) {
			if ( isset( $case->{$element} ) ) {
				$type = (string) ( $case->{$element}['type'] ?? '' );
				// PHPUnit 9 reports risky tests as errors; PHPUnit 13 counts them as passed.
				if ( false !== stripos( $type, 'RiskyTest' ) ) {
					return self::PASS;
				}
				// PHPUnit 9 reports incomplete tests as errors of this type.
				if ( false !== stripos( $type, 'IncompleteTest' ) || false !== stripos( $type, 'SkippedTest' ) ) {
					return self::SKIP;
				}
				return self::FAIL;
			}
		}

		return self::PASS;
	}

	/**
	 * @param SimpleXMLElement $case
	 *
	 * @return string
	 */
	private static function message( SimpleXMLElement $case ) {
		foreach ( array( 'failure', 'error', 'skipped' ) as $element ) {
			if ( isset( $case->{$element} ) ) {
				// PHPUnit 13 starts the text with the test's own name; the reason is the next line.
				foreach ( preg_split( '/\R/', trim( (string) $case->{$element} ) ) as $line ) {
					$line = trim( $line );
					if ( '' !== $line && false === strpos( $line, (string) $case['name'] ) ) {
						return $line;
					}
				}
				return '';
			}
		}

		return '';
	}
}
