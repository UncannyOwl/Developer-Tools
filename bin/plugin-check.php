#!/usr/bin/env php
<?php
// phpcs:disable WordPress.WP.AlternativeFunctions, WordPress.Security.EscapeOutput, WordPress.PHP.DiscouragedPHPFunctions, WordPress.PHP.NoSilencedErrors -- CLI tool that runs outside WordPress.
/**
 * Run WordPress.org's Plugin Check on the file set a plugin ships, and fail on errors its baseline
 * does not hold.
 *
 * Plugin Check is the plugin-check plugin's `wp plugin check`. It needs a WordPress install with
 * plugin-check active; the checked plugin does not need to be installed there. Only the static
 * checks run (every category): the runtime ones need the plugin active in that site.
 *
 * It checks the release zip, not the checkout: --build runs the plugin's own zip script (config
 * `build`, with CI=true) and checks the zip it names (config `zip`). A new ERROR fails; errors the
 * committed baseline lists, warnings and ignored codes are reported only.
 *
 * Usage:
 *   php bin/plugin-check.php --plugin-path . --build --wp-path ~/wp
 *   php bin/plugin-check.php --plugin-path . --zip zip-package/foo.zip --wp-path … --format=github --summary-file "$GITHUB_STEP_SUMMARY"
 *   php bin/plugin-check.php --plugin-path . --build --wp-path … --update-baseline
 *   php bin/plugin-check.php --plugin-path . --results saved.json           (gate a saved run again)
 *
 * Options:
 *   --plugin-path PATH   Plugin checkout holding .plugin-check/config.json and baseline.json (default: cwd)
 *   --build              Run config `build` in the plugin folder, then check config `zip`
 *   --zip FILE           Release zip to check (default: config `zip` with --build); unpacked to a temp folder
 *   --dir DIR            Unpacked shipped file set (the folder holding the main plugin file)
 *   --results FILE       Gate saved results (Plugin Check strict-json, or --save-results output) instead of running
 *   --wp-path PATH       WordPress root with plugin-check active (default: $WP_PATH, else cwd)
 *   --wp CMD             WP-CLI command (default: $WP_CLI_BIN, else wp), e.g. "php -d memory_limit=-1 /usr/local/bin/wp"
 *   --jobs N             Split the checks over N parallel processes (default 1)
 *   --config FILE        Default {plugin-path}/.plugin-check/config.json
 *   --baseline FILE      Default {plugin-path}/.plugin-check/baseline.json
 *   --update-baseline    Write the current errors to the baseline and exit 0
 *   --save-results FILE  Write the normalised results, to gate again with --results
 *   --summary-file FILE  Append a Markdown summary (for $GITHUB_STEP_SUMMARY)
 *   --format FORMAT      text (default) | json | github
 *   --strict             Also fail when baselined errors no longer occur, so the baseline only shrinks
 *
 * Exit codes: 0 no new errors, 1 new errors, 2 usage or environment error.
 */

require_once __DIR__ . '/plugin-check/functions.php';

/**
 * @param string[] $argv
 *
 * @return array
 */
function pc_parse_args( array $argv ) {
	$opts = array(
		'plugin-path'     => getcwd(),
		'zip'             => '',
		'dir'             => '',
		'results'         => '',
		'wp-path'         => (string) getenv( 'WP_PATH' ),
		'wp'              => getenv( 'WP_CLI_BIN' ) ? (string) getenv( 'WP_CLI_BIN' ) : 'wp',
		'jobs'            => '1',
		'config'          => '',
		'baseline'        => '',
		'save-results'    => '',
		'summary-file'    => '',
		'format'          => 'text',
		'update-baseline' => false,
		'build'           => false,
		'strict'          => false,
		'help'            => false,
	);
	$n    = count( $argv );
	for ( $i = 1; $i < $n; $i++ ) {
		$a = $argv[ $i ];
		if ( preg_match( '~^--(plugin-path|zip|dir|results|wp-path|wp|jobs|config|baseline|save-results|summary-file|format)(?:=(.*))?$~s', $a, $m ) ) {
			$opts[ $m[1] ] = isset( $m[2] ) ? $m[2] : ( isset( $argv[ $i + 1 ] ) ? $argv[ ++$i ] : '' );
		} elseif ( in_array( $a, array( '--update-baseline', '--build', '--strict', '--help', '-h' ), true ) ) {
			$opts[ '-h' === $a ? 'help' : substr( $a, 2 ) ] = true;
		} else {
			pc_fail( "unknown option $a" );
		}
	}
	if ( ! in_array( $opts['format'], array( 'text', 'json', 'github' ), true ) ) {
		pc_fail( "unknown format {$opts['format']}" );
	}
	return $opts;
}

/**
 * @param string $message
 */
function pc_fail( $message ) {
	fwrite( STDERR, "plugin-check: $message\n" );
	exit( 2 );
}

/**
 * @param string $file
 * @param bool   $required
 *
 * @return array Decoded JSON object, or array() when the file is missing and not required.
 */
function pc_read_json( $file, $required ) {
	if ( '' === $file || ! is_file( $file ) ) {
		if ( $required ) {
			pc_fail( "missing $file" );
		}
		return array();
	}
	$data = json_decode( (string) file_get_contents( $file ), true );
	if ( ! is_array( $data ) ) {
		pc_fail( "$file is not valid JSON" );
	}
	return $data;
}

/**
 * Every ignore and excluded check must say why: an ignore list without reasons rots.
 *
 * @param array  $config
 * @param string $file
 */
function pc_validate_config( array $config, $file ) {
	foreach ( array( 'ignore', 'exclude_checks' ) as $key ) {
		foreach ( isset( $config[ $key ] ) ? (array) $config[ $key ] : array() as $i => $entry ) {
			if ( ! is_array( $entry ) || empty( $entry['reason'] ) ) {
				pc_fail( "$file: $key[$i] needs a reason" );
			}
			if ( 'exclude_checks' === $key && empty( $entry['check'] ) ) {
				pc_fail( "$file: exclude_checks[$i] needs a check slug" );
			}
		}
	}
}

/**
 * Run the plugin's zip script. CI=true keeps zip-plugin.js from prompting about stale generated
 * files; its output goes to stderr so stdout stays the report.
 *
 * @param string $plugin_dir Plugin folder.
 * @param string $cmd    Shell command from config `build`.
 */
function pc_build( $plugin_dir, $cmd ) {
	fwrite( STDERR, "plugin-check: building: $cmd\n" );
	$proc = proc_open(
		$cmd,
		array(
			0 => array( 'file', '/dev/null', 'r' ),
			1 => STDERR,
			2 => STDERR,
		),
		$pipes,
		$plugin_dir,
		array_merge( getenv(), array( 'CI' => 'true' ) )
	);
	$code = is_resource( $proc ) ? proc_close( $proc ) : 1;
	if ( 0 !== $code ) {
		pc_fail( "build failed (exit $code): $cmd" );
	}
}

/**
 * @param string $zip
 *
 * @return string The unpacked plugin folder (the zip's single top-level folder).
 */
function pc_unzip( $zip ) {
	if ( ! is_file( $zip ) ) {
		pc_fail( "no zip at $zip" );
	}
	$tmp = rtrim( sys_get_temp_dir(), '/' ) . '/plugin-check-' . getmypid() . '-' . bin2hex( random_bytes( 4 ) );
	if ( ! mkdir( $tmp, 0700, true ) ) {
		pc_fail( "cannot create $tmp" );
	}
	register_shutdown_function(
		function () use ( $tmp ) {
			exec( 'rm -rf ' . escapeshellarg( $tmp ) );
		}
	);
	exec( 'unzip -q ' . escapeshellarg( $zip ) . ' -d ' . escapeshellarg( $tmp ) . ' 2>&1', $out, $code );
	if ( 0 !== $code ) {
		pc_fail( "unzip failed for $zip: " . implode( ' ', $out ) );
	}
	$top = array_values( array_diff( scandir( $tmp ), array( '.', '..', '__MACOSX' ) ) );
	// The real path: PHP_CodeSniffer reports real paths, and Plugin Check strips the path it was given
	// from them, so a symlinked temp folder (/var -> /private/var on macOS) leaves "/private" behind.
	return realpath( 1 === count( $top ) && is_dir( $tmp . '/' . $top[0] ) ? $tmp . '/' . $top[0] : $tmp );
}

/**
 * @param array  $opts
 * @param string $args WP-CLI arguments after the command.
 *
 * @return string Shell command.
 */
function pc_wp_command( array $opts, $args ) {
	$path = '' !== $opts['wp-path'] ? ' --path=' . escapeshellarg( $opts['wp-path'] ) : '';
	return $opts['wp'] . $path . ' ' . $args;
}

/**
 * Run WP-CLI commands in parallel, each with stdout and stderr in temp files (no pipe deadlocks).
 *
 * @param string[] $commands
 *
 * @return array<int,array{code:int,out:string,err:string}>
 */
function pc_run_parallel( array $commands ) {
	$procs = array();
	foreach ( $commands as $i => $cmd ) {
		$out         = tempnam( sys_get_temp_dir(), 'pc-out' );
		$err         = tempnam( sys_get_temp_dir(), 'pc-err' );
		$procs[ $i ] = array( proc_open( $cmd . ' > ' . escapeshellarg( $out ) . ' 2> ' . escapeshellarg( $err ), array(), $pipes ), $out, $err );
	}
	$results = array();
	foreach ( $procs as $i => $p ) {
		$code          = proc_close( $p[0] );
		$results[ $i ] = array(
			'code' => $code,
			'out'  => (string) file_get_contents( $p[1] ),
			'err'  => (string) file_get_contents( $p[2] ),
		);
		unlink( $p[1] );
		unlink( $p[2] );
	}
	return $results;
}

/**
 * @param array  $opts
 * @param array  $config
 * @param string $dir
 *
 * @return array Raw strict-json items from every process.
 */
function pc_run_plugin_check( array $opts, array $config, $dir ) {
	$excluded = array();
	foreach ( isset( $config['exclude_checks'] ) ? $config['exclude_checks'] : array() as $e ) {
		$excluded[] = $e['check'];
	}
	$base       = 'plugin check ' . escapeshellarg( $dir )
		. ' --slug=' . escapeshellarg( $config['slug'] )
		. ' --mode=' . escapeshellarg( isset( $config['mode'] ) ? $config['mode'] : 'new' )
		. ' --format=strict-json --fields=file,line,column,type,code,message,docs,severity'
		. ' --skip-plugins=' . escapeshellarg( $config['slug'] );
	$path_flags = array(
		'exclude_directories' => 'exclude-directories',
		'exclude_files'       => 'exclude-files',
	);
	foreach ( $path_flags as $key => $flag ) {
		if ( ! empty( $config[ $key ] ) ) {
			$base .= " --$flag=" . escapeshellarg( implode( ',', (array) $config[ $key ] ) );
		}
	}

	$jobs     = max( 1, (int) $opts['jobs'] );
	$commands = array();
	if ( 1 === $jobs ) {
		$commands[] = pc_wp_command( $opts, $base . ( $excluded ? ' --exclude-checks=' . escapeshellarg( implode( ',', $excluded ) ) : '' ) );
	} else {
		$list = pc_run_parallel( array( pc_wp_command( $opts, 'plugin list-checks --format=json' ) ) )[0];
		$all  = json_decode( $list['out'], true );
		if ( 0 !== $list['code'] || ! is_array( $all ) ) {
			pc_fail( "wp plugin list-checks failed:\n" . $list['err'] );
		}
		$checks = array_values( array_diff( array_column( $all, 'slug' ), $excluded ) );
		$groups = pc_partition_checks( $checks, $jobs );
		$last   = array_pop( $groups );
		foreach ( $groups as $group ) {
			$commands[] = pc_wp_command( $opts, $base . ' --checks=' . escapeshellarg( implode( ',', $group ) ) );
		}
		// The last process runs everything the others do not: list-checks also names runtime checks,
		// which `--checks` rejects outside a runtime environment, and a plain run skips quietly.
		$commands[] = pc_wp_command( $opts, $base . ' --exclude-checks=' . escapeshellarg( implode( ',', array_merge( array_diff( $checks, $last ), $excluded ) ) ) );
	}

	$items = array();
	foreach ( pc_run_parallel( $commands ) as $i => $r ) {
		$decoded = pc_decode_output( $r['out'] );
		if ( 0 !== $r['code'] || null === $decoded ) {
			pc_fail( "wp plugin check failed (exit {$r['code']}): {$commands[$i]}\n" . trim( substr( $r['err'], -2000 ) ) . "\n" . substr( $r['out'], 0, 500 ) );
		}
		$items = array_merge( $items, $decoded );
	}
	return $items;
}

$opts = pc_parse_args( $argv );
if ( $opts['help'] ) {
	preg_match( '~/\*\*(.*?)\*/~s', file_get_contents( __FILE__ ), $doc );
	echo preg_replace( '~^ \* ?~m', '', $doc[1] );
	exit( 0 );
}

$plugin_dir = realpath( $opts['plugin-path'] );
if ( false === $plugin_dir ) {
	pc_fail( "no plugin at {$opts['plugin-path']}" );
}
$config_file   = '' !== $opts['config'] ? $opts['config'] : $plugin_dir . '/.plugin-check/config.json';
$baseline_file = '' !== $opts['baseline'] ? $opts['baseline'] : $plugin_dir . '/.plugin-check/baseline.json';
$config        = pc_read_json( $config_file, false );
pc_validate_config( $config, $config_file );
if ( empty( $config['slug'] ) ) {
	$config['slug'] = basename( $plugin_dir );
}
$baseline = pc_read_json( $baseline_file, false );

$started = microtime( true );
$version = '';
if ( '' !== $opts['results'] ) {
	$saved   = pc_read_json( $opts['results'], true );
	$items   = isset( $saved['findings'] ) ? $saved['findings'] : $saved;
	$root    = $opts['dir']; // Only stripped from paths and messages; the folder need not exist any more.
	$source  = $opts['results'];
	$checked = '' !== $root ? $root : $opts['results'];
	$version = isset( $saved['plugin_check_version'] ) ? $saved['plugin_check_version'] : '';
} else {
	if ( $opts['build'] ) {
		if ( empty( $config['build'] ) || ( empty( $config['zip'] ) && '' === $opts['zip'] ) ) {
			pc_fail( "--build needs `build` and `zip` in $config_file" );
		}
		pc_build( $plugin_dir, $config['build'] );
		if ( '' === $opts['zip'] ) {
			$opts['zip'] = $plugin_dir . '/' . $config['zip'];
		}
	}
	if ( '' !== $opts['zip'] ) {
		$root   = pc_unzip( $opts['zip'] );
		$source = pc_rel_path( $opts['zip'], $plugin_dir );
	} elseif ( '' !== $opts['dir'] ) {
		$root   = realpath( $opts['dir'] );
		$source = $opts['dir'];
		if ( false === $root ) {
			pc_fail( "no folder at {$opts['dir']}" );
		}
	} else {
		pc_fail( 'give --build, --zip, --dir or --results: Plugin Check runs on what ships, not on the checkout' );
	}
	if ( ! is_file( $root . '/' . $config['slug'] . '.php' ) ) {
		fwrite( STDERR, "plugin-check: warning: no {$config['slug']}.php in $root\n" );
	}
	$probe = pc_run_parallel( array( pc_wp_command( $opts, 'plugin get plugin-check --field=version' ) ) )[0];
	if ( 0 !== $probe['code'] ) {
		pc_fail( "plugin-check is not installed in the WordPress at '{$opts['wp-path']}' (wp plugin install plugin-check --activate):\n" . trim( $probe['err'] ) );
	}
	$version = trim( $probe['out'] );
	$items   = pc_run_plugin_check( $opts, $config, $root );
	$checked = $root;
}

$rows = pc_normalize( $items, array( $root, (string) realpath( $root ) ) );
if ( '' !== $opts['save-results'] ) {
	$saved = array(
		'plugin_check_version' => $version,
		'findings'             => $rows,
	);
	file_put_contents( $opts['save-results'], json_encode( $saved, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . "\n" );
}

if ( $opts['update-baseline'] ) {
	$gate = pc_gate( $rows, $config, array() );
	$new  = pc_build_baseline( $gate['rows'], $version );
	if ( ! is_dir( dirname( $baseline_file ) ) ) {
		mkdir( dirname( $baseline_file ), 0755, true );
	}
	file_put_contents( $baseline_file, json_encode( $new, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . "\n" );
	$was = isset( $baseline['total'] ) ? (int) $baseline['total'] : 0;
	echo "Wrote $baseline_file: {$new['total']} errors (was $was).\n";
	exit( 0 );
}

$gate   = pc_gate( $rows, $config, $baseline );
$report = pc_report_array(
	$gate,
	array(
		'slug'                 => $config['slug'],
		'plugin_check_version' => $version,
		'baseline_version'     => isset( $baseline['plugin_check_version'] ) ? $baseline['plugin_check_version'] : '',
		'checked'              => $checked,
		'source'               => $source,
		'baseline'             => empty( $baseline ) ? null : pc_rel_path( $baseline_file, $plugin_dir ),
		'elapsed'              => round( microtime( true ) - $started, 1 ),
		'strict'               => $opts['strict'],
	)
);
if ( '' !== $opts['summary-file'] ) {
	file_put_contents( $opts['summary-file'], pc_render_markdown( $report ), FILE_APPEND );
}
echo pc_render( $report, $opts['format'] );
exit( $report['exit'] );
