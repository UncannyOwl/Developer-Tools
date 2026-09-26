<?php
// phpcs:disable WordPress.WP.AlternativeFunctions, WordPress.Security.EscapeOutput, WordPress.PHP.DiscouragedPHPFunctions, WordPress.PHP.NoSilencedErrors -- CLI tool that runs outside WordPress.
/**
 * The checks. Each is a function ( Lint_Context $ctx ): Lint_Finding[] named after its L-id and
 * mapped to a rule in .claude/skills/integration-rules (Automator repo). Regexes mirror
 * .claude/skills/audit-integration/checks.sh one for one, so the two agree while both exist.
 */

/**
 * @return array<string,callable> check id => function.
 */
function lint_all_checks() {
	return array(
		'L-def'       => 'lint_check_def',
		'L-setup'     => 'lint_check_setup',
		'L-hookconst' => 'lint_check_hookconst',
		'L-naming'    => 'lint_check_naming',
		'L-helper'    => 'lint_check_helper',
		'L-login'     => 'lint_check_login',
		'L-sentence'  => 'lint_check_sentence',
		'L-tokens'    => 'lint_check_tokens',
		'L-remote'    => 'lint_check_remote',
		'L-any'       => 'lint_check_any',
		'L-dedupe'    => 'lint_check_dedupe',
		'L-bind'      => 'lint_check_bind',
		'L-pro'       => 'lint_check_pro',
		'L-ns'        => 'lint_check_ns',
		'L-storage'   => 'lint_check_storage',
		'L-dispatch'  => 'lint_check_dispatch',
		'L-active'    => 'lint_check_active',
		'L-manifest'  => 'lint_check_manifest',
		'L-i18n'      => 'lint_check_i18n',
		'L-sec'       => 'lint_check_sec',
		'L-hygiene'   => 'lint_check_hygiene',
		'L-tests'     => 'lint_check_tests',
		'L-scope'     => 'lint_check_scope',
	);
}

/**
 * @param Lint_Context $ctx
 *
 * @return string[] All .php files across Free and Pro.
 */
function lint_all_files( Lint_Context $ctx ) {
	$files = array();
	foreach ( $ctx->dirs() as $dir ) {
		$files = array_merge( $files, $ctx->php_files( $dir ) );
	}
	return $files;
}

/**
 * @param Lint_Context $ctx
 * @param string       $sub
 *
 * @return string[] Files under one sub-folder across Free and Pro.
 */
function lint_files_in( Lint_Context $ctx, $sub ) {
	$files = array();
	foreach ( $ctx->dirs() as $dir ) {
		$files = array_merge( $files, $ctx->php_files( $dir, $sub ) );
	}
	return $files;
}

/**
 * Turn grep hits into findings.
 *
 * @param array  $hits
 * @param string $check
 * @param string $rule
 * @param string $severity
 * @param string $message
 * @param bool   $fixable
 *
 * @return Lint_Finding[]
 */
function lint_from_hits( array $hits, $check, $rule, $severity, $message, $fixable = false ) {
	$out = array();
	foreach ( $hits as $h ) {
		$out[] = new Lint_Finding( $check, $rule, $severity, $h[0], $h[1], $message, $fixable, array( 'text' => $h[2] ) );
	}
	return $out;
}

// ---------------------------------------------------------------- triggers

/**
 * L-def (R5): every trigger declares definition() with ->hook().
 *
 * @param Lint_Context $ctx
 *
 * @return Lint_Finding[]
 */
function lint_check_def( Lint_Context $ctx ) {
	$out = array();
	foreach ( lint_files_in( $ctx, 'triggers' ) as $f ) {
		if ( ! $ctx->has_method( $f, 'definition' ) || ! $ctx->has( $f, '->hook\(' ) ) {
			$out[] = new Lint_Finding( 'L-def', 'R5', 'P1', $f, 0, 'no definition() with ->hook()' );
		}
	}
	return $out;
}

/**
 * L-setup (R5, R11): no identity setters or add_action() in triggers; actions set set_requires_user().
 *
 * @param Lint_Context $ctx
 *
 * @return Lint_Finding[]
 */
function lint_check_setup( Lint_Context $ctx ) {
	$out = lint_from_hits(
		$ctx->grep( 'add_action\(|set_integration\(|set_trigger_(code|meta|type)\(', lint_files_in( $ctx, 'triggers' ) ),
		'L-setup',
		'R5',
		'P1',
		'identity setter or add_action() in a trigger; declare them in definition()'
	);
	foreach ( lint_files_in( $ctx, 'actions' ) as $f ) {
		if ( ! $ctx->has( $f, 'set_requires_user\(' ) ) {
			$out[] = new Lint_Finding( 'L-setup', 'R11', 'P1', $f, 0, 'no explicit set_requires_user()' );
		}
	}
	return $out;
}

/**
 * L-hookconst (R6): hook names, codes and metas are literals, never constants.
 *
 * @param Lint_Context $ctx
 *
 * @return Lint_Finding[]
 */
function lint_check_hookconst( Lint_Context $ctx ) {
	$files = lint_all_files( $ctx );
	$out   = array();
	foreach ( $ctx->grep( '(->hook|do_action|add_action)\( *(self|static|[A-Z][A-Za-z0-9_\\\\]*)::[A-Z0-9_]+', $files ) as $h ) {
		preg_match( '~(->hook|do_action|add_action)\( *((?:self|static|[A-Z][A-Za-z0-9_\\\\]*)::[A-Z0-9_]+)~', $h[2], $m );
		$out[] = new Lint_Finding( 'L-hookconst', 'R6', 'P1', $h[0], $h[1], 'hook name from a constant: use the literal string', true, array( 'ref' => $m[2] ) );
	}
	foreach ( $ctx->grep( "const [A-Z0-9_]+ *= *'[A-Z0-9_]+';", $files ) as $h ) {
		if ( false === strpos( $h[2], 'ANY_VALUE' ) ) {
			$out[] = new Lint_Finding( 'L-hookconst', 'R6', 'P2', $h[0], $h[1], 'code, meta or option-code constant: inline the string' );
		}
	}
	return $out;
}

/**
 * L-naming (R7): class names are Pascal_Case_With_Underscores.
 *
 * @param Lint_Context $ctx
 *
 * @return Lint_Finding[]
 */
function lint_check_naming( Lint_Context $ctx ) {
	$out = array();
	foreach ( lint_all_files( $ctx ) as $f ) {
		foreach ( $ctx->class_info( $f )['classes'] as $c ) {
			if ( preg_match( '~^[A-Z0-9_]+$~', $c['name'] ) && preg_match( '~[A-Z]~', $c['name'] ) ) {
				$out[] = new Lint_Finding( 'L-naming', 'R7', 'P2', $f, $c['line'], "SCREAMING_SNAKE class name {$c['name']}: use Pascal_Case_With_Underscores" );
			}
		}
	}
	return $out;
}

/**
 * L-helper (R8): $this->item_helpers behind a class-level @property; the helper is assigned in setup().
 *
 * @param Lint_Context $ctx
 *
 * @return Lint_Finding[]
 */
function lint_check_helper( Lint_Context $ctx ) {
	$files = lint_all_files( $ctx );
	$out   = lint_from_hits(
		$ctx->grep( 'get_item_helpers\(\)|array_shift\(.*dependencies|\$this->helper->', $files ),
		'L-helper',
		'R8',
		'P1',
		'old helper access: use $this->item_helpers behind a class-level @property'
	);
	$out   = array_merge( $out, lint_from_hits( $ctx->grep( '@method .*get_item_helpers', $files, true ), 'L-helper', 'R8', 'P1', 'the @method get_item_helpers() docblock: use a class-level @property $item_helpers' ) );
	foreach ( array( 'triggers', 'actions', 'conditions', 'loop-filters' ) as $sub ) {
		foreach ( lint_files_in( $ctx, $sub ) as $f ) {
			if ( $ctx->has( $f, 'item_helpers' ) && ! $ctx->has( $f, '@property [^ ]+ \$item_helpers', true ) ) {
				$out[] = new Lint_Finding( 'L-helper', 'R8', 'P1', $f, 0, 'uses item_helpers without a class-level @property' );
			}
		}
	}
	foreach ( $ctx->dirs() as $dir ) {
		foreach ( $ctx->integration_files( $dir ) as $f ) {
			if ( ! $ctx->has( $f, '\$this->helpers *= *new' ) ) {
				$out[] = new Lint_Finding( 'L-helper', 'R8', 'report', $f, 0, 'no $this->helpers assignment found; it belongs in setup()' );
			}
		}
	}
	return $out;
}

/**
 * L-login (R9): never set_is_login_required( true ); ( false ) is inert on anonymous triggers.
 *
 * @param Lint_Context $ctx
 *
 * @return Lint_Finding[]
 */
function lint_check_login( Lint_Context $ctx ) {
	$out = lint_from_hits(
		$ctx->grep( 'set_is_login_required\( *true', lint_all_files( $ctx ) ),
		'L-login',
		'R9',
		'P1',
		'set_is_login_required( true ) is never used',
		true
	);
	foreach ( lint_files_in( $ctx, 'triggers' ) as $f ) {
		if ( $ctx->is_anonymous_trigger( $f ) ) {
			$out = array_merge( $out, lint_from_hits( $ctx->grep( 'set_is_login_required\( *false', array( $f ) ), 'L-login', 'R9', 'P2', 'inert on an anonymous trigger: remove it', true ) );
		}
	}
	return $out;
}

/**
 * L-sentence (R10): the sentence subject matches the trigger type (heuristic).
 *
 * @param Lint_Context $ctx
 *
 * @return Lint_Finding[]
 */
function lint_check_sentence( Lint_Context $ctx ) {
	$out = array();
	foreach ( lint_files_in( $ctx, 'triggers' ) as $f ) {
		if ( $ctx->is_anonymous_trigger( $f ) ) {
			$out = array_merge( $out, lint_from_hits( $ctx->grep( "esc_html_x\( *['\"](A|An|The) user ", array( $f ) ), 'L-sentence', 'R10', 'P1', 'user-subject sentence on an anonymous trigger' ) );
		} else {
			$out = array_merge( $out, lint_from_hits( $ctx->grep( "esc_html_x\( *['\"](A|An) (guest|visitor) [a-z]+s ", array( $f ) ), 'L-sentence', 'R10', 'P2', 'logged-out actor on a user trigger' ) );
		}
	}
	return $out;
}

/**
 * L-tokens (R11): action tokens come from define_tokens(), never set_action_tokens().
 *
 * @param Lint_Context $ctx
 *
 * @return Lint_Finding[]
 */
function lint_check_tokens( Lint_Context $ctx ) {
	return lint_from_hits( $ctx->grep( 'set_action_tokens', lint_all_files( $ctx ) ), 'L-tokens', 'R11', 'P1', 'set_action_tokens(): use define_tokens()' );
}

/**
 * L-remote (R14): remote data handlers and field configs; actions use _strict segments and never offer "Any".
 *
 * @param Lint_Context $ctx
 *
 * @return Lint_Finding[]
 */
function lint_check_remote( Lint_Context $ctx ) {
	$files = lint_all_files( $ctx );
	$out   = lint_from_hits( $ctx->grep( "'ajax' *=>|wp_ajax_|is_ajax|fill_values_in", $files ), 'L-remote', 'R14', 'P1', 'legacy AJAX field or handler: use remote data' );
	$out   = array_merge( $out, lint_from_hits( $ctx->grep( '(public|private) +function +remote_data_get_', $files ), 'L-remote', 'R14', 'P1', 'remote_data_get_* must be protected' ) );
	$out   = array_merge( $out, lint_from_hits( $ctx->grep( 'function remote_data_get_[a-z_0-9]+\( *Remote_Data_Request', $files ), 'L-remote', 'R14', 'P1', '$request must be untyped' ) );
	$acts  = lint_files_in( $ctx, 'actions' );
	foreach ( $ctx->grep( "remote_data_(load|parent|search)_config\( *'[a-z_0-9]+'", $acts ) as $h ) {
		if ( ! preg_match( "~_strict'~", $h[2] ) ) {
			$out[] = new Lint_Finding( 'L-remote', 'R14', 'P1', $h[0], $h[1], 'action on a non-strict segment' );
		}
	}
	$out = array_merge( $out, lint_from_hits( $ctx->grep( "'value' *=> *'-1'", $acts ), 'L-remote', 'R14', 'P0', '"Any" option in an action' ) );
	return $out;
}

/**
 * L-any (R15): a cast of a selection is listed so a person confirms the '-1' compare runs first.
 *
 * @param Lint_Context $ctx
 *
 * @return Lint_Finding[]
 */
function lint_check_any( Lint_Context $ctx ) {
	return lint_from_hits(
		$ctx->grep( '(absint|intval)\( *\$(trigger|selected|values|meta)|\(int\) *\$(trigger|selected)', lint_all_files( $ctx ) ),
		'L-any',
		'R15',
		'report',
		"confirm the '-1' string compare runs before this cast"
	);
}

/**
 * L-dedupe (R2): trigger state is keyed on $trigger['ID'] and never the object cache.
 *
 * @param Lint_Context $ctx
 *
 * @return Lint_Finding[]
 */
function lint_check_dedupe( Lint_Context $ctx ) {
	$out = array();
	foreach ( lint_files_in( $ctx, 'triggers' ) as $f ) {
		if ( $ctx->has( $f, 'static \$' ) && ! $ctx->has( $f, "\\\$trigger\['ID'\]" ) ) {
			$out[] = new Lint_Finding( 'L-dedupe', 'R2', 'P0', $f, 0, "static state in a trigger without \$trigger['ID'] in its key" );
		}
		$out = array_merge( $out, lint_from_hits( $ctx->grep( 'Automator\(\)->cache|wp_cache_(get|set|add)\(|set_transient\(', array( $f ) ), 'L-dedupe', 'R2', 'P0', 'object cache or transient as trigger state' ) );
	}
	return $out;
}

/**
 * L-bind (R1): user lookups by typed values are listed for judgement; inline admin checks are findings.
 *
 * @param Lint_Context $ctx
 *
 * @return Lint_Finding[]
 */
function lint_check_bind( Lint_Context $ctx ) {
	$files = array_merge( lint_files_in( $ctx, 'triggers' ), lint_files_in( $ctx, 'helpers' ) );
	$out   = lint_from_hits(
		$ctx->grep( "get_user_by\( *'(email|login|slug)'|email_exists\(|username_exists\(", $files ),
		'L-bind',
		'R1',
		'report',
		'user lookup by a typed value: a vetted hook id, an approved record owner through automator_can_bind_user(), or a finding'
	);
	return array_merge( $out, lint_from_hits( $ctx->grep( "user_can\( *[^,]+, *'manage_options'", lint_all_files( $ctx ) ), 'L-bind', 'R1', 'P1', 'inline admin check: use automator_can_bind_user()' ) );
}

// ---------------------------------------------------------------- Pro

/**
 * L-pro (R3): the Pro helper composes Free and wraps every public Free method and segment.
 *
 * @param Lint_Context $ctx
 *
 * @return Lint_Finding[]
 */
function lint_check_pro( Lint_Context $ctx ) {
	if ( null === $ctx->pro ) {
		return array();
	}
	$out         = array();
	$free_helper = $ctx->helper_file( $ctx->free );
	$pro_helper  = $ctx->helper_file( $ctx->pro, true );
	$pro_parts   = array_merge( $ctx->php_files( $ctx->pro, 'triggers' ), $ctx->php_files( $ctx->pro, 'actions' ), $ctx->php_files( $ctx->pro, 'conditions' ), $ctx->php_files( $ctx->pro, 'loop-filters' ) );
	$uses_helper = ! empty( $ctx->grep( 'item_helpers', $pro_parts ) );

	foreach ( $ctx->integration_files( $ctx->pro ) as $f ) {
		foreach ( $ctx->grep( 'new [A-Za-z_]+_Helpers\(', array( $f ) ) as $h ) {
			if ( false === strpos( $h[2], 'Pro_Helpers' ) ) {
				$out[] = new Lint_Finding( 'L-pro', 'R3', 'P1', $h[0], $h[1], "Pro integration injects Free's helper" );
			}
		}
	}
	if ( $uses_helper && null === $pro_helper ) {
		$out[] = new Lint_Finding( 'L-pro', 'R3', 'P0', $ctx->pro . '/helpers', 0, 'Pro parts use a helper but no {Name}_Pro_Helpers exists' );
	}
	if ( null === $pro_helper ) {
		return $out;
	}
	if ( ! $ctx->has( $pro_helper, 'extends Abstract_Pro_Helpers' ) ) {
		$out[] = new Lint_Finding( 'L-pro', 'R3', 'P1', $pro_helper, 0, 'Pro helper must extend Abstract_Pro_Helpers' );
	}
	if ( ! $ctx->has( $pro_helper, '\$this->base *= *new' ) ) {
		$out[] = new Lint_Finding( 'L-pro', 'R3', 'P1', $pro_helper, 0, 'no $this->base = new {Name}_Helpers()' );
	}
	$out          = array_merge( $out, lint_from_hits( $ctx->grep( 'method_exists|function __call', array( $pro_helper ) ), 'L-pro', 'R3', 'P1', 'guard in the Pro helper' ) );
	$forwards_all = $ctx->has_method( $pro_helper, 'process_remote_data_request' );
	if ( $forwards_all && $ctx->has( $pro_helper, 'function remote_data_get_' ) ) {
		$out[] = new Lint_Finding( 'L-pro', 'R3', 'P1', $pro_helper, 0, 'process_remote_data_request() override alongside Pro-owned segments' );
	}
	if ( null === $free_helper ) {
		return $out;
	}
	$pro_methods = array_column( $ctx->class_info( $pro_helper )['methods'], 'name' );
	foreach ( $ctx->class_info( $free_helper )['methods'] as $m ) {
		if ( 'public' !== $m['visibility'] || 0 === strpos( $m['name'], '__' ) || in_array( $m['name'], $pro_methods, true ) ) {
			continue;
		}
		if ( 0 === strpos( $m['name'], 'remote_data_get_' ) ) {
			if ( ! $forwards_all ) {
				$out[] = new Lint_Finding( 'L-pro', 'R3', 'P0', $pro_helper, 0, "no wrapper for Free segment {$m['name']}()" );
			}
			continue;
		}
		$out[] = new Lint_Finding( 'L-pro', 'R3', 'P1', $pro_helper, 0, "no wrapper for Free method {$m['name']}()" );
	}
	foreach ( $ctx->class_info( $free_helper )['methods'] as $m ) {
		if ( 'protected' === $m['visibility'] && 0 === strpos( $m['name'], 'remote_data_get_' ) && ! in_array( $m['name'], $pro_methods, true ) && ! $forwards_all ) {
			$out[] = new Lint_Finding( 'L-pro', 'R3', 'P0', $pro_helper, 0, "no wrapper for Free segment {$m['name']}()" );
		}
	}
	if ( $ctx->has( $free_helper, 'remote_data_(load|parent|search)_config' ) && ! $ctx->has( $pro_helper, 'base->set_remote_data_id' ) ) {
		$out[] = new Lint_Finding( 'L-pro', 'R3', 'P0', $pro_helper, 0, 'Free builds remote_data fields but set_remote_data_id() is not passed to $base' );
	}
	$free_class = $ctx->class_name( $free_helper );
	if ( '' !== $free_class ) {
		$out = array_merge( $out, lint_from_hits( $ctx->grep( preg_quote( $free_class, '~' ) . '::|new ' . preg_quote( $free_class, '~' ) . '\(|@property [^ ]*' . preg_quote( $free_class, '~' ) . ' \$item_helpers', $pro_parts ), 'L-pro', 'R3', 'P1', "Pro part names Free's helper" ) );
	}
	return $out;
}

/**
 * L-ns (R16): conditions in \Conditions, loop filters in \Loop_Filters, built in load().
 *
 * @param Lint_Context $ctx
 *
 * @return Lint_Finding[]
 */
function lint_check_ns( Lint_Context $ctx ) {
	if ( null === $ctx->pro ) {
		return array();
	}
	$out = array();
	foreach ( $ctx->php_files( $ctx->pro, 'conditions' ) as $f ) {
		if ( ! preg_match( '~\\\\Conditions$~', $ctx->class_info( $f )['namespace'] ) ) {
			$out[] = new Lint_Finding( 'L-ns', 'R16', 'P1', $f, 0, 'condition outside \\Conditions' );
		}
	}
	foreach ( $ctx->php_files( $ctx->pro, 'loop-filters' ) as $f ) {
		if ( ! preg_match( '~\\\\Loop_Filters$~', $ctx->class_info( $f )['namespace'] ) ) {
			$out[] = new Lint_Finding( 'L-ns', 'R16', 'P1', $f, 0, 'loop filter outside \\Loop_Filters' );
		}
		if ( ! $ctx->has( $f, 'set_loop_type\(' ) ) {
			$out[] = new Lint_Finding( 'L-ns', 'R16', 'report', $f, 0, 'no set_loop_type(): defaults to users' );
		}
	}
	$both = array_merge( $ctx->php_files( $ctx->pro, 'conditions' ), $ctx->php_files( $ctx->pro, 'loop-filters' ) );
	return array_merge( $out, lint_from_hits( $ctx->grep( '^new [A-Za-z_\\\\]+\(', $both ), 'L-ns', 'R16', 'P1', 'self-instantiation: build it in load() with the helper' ) );
}

/**
 * L-storage (R13): reads of another plugin's comments are listed; its functions are the source.
 *
 * @param Lint_Context $ctx
 *
 * @return Lint_Finding[]
 */
function lint_check_storage( Lint_Context $ctx ) {
	return lint_from_hits( $ctx->grep( 'get_comments\(|WP_Comment_Query|get_comment_meta\(', lint_all_files( $ctx ) ), 'L-storage', 'R13', 'report', "read of another plugin's comments: use its function" );
}

// ---------------------------------------------------------------- integration class

/**
 * L-dispatch (R17): dispatchers/ with boot() and reset_boot(); hooks registered in the integration class are listed.
 *
 * @param Lint_Context $ctx
 *
 * @return Lint_Finding[]
 */
function lint_check_dispatch( Lint_Context $ctx ) {
	$out = array();
	foreach ( $ctx->dirs() as $dir ) {
		if ( is_dir( $dir . '/handlers' ) ) {
			$out[] = new Lint_Finding( 'L-dispatch', 'R17', 'P1', $dir . '/handlers', 0, 'handlers/ folder: use dispatchers/' );
		}
		foreach ( $ctx->php_files( $dir, 'dispatchers' ) as $f ) {
			if ( ! $ctx->has_method( $f, 'boot' ) || ! $ctx->has_method( $f, 'reset_boot' ) ) {
				$out[] = new Lint_Finding( 'L-dispatch', 'R17', 'P1', $f, 0, 'dispatcher without boot() and reset_boot()' );
			}
		}
		foreach ( $ctx->integration_files( $dir ) as $f ) {
			$out = array_merge( $out, lint_from_hits( $ctx->grep( 'add_action\(|add_filter\(', array( $f ) ), 'L-dispatch', 'R17', 'report', 'hook registered in the integration class: run-time in load_shared_hooks(), builder in load(), never setup()' ) );
		}
	}
	return $out;
}

/**
 * L-active (R20): plugin_active() never calls is_plugin_active().
 *
 * @param Lint_Context $ctx
 *
 * @return Lint_Finding[]
 */
function lint_check_active( Lint_Context $ctx ) {
	$out = array();
	foreach ( $ctx->dirs() as $dir ) {
		$out = array_merge( $out, lint_from_hits( $ctx->grep( 'is_plugin_active\(', $ctx->integration_files( $dir ) ), 'L-active', 'R20', 'P1', 'plugin_active() must not use is_plugin_active()' ) );
	}
	return $out;
}

/**
 * L-manifest (R19): the four metadata setters, identical in Free and Pro, never open_source.
 *
 * @param Lint_Context $ctx
 *
 * @return Lint_Finding[]
 */
function lint_check_manifest( Lint_Context $ctx ) {
	$out     = array();
	$setters = array( 'set_plugin_file_path', 'set_developer_name', 'set_integration_type', 'set_distribution_type' );
	$values  = array();
	foreach ( $ctx->dirs() as $dir ) {
		$files = $ctx->integration_files( $dir );
		foreach ( $setters as $setter ) {
			$hits = $ctx->grep( $setter . "\( *'[^']*'", $files );
			if ( empty( $hits ) ) {
				$out[] = new Lint_Finding( 'L-manifest', 'R19', 'P1', $dir, 0, "setup() lacks $setter()" );
				continue;
			}
			preg_match( "~$setter\( *'([^']*)'~", $hits[0][2], $m );
			$values[ $setter ][ $dir ] = $m[1];
		}
		$out = array_merge( $out, lint_from_hits( $ctx->grep( "set_distribution_type\( *'open_source'", $files ), 'L-manifest', 'R19', 'P1', 'open_source is never used' ) );
	}
	if ( null !== $ctx->pro ) {
		foreach ( $values as $setter => $per_dir ) {
			if ( isset( $per_dir[ $ctx->free ], $per_dir[ $ctx->pro ] ) && $per_dir[ $ctx->free ] !== $per_dir[ $ctx->pro ] ) {
				$out[] = new Lint_Finding( 'L-manifest', 'R19', 'P1', $ctx->pro, 0, "$setter differs: Free '{$per_dir[ $ctx->free ]}' · Pro '{$per_dir[ $ctx->pro ]}'" );
			}
		}
	}
	return $out;
}

/**
 * L-i18n (R21): user-facing strings, log errors and condition failures go through esc_html_x().
 *
 * @param Lint_Context $ctx
 *
 * @return Lint_Finding[]
 */
function lint_check_i18n( Lint_Context $ctx ) {
	$files = lint_all_files( $ctx );
	$out   = lint_from_hits( $ctx->grep( "add_log_error\( *(sprintf\( *)?'", $files ), 'L-i18n', 'R21', 'P2', 'untranslated add_log_error()' );
	$out   = array_merge( $out, lint_from_hits( $ctx->grep( "condition_failed\( *(sprintf\( *)?'", $files ), 'L-i18n', 'R21', 'P2', 'untranslated condition_failed()' ) );
	return array_merge( $out, lint_from_hits( $ctx->grep( "esc_html__\(|[^a-z_]__\( *'|_e\( *'", $files ), 'L-i18n', 'R21', 'P2', 'use esc_html_x() with the integration context' ) );
}

/**
 * L-sec (R22): no raw superglobals, no unserialize on foreign data, no own endpoints, prepared queries.
 *
 * @param Lint_Context $ctx
 *
 * @return Lint_Finding[]
 */
function lint_check_sec( Lint_Context $ctx ) {
	$files = lint_all_files( $ctx );
	$out   = lint_from_hits( $ctx->grep( '\$_(POST|GET|REQUEST)\[', $files ), 'L-sec', 'R22', 'P0', 'raw superglobal read' );
	$out   = array_merge( $out, lint_from_hits( $ctx->grep( 'maybe_unserialize\(|[^_a-z]unserialize\(', $files ), 'L-sec', 'R22', 'P0', 'use automator_safe_unserialize()' ) );
	foreach ( $ctx->grep( '\$wpdb->(query|get_results|get_var|get_col|get_row)\(', $files ) as $h ) {
		if ( false === strpos( $h[2], 'prepare' ) ) {
			$out[] = new Lint_Finding( 'L-sec', 'R22', 'report', $h[0], $h[1], 'direct query: confirm the next lines call prepare(), or justify the raw SQL' );
		}
	}
	$out = array_merge( $out, lint_from_hits( $ctx->grep( 'register_rest_route', $files ), 'L-sec', 'R22', 'P1', 'integration registers its own endpoint' ) );
	$out = array_merge( $out, lint_from_hits( $ctx->grep( '[^_]filter_input\(', $files ), 'L-sec', 'R22', 'P2', 'use automator_filter_input()' ) );
	foreach ( $ctx->grep( '(?i)card|cvv|passw|secret|otp', $files ) as $h ) {
		if ( preg_match( '~(?i)token|hydrate~', $h[2] ) ) {
			$out[] = new Lint_Finding( 'L-sec', 'R22', 'report', $h[0], $h[1], 'secret-like value near tokens: confirm it is masked' );
		}
	}
	return $out;
}

/**
 * L-hygiene (R25): no upstream file:line citations in src, helpers under 1,000 lines, files that parse.
 *
 * @param Lint_Context $ctx
 *
 * @return Lint_Finding[]
 */
function lint_check_hygiene( Lint_Context $ctx ) {
	$files = lint_all_files( $ctx );
	$out   = lint_from_hits( $ctx->grep( '\.php:[0-9]+', $files, true ), 'L-hygiene', 'R25', 'P2', 'upstream file:line citation in src' );
	foreach ( lint_files_in( $ctx, 'helpers' ) as $f ) {
		$n = substr_count( $ctx->read( $f ), "\n" ) + 1;
		if ( $n > 1000 ) {
			$out[] = new Lint_Finding( 'L-hygiene', 'R25', 'P2', $f, 0, "$n lines: split by concern" );
		}
	}
	foreach ( $files as $f ) {
		try {
			token_get_all( $ctx->read( $f ), TOKEN_PARSE ); // In-process syntax check; no php -l subprocess per file.
		} catch ( ParseError $e ) {
			$out[] = new Lint_Finding( 'L-hygiene', 'R25', 'P0', $f, $e->getLine(), 'syntax error: ' . $e->getMessage() );
		}
	}
	return $out;
}

/**
 * L-tests (R26): a test file per part, stubs that name their source, no assertTrue( true ).
 *
 * @param Lint_Context $ctx
 *
 * @return Lint_Finding[]
 */
function lint_check_tests( Lint_Context $ctx ) {
	$out   = array();
	$pairs = array( array( $ctx->free_repo, $ctx->free ) );
	if ( null !== $ctx->pro ) {
		$pairs[] = array( $ctx->pro_repo, $ctx->pro );
	}
	foreach ( $pairs as $pair ) {
		list( $repo, $src ) = $pair;
		$tdir               = $repo . '/tests/wpunit/integrations/' . $ctx->slug;
		if ( ! is_dir( $tdir ) ) {
			$out[] = new Lint_Finding( 'L-tests', 'R26', 'P1', $tdir, 0, 'no test folder' );
			continue;
		}
		$tests = $ctx->php_files( $tdir );
		$names = array_map( 'basename', $tests );
		foreach ( $ctx->parts( $src ) as $f ) {
			$cls = $ctx->class_name( $f );
			if ( '' !== $cls && ! in_array( $cls . '_Test.php', $names, true ) ) {
				$out[] = new Lint_Finding( 'L-tests', 'R26', 'P2', $f, 0, "no {$cls}_Test.php" );
			}
		}
		$out = array_merge( $out, lint_from_hits( $ctx->grep( 'assertTrue\( *true *\)', $tests ), 'L-tests', 'R26', 'P1', 'assertTrue( true )' ) );
		foreach ( $tests as $t ) {
			if ( false !== stripos( basename( $t ), 'stub' ) && ! $ctx->has( $t, '(?i)version|verified against|\.php:[0-9]+', true ) ) {
				$out[] = new Lint_Finding( 'L-tests', 'R26', 'P2', $t, 0, 'stub without the plugin version and file:line it copies' );
			}
		}
	}
	return $out;
}

/**
 * L-scope (R0): the scope doc's ClickUp tasks table and the built items match in both directions.
 *
 * Every signed-off row is built, and every built trigger, action, condition and loop filter has a
 * row. A missing or duplicate doc is judged in lint-integration.php, which knows whether the
 * integration is new.
 *
 * @param Lint_Context $ctx
 *
 * @return Lint_Finding[]
 */
function lint_check_scope( Lint_Context $ctx ) {
	if ( '' === $ctx->scope_doc || ! is_file( $ctx->scope_doc ) ) {
		return array();
	}
	$doc = file_get_contents( $ctx->scope_doc );
	if ( ! preg_match( '~^## ClickUp tasks\s*$(.*?)(^## |^---\s*$|\z)~ms', $doc, $m ) ) {
		return array( new Lint_Finding( 'L-scope', 'R0', 'P1', $ctx->scope_doc, 0, 'no ClickUp tasks table in the scope doc: nothing is signed off, nothing may be built (R0)' ) );
	}
	$signed = array();
	foreach ( explode( "\n", $m[1] ) as $row ) {
		// | 🔴 [T] A user is approved (Free) | https://app.clickup.com/t/… |
		if ( preg_match( '~^\| *[🔴🟠🟡🟢⚠️]+ *(?:\[[TACL]\] *)?([^|]+?) *(?:\((Free|Pro)\))? *\|~u', $row, $r ) ) {
			// Without a Pro checkout the Pro rows cannot be verified either way.
			if ( null === $ctx->pro && isset( $r[2] ) && 'Pro' === $r[2] ) {
				continue;
			}
			$signed[ lint_normalize_sentence( $r[1] ) ] = trim( $r[1] );
		}
	}
	$built = array();
	$parts = $ctx->parts( $ctx->free );
	if ( null !== $ctx->pro ) {
		$parts = array_merge( $parts, $ctx->parts( $ctx->pro ) );
	}
	// Readable sentences of triggers and actions, the name of a condition, the sentence of a loop filter;
	// the call may span lines, so the comment-stripped file is matched, not its lines.
	foreach ( $parts as $file ) {
		if ( ! preg_match_all( '~(?:set_readable_sentence\(|set_sentence\(|->name\s*=)\s*esc_html_x\(\s*([\'"])(.*?)\1~s', $ctx->code( $file ), $all ) ) {
			continue;
		}
		foreach ( $all[2] as $sentence ) {
			$norm = lint_normalize_sentence( $sentence );
			if ( '' !== $norm && ! isset( $built[ $norm ] ) ) {
				$built[ $norm ] = $file;
			}
		}
	}
	$out = array();
	foreach ( $signed as $norm => $sentence ) {
		if ( ! isset( $built[ $norm ] ) ) {
			$out[] = new Lint_Finding( 'L-scope', 'R0', 'P1', $ctx->scope_doc, 0, "signed-off item not built: $norm" );
		}
	}
	foreach ( $built as $norm => $file ) {
		if ( ! isset( $signed[ $norm ] ) ) {
			$out[] = new Lint_Finding( 'L-scope', 'R0', 'P1', $file, 0, "built item is not in the ClickUp tasks table: $norm (a task is the lead's sign-off, R0)" );
		}
	}
	return $out;
}

/**
 * @param string $s
 *
 * @return string A sentence stripped of codes and case for loose matching.
 */
function lint_normalize_sentence( $s ) {
	$s = preg_replace( '~:%\d+\$s~', '', $s );
	$s = preg_replace( '~[^a-z0-9 {}/]~i', '', $s );
	return strtolower( trim( preg_replace( '~\s+~', ' ', $s ) ) );
}
