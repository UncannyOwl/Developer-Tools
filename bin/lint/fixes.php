<?php
// phpcs:disable WordPress.WP.AlternativeFunctions, WordPress.Security.EscapeOutput, WordPress.PHP.DiscouragedPHPFunctions, WordPress.PHP.NoSilencedErrors -- CLI tool that runs outside WordPress.
/**
 * --fix: the mechanical corrections the lint can make safely. Everything else is a person's job.
 *
 * L-login: remove a set_is_login_required( true ) line, and a ( false ) line on an anonymous trigger.
 * L-hookconst: replace Class::CONST or self::CONST with the string literal the constant holds,
 *              when that constant is declared with a literal in the same integration (Free or Pro).
 */

/**
 * @param Lint_Context   $ctx
 * @param Lint_Finding[] $findings
 *
 * @return int Number of fixes applied.
 */
function lint_apply_fixes( Lint_Context $ctx, array $findings ) {
	$applied = 0;
	foreach ( $findings as $f ) {
		if ( ! $f->fixable ) {
			continue;
		}
		if ( 'L-login' === $f->check ) {
			$applied += lint_fix_remove_line( $ctx, $f->file, $f->line, 'set_is_login_required' );
		} elseif ( 'L-hookconst' === $f->check && ! empty( $f->data['ref'] ) ) {
			$applied += lint_fix_inline_constant( $ctx, $f->file, $f->line, $f->data['ref'] );
		}
	}
	return $applied;
}

/**
 * Remove one line when it still contains the marker (the file may have moved since the check ran).
 *
 * @param Lint_Context $ctx
 * @param string       $file
 * @param int          $line   1-based.
 * @param string       $marker
 *
 * @return int
 */
function lint_fix_remove_line( Lint_Context $ctx, $file, $line, $marker ) {
	$lines = explode( "\n", $ctx->read( $file ) );
	$i     = $line - 1;
	if ( ! isset( $lines[ $i ] ) || false === strpos( $lines[ $i ], $marker ) ) {
		return 0;
	}
	array_splice( $lines, $i, 1 );
	$ctx->write( $file, implode( "\n", $lines ) );
	return 1;
}

/**
 * @param Lint_Context $ctx
 * @param string       $file
 * @param int          $line
 * @param string       $ref  e.g. Foo_Dispatcher::HOOK or self::HOOK.
 *
 * @return int
 */
function lint_fix_inline_constant( Lint_Context $ctx, $file, $line, $ref ) {
	list( $class_name, $const_name ) = explode( '::', $ref, 2 );
	$class_name                      = ltrim( $class_name, '\\' );
	if ( in_array( $class_name, array( 'self', 'static' ), true ) ) {
		$class_name = $ctx->class_name( $file );
	}
	$literal = lint_find_constant_literal( $ctx, $class_name, $const_name );
	if ( null === $literal ) {
		return 0;
	}
	$lines = explode( "\n", $ctx->read( $file ) );
	$i     = $line - 1;
	if ( ! isset( $lines[ $i ] ) || false === strpos( $lines[ $i ], $ref ) ) {
		return 0;
	}
	$lines[ $i ] = str_replace( $ref, "'" . $literal . "'", $lines[ $i ] );
	$ctx->write( $file, implode( "\n", $lines ) );
	return 1;
}

/**
 * Find `const NAME = 'literal';` inside the named class, anywhere in the integration.
 *
 * @param Lint_Context $ctx
 * @param string       $class_name Short class name (namespace ignored).
 * @param string       $const_name
 *
 * @return string|null
 */
function lint_find_constant_literal( Lint_Context $ctx, $class_name, $const_name ) {
	$short = ( false !== strpos( $class_name, '\\' ) ) ? substr( $class_name, strrpos( $class_name, '\\' ) + 1 ) : $class_name;
	foreach ( $ctx->dirs() as $dir ) {
		foreach ( $ctx->php_files( $dir ) as $f ) {
			$names = array_column( $ctx->class_info( $f )['classes'], 'name' );
			if ( ! in_array( $short, $names, true ) ) {
				continue;
			}
			if ( preg_match( '~const\s+' . preg_quote( $const_name, '~' ) . "\s*=\s*'([^']*)'\s*;~", $ctx->read( $f ), $m ) ) {
				return $m[1];
			}
		}
	}
	return null;
}
