<?php
/**
 * Decision tests for creature-feedback-files.php.
 *
 *   php wordpress-plugins/mu-plugins/tests/creature-feedback-files-test.php
 *
 * No WordPress install and no network. Stubs stand in for hooks, posts,
 * cron, and the REST server. Fetchers in this file never open a socket.
 */

if ( PHP_SAPI !== 'cli' || ( isset( $_SERVER['SCRIPT_FILENAME'] ) && realpath( $_SERVER['SCRIPT_FILENAME'] ) !== realpath( __FILE__ ) ) ) {
	return;
}

ini_set( 'memory_limit', '512M' );

set_error_handler(
	static function ( $severity, $message, $file, $line ) {
		if ( ! ( error_reporting() & $severity ) ) {
			return false;
		}
		throw new ErrorException( $message, 0, $severity, $file, $line );
	}
);

$creature_ff_root = sys_get_temp_dir() . '/creature-ff-' . getmypid();
$creature_ff_abs  = $creature_ff_root . '/abs';
$creature_ff_out  = $creature_ff_root . '/private';
mkdir( $creature_ff_abs . '/wp-content/uploads', 0777, true );
mkdir( $creature_ff_out, 0777, true );

define( 'ABSPATH', $creature_ff_abs . '/' );

$GLOBALS['creature_ff_filters']       = array();
$GLOBALS['creature_ff_hooks']         = array();
$GLOBALS['creature_ff_posts']         = array();
$GLOBALS['creature_ff_meta']          = array();
$GLOBALS['creature_ff_options']       = array();
$GLOBALS['creature_ff_option_autoload'] = array();
$GLOBALS['creature_ff_events']        = array();
$GLOBALS['creature_ff_mail']          = array();
$GLOBALS['creature_ff_mail_ok']       = true;
$GLOBALS['creature_ff_admin']         = false;
$GLOBALS['creature_ff_routes']        = array();
$GLOBALS['creature_ff_deleted_posts'] = array();
$GLOBALS['creature_ff_uploads']       = $creature_ff_abs . '/wp-content/uploads';
$GLOBALS['creature_ff_queries']       = array();
$GLOBALS['creature_ff_commands']      = array();

$failures = 0;
$checks   = 0;

/**
 * @param bool   $condition Condition.
 * @param string $message   Failure text.
 * @return void
 */
function creature_ff_expect( $condition, $message ) {
	global $failures, $checks;
	$checks++;
	if ( $condition ) {
		return;
	}
	$failures++;
	fwrite( STDERR, "FAIL {$message}\n" );
}

/**
 * @param string $hook Hook name.
 * @return void
 */
function creature_ff_clear_filter( $hook ) {
	unset( $GLOBALS['creature_ff_filters'][ $hook ] );
}

/**
 * @param string   $hook    Hook.
 * @param int|null $post_id Post id when the event args start with one.
 * @return array
 */
function creature_ff_events_for( $hook, $post_id = null ) {
	$found = array();
	foreach ( $GLOBALS['creature_ff_events'] as $event ) {
		if ( $event['hook'] !== $hook ) {
			continue;
		}
		if ( null !== $post_id ) {
			$args = isset( $event['args'] ) ? $event['args'] : array();
			if ( ! isset( $args[0] ) || (int) $args[0] !== (int) $post_id ) {
				continue;
			}
		}
		$found[] = $event;
	}
	return $found;
}

/**
 * @param string $dir Directory.
 * @return void
 */
function creature_ff_rrmdir( $dir ) {
	if ( ! is_dir( $dir ) ) {
		return;
	}
	$items = scandir( $dir );
	if ( ! is_array( $items ) ) {
		return;
	}
	foreach ( $items as $item ) {
		if ( '.' === $item || '..' === $item ) {
			continue;
		}
		$full = $dir . '/' . $item;
		if ( is_dir( $full ) ) {
			creature_ff_rrmdir( $full );
		} else {
			unlink( $full );
		}
	}
	rmdir( $dir );
}

if ( ! function_exists( 'add_filter' ) ) {
	/**
	 * @param callable $callback Callback.
	 * @return bool
	 */
	function add_filter( $hook, $callback, $priority = 10, $accepted_args = 1 ) {
		$GLOBALS['creature_ff_hooks'][] = array( $hook, $callback, (int) $priority, (int) $accepted_args );
		if ( ! isset( $GLOBALS['creature_ff_filters'][ $hook ] ) ) {
			$GLOBALS['creature_ff_filters'][ $hook ] = array();
		}
		$GLOBALS['creature_ff_filters'][ $hook ][ (int) $priority ][] = array( $callback, (int) $accepted_args );
		ksort( $GLOBALS['creature_ff_filters'][ $hook ] );
		return true;
	}
}

if ( ! function_exists( 'add_action' ) ) {
	/**
	 * @param callable $callback Callback.
	 * @return bool
	 */
	function add_action( $hook, $callback, $priority = 10, $accepted_args = 1 ) {
		return add_filter( $hook, $callback, $priority, $accepted_args );
	}
}

if ( ! function_exists( 'apply_filters' ) ) {
	/**
	 * @param string $hook  Hook.
	 * @param mixed  $value Value.
	 * @return mixed
	 */
	function apply_filters( $hook, $value, ...$args ) {
		if ( empty( $GLOBALS['creature_ff_filters'][ $hook ] ) ) {
			return $value;
		}
		$priorities = array_keys( $GLOBALS['creature_ff_filters'][ $hook ] );
		sort( $priorities, SORT_NUMERIC );
		foreach ( $priorities as $priority ) {
			if ( empty( $GLOBALS['creature_ff_filters'][ $hook ][ $priority ] ) ) {
				continue;
			}
			$rows = $GLOBALS['creature_ff_filters'][ $hook ][ $priority ];
			foreach ( $rows as $row ) {
				$params = array_merge( array( $value ), $args );
				if ( $row[1] > 0 && count( $params ) > $row[1] ) {
					$params = array_slice( $params, 0, $row[1] );
				}
				$value = call_user_func_array( $row[0], $params );
			}
		}
		return $value;
	}
}

if ( ! function_exists( 'do_action' ) ) {
	/**
	 * @param string $hook Hook.
	 * @return void
	 */
	function do_action( $hook, ...$args ) {
		if ( empty( $GLOBALS['creature_ff_filters'][ $hook ] ) ) {
			return;
		}
		$priorities = array_keys( $GLOBALS['creature_ff_filters'][ $hook ] );
		sort( $priorities, SORT_NUMERIC );
		foreach ( $priorities as $priority ) {
			if ( empty( $GLOBALS['creature_ff_filters'][ $hook ][ $priority ] ) ) {
				continue;
			}
			$rows = $GLOBALS['creature_ff_filters'][ $hook ][ $priority ];
			foreach ( $rows as $row ) {
				$params = $args;
				if ( $row[1] > 0 && count( $params ) > $row[1] ) {
					$params = array_slice( $params, 0, $row[1] );
				}
				call_user_func_array( $row[0], $params );
			}
		}
	}
}

if ( ! class_exists( 'WP_Error' ) ) {
	/**
	 * Minimal WP_Error.
	 */
	class WP_Error {
		/** @var string */
		public $code;
		/** @var string */
		public $message;
		/** @var mixed */
		public $data;

		/**
		 * @param string $code    Code.
		 * @param string $message Message.
		 * @param mixed  $data    Data.
		 */
		public function __construct( $code = '', $message = '', $data = array() ) {
			$this->code    = (string) $code;
			$this->message = (string) $message;
			$this->data    = $data;
		}

		/**
		 * @return string
		 */
		public function get_error_code() {
			return $this->code;
		}

		/**
		 * @return string
		 */
		public function get_error_message() {
			return $this->message;
		}

		/**
		 * @return mixed
		 */
		public function get_error_data() {
			return $this->data;
		}
	}
}

if ( ! function_exists( 'is_wp_error' ) ) {
	/**
	 * @param mixed $thing Thing.
	 * @return bool
	 */
	function is_wp_error( $thing ) {
		return $thing instanceof WP_Error;
	}
}

if ( ! function_exists( 'get_post' ) ) {
	/**
	 * @param mixed $post Post or id.
	 * @return object|null
	 */
	function get_post( $post ) {
		$id = is_object( $post ) ? (int) $post->ID : (int) $post;
		return isset( $GLOBALS['creature_ff_posts'][ $id ] ) ? $GLOBALS['creature_ff_posts'][ $id ] : null;
	}
}

if ( ! function_exists( 'update_post_meta' ) ) {
	/**
	 * @param int    $post_id Post id.
	 * @param string $key     Key.
	 * @param mixed  $value   Value.
	 * @return bool
	 */
	function update_post_meta( $post_id, $key, $value ) {
		$GLOBALS['creature_ff_meta'][ (int) $post_id ][ (string) $key ] = $value;
		return true;
	}
}

if ( ! function_exists( 'get_post_meta' ) ) {
	/**
	 * @param int    $post_id Post id.
	 * @param string $key     Key.
	 * @param bool   $single  Single.
	 * @return mixed
	 */
	function get_post_meta( $post_id, $key = '', $single = false ) {
		$post_id = (int) $post_id;
		if ( '' === $key ) {
			return isset( $GLOBALS['creature_ff_meta'][ $post_id ] ) ? $GLOBALS['creature_ff_meta'][ $post_id ] : array();
		}
		if ( ! isset( $GLOBALS['creature_ff_meta'][ $post_id ][ $key ] ) ) {
			return $single ? '' : array();
		}
		$value = $GLOBALS['creature_ff_meta'][ $post_id ][ $key ];
		return $single ? $value : array( $value );
	}
}

if ( ! function_exists( 'get_option' ) ) {
	/**
	 * @param string $option  Option.
	 * @param mixed  $default Default.
	 * @return mixed
	 */
	function get_option( $option, $default = false ) {
		if ( array_key_exists( $option, $GLOBALS['creature_ff_options'] ) ) {
			return $GLOBALS['creature_ff_options'][ $option ];
		}
		if ( 'admin_email' === $option ) {
			return 'admin@example.com';
		}
		return $default;
	}
}

if ( ! function_exists( 'add_option' ) ) {
	/**
	 * @param string $option   Option.
	 * @param mixed  $value    Value.
	 * @param string $deprecated Deprecated.
	 * @param mixed  $autoload Autoload.
	 * @return bool
	 */
	function add_option( $option, $value = '', $deprecated = '', $autoload = 'yes' ) {
		unset( $deprecated );
		if ( array_key_exists( $option, $GLOBALS['creature_ff_options'] ) ) {
			return false;
		}
		$GLOBALS['creature_ff_options'][ $option ]         = $value;
		$GLOBALS['creature_ff_option_autoload'][ $option ] = $autoload;
		return true;
	}
}

if ( ! function_exists( 'wp_schedule_single_event' ) ) {
	/**
	 * @param int    $timestamp Timestamp.
	 * @param string $hook      Hook.
	 * @param array  $args      Args.
	 * @return bool
	 */
	function wp_schedule_single_event( $timestamp, $hook, $args = array() ) {
		$GLOBALS['creature_ff_events'][] = array(
			'timestamp' => (int) $timestamp,
			'hook'      => $hook,
			'args'      => $args,
			'schedule'  => false,
		);
		return true;
	}
}

if ( ! function_exists( 'wp_schedule_event' ) ) {
	/**
	 * @param int    $timestamp  Timestamp.
	 * @param string $recurrence Recurrence.
	 * @param string $hook       Hook.
	 * @param array  $args       Args.
	 * @return bool
	 */
	function wp_schedule_event( $timestamp, $recurrence, $hook, $args = array() ) {
		$GLOBALS['creature_ff_events'][] = array(
			'timestamp' => (int) $timestamp,
			'hook'      => $hook,
			'args'      => $args,
			'schedule'  => $recurrence,
		);
		return true;
	}
}

if ( ! function_exists( 'wp_next_scheduled' ) ) {
	/**
	 * @param string $hook Hook.
	 * @param array  $args Args.
	 * @return int|false
	 */
	function wp_next_scheduled( $hook, $args = array() ) {
		foreach ( $GLOBALS['creature_ff_events'] as $event ) {
			if ( $event['hook'] !== $hook ) {
				continue;
			}
			$event_args = isset( $event['args'] ) ? $event['args'] : array();
			if ( $event_args == $args ) {
				return (int) $event['timestamp'];
			}
		}
		return false;
	}
}

if ( ! function_exists( 'wp_unschedule_event' ) ) {
	/**
	 * @param int    $timestamp Timestamp.
	 * @param string $hook      Hook.
	 * @param array  $args      Args.
	 * @return bool
	 */
	function wp_unschedule_event( $timestamp, $hook, $args = array() ) {
		$kept    = array();
		$removed = false;
		foreach ( $GLOBALS['creature_ff_events'] as $event ) {
			if ( ! $removed && (int) $event['timestamp'] === (int) $timestamp && $event['hook'] === $hook && $event['args'] == $args ) {
				$removed = true;
				continue;
			}
			$kept[] = $event;
		}
		$GLOBALS['creature_ff_events'] = $kept;
		return true;
	}
}

if ( ! function_exists( 'wp_mail' ) ) {
	/**
	 * @param string $to      To.
	 * @param string $subject Subject.
	 * @param string $message Message.
	 * @return bool
	 */
	function wp_mail( $to, $subject, $message ) {
		$GLOBALS['creature_ff_mail'][] = array(
			'to'      => $to,
			'subject' => $subject,
			'message' => $message,
		);
		return ! empty( $GLOBALS['creature_ff_mail_ok'] );
	}
}

if ( ! function_exists( 'current_user_can' ) ) {
	/**
	 * @param string $cap Capability.
	 * @return bool
	 */
	function current_user_can( $cap ) {
		if ( 'manage_options' !== $cap ) {
			return false;
		}
		return ! empty( $GLOBALS['creature_ff_admin'] );
	}
}

if ( ! function_exists( 'register_rest_route' ) ) {
	/**
	 * @param string $namespace Namespace.
	 * @param string $route     Route.
	 * @param array  $args      Args.
	 * @return bool
	 */
	function register_rest_route( $namespace, $route, $args ) {
		$GLOBALS['creature_ff_routes'][] = array( $namespace, $route, $args );
		return true;
	}
}

if ( ! function_exists( 'wp_upload_dir' ) ) {
	/**
	 * @return array
	 */
	function wp_upload_dir() {
		return array( 'basedir' => $GLOBALS['creature_ff_uploads'] );
	}
}

if ( ! function_exists( 'wp_delete_post' ) ) {
	/**
	 * @param int $post_id Post id.
	 * @return null
	 */
	function wp_delete_post( $post_id ) {
		$GLOBALS['creature_ff_deleted_posts'][] = (int) $post_id;
		return null;
	}
}

if ( ! function_exists( 'wp_delete_attachment' ) ) {
	/**
	 * @param int $post_id Post id.
	 * @return null
	 */
	function wp_delete_attachment( $post_id ) {
		$GLOBALS['creature_ff_deleted_posts'][] = (int) $post_id;
		return null;
	}
}

if ( ! class_exists( 'WP_REST_Response' ) ) {
	/**
	 * Minimal REST response.
	 */
	class WP_REST_Response {
		/** @var mixed */
		private $data;
		/** @var int */
		private $status;
		/** @var array */
		private $headers = array();

		/**
		 * @param mixed $data   Data.
		 * @param int   $status Status.
		 */
		public function __construct( $data = null, $status = 200 ) {
			$this->data   = $data;
			$this->status = (int) $status;
		}

		/**
		 * @param string $key   Header.
		 * @param mixed  $value Value.
		 * @return void
		 */
		public function header( $key, $value ) {
			$this->headers[ $key ] = (string) $value;
		}

		/**
		 * @return array
		 */
		public function get_headers() {
			return $this->headers;
		}

		/**
		 * @return mixed
		 */
		public function get_data() {
			return $this->data;
		}

		/**
		 * @return int
		 */
		public function get_status() {
			return $this->status;
		}
	}
}

if ( ! class_exists( 'WP_REST_Request' ) ) {
	/**
	 * Minimal REST request.
	 */
	class WP_REST_Request {
		/** @var array */
		public $params;
		/** @var string */
		public $route;

		/**
		 * @param array  $params Params.
		 * @param string $route  Route.
		 */
		public function __construct( $params = array(), $route = '' ) {
			$this->params = $params;
			$this->route  = $route;
		}

		/**
		 * @param string $key Key.
		 * @return mixed
		 */
		public function get_param( $key ) {
			return array_key_exists( $key, $this->params ) ? $this->params[ $key ] : null;
		}

		/**
		 * @return string
		 */
		public function get_route() {
			return $this->route;
		}
	}
}

if ( ! class_exists( 'WP_Query' ) ) {
	/**
	 * In-memory feedback query.
	 */
	class WP_Query {
		/** @var array */
		public $query;
		/** @var array */
		public $posts = array();
		/** @var int */
		public $found_posts = 0;
		/** @var int */
		public $max_num_pages = 0;

		/**
		 * @param array $args Query.
		 */
		public function __construct( $args ) {
			$this->query                    = $args;
			$GLOBALS['creature_ff_queries'][] = $args;
			$matched                        = array();
			foreach ( $GLOBALS['creature_ff_posts'] as $post ) {
				if ( isset( $args['post_type'] ) && $args['post_type'] !== $post->post_type ) {
					continue;
				}
				if ( isset( $args['post_parent'] ) && (int) $args['post_parent'] !== (int) $post->post_parent ) {
					continue;
				}
				if ( ! empty( $args['meta_key'] ) ) {
					$meta = get_post_meta( $post->ID, $args['meta_key'], true );
					if ( '' === $meta || null === $meta || false === $meta ) {
						continue;
					}
				}
				if ( ! empty( $args['date_query'][0]['after'] ) ) {
					$after = (string) $args['date_query'][0]['after'];
					if ( (string) $post->post_date_gmt < $after ) {
						continue;
					}
				}
				$matched[] = $post;
			}
			usort(
				$matched,
				static function ( $a, $b ) {
					$cmp = strcmp( (string) $b->post_date_gmt, (string) $a->post_date_gmt );
					if ( 0 !== $cmp ) {
						return $cmp;
					}
					return (int) $b->ID - (int) $a->ID;
				}
			);
			$per  = isset( $args['posts_per_page'] ) ? (int) $args['posts_per_page'] : 10;
			$page = isset( $args['paged'] ) ? (int) $args['paged'] : 1;
			if ( $page < 1 ) {
				$page = 1;
			}
			$this->found_posts = count( $matched );
			if ( $per < 1 ) {
				$slice                = $matched;
				$this->max_num_pages  = $matched ? 1 : 0;
			} else {
				$this->max_num_pages = (int) ceil( $this->found_posts / $per );
				$slice               = array_slice( $matched, ( $page - 1 ) * $per, $per );
			}
			if ( isset( $args['fields'] ) && 'ids' === $args['fields'] ) {
				$this->posts = array();
				foreach ( $slice as $post ) {
					$this->posts[] = (int) $post->ID;
				}
			} else {
				$this->posts = $slice;
			}
		}
	}
}

/**
 * Feedback post stand-in.
 */
class Creature_FF_Post {
	/** @var int */
	public $ID;
	/** @var int */
	public $post_parent;
	/** @var string */
	public $post_type = 'feedback';
	/** @var string */
	public $post_status = 'publish';
	/** @var string */
	public $post_date_gmt = '';
	/** @var string */
	public $post_content = '';
}

/**
 * Field object with get_value(), the Feedback_Field shape.
 */
class Creature_FF_Value {
	/** @var mixed */
	private $value;

	/**
	 * @param mixed $value Value.
	 */
	public function __construct( $value ) {
		$this->value = $value;
	}

	/**
	 * @return mixed
	 */
	public function get_value() {
		return $this->value;
	}
}

require dirname( __DIR__ ) . '/creature-feedback-files.php';

/**
 * Fetcher that stays in process.
 */
class Creature_FF_Fake_Fetcher implements Creature_Feedback_Files_Fetcher {
	/** @var int */
	public $calls = 0;
	/** @var array<int, string> */
	public $bodies = array();
	/** @var string */
	public $content_type = 'image/jpeg';
	/** @var WP_Error|null */
	public $error = null;

	/**
	 * @param int $file_id File id.
	 * @return array{body: string, content_type: string}|WP_Error
	 */
	public function fetch( int $file_id ) {
		$this->calls++;
		if ( $this->error instanceof WP_Error ) {
			return $this->error;
		}
		if ( ! isset( $this->bodies[ $file_id ] ) ) {
			return new WP_Error( 'missing', 'No body for that file.' );
		}
		return array(
			'body'         => $this->bodies[ $file_id ],
			'content_type' => $this->content_type,
		);
	}
}

/**
 * WP-CLI stand-in. Defined after the plugin loads so registration can be re-run.
 */
class WP_CLI {
	/** @var string[] */
	public static $logs = array();
	/** @var string[] */
	public static $errors = array();

	/**
	 * @param string $message Message.
	 * @return void
	 */
	public static function log( $message ) {
		self::$logs[] = (string) $message;
	}

	/**
	 * @param string $message Message.
	 * @return void
	 */
	public static function success( $message ) {
		self::$logs[] = (string) $message;
	}

	/**
	 * @param string $message Message.
	 * @return void
	 */
	public static function warning( $message ) {
		self::$logs[] = (string) $message;
	}

	/**
	 * @param string $message Message.
	 * @return void
	 */
	public static function error( $message ) {
		self::$errors[] = (string) $message;
	}

	/**
	 * @param string $name     Command.
	 * @param mixed  $callable Callable.
	 * @return void
	 */
	public static function add_command( $name, $callable ) {
		$GLOBALS['creature_ff_commands'][ $name ] = $callable;
	}
}

/**
 * @param int    $id     Post id.
 * @param int    $parent Parent.
 * @param array  $args   Overrides.
 * @return Creature_FF_Post
 */
function creature_ff_add_post( $id, $parent, $args = array() ) {
	$post                = new Creature_FF_Post();
	$post->ID            = (int) $id;
	$post->post_parent   = (int) $parent;
	$post->post_type     = isset( $args['post_type'] ) ? $args['post_type'] : 'feedback';
	$post->post_status   = 'publish';
	$post->post_date_gmt = isset( $args['post_date_gmt'] ) ? $args['post_date_gmt'] : gmdate( 'Y-m-d H:i:s' );
	$post->post_content  = isset( $args['post_content'] ) ? $args['post_content'] : '';
	$GLOBALS['creature_ff_posts'][ $post->ID ] = $post;
	return $post;
}

/**
 * @param string $dir Directory the filter should return.
 * @return void
 */
function creature_ff_use_private_dir( $dir ) {
	creature_ff_clear_filter( 'creature_feedback_files_private_dirs' );
	add_filter(
		'creature_feedback_files_private_dirs',
		static function ( $dirs ) use ( $dir ) {
			unset( $dirs );
			return array( $dir );
		}
	);
}

register_shutdown_function(
	static function () use ( $creature_ff_root ) {
		creature_ff_rrmdir( $creature_ff_root );
	}
);

creature_ff_use_private_dir( $creature_ff_out );

$source = file_get_contents( dirname( __DIR__ ) . '/creature-feedback-files.php' );
creature_ff_expect( false !== strpos( $source, 'Version: 0.1.0' ), 'plugin header version is 0.1.0' );
creature_ff_expect( '0.1.0' === Creature_Feedback_Files::VERSION, 'version constant is 0.1.0' );

$hooked = false;
foreach ( $GLOBALS['creature_ff_hooks'] as $row ) {
	if ( 'grunion_after_feedback_post_inserted' === $row[0] && is_array( $row[1] ) && 'Creature_Feedback_Files' === $row[1][0] && 'on_feedback_inserted' === $row[1][1] ) {
		$hooked = ( 10 === $row[2] && 4 === $row[3] );
	}
}
creature_ff_expect( $hooked, 'feedback hook is priority 10 with 4 args' );

$hook_names = array();
foreach ( $GLOBALS['creature_ff_hooks'] as $row ) {
	$hook_names[] = $row[0];
}
foreach ( array( 'creature_feedback_files_fetch', 'creature_feedback_files_retention', 'init', 'rest_api_init', 'rest_pre_serve_request' ) as $required_hook ) {
	creature_ff_expect( in_array( $required_hook, $hook_names, true ), "missing hook {$required_hook}" );
}

Creature_Feedback_Files::register_routes();
$routes = $GLOBALS['creature_ff_routes'];
creature_ff_expect( 2 === count( $routes ), 'two REST routes' );
$saw_list     = false;
$saw_download = false;
foreach ( $routes as $route ) {
	creature_ff_expect( 'creature/v1' === $route[0], 'REST namespace creature/v1' );
	creature_ff_expect( 'GET' === $route[2]['methods'], 'REST route is GET only' );
	creature_ff_expect( 'Creature_Feedback_Files' === $route[2]['permission_callback'][0], 'permission callback class' );
	creature_ff_expect( 'rest_permission' === $route[2]['permission_callback'][1], 'permission callback is rest_permission' );
	if ( '/feedback-files' === $route[1] ) {
		$saw_list = true;
	}
	if ( '/feedback-files/(?P<response_id>\d+)/(?P<file_id>\d+)' === $route[1] ) {
		$saw_download = true;
	}
}
creature_ff_expect( $saw_list, 'list route registered' );
creature_ff_expect( $saw_download, 'download route registered' );

$direct = new Creature_Feedback_Files_Fetcher_Direct();
$direct_error = $direct->fetch( 4 );
creature_ff_expect( is_wp_error( $direct_error ) && 'not_implemented' === $direct_error->get_error_code(), 'direct fetcher returns not_implemented' );
$loop = new Creature_Feedback_Files_Fetcher_Loopback();
$loop_error = $loop->fetch( 5 );
creature_ff_expect( is_wp_error( $loop_error ) && 'not_implemented' === $loop_error->get_error_code(), 'loopback fetcher returns not_implemented' );
creature_ff_expect( Creature_Feedback_Files::fetcher() instanceof Creature_Feedback_Files_Fetcher_Direct, 'default fetcher is direct' );

add_filter(
	'creature_feedback_files_fetcher',
	static function ( $fetcher ) {
		unset( $fetcher );
		return new Creature_Feedback_Files_Fetcher_Loopback();
	}
);
creature_ff_expect( Creature_Feedback_Files::fetcher() instanceof Creature_Feedback_Files_Fetcher_Loopback, 'filter selects the loopback fetcher' );
creature_ff_expect( 'not_implemented' === Creature_Feedback_Files::fetcher()->fetch( 8 )->get_error_code(), 'selected loopback stub is not implemented' );
creature_ff_clear_filter( 'creature_feedback_files_fetcher' );
creature_ff_expect( Creature_Feedback_Files::fetcher() instanceof Creature_Feedback_Files_Fetcher_Direct, 'clearing the filter restores direct' );

creature_ff_expect( Creature_Feedback_Files::backfill_is_dry( array() ), 'backfill defaults to dry-run' );
creature_ff_expect( Creature_Feedback_Files::backfill_is_dry( array( 'dry-run' => true ) ), '--dry-run stays a dry-run' );
creature_ff_expect( ! Creature_Feedback_Files::backfill_is_dry( array( 'yes' => true ) ), '--yes writes' );
creature_ff_expect( ! Creature_Feedback_Files::backfill_is_dry( array( 'yes' => true, 'dry-run' => true ) ), '--yes wins over --dry-run' );

$legacy = array(
	array(
		'field_id' => 'g1',
		'files'    => array(
			array(
				'file_id' => '77',
				'name'    => 'legacy.jpg',
				'size'    => '9',
			),
			array(
				'file_id' => 0,
				'name'    => 'skip.jpg',
				'size'    => 3,
			),
		),
	),
);
$legacy_rows = Creature_Feedback_Files::collect_files( $legacy, array( 'entry_title' => 'Demo' ) );
creature_ff_expect( 1 === count( $legacy_rows ), 'legacy files key yields one row' );
creature_ff_expect( 77 === $legacy_rows[0]['file_id'] && 9 === $legacy_rows[0]['size'], 'file_id and size are ints' );
creature_ff_expect( 'pending' === $legacy_rows[0]['status'] && 0 === $legacy_rows[0]['attempts'], 'queued row is pending with 0 attempts' );

$raw_rows = Creature_Feedback_Files::collect_files(
	array( '{"file_id":"15","name":"raw.jpg","size":"4","type":"image/jpeg"}' ),
	array()
);
creature_ff_expect( 1 === count( $raw_rows ) && 15 === $raw_rows[0]['file_id'], 'raw JSON file list is collected' );

$object_rows = Creature_Feedback_Files::collect_files(
	array(
		new Creature_FF_Value(
			array(
				'files' => array(
					array(
						'file_id' => 15,
						'name'    => 'again.jpg',
						'size'    => 4,
					),
				),
			)
		),
	),
	array(
		'files' => array(
			array(
				'file_id' => 15,
				'name'    => 'again.jpg',
				'size'    => 4,
			),
		),
	)
);
creature_ff_expect( 1 === count( $object_rows ), 'the same file_id from fields and entry values is stored once' );

$jpeg = "\xFF\xD8\xFF" . str_repeat( ' ', 9 );
$png  = "\x89PNG\r\n\x1A\n" . 'SECRET-PNG-BYTES';
$gif  = 'GIF89aSECRET-PHOTO-BYTES';
$webp = 'RIFF' . 'xxxx' . 'WEBP' . 'more';
$pdf  = "%PDF-1.4\nnot-an-image";
creature_ff_expect( 'image/jpeg' === Creature_Feedback_Files::sniff_image_mime( $jpeg ), 'jpeg magic' );
creature_ff_expect( 'image/png' === Creature_Feedback_Files::sniff_image_mime( $png ), 'png magic' );
creature_ff_expect( 'image/gif' === Creature_Feedback_Files::sniff_image_mime( $gif ), 'gif magic' );
creature_ff_expect( 'image/webp' === Creature_Feedback_Files::sniff_image_mime( $webp ), 'webp magic' );
creature_ff_expect( '' === Creature_Feedback_Files::sniff_image_mime( $pdf ), 'pdf is not an image' );
$pdf_inspect = Creature_Feedback_Files::inspect_body( $pdf );
creature_ff_expect( is_wp_error( $pdf_inspect ) && 'creature_feedback_files_mime' === $pdf_inspect->get_error_code(), 'MIME is sniffed, not trusted' );

$defaults = Creature_Feedback_Files::default_private_dirs();
$abs_norm = rtrim( ABSPATH, '/' );
creature_ff_expect( 1 === count( $defaults ) && '/creature-private' === substr( $defaults[0], -17 ), 'default private dir name' );
creature_ff_expect( 0 !== strpos( $defaults[0], $abs_norm ), 'default private dir is outside ABSPATH' );

$inside = $creature_ff_abs . '/wp-content/creature-private';
creature_ff_clear_filter( 'creature_feedback_files_private_dirs' );
add_filter(
	'creature_feedback_files_private_dirs',
	static function ( $dirs ) use ( $inside, $creature_ff_out ) {
		unset( $dirs );
		return array( $inside, $creature_ff_out );
	}
);
$chosen = Creature_Feedback_Files::base_dir();
creature_ff_expect( $creature_ff_out === $chosen, 'first writable dir outside ABSPATH wins' );
creature_ff_expect( ! is_dir( $inside ), 'a candidate inside ABSPATH is not created' );
creature_ff_expect( is_file( $creature_ff_out . '/index.php' ) && '' === file_get_contents( $creature_ff_out . '/index.php' ), 'private root gets a blank index.php' );

creature_ff_clear_filter( 'creature_feedback_files_private_dirs' );
add_filter(
	'creature_feedback_files_private_dirs',
	static function ( $dirs ) use ( $inside ) {
		unset( $dirs );
		return array( $inside );
	}
);
$fallback = Creature_Feedback_Files::base_dir();
creature_ff_expect( $GLOBALS['creature_ff_uploads'] . '/creature-private' === $fallback, 'fallback is uploads/creature-private' );
creature_ff_expect( is_file( $fallback . '/index.php' ) && '' === file_get_contents( $fallback . '/index.php' ), 'fallback dir gets a blank index.php' );
creature_ff_use_private_dir( $creature_ff_out );

$photo_fields = array(
	array(
		'label' => 'Photo',
		'value' => array(
			'files' => array(
				array(
					'file_id' => 9,
					'name'    => '../../weird name.jpg',
					'size'    => 10,
				),
				array(
					'file_id' => 10,
					'name'    => 'other.png',
					'size'    => 11,
				),
			),
		),
	),
	array(
		'label' => 'Note',
		'value' => 'hello',
	),
	new Creature_FF_Value(
		array(
			'files' => array(
				array(
					'file_id' => 9,
					'name'    => '../../weird name.jpg',
					'size'    => 10,
				),
			),
		)
	),
);

creature_ff_add_post( 200, 8901 );
do_action( 'grunion_after_feedback_post_inserted', 200, $photo_fields, true, array() );
creature_ff_expect( '' === get_post_meta( 200, '_creature_feedback_files', true ), 'spam does not queue files' );
creature_ff_expect( array() === creature_ff_events_for( 'creature_feedback_files_fetch', 200 ), 'spam does not schedule a fetch' );

creature_ff_add_post( 201, 1 );
do_action( 'grunion_after_feedback_post_inserted', 201, $photo_fields, false, array() );
creature_ff_expect( '' === get_post_meta( 201, '_creature_feedback_files', true ), 'other form pages are ignored' );
creature_ff_expect( array() === creature_ff_events_for( 'creature_feedback_files_fetch', 201 ), 'other form pages are not scheduled' );

add_filter(
	'creature_feedback_files_form_ids',
	static function ( $ids ) {
		unset( $ids );
		return array( 42, 'nope', 0, 42 );
	}
);
creature_ff_expect( array( 42 ) === Creature_Feedback_Files::form_ids(), 'form id filter normalizes to unique positive ints' );
creature_ff_add_post( 202, 42 );
do_action( 'grunion_after_feedback_post_inserted', 202, $photo_fields, false, array() );
$filtered_meta = get_post_meta( 202, '_creature_feedback_files', true );
creature_ff_expect( is_array( $filtered_meta ) && 2 === count( $filtered_meta ), 'filtered form id queues the response' );
creature_ff_clear_filter( 'creature_feedback_files_form_ids' );
creature_ff_expect( array( 8901 ) === Creature_Feedback_Files::form_ids(), 'form ids restored' );

$fake            = new Creature_FF_Fake_Fetcher();
$GLOBALS['creature_ff_fetcher'] = $fake;
add_filter(
	'creature_feedback_files_fetcher',
	static function ( $fetcher ) {
		unset( $fetcher );
		return $GLOBALS['creature_ff_fetcher'];
	}
);

creature_ff_add_post( 100, 8901 );
$before_queue = time();
do_action( 'grunion_after_feedback_post_inserted', 100, $photo_fields, false, array() );
$after_queue = time();
$queued      = get_post_meta( 100, '_creature_feedback_files', true );
creature_ff_expect( is_array( $queued ) && 2 === count( $queued ), 'hook queues both files once' );
$queued_keys = array_keys( $queued[0] );
sort( $queued_keys );
creature_ff_expect(
	array( 'attempts', 'file_id', 'name', 'size', 'status' ) === $queued_keys,
	'pending meta is file_id, name, size, status, attempts'
);
creature_ff_expect( 9 === $queued[0]['file_id'] && 'pending' === $queued[0]['status'] && 0 === $queued[0]['attempts'], 'first file is pending' );
creature_ff_expect( 10 === $queued[1]['file_id'] && 'other.png' === $queued[1]['name'], 'second file keeps its name' );
creature_ff_expect( '../../weird name.jpg' === $queued[0]['name'], 'queued name is the submitted name' );
$fetch_events = creature_ff_events_for( 'creature_feedback_files_fetch', 100 );
creature_ff_expect( 1 === count( $fetch_events ), 'one fetch event is scheduled' );
creature_ff_expect(
	$fetch_events[0]['timestamp'] >= $before_queue + 120 && $fetch_events[0]['timestamp'] <= $after_queue + 120,
	'fetch is scheduled 120 seconds later'
);
creature_ff_expect( array( 100 ) === $fetch_events[0]['args'], 'fetch event args are the post id' );
creature_ff_expect( 0 === $fake->calls, 'the visitor request does not download' );
creature_ff_expect( ! array_key_exists( 'creature_feedback_files_key', $GLOBALS['creature_ff_options'] ), 'the visitor request does not create the key' );

do_action( 'grunion_after_feedback_post_inserted', 100, $photo_fields, false, array() );
creature_ff_expect( 1 === count( creature_ff_events_for( 'creature_feedback_files_fetch', 100 ) ), 'a second submit does not stack a second event' );

$plain_one = 'alpha-secret-photo';
$blob_one  = Creature_Feedback_Files::encrypt_blob( $plain_one );
$blob_two  = Creature_Feedback_Files::encrypt_blob( 'beta-secret-photo' );
creature_ff_expect( is_string( $blob_one ) && is_string( $blob_two ), 'encrypt returns ciphertext' );
creature_ff_expect(
	strlen( $blob_one ) === SODIUM_CRYPTO_SECRETBOX_NONCEBYTES + strlen( $plain_one ) + SODIUM_CRYPTO_SECRETBOX_MACBYTES,
	'nonce is prepended to the secretbox ciphertext'
);
creature_ff_expect( false === strpos( $blob_one, 'alpha-secret-photo' ), 'ciphertext does not contain the plaintext' );
$stored_key = get_option( 'creature_feedback_files_key' );
creature_ff_expect( is_string( $stored_key ) && '' !== $stored_key, 'key option is created once' );
creature_ff_expect( false === $GLOBALS['creature_ff_option_autoload']['creature_feedback_files_key'], 'key option autoload is false' );
$key = base64_decode( $stored_key, true );
creature_ff_expect( is_string( $key ) && SODIUM_CRYPTO_SECRETBOX_KEYBYTES === strlen( $key ), 'stored key is base64 of 32 bytes' );
$opened = sodium_crypto_secretbox_open(
	substr( $blob_one, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ),
	substr( $blob_one, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ),
	$key
);
creature_ff_expect( $plain_one === $opened, 'prepended nonce opens with the stored key' );
creature_ff_expect( $plain_one === Creature_Feedback_Files::decrypt_blob( $blob_one ), 'decrypt round-trip' );
creature_ff_expect( 'beta-secret-photo' === Creature_Feedback_Files::decrypt_blob( $blob_two ), 'second blob uses the same key' );
creature_ff_expect( hash( 'sha256', $plain_one ) === hash( 'sha256', Creature_Feedback_Files::decrypt_blob( $blob_one ) ), 'sha256 of the decrypted plaintext matches' );
$calls_after_key = 0;
foreach ( $GLOBALS['creature_ff_option_autoload'] as $option => $autoload ) {
	unset( $autoload );
	if ( 'creature_feedback_files_key' === $option ) {
		$calls_after_key++;
	}
}
creature_ff_expect( 1 === $calls_after_key, 'the key option is not rewritten' );

$fake->bodies = array(
	9  => $gif,
	10 => $png,
);
$calls_before_fetch = $fake->calls;
do_action( 'creature_feedback_files_fetch', 100 );
$saved = get_post_meta( 100, '_creature_feedback_files', true );
creature_ff_expect( $fake->calls === $calls_before_fetch + 2, 'fetch runs once per pending file' );
creature_ff_expect( 'saved' === $saved[0]['status'] && 'saved' === $saved[1]['status'], 'both files are saved' );
creature_ff_expect( hash( 'sha256', $gif ) === $saved[0]['sha256'], 'sha256 is of the plaintext gif' );
creature_ff_expect( hash( 'sha256', $png ) === $saved[1]['sha256'], 'sha256 is of the plaintext png' );
creature_ff_expect( 'image/gif' === $saved[0]['mime'] && 'image/png' === $saved[1]['mime'], 'mime comes from the bytes' );
creature_ff_expect( strlen( $gif ) === $saved[0]['size'] && strlen( $png ) === $saved[1]['size'], 'size is the plaintext length' );
creature_ff_expect( preg_match( '/^[a-f0-9]{32}\/[a-f0-9]{32}\.bin$/', $saved[0]['path'] ) === 1, 'stored path is a random bin' );
creature_ff_expect( preg_match( '/^[a-f0-9]{32}\/[a-f0-9]{32}\.bin$/', $saved[1]['path'] ) === 1, 'second stored path is a random bin' );
creature_ff_expect( dirname( $saved[0]['path'] ) === dirname( $saved[1]['path'] ), 'one folder per response' );
creature_ff_expect( $saved[0]['path'] !== $saved[1]['path'], 'each file has its own name' );
$gif_full = $creature_ff_out . '/' . $saved[0]['path'];
$png_full = $creature_ff_out . '/' . $saved[1]['path'];
$folder   = $creature_ff_out . '/' . dirname( $saved[0]['path'] );
creature_ff_expect( is_file( $gif_full ) && false === strpos( (string) file_get_contents( $gif_full ), 'SECRET-PHOTO-BYTES' ), 'file on disk is ciphertext' );
creature_ff_expect( is_file( $folder . '/index.php' ) && '' === file_get_contents( $folder . '/index.php' ), 'response folder gets a blank index.php' );
creature_ff_expect( array() === creature_ff_events_for( 'creature_feedback_files_fetch', 100 ), 'a saved response is not rescheduled' );
$saved_calls = $fake->calls;
do_action( 'creature_feedback_files_fetch', 100 );
creature_ff_expect( $fake->calls === $saved_calls, 'saved files are not fetched again' );

creature_ff_add_post( 101, 8901, array( 'post_date_gmt' => gmdate( 'Y-m-d H:i:s', time() - 2 * DAY_IN_SECONDS ) ) );
update_post_meta(
	101,
	'_creature_feedback_files',
	array(
		array(
			'file_id'  => 3,
			'name'     => 'older.jpg',
			'size'     => 4,
			'status'   => 'pending',
			'attempts' => 0,
			'path'     => dirname( $saved[0]['path'] ) . '/should-not-leak.bin',
		),
	)
);

$GLOBALS['creature_ff_admin'] = false;
creature_ff_expect( false === Creature_Feedback_Files::rest_permission(), 'non-admin fails the permission callback' );
$denied = Creature_Feedback_Files::rest_list( new WP_REST_Request( array( 'form' => 8901 ) ) );
creature_ff_expect( is_wp_error( $denied ) && 403 === $denied->get_error_data()['status'], 'list is 403 for a non-admin' );
$denied_file = Creature_Feedback_Files::rest_download(
	new WP_REST_Request(
		array(
			'response_id' => 100,
			'file_id'     => 9,
		)
	)
);
creature_ff_expect( is_wp_error( $denied_file ) && 403 === $denied_file->get_error_data()['status'], 'download is 403 for a non-admin' );

$GLOBALS['creature_ff_admin'] = true;
$list = Creature_Feedback_Files::rest_list(
	new WP_REST_Request(
		array(
			'form'     => 8901,
			'page'     => 1,
			'per_page' => 1,
		)
	)
);
creature_ff_expect( $list instanceof WP_REST_Response, 'admin list returns a response' );
$list_headers = $list->get_headers();
creature_ff_expect( '2' === $list_headers['X-WP-Total'], 'X-WP-Total counts both responses' );
creature_ff_expect( '2' === $list_headers['X-WP-TotalPages'], 'X-WP-TotalPages honours per_page' );
creature_ff_expect( 1 === count( $list->get_data() ), 'page size is one response' );
$encoded_list = json_encode( $list->get_data() );
creature_ff_expect( false === strpos( $encoded_list, $saved[0]['path'] ), 'listing does not contain the stored path' );
creature_ff_expect( false === strpos( $encoded_list, 'should-not-leak.bin' ), 'listing does not contain a pending path either' );
creature_ff_expect( false === strpos( $encoded_list, '"path"' ), 'listing has no path field' );
creature_ff_expect( false === strpos( $encoded_list, '"attempts"' ), 'listing has no attempts field' );
creature_ff_expect( false !== strpos( $encoded_list, hash( 'sha256', $gif ) ), 'listing includes the plaintext sha256' );
$full_list = Creature_Feedback_Files::rest_list(
	new WP_REST_Request(
		array(
			'form'     => 8901,
			'per_page' => 50,
		)
	)
);
$encoded_full = json_encode( $full_list->get_data() );
creature_ff_expect( false === strpos( $encoded_full, 'should-not-leak.bin' ), 'a full page listing still omits stored paths' );
creature_ff_expect( false === strpos( $encoded_full, '"path"' ), 'a full page listing has no path field' );

$since = gmdate( 'Y-m-d\TH:i:s\Z', time() - DAY_IN_SECONDS );
$since_list = Creature_Feedback_Files::rest_list(
	new WP_REST_Request(
		array(
			'form'  => 8901,
			'since' => $since,
		)
	)
);
$since_ids = array();
foreach ( $since_list->get_data() as $row ) {
	$since_ids[] = $row['response_id'];
}
creature_ff_expect( array( 100 ) === $since_ids, 'since drops responses older than the cutoff' );

$wrong_form = Creature_Feedback_Files::rest_list( new WP_REST_Request( array( 'form' => 42 ) ) );
creature_ff_expect( array() === $wrong_form->get_data() && '0' === $wrong_form->get_headers()['X-WP-Total'], 'a form that is not allowed lists nothing' );

$download = Creature_Feedback_Files::rest_download(
	new WP_REST_Request(
		array(
			'response_id' => 100,
			'file_id'     => 9,
		),
		'/creature/v1/feedback-files/100/9'
	)
);
creature_ff_expect( $download instanceof WP_REST_Response && $gif === $download->get_data(), 'download returns the plaintext' );
$download_headers = $download->get_headers();
creature_ff_expect( 'image/gif' === $download_headers['Content-Type'], 'Content-Type is the sniffed mime' );
creature_ff_expect( 'attachment; filename="weird-name.jpg"' === $download_headers['Content-Disposition'], 'attachment name is sanitised' );
creature_ff_expect( false === strpos( $download_headers['Content-Disposition'], '../' ), 'attachment name has no traversal' );
creature_ff_expect( 'no-store' === $download_headers['Cache-Control'], 'Cache-Control is no-store' );
creature_ff_expect( 'noindex' === $download_headers['X-Robots-Tag'], 'X-Robots-Tag is noindex' );
creature_ff_expect( 'nosniff' === $download_headers['X-Content-Type-Options'], 'X-Content-Type-Options is nosniff' );

ob_start();
$served = Creature_Feedback_Files::serve_download( false, $download, new WP_REST_Request( array(), '/creature/v1/feedback-files/100/9' ), null );
$streamed = ob_get_clean();
creature_ff_expect( true === $served && $gif === $streamed, 'download is streamed as raw bytes' );
ob_start();
$list_served = Creature_Feedback_Files::serve_download( false, $list, new WP_REST_Request( array(), '/creature/v1/feedback-files' ), null );
$list_stream = ob_get_clean();
creature_ff_expect( false === $list_served && '' === $list_stream, 'the list route is not streamed as a file' );

creature_ff_add_post( 4242, 4242 );
update_post_meta(
	4242,
	'_creature_feedback_files',
	array(
		array(
			'file_id' => 9,
			'name'    => 'nope.jpg',
			'status'  => 'saved',
			'path'    => $saved[0]['path'],
			'sha256'  => hash( 'sha256', $gif ),
			'mime'    => 'image/gif',
		),
	)
);
$wrong = Creature_Feedback_Files::rest_download(
	new WP_REST_Request(
		array(
			'response_id' => 4242,
			'file_id'     => 9,
		)
	)
);
creature_ff_expect( is_wp_error( $wrong ) && 404 === $wrong->get_error_data()['status'], 'a response from another form is 404' );
$missing = Creature_Feedback_Files::rest_download(
	new WP_REST_Request(
		array(
			'response_id' => 999999,
			'file_id'     => 9,
		)
	)
);
creature_ff_expect( is_wp_error( $missing ) && 404 === $missing->get_error_data()['status'], 'unknown response is 404' );
$missing_file = Creature_Feedback_Files::rest_download(
	new WP_REST_Request(
		array(
			'response_id' => 100,
			'file_id'     => 404,
		)
	)
);
creature_ff_expect( is_wp_error( $missing_file ) && 404 === $missing_file->get_error_data()['status'], 'unknown file id is 404' );

$original_blob = file_get_contents( $gif_full );
$tampered      = substr( $original_blob, 0, -1 ) . ( "\0" === substr( $original_blob, -1 ) ? "\x01" : "\0" );
file_put_contents( $gif_full, $tampered );
$bad_hash = Creature_Feedback_Files::rest_download(
	new WP_REST_Request(
		array(
			'response_id' => 100,
			'file_id'     => 9,
		)
	)
);
creature_ff_expect( is_wp_error( $bad_hash ) && $gif !== ( is_wp_error( $bad_hash ) ? '' : $bad_hash->get_data() ), 'a tampered file is not streamed' );
file_put_contents( $gif_full, $original_blob );

$fake->bodies        = array( 30 => $pdf );
$fake->content_type  = 'image/jpeg';
$fake->error         = null;
creature_ff_add_post( 300, 8901 );
update_post_meta(
	300,
	'_creature_feedback_files',
	array(
		array(
			'file_id'  => 30,
			'name'     => 'notes.pdf',
			'size'     => 20,
			'status'   => 'pending',
			'attempts' => 0,
		),
	)
);
do_action( 'creature_feedback_files_fetch', 300 );
$mime_row = get_post_meta( 300, '_creature_feedback_files', true )[0];
creature_ff_expect( 'failed' === $mime_row['status'] && 1 === $mime_row['attempts'], 'a non-image is failed and counts an attempt' );
creature_ff_expect( empty( $mime_row['path'] ), 'a rejected file is not stored' );
creature_ff_expect( array() === creature_ff_events_for( 'creature_feedback_files_fetch', 300 ), 'a rejected image is not retried' );
$fake->content_type = 'image/jpeg';

$big = "\xFF\xD8\xFF" . str_repeat( "\0", Creature_Feedback_Files::MAX_BYTES );
creature_ff_expect( strlen( $big ) > Creature_Feedback_Files::MAX_BYTES, 'oversized fixture is over 20 MB' );
$big_inspect = Creature_Feedback_Files::inspect_body( $big );
creature_ff_expect( is_wp_error( $big_inspect ) && 'creature_feedback_files_too_large' === $big_inspect->get_error_code(), 'inspect enforces the 20 MB cap' );
$fake->bodies = array( 31 => $big );
creature_ff_add_post( 301, 8901 );
update_post_meta(
	301,
	'_creature_feedback_files',
	array(
		array(
			'file_id'  => 31,
			'name'     => 'huge.jpg',
			'size'     => 1,
			'status'   => 'pending',
			'attempts' => 0,
		),
	)
);
do_action( 'creature_feedback_files_fetch', 301 );
$big_row = get_post_meta( 301, '_creature_feedback_files', true )[0];
creature_ff_expect( 'failed' === $big_row['status'] && 1 === $big_row['attempts'] && empty( $big_row['path'] ), 'the fetch job rejects a body over 20 MB' );
unset( $big );

$fake->error  = new WP_Error( 'transport', 'Timed out.' );
$fake->bodies = array();
add_filter(
	'creature_feedback_files_alert_email',
	static function ( $email ) {
		unset( $email );
		return 'ops@example.com';
	}
);
creature_ff_add_post( 400, 8901, array( 'post_date_gmt' => gmdate( 'Y-m-d H:i:s', time() - 4 * DAY_IN_SECONDS ) ) );
update_post_meta(
	400,
	'_creature_feedback_files',
	array(
		array(
			'file_id'  => 40,
			'name'     => 'wheel.jpg',
			'size'     => 8,
			'status'   => 'pending',
			'attempts' => 0,
		),
	)
);
$mail_before = count( $GLOBALS['creature_ff_mail'] );
$retry_from  = time();
do_action( 'creature_feedback_files_fetch', 400 );
$retry_to = time();
$alerted  = get_post_meta( 400, '_creature_feedback_files', true )[0];
creature_ff_expect( 'pending' === $alerted['status'] && 1 === $alerted['attempts'], 'a transport failure stays pending and increments attempts' );
$retry_events = creature_ff_events_for( 'creature_feedback_files_fetch', 400 );
creature_ff_expect( 1 === count( $retry_events ), 'a transport failure reschedules one event' );
creature_ff_expect(
	$retry_events[0]['timestamp'] >= $retry_from + HOUR_IN_SECONDS && $retry_events[0]['timestamp'] <= $retry_to + HOUR_IN_SECONDS,
	'retry is an hour later'
);
creature_ff_expect( count( $GLOBALS['creature_ff_mail'] ) === $mail_before + 1, 'an old unsaved response sends one alert' );
$alert = $GLOBALS['creature_ff_mail'][ count( $GLOBALS['creature_ff_mail'] ) - 1 ];
creature_ff_expect( 'ops@example.com' === $alert['to'], 'alert recipient follows the filter' );
creature_ff_expect( false !== strpos( $alert['message'], '400' ), 'alert names the response id' );
creature_ff_expect( false === strpos( $alert['message'], 'wheel.jpg' ), 'alert does not include the file name' );
creature_ff_expect( false === strpos( $alert['subject'] . $alert['message'], $stored_key ), 'alert does not include the key' );
creature_ff_expect( '1' === get_post_meta( 400, '_creature_feedback_files_alerted', true ), 'alert flag is stored' );
do_action( 'creature_feedback_files_fetch', 400 );
creature_ff_expect( count( $GLOBALS['creature_ff_mail'] ) === $mail_before + 1, 'the alert is not sent again' );
creature_ff_expect( 2 === get_post_meta( 400, '_creature_feedback_files', true )[0]['attempts'], 'retries keep incrementing attempts' );
creature_ff_clear_filter( 'creature_feedback_files_alert_email' );

creature_ff_add_post( 401, 8901, array( 'post_date_gmt' => gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS ) ) );
update_post_meta(
	401,
	'_creature_feedback_files',
	array(
		array(
			'file_id'  => 41,
			'name'     => 'young.jpg',
			'size'     => 8,
			'status'   => 'pending',
			'attempts' => 0,
		),
	)
);
do_action( 'creature_feedback_files_fetch', 401 );
creature_ff_expect( count( $GLOBALS['creature_ff_mail'] ) === $mail_before + 1, 'a response younger than 3 days does not alert' );
creature_ff_expect( '' === get_post_meta( 401, '_creature_feedback_files_alerted', true ), 'a young response has no alert flag' );
$fake->error = null;

$ret_gif = 'GIF89aRETENTION-FILE-OK';
$fake->bodies = array(
	50 => $ret_gif,
	51 => $ret_gif,
	52 => $ret_gif,
	53 => $ret_gif,
	54 => $ret_gif,
);
$retention_posts = array(
	500 => array( 50, 51 ),
	501 => array( 52 ),
	502 => array( 53 ),
	503 => array( 54 ),
);
foreach ( $retention_posts as $post_id => $file_ids ) {
	creature_ff_add_post( $post_id, 8901, array( 'post_content' => 'jetpack-original-' . $post_id ) );
	$rows = array();
	foreach ( $file_ids as $file_id ) {
		$rows[] = array(
			'file_id'  => $file_id,
			'name'     => 'keep.jpg',
			'size'     => 4,
			'status'   => 'pending',
			'attempts' => 0,
		);
	}
	update_post_meta( $post_id, '_creature_feedback_files', $rows );
	do_action( 'creature_feedback_files_fetch', $post_id );
}
$mixed = get_post_meta( 500, '_creature_feedback_files', true );
$mixed[0]['saved_at'] = gmdate( 'Y-m-d H:i:s', time() - 400 * DAY_IN_SECONDS );
$mixed[1]['saved_at'] = gmdate( 'Y-m-d H:i:s', time() - 10 * DAY_IN_SECONDS );
update_post_meta( 500, '_creature_feedback_files', $mixed );
$only_old = get_post_meta( 501, '_creature_feedback_files', true );
$only_old[0]['saved_at'] = gmdate( 'Y-m-d H:i:s', time() - 400 * DAY_IN_SECONDS );
update_post_meta( 501, '_creature_feedback_files', $only_old );
$keep_20 = get_post_meta( 502, '_creature_feedback_files', true );
$keep_20[0]['saved_at'] = gmdate( 'Y-m-d H:i:s', time() - 20 * DAY_IN_SECONDS );
update_post_meta( 502, '_creature_feedback_files', $keep_20 );
$drop_40 = get_post_meta( 503, '_creature_feedback_files', true );
$drop_40[0]['saved_at'] = gmdate( 'Y-m-d H:i:s', time() - 40 * DAY_IN_SECONDS );
update_post_meta( 503, '_creature_feedback_files', $drop_40 );

$mixed_old_path = $creature_ff_out . '/' . $mixed[0]['path'];
$mixed_new_path = $creature_ff_out . '/' . $mixed[1]['path'];
$only_old_dir   = $creature_ff_out . '/' . dirname( $only_old[0]['path'] );
$only_old_path  = $creature_ff_out . '/' . $only_old[0]['path'];
$content_before = array();
foreach ( array( 500, 501, 502, 503 ) as $post_id ) {
	$content_before[ $post_id ] = get_post( $post_id )->post_content;
}

do_action( 'creature_feedback_files_retention' );
clearstatcache();
$mixed_after = get_post_meta( 500, '_creature_feedback_files', true );
creature_ff_expect( 'purged' === $mixed_after[0]['status'] && 'saved' === $mixed_after[1]['status'], 'retention purges only the file older than 365 days' );
creature_ff_expect( ! is_file( $mixed_old_path ) && is_file( $mixed_new_path ), 'the old bin is gone and the recent bin stays' );
creature_ff_expect( is_dir( dirname( $mixed_new_path ) ), 'a folder that still holds a file stays' );
$only_after = get_post_meta( 501, '_creature_feedback_files', true );
creature_ff_expect( 'purged' === $only_after[0]['status'] && ! is_file( $only_old_path ), 'a response whose only file is old is purged' );
creature_ff_expect( ! is_dir( $only_old_dir ), 'an empty response folder is removed' );
creature_ff_expect( is_dir( $creature_ff_out ) && is_file( $creature_ff_out . '/index.php' ), 'the private root stays' );
creature_ff_expect( 'saved' === get_post_meta( 502, '_creature_feedback_files', true )[0]['status'], 'a 20 day old file stays under the 365 day default' );
creature_ff_expect( 'saved' === get_post_meta( 503, '_creature_feedback_files', true )[0]['status'], 'a 40 day old file stays under the 365 day default' );
foreach ( $content_before as $post_id => $content ) {
	creature_ff_expect( get_post( $post_id ) instanceof Creature_FF_Post && $content === get_post( $post_id )->post_content, "feedback post {$post_id} is untouched" );
}
creature_ff_expect( array() === $GLOBALS['creature_ff_deleted_posts'], 'retention does not delete posts or attachments' );

add_filter(
	'creature_feedback_files_retention_days',
	static function ( $days ) {
		unset( $days );
		return 5;
	}
);
creature_ff_expect( 30 === Creature_Feedback_Files::retention_days(), 'retention shorter than 30 days is raised to 30' );
do_action( 'creature_feedback_files_retention' );
clearstatcache();
creature_ff_expect( 'saved' === get_post_meta( 502, '_creature_feedback_files', true )[0]['status'], '20 days is inside the 30 day minimum' );
creature_ff_expect( 'purged' === get_post_meta( 503, '_creature_feedback_files', true )[0]['status'], '40 days is past the 30 day minimum' );
creature_ff_expect( 'saved' === get_post_meta( 500, '_creature_feedback_files', true )[1]['status'], 'the 10 day file stays when the window is 30 days' );
creature_ff_expect( array() === $GLOBALS['creature_ff_deleted_posts'], 'the shorter window still does not delete the feedback post' );
creature_ff_clear_filter( 'creature_feedback_files_retention_days' );

do_action( 'init' );
do_action( 'init' );
$daily = creature_ff_events_for( 'creature_feedback_files_retention' );
creature_ff_expect( 1 === count( $daily ) && 'daily' === $daily[0]['schedule'], 'retention cron is scheduled once daily' );

$backfill_payload = array(
	'fields' => array(
		array(
			'key'   => 'photo',
			'label' => 'Photo',
			'type'  => 'file',
			'value' => array(
				'files' => array(
					array(
						'file_id' => 55,
						'name'    => 'wheel.jpg',
						'size'    => 1200,
						'type'    => 'image/jpeg',
					),
				),
			),
		),
	),
);
creature_ff_add_post(
	600,
	8901,
	array(
		'post_content' => addslashes( json_encode( $backfill_payload ) ),
	)
);
$calls_before_backfill = $fake->calls;
$dry = Creature_Feedback_Files::backfill_post( 600, true );
creature_ff_expect( is_array( $dry ) && ! empty( $dry['dry_run'] ) && 55 === $dry['files'][0]['file_id'], 'dry-run reports the existing file' );
creature_ff_expect( '' === get_post_meta( 600, '_creature_feedback_files', true ), 'dry-run does not write meta' );
creature_ff_expect( array() === creature_ff_events_for( 'creature_feedback_files_fetch', 600 ), 'dry-run does not schedule' );
creature_ff_expect( $fake->calls === $calls_before_backfill, 'dry-run does not download' );

$written = Creature_Feedback_Files::backfill_post( 600, false );
$backfill_meta = get_post_meta( 600, '_creature_feedback_files', true );
creature_ff_expect( is_array( $written ) && empty( $written['dry_run'] ), 'yes-run returns a write result' );
creature_ff_expect( is_array( $backfill_meta ) && 'pending' === $backfill_meta[0]['status'] && 55 === $backfill_meta[0]['file_id'], 'yes-run queues the existing file' );
creature_ff_expect( 1 === count( creature_ff_events_for( 'creature_feedback_files_fetch', 600 ) ), 'yes-run schedules the fetch' );
creature_ff_expect( $fake->calls === $calls_before_backfill, 'backfill does not download during the command' );

creature_ff_add_post( 602, 777, array( 'post_content' => get_post( 600 )->post_content ) );
$wrong_backfill = Creature_Feedback_Files::backfill_post( 602, false );
creature_ff_expect( is_wp_error( $wrong_backfill ), 'backfill refuses a response from another form' );
creature_ff_expect( '' === get_post_meta( 602, '_creature_feedback_files', true ), 'refused backfill writes nothing' );

if ( ! defined( 'WP_CLI' ) ) {
	define( 'WP_CLI', true );
}
Creature_Feedback_Files::register_cli();
creature_ff_expect( 'Creature_Feedback_Files_CLI' === $GLOBALS['creature_ff_commands']['creature-feedback'], 'wp creature-feedback is registered' );

creature_ff_add_post( 603, 8901, array( 'post_content' => get_post( 600 )->post_content ) );
$cli = new Creature_Feedback_Files_CLI();
WP_CLI::$logs   = array();
WP_CLI::$errors = array();
$cli->backfill( array(), array( 'post' => 603, 'dry-run' => true ) );
creature_ff_expect( '' === get_post_meta( 603, '_creature_feedback_files', true ), 'CLI dry-run writes nothing' );
creature_ff_expect( false !== strpos( implode( "\n", WP_CLI::$logs ), 'Dry run' ), 'CLI dry-run says so' );
creature_ff_expect( false !== strpos( implode( "\n", WP_CLI::$logs ), '55' ), 'CLI dry-run prints the file id' );
WP_CLI::$logs = array();
$cli->backfill( array(), array( 'post' => 603, 'yes' => true ) );
creature_ff_expect( is_array( get_post_meta( 603, '_creature_feedback_files', true ) ), 'CLI --yes queues the response' );
creature_ff_expect( false !== strpos( implode( "\n", WP_CLI::$logs ), 'Queued' ), 'CLI --yes reports the queue' );
WP_CLI::$errors = array();
$cli->backfill( array(), array( 'post' => 602, 'yes' => true ) );
creature_ff_expect( 1 === count( WP_CLI::$errors ), 'CLI reports a response from another form' );

if ( $failures > 0 ) {
	fwrite( STDERR, "{$failures} failed, {$checks} checks\n" );
	exit( 1 );
}

echo "{$checks} checks passed\n";
exit( 0 );
