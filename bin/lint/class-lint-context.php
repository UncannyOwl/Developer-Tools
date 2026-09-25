<?php
// phpcs:disable WordPress.WP.AlternativeFunctions, WordPress.Security.EscapeOutput, WordPress.PHP.DiscouragedPHPFunctions, WordPress.PHP.NoSilencedErrors -- CLI tool that runs outside WordPress.
/**
 * Everything a lint check needs to know about one integration: its folders in Free and Pro,
 * the files under them, their contents, and a tokenizer view of classes and methods.
 */

class Lint_Context {

	/** @var string */
	public $slug;
	/** @var string */
	public $free_repo;
	/** @var string|null */
	public $pro_repo;
	/** @var string */
	public $free;
	/** @var string|null */
	public $pro;
	/** @var string */
	public $scope_doc;

	/** @var array<string,string> */
	private $contents = array();
	/** @var array<string,string> */
	private $code = array();
	/** @var array<string,array> */
	private $class_info = array();

	/**
	 * @param string      $slug
	 * @param string      $free_repo Plugin root of Free.
	 * @param string|null $pro_repo  Plugin root of Pro, or null.
	 * @param string      $scope_doc Path to the scope doc, or ''.
	 */
	public function __construct( $slug, $free_repo, $pro_repo, $scope_doc = '' ) {
		$this->slug      = $slug;
		$this->free_repo = rtrim( $free_repo, '/' );
		$this->free      = $this->free_repo . '/src/integrations/' . $slug;
		$this->pro_repo  = null;
		$this->pro       = null;
		$this->scope_doc = $scope_doc;

		if ( $pro_repo && is_dir( rtrim( $pro_repo, '/' ) . '/src/integrations/' . $slug ) ) {
			$this->pro_repo = rtrim( $pro_repo, '/' );
			$this->pro      = $this->pro_repo . '/src/integrations/' . $slug;
		}
	}

	/**
	 * @return string[] The Free folder, and the Pro folder when it exists.
	 */
	public function dirs() {
		return null === $this->pro ? array( $this->free ) : array( $this->free, $this->pro );
	}

	/**
	 * @param string $dir
	 *
	 * @return string Which repo root a file or folder belongs to.
	 */
	public function repo_of( $path ) {
		return ( null !== $this->pro_repo && 0 === strpos( $path, $this->pro_repo ) ) ? $this->pro_repo : $this->free_repo;
	}

	/**
	 * @param string $path
	 *
	 * @return string Path relative to its repo root, for reports.
	 */
	public function rel( $path ) {
		$repo = $this->repo_of( $path );
		return 0 === strpos( $path, $repo . '/' ) ? substr( $path, strlen( $repo ) + 1 ) : $path;
	}

	/**
	 * @param string $dir Absolute folder.
	 * @param string $sub Optional sub-folder.
	 *
	 * @return string[] Sorted .php files, recursive.
	 */
	public function php_files( $dir, $sub = '' ) {
		$root = '' === $sub ? $dir : $dir . '/' . $sub;
		if ( ! is_dir( $root ) ) {
			return array();
		}
		$files = array();
		$it    = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ) );
		foreach ( $it as $f ) {
			if ( 'php' === strtolower( $f->getExtension() ) ) {
				$files[] = $f->getPathname();
			}
		}
		sort( $files );
		return $files;
	}

	/**
	 * @param string $dir
	 *
	 * @return string[] Every recipe-part file: triggers, actions, conditions, loop filters, helpers, dispatchers.
	 */
	public function parts( $dir ) {
		$files = array();
		foreach ( array( 'triggers', 'actions', 'conditions', 'loop-filters', 'helpers', 'dispatchers' ) as $sub ) {
			$files = array_merge( $files, $this->php_files( $dir, $sub ) );
		}
		return $files;
	}

	/**
	 * @param string $dir
	 *
	 * @return string[] The *-integration.php files at the folder root.
	 */
	public function integration_files( $dir ) {
		$files = glob( $dir . '/*-integration.php' );
		return false === $files ? array() : $files;
	}

	/**
	 * @param string $dir
	 * @param bool   $pro Whether to look for the Pro helper.
	 *
	 * @return string|null The main helper file.
	 */
	public function helper_file( $dir, $pro = false ) {
		foreach ( $this->php_files( $dir, 'helpers' ) as $f ) {
			$is_pro = (bool) preg_match( '~-pro-helpers\.php$~', $f );
			if ( $pro === $is_pro && preg_match( '~-helpers\.php$~', $f ) ) {
				return $f;
			}
		}
		return null;
	}

	/**
	 * @param string $file
	 *
	 * @return string
	 */
	public function read( $file ) {
		if ( ! isset( $this->contents[ $file ] ) ) {
			$c                       = @file_get_contents( $file );
			$this->contents[ $file ] = false === $c ? '' : $c;
		}
		return $this->contents[ $file ];
	}

	/**
	 * Replace a file's content on disk and in the cache (used by --fix).
	 *
	 * @param string $file
	 * @param string $content
	 */
	public function write( $file, $content ) {
		file_put_contents( $file, $content );
		$this->contents[ $file ] = $content;
		unset( $this->class_info[ $file ], $this->code[ $file ] );
	}

	/**
	 * The file with every comment blanked out, line numbers preserved, so a rule named in a
	 * docblock ("never set_action_tokens()") is not mistaken for a violation.
	 *
	 * @param string $file
	 *
	 * @return string
	 */
	public function code( $file ) {
		if ( isset( $this->code[ $file ] ) ) {
			return $this->code[ $file ];
		}
		$out = '';
		foreach ( @token_get_all( $this->read( $file ) ) as $t ) {
			if ( is_array( $t ) && in_array( $t[0], array( T_COMMENT, T_DOC_COMMENT ), true ) ) {
				$out .= str_repeat( "\n", substr_count( $t[1], "\n" ) );
			} else {
				$out .= is_array( $t ) ? $t[1] : $t;
			}
		}
		$this->code[ $file ] = $out;
		return $out;
	}

	/**
	 * @param string   $regex         PCRE without delimiters.
	 * @param string[] $files
	 * @param bool     $with_comments Match inside comments too (docblock tags, citations).
	 *
	 * @return array<int,array{0:string,1:int,2:string}> [file, line number, line].
	 */
	public function grep( $regex, $files, $with_comments = false ) {
		$hits = array();
		foreach ( $files as $file ) {
			$source = $with_comments ? $this->read( $file ) : $this->code( $file );
			foreach ( explode( "\n", $source ) as $i => $line ) {
				if ( preg_match( '~' . $regex . '~', $line ) ) {
					$hits[] = array( $file, $i + 1, trim( $line ) );
				}
			}
		}
		return $hits;
	}

	/**
	 * @param string $file
	 * @param string $regex
	 * @param bool   $with_comments
	 *
	 * @return bool
	 */
	public function has( $file, $regex, $with_comments = false ) {
		return (bool) preg_match( '~' . $regex . '~', $with_comments ? $this->read( $file ) : $this->code( $file ) );
	}

	/**
	 * Tokenizer view of a file: namespace, classes (name, parent, line) and methods (name, visibility, static, line).
	 *
	 * @param string $file
	 *
	 * @return array{namespace:string,classes:array,methods:array}
	 */
	public function class_info( $file ) {
		if ( isset( $this->class_info[ $file ] ) ) {
			return $this->class_info[ $file ];
		}
		$info   = array(
			'namespace' => '',
			'classes'   => array(),
			'methods'   => array(),
		);
		$tokens = @token_get_all( $this->read( $file ) );
		$count  = count( $tokens );
		$name_t = array( T_STRING, T_NS_SEPARATOR );
		if ( defined( 'T_NAME_QUALIFIED' ) ) {
			$name_t[] = T_NAME_QUALIFIED;
			$name_t[] = T_NAME_FULLY_QUALIFIED;
		}

		$read_name = function ( $from ) use ( $tokens, $count, $name_t ) {
			$name = '';
			for ( $j = $from; $j < $count; $j++ ) {
				if ( is_array( $tokens[ $j ] ) && T_WHITESPACE === $tokens[ $j ][0] ) {
					if ( '' !== $name ) {
						break;
					}
					continue;
				}
				if ( is_array( $tokens[ $j ] ) && in_array( $tokens[ $j ][0], $name_t, true ) ) {
					$name .= $tokens[ $j ][1];
					continue;
				}
				break;
			}
			return $name;
		};

		for ( $i = 0; $i < $count; $i++ ) {
			$t = $tokens[ $i ];
			if ( ! is_array( $t ) ) {
				continue;
			}
			if ( T_NAMESPACE === $t[0] ) {
				$info['namespace'] = $read_name( $i + 1 );
			} elseif ( T_CLASS === $t[0] ) {
				$prev = $i > 0 ? $tokens[ $i - 1 ] : null;
				if ( is_array( $prev ) && T_DOUBLE_COLON === $prev[0] ) {
					continue; // Foo::class
				}
				$name = $read_name( $i + 1 );
				if ( '' === $name ) {
					continue; // anonymous class
				}
				$extends = '';
				for ( $j = $i + 1; $j < $count && '{' !== $tokens[ $j ]; $j++ ) {
					if ( is_array( $tokens[ $j ] ) && T_EXTENDS === $tokens[ $j ][0] ) {
						$extends = $read_name( $j + 1 );
						break;
					}
				}
				$info['classes'][] = array(
					'name'    => $name,
					'extends' => $extends,
					'line'    => $t[2],
				);
			} elseif ( T_FUNCTION === $t[0] ) {
				$name = $read_name( $i + 1 );
				if ( '' === $name ) {
					continue; // closure
				}
				$visibility = 'public';
				$static     = false;
				for ( $j = $i - 1, $seen = 0; $j >= 0 && $seen < 6; $j-- ) {
					$p = $tokens[ $j ];
					if ( ! is_array( $p ) ) {
						break;
					}
					if ( T_WHITESPACE === $p[0] ) {
						continue;
					}
					++$seen;
					if ( in_array( $p[0], array( T_PUBLIC, T_PROTECTED, T_PRIVATE ), true ) ) {
						$visibility = strtolower( $p[1] );
					} elseif ( T_STATIC === $p[0] ) {
						$static = true;
					} elseif ( ! in_array( $p[0], array( T_ABSTRACT, T_FINAL ), true ) ) {
						break;
					}
				}
				$info['methods'][] = array(
					'name'       => $name,
					'visibility' => $visibility,
					'static'     => $static,
					'line'       => $t[2],
				);
			}
		}

		$this->class_info[ $file ] = $info;
		return $info;
	}

	/**
	 * @param string $file
	 * @param string $method
	 *
	 * @return bool
	 */
	public function has_method( $file, $method ) {
		foreach ( $this->class_info( $file )['methods'] as $m ) {
			if ( $m['name'] === $method ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * @param string $file
	 *
	 * @return string The first class name declared in the file, or ''.
	 */
	public function class_name( $file ) {
		$classes = $this->class_info( $file )['classes'];
		return empty( $classes ) ? '' : $classes[0]['name'];
	}

	/**
	 * @param string $file
	 *
	 * @return bool Whether a trigger file declares an anonymous type.
	 */
	public function is_anonymous_trigger( $file ) {
		return $this->has( $file, "trigger_type\( *'anonymous' *\)" );
	}
}
