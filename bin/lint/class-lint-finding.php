<?php
// phpcs:disable WordPress.WP.AlternativeFunctions, WordPress.Security.EscapeOutput, WordPress.PHP.DiscouragedPHPFunctions, WordPress.PHP.NoSilencedErrors -- CLI tool that runs outside WordPress.
/**
 * One lint finding. Severity is P0, P1, P2 or report; only P0 and P1 fail a run.
 */

class Lint_Finding {

	/** @var string */
	public $check;
	/** @var string */
	public $rule;
	/** @var string */
	public $severity;
	/** @var string */
	public $file;
	/** @var int */
	public $line;
	/** @var string */
	public $message;
	/** @var bool */
	public $fixable;
	/** @var array Extra data a fixer needs. */
	public $data;
	/** @var bool In --changed mode: the finding sits outside the changed lines of an existing integration. */
	public $preexisting = false;

	/**
	 * @param string $check    L-id.
	 * @param string $rule     R-id.
	 * @param string $severity P0 | P1 | P2 | report.
	 * @param string $file     Absolute path (a folder is allowed).
	 * @param int    $line     Line number, 0 for a whole file.
	 * @param string $message
	 * @param bool   $fixable
	 * @param array  $data
	 */
	public function __construct( $check, $rule, $severity, $file, $line, $message, $fixable = false, $data = array() ) {
		$this->check    = $check;
		$this->rule     = $rule;
		$this->severity = $severity;
		$this->file     = $file;
		$this->line     = (int) $line;
		$this->message  = $message;
		$this->fixable  = $fixable;
		$this->data     = $data;
	}

	/**
	 * @return bool Whether this finding fails the run.
	 */
	public function blocks() {
		return ! $this->preexisting && in_array( $this->severity, array( 'P0', 'P1' ), true );
	}

	/**
	 * @param Lint_Context $ctx
	 *
	 * @return array
	 */
	public function to_array( Lint_Context $ctx ) {
		return array(
			'check'       => $this->check,
			'rule'        => $this->rule,
			'severity'    => $this->severity,
			'file'        => $this->file,
			'path'        => $ctx->rel( $this->file ),
			'line'        => $this->line,
			'message'     => $this->message,
			'fixable'     => $this->fixable,
			'preexisting' => $this->preexisting,
		);
	}
}
