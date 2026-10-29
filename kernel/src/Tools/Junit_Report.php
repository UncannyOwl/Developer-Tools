<?php

namespace Uncanny_Owl\Wp_Test_Kernel\Tools;

use RuntimeException;
use SimpleXMLElement;

/**
 * Reads a JUnit XML file (Codeception 4 / PHPUnit 9 or PHPUnit 13) into normalised test outcomes.
 */
final class Junit_Report {

	const PASS = 'pass';
	const FAIL = 'fail';
	const SKIP = 'skip';

	/**
	 * @param string $file
	 *
	 * @return array{tests: array<string, array{outcome: string, file: string, message: string}>, files: string[]}
	 */
	public static function read( $file ) {

		if ( ! is_readable( $file ) ) {
			throw new RuntimeException( 'JUnit file not readable: ' . $file );
		}

		$xml = simplexml_load_file( $file );
		if ( false === $xml ) {
			throw new RuntimeException( 'Invalid JUnit XML: ' . $file );
		}

		$tests = array();
		$files = array();

		foreach ( $xml->xpath( '//testcase' ) as $case ) {
			$id                  = self::id( $case );
			$path                = (string) ( $case['file'] ?? '' );
			$tests[ $id ]        = array(
				'outcome' => self::outcome( $case ),
				'file'    => $path,
				'message' => self::message( $case ),
			);
			$files[ $path ]      = true;
		}

		return array(
			'tests' => $tests,
			'files' => array_keys( array_filter( $files, 'strlen', ARRAY_FILTER_USE_KEY ) ),
		);
	}

	/**
	 * Re-key tests whose class was renamed, so a renamed test compares with its old self.
	 *
	 * @param array<string, array{outcome: string, file: string, message: string}> $tests
	 * @param array<string, string>                                                $renames Old short class name => new.
	 *
	 * @return array<string, array{outcome: string, file: string, message: string}>
	 */
	public static function rename_classes( array $tests, array $renames ) {

		$renamed = array();
		foreach ( $tests as $id => $test ) {
			$class           = strstr( $id, '::', true );
			$key             = isset( $renames[ $class ] ) ? $renames[ $class ] . substr( $id, strlen( $class ) ) : $id;
			$renamed[ $key ] = $test;
		}

		return $renamed;
	}

	/**
	 * "Class::method" plus a normalised data-set suffix, the same for both runners.
	 *
	 * @param SimpleXMLElement $case
	 *
	 * @return string
	 */
	private static function id( SimpleXMLElement $case ) {

		$name  = (string) $case['name'];
		$class = (string) ( $case['class'] ?? '' );

		if ( '' === $class && isset( $case['classname'] ) ) {
			$class = (string) $case['classname'];
		}
		$class = ltrim( (string) preg_replace( '/^.*\\\\/', '', $class ), '\\' );

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

		return $class . '::' . $name . $set;
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
