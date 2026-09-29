<?php
/**
 * Controlled execution for trusted PHP snippets.
 *
 * @package MCSnippetManager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class MCSM_PHP_Executor {
	/**
	 * Fatal PHP error types that can be observed during shutdown.
	 */
	const FATAL_ERROR_TYPES = array( E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR, E_RECOVERABLE_ERROR );

	/**
	 * Per-request validation cache, keyed by code hash.
	 *
	 * @var array
	 */
	private static $validation_cache = array();

	/**
	 * Snippet currently executing, used by the shutdown recovery handler.
	 *
	 * @var array|null
	 */
	private static $executing_snippet = null;

	/**
	 * Whether the shutdown recovery handler has been registered.
	 *
	 * @var bool
	 */
	private static $shutdown_registered = false;

	/**
	 * Reserved memory released during shutdown so recovery can still run after
	 * a memory exhaustion error.
	 *
	 * @var string|null
	 */
	private static $recovery_memory = null;

	/**
	 * Database advisory lock held while a PHP snippet executes.
	 *
	 * @var string
	 */
	private static $recovery_lock = '';

	/**
	 * Persistent marker removed only after successful or caught execution.
	 *
	 * @var string
	 */
	private static $recovery_option = '';

	/**
	 * Check whether PHP snippet execution has been disabled in wp-config.php.
	 *
	 * @return bool
	 */
	public static function is_disabled() {
		return defined( 'MCSM_DISABLE_PHP_SNIPPETS' ) && MCSM_DISABLE_PHP_SNIPPETS;
	}

	/**
	 * Validate PHP snippet code without executing it.
	 *
	 * @param string $code PHP code without tags.
	 * @return true|WP_Error
	 */
	public static function validate( $code ) {
		$code = (string) $code;
		$key  = hash( 'sha256', $code );

		if ( isset( self::$validation_cache[ $key ] ) ) {
			return self::$validation_cache[ $key ];
		}

		if ( false !== strpos( $code, '<?' ) || false !== strpos( $code, '?>' ) ) {
			self::$validation_cache[ $key ] = new WP_Error( 'mcsm_php_tags', __( 'PHP snippets must not include opening or closing PHP tags.', 'mcsm' ) );
			return self::$validation_cache[ $key ];
		}

		$tokens             = token_get_all( "<?php\n" . $code );
		$blocked_tokens     = array( T_EXIT, T_HALT_COMPILER, T_NAMESPACE, T_GOTO, T_INTERFACE, T_TRAIT, T_EVAL, T_INCLUDE, T_INCLUDE_ONCE, T_REQUIRE, T_REQUIRE_ONCE );
		$declaration_tokens = array( T_FUNCTION );

		if ( defined( 'T_ENUM' ) ) {
			$blocked_tokens[] = constant( 'T_ENUM' );
		}

		foreach ( $tokens as $index => $token ) {
			if ( ! is_array( $token ) ) {
				continue;
			}

			if ( in_array( $token[0], $blocked_tokens, true ) ) {
				self::$validation_cache[ $key ] = new WP_Error( 'mcsm_php_blocked_construct', __( 'PHP snippets cannot use exit, eval, file includes, namespaces, goto, or class-like declarations.', 'mcsm' ) );
				return self::$validation_cache[ $key ];
			}

			if ( T_CLASS === $token[0] && ! self::is_class_constant( $tokens, $index ) ) {
				self::$validation_cache[ $key ] = new WP_Error( 'mcsm_php_blocked_construct', __( 'PHP snippets cannot use exit, eval, file includes, namespaces, goto, or class-like declarations.', 'mcsm' ) );
				return self::$validation_cache[ $key ];
			}

			if ( in_array( $token[0], $declaration_tokens, true ) && self::has_named_function( $tokens, $index + 1 ) ) {
				self::$validation_cache[ $key ] = new WP_Error( 'mcsm_php_named_function', __( 'Use anonymous callbacks instead of named functions to avoid conflicts.', 'mcsm' ) );
				return self::$validation_cache[ $key ];
			}
		}

		try {
			eval( "return;\n" . $code ); // phpcs:ignore Squiz.PHP.Eval.Discouraged
		} catch ( Throwable $error ) {
			self::$validation_cache[ $key ] = new WP_Error( 'mcsm_php_parse_error', $error->getMessage() );
			return self::$validation_cache[ $key ];
		}

		self::$validation_cache[ $key ] = true;
		return self::$validation_cache[ $key ];
	}

	/**
	 * Execute a validated PHP snippet and discard accidental direct output.
	 *
	 * @param array $snippet Snippet record.
	 * @return bool
	 */
	public static function execute( $snippet ) {
		if ( self::is_disabled() ) {
			return false;
		}

		$code       = (string) ( $snippet['code'] ?? '' );
		$validation = self::validate( $code );

		if ( is_wp_error( $validation ) ) {
			self::report_error( $snippet, $validation->get_error_message() );
			self::deactivate_snippet( $snippet, $validation->get_error_message() );
			return false;
		}

		if ( ! self::prepare_recovery( $snippet ) ) {
			return false;
		}

		$buffer_level = ob_get_level();
		ob_start();

		try {
			( static function () use ( $code ) {
				eval( $code ); // phpcs:ignore Squiz.PHP.Eval.Discouraged
			} )();
		} catch ( Throwable $error ) {
			self::finish_recovery();
			while ( ob_get_level() > $buffer_level ) {
				ob_end_clean();
			}
			self::report_error( $snippet, $error->getMessage() );
			self::deactivate_snippet( $snippet, $error->getMessage() );
			return false;
		}

		self::finish_recovery();
		while ( ob_get_level() > $buffer_level ) {
			ob_end_clean();
		}

		return true;
	}

	/**
	 * Prepare request-level fatal error recovery for one snippet execution.
	 *
	 * A database advisory lock prevents overlapping requests from treating an
	 * in-progress execution as a crash. The persistent option remains behind
	 * after an uncatchable fatal error; the next request then deactivates the
	 * snippet before it can run again.
	 *
	 * @param array $snippet Snippet record.
	 * @return bool Whether execution may proceed.
	 */
	private static function prepare_recovery( $snippet ) {
		global $wpdb;

		$identity       = (string) ( $snippet['id'] ?? '' ) . '|' . hash( 'sha256', (string) ( $snippet['code'] ?? '' ) );
		$hash           = hash( 'sha256', $identity );
		$lock_name      = 'mcsm_php_' . substr( $hash, 0, 40 );
		$recovery_option = 'mcsm_php_running_' . substr( $hash, 0, 32 );
		$lock_acquired  = 1 === (int) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 0)', $lock_name ) );

		if ( ! $lock_acquired ) {
			return false;
		}

		$previous_marker = get_option( $recovery_option, false );
		if ( false !== $previous_marker ) {
			$message = __( 'The snippet was automatically deactivated because its previous execution ended unexpectedly.', 'mcsm' );
			self::deactivate_snippet( $snippet, $message );
			delete_option( $recovery_option );
			$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock_name ) );
			self::report_error( $snippet, $message );
			return false;
		}

		update_option( $recovery_option, time(), false );
		self::$executing_snippet = $snippet;
		self::$recovery_lock     = $lock_name;
		self::$recovery_option   = $recovery_option;

		if ( self::$shutdown_registered ) {
			return true;
		}

		self::$recovery_memory     = str_repeat( 'R', 262144 );
		self::$shutdown_registered = true;
		register_shutdown_function( array( __CLASS__, 'handle_shutdown' ) );
		return true;
	}

	/**
	 * Clear the crash marker and release the execution lock.
	 */
	private static function finish_recovery() {
		global $wpdb;

		if ( '' !== self::$recovery_option ) {
			delete_option( self::$recovery_option );
		}

		if ( '' !== self::$recovery_lock ) {
			$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', self::$recovery_lock ) );
		}

		self::$executing_snippet = null;
		self::$recovery_lock     = '';
		self::$recovery_option   = '';
	}

	/**
	 * Deactivate the snippet responsible for an uncatchable fatal error.
	 */
	public static function handle_shutdown() {
		self::$recovery_memory = null;
		$error                 = error_get_last();
		$snippet               = self::$executing_snippet;

		if ( ! $snippet || ! is_array( $error ) || ! in_array( $error['type'] ?? 0, self::FATAL_ERROR_TYPES, true ) ) {
			return;
		}

		$message = isset( $error['message'] ) ? (string) $error['message'] : __( 'Fatal PHP error.', 'mcsm' );
		$deactivated = self::deactivate_snippet( $snippet, $message );
		self::report_error( $snippet, $message );

		if ( $deactivated ) {
			self::finish_recovery();
		}
	}

	/**
	 * Deactivate a failed global or local snippet to prevent repeated failures.
	 *
	 * @param array  $snippet Snippet record.
	 * @param string $message Error message.
	 * @return bool
	 */
	private static function deactivate_snippet( $snippet, $message ) {
		global $wpdb;

		$snippet_id = (string) ( $snippet['id'] ?? '' );
		$changed    = false;

		if ( ctype_digit( $snippet_id ) && absint( $snippet_id ) ) {
			$changed = false !== $wpdb->update(
				MCSM_Repository::table_name(),
				array(
					'status'     => 'inactive',
					'updated_at' => current_time( 'mysql' ),
				),
				array( 'id' => absint( $snippet_id ) ),
				array( '%s', '%s' ),
				array( '%d' )
			);
		} elseif ( preg_match( '/^local-(\d+)-(\d+)$/', $snippet_id, $matches ) ) {
			$post_id  = absint( $matches[1] );
			$index    = absint( $matches[2] );
			$snippets = get_post_meta( $post_id, '_mcsm_local_snippets', true );

			if ( is_array( $snippets ) && isset( $snippets[ $index ] ) && is_array( $snippets[ $index ] ) ) {
				$snippets[ $index ]['status'] = 'inactive';
				$changed                      = false !== update_post_meta( $post_id, '_mcsm_local_snippets', $snippets );
			}
		}

		if ( $changed ) {
			do_action( 'mcsm_php_snippet_deactivated_after_error', $snippet_id, $message, $snippet );
		}

		return $changed;
	}

	/**
	 * Determine whether a function declaration has a name.
	 *
	 * @param array $tokens Tokens.
	 * @param int   $offset Start offset.
	 * @return bool
	 */
	private static function has_named_function( $tokens, $offset ) {
		$count = count( $tokens );

		for ( $index = $offset; $index < $count; $index++ ) {
			$token = $tokens[ $index ];

			if ( is_array( $token ) && in_array( $token[0], array( T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ), true ) ) {
				continue;
			}

			if ( '&' === $token ) {
				continue;
			}

			return is_array( $token ) && T_STRING === $token[0];
		}

		return false;
	}

	/**
	 * Determine whether a T_CLASS token belongs to a Foo::class constant.
	 *
	 * @param array $tokens Tokens.
	 * @param int   $index Current token index.
	 * @return bool
	 */
	private static function is_class_constant( $tokens, $index ) {
		for ( $index--; $index >= 0; $index-- ) {
			$token = $tokens[ $index ];

			if ( is_array( $token ) && in_array( $token[0], array( T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ), true ) ) {
				continue;
			}

			return is_array( $token ) && T_DOUBLE_COLON === $token[0];
		}

		return false;
	}

	/**
	 * Report an execution problem without exposing details on the frontend.
	 *
	 * @param array  $snippet Snippet record.
	 * @param string $message Error message.
	 */
	private static function report_error( $snippet, $message ) {
		$snippet_id = sanitize_text_field( (string) ( $snippet['id'] ?? 'unknown' ) );
		do_action( 'mcsm_php_snippet_error', $snippet_id, $message, $snippet );

		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( sprintf( 'MCSM PHP snippet %s: %s', $snippet_id, $message ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		}
	}
}
