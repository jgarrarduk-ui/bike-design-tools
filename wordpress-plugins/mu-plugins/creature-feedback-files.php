<?php
/**
 * Plugin Name: Creature Cycles Feedback Files
 * Description: Keep private encrypted copies of photos uploaded through the Jetpack Form on the demo feedback page. Must-use plugin. Does not download files during the visitor's request.
 * Version: 0.1.0
 * Author: Creature Cycles
 * License: GPL-2.0-or-later
 *
 * Copy this file to wp-content/mu-plugins/ (not into a subfolder). It loads on
 * the next request. There is no activate step and no uninstall hook.
 *
 * Jetpack's own file links (admin-ajax.php?action=jetpack_unauth_file_download)
 * expire after 7 days and need a logged-in admin. This plugin queues a private
 * copy when a response is saved on an allowed form page, then a cron job
 * fetches the bytes. The concrete fetch is not decided yet: both built-in
 * fetchers return WP_Error( 'not_implemented' ).
 *
 * Nothing here touches WooCommerce, prices, or the Jetpack feedback post.
 */

if ( ! defined( 'ABSPATH' ) && PHP_SAPI !== 'cli' ) {
	exit;
}

if ( ! defined( 'HOUR_IN_SECONDS' ) ) {
	define( 'HOUR_IN_SECONDS', 3600 );
}

if ( ! defined( 'DAY_IN_SECONDS' ) ) {
	define( 'DAY_IN_SECONDS', 86400 );
}

/**
 * How a Jetpack file id becomes bytes.
 *
 * The concrete method is undecided until a read-only check of the WordPress.com
 * handler. Callers must not trust content_type; image bytes are sniffed later.
 */
interface Creature_Feedback_Files_Fetcher {

	/**
	 * Fetch one Jetpack file by id.
	 *
	 * @param int $file_id Jetpack file id.
	 * @return array{body: string, content_type: string}|WP_Error
	 */
	public function fetch( int $file_id );
}

/**
 * Option (a): a redirect or signed URL, or an internal Jetpack function.
 *
 * Not implemented. Do not log the URL or the body when this is filled in.
 */
final class Creature_Feedback_Files_Fetcher_Direct implements Creature_Feedback_Files_Fetcher {

	/**
	 * @param int $file_id Jetpack file id.
	 * @return array{body: string, content_type: string}|WP_Error
	 */
	public function fetch( int $file_id ) {
		unset( $file_id );
		// TODO: option (a) — follow a redirect or signed URL, or call an internal Jetpack function.
		// The fetch method stays undecided until a read-only check of the WP.com handler.
		return new WP_Error( 'not_implemented', 'Direct file fetch is not implemented.' );
	}
}

/**
 * Option (b): loopback to admin-ajax with a short-lived cookie for one Editor.
 *
 * Not implemented. Do not log the cookie or the body when this is filled in.
 */
final class Creature_Feedback_Files_Fetcher_Loopback implements Creature_Feedback_Files_Fetcher {

	/**
	 * @param int $file_id Jetpack file id.
	 * @return array{body: string, content_type: string}|WP_Error
	 */
	public function fetch( int $file_id ) {
		unset( $file_id );
		// TODO: option (b) — loopback to admin-ajax.php?action=jetpack_unauth_file_download
		// with a short-lived auth cookie for a dedicated Editor user.
		return new WP_Error( 'not_implemented', 'Loopback file fetch is not implemented.' );
	}
}

/**
 * Queue, store, and serve private copies of Jetpack Form photos.
 */
final class Creature_Feedback_Files {

	const VERSION          = '0.1.0';
	const META             = '_creature_feedback_files';
	const ALERT_META       = '_creature_feedback_files_alerted';
	const KEY_OPTION       = 'creature_feedback_files_key';
	const KEY_EXPORTED_OPTION = 'creature_feedback_files_key_exported_at';
	const CRON_FETCH       = 'creature_feedback_files_fetch';
	const CRON_RETENTION   = 'creature_feedback_files_retention';
	const REST_NAMESPACE   = 'creature/v1';
	const MAX_BYTES        = 20971520;
	const FETCH_DELAY      = 120;
	const DEFAULT_FORM_ID  = 8901;
	const RETENTION_DAYS   = 365;
	const MIN_RETENTION    = 30;
	const ALERT_AFTER_DAYS = 3;

	/**
	 * Form page ids whose feedback posts are copied. Default is /demo-feedback/.
	 *
	 * @return int[]
	 */
	public static function form_ids() {
		$ids = apply_filters( 'creature_feedback_files_form_ids', array( self::DEFAULT_FORM_ID ) );
		return self::normalize_ids( $ids );
	}

	/**
	 * Days to keep a saved copy. Values under 30 are raised to 30.
	 *
	 * @return int
	 */
	public static function retention_days() {
		$days = (int) apply_filters( 'creature_feedback_files_retention_days', self::RETENTION_DAYS );
		if ( $days < self::MIN_RETENTION ) {
			$days = self::MIN_RETENTION;
		}
		return $days;
	}

	/**
	 * Active fetcher. Filter creature_feedback_files_fetcher to choose one.
	 *
	 * @return Creature_Feedback_Files_Fetcher
	 */
	public static function fetcher() {
		$fetcher = apply_filters( 'creature_feedback_files_fetcher', new Creature_Feedback_Files_Fetcher_Direct() );
		if ( $fetcher instanceof Creature_Feedback_Files_Fetcher ) {
			return $fetcher;
		}
		return new Creature_Feedback_Files_Fetcher_Direct();
	}

	/**
	 * True when --yes was not passed. Dry-run is the default.
	 *
	 * @param array $assoc_args WP-CLI flags.
	 * @return bool
	 */
	public static function backfill_is_dry( $assoc_args ) {
		if ( ! is_array( $assoc_args ) ) {
			return true;
		}
		return empty( $assoc_args['yes'] );
	}

	/**
	 * Feedback post on an allowed form page.
	 *
	 * @param mixed $post Post object.
	 * @return bool
	 */
	public static function is_allowed_feedback( $post ) {
		if ( ! is_object( $post ) || empty( $post->ID ) ) {
			return false;
		}
		if ( ! isset( $post->post_type ) || 'feedback' !== $post->post_type ) {
			return false;
		}
		$parent = isset( $post->post_parent ) ? (int) $post->post_parent : 0;
		return in_array( $parent, self::form_ids(), true );
	}

	/**
	 * Queue files after Jetpack inserts a feedback post.
	 *
	 * Downloads nothing. The visitor's request only writes post meta and
	 * schedules creature_feedback_files_fetch.
	 *
	 * @param int   $post_id      Feedback post id.
	 * @param mixed $fields       Visible form fields.
	 * @param bool  $is_spam      Whether Jetpack marked the response as spam.
	 * @param mixed $entry_values Feedback entry values.
	 * @return void
	 */
	public static function on_feedback_inserted( $post_id, $fields, $is_spam, $entry_values = array() ) {
		if ( $is_spam ) {
			return;
		}

		$post = get_post( (int) $post_id );
		if ( ! self::is_allowed_feedback( $post ) ) {
			return;
		}

		$files = self::dedupe_files(
			array_merge(
				self::collect_files( $fields, $entry_values ),
				self::files_from_post( $post )
			)
		);
		if ( ! $files ) {
			return;
		}

		self::queue( (int) $post->ID, $files );
	}

	/**
	 * File rows from hook args. A Jetpack file field value has a files list
	 * of {file_id, name, size, ...}. A raw list of JSON strings (the shape
	 * Contact_Form_Field holds during submit) is accepted too.
	 *
	 * @param mixed $fields       Fields from the hook.
	 * @param mixed $entry_values Entry values from the hook.
	 * @return array<int, array>
	 */
	public static function collect_files( $fields, $entry_values = array() ) {
		$found = array();
		self::walk_for_files( $fields, $found );
		self::walk_for_files( $entry_values, $found );
		return self::dedupe_files( $found );
	}

	/**
	 * File rows already stored on a feedback post (v3 JSON content or meta).
	 *
	 * @param object $post Feedback post.
	 * @return array<int, array>
	 */
	public static function files_from_post( $post ) {
		$found = array();
		if ( is_object( $post ) && isset( $post->post_content ) ) {
			self::walk_for_files( self::decode_content( $post->post_content ), $found );
		}
		$post_id = ( is_object( $post ) && isset( $post->ID ) ) ? (int) $post->ID : 0;
		if ( $post_id > 0 ) {
			foreach ( array( '_feedback_all_fields', '_feedback_extra_fields' ) as $key ) {
				self::walk_for_files( get_post_meta( $post_id, $key, true ), $found );
			}
		}
		return self::dedupe_files( $found );
	}

	/**
	 * Write the pending list and schedule one fetch 120 seconds later.
	 *
	 * @param int   $post_id Feedback post id.
	 * @param array $files   Pending file rows.
	 * @return void
	 */
	public static function queue( $post_id, array $files ) {
		update_post_meta( (int) $post_id, self::META, array_values( $files ) );
		self::schedule_fetch( (int) $post_id, time() + self::FETCH_DELAY );
	}

	/**
	 * Queue one existing response. Dry-run writes nothing.
	 *
	 * @param int  $post_id Feedback post id.
	 * @param bool $dry_run When true, do not write meta or schedule cron.
	 * @return array|WP_Error
	 */
	public static function backfill_post( $post_id, $dry_run = true ) {
		$post_id = (int) $post_id;
		$post    = get_post( $post_id );
		if ( ! is_object( $post ) ) {
			return new WP_Error( 'creature_feedback_files_backfill', 'Feedback post not found.' );
		}
		if ( ! self::is_allowed_feedback( $post ) ) {
			return new WP_Error( 'creature_feedback_files_backfill', 'That response is not from an allowed form.' );
		}

		$files = self::files_from_post( $post );
		if ( ! $files ) {
			return new WP_Error( 'creature_feedback_files_backfill', 'No file fields on that response.' );
		}

		if ( $dry_run ) {
			return array(
				'dry_run' => true,
				'post_id' => $post_id,
				'files'   => $files,
			);
		}

		self::queue( $post_id, $files );
		return array(
			'dry_run' => false,
			'post_id' => $post_id,
			'files'   => $files,
		);
	}

	/**
	 * Cron: fetch pending files for one response. Failures retry in an hour.
	 *
	 * A body that is not an image, or is over 20 MB, is marked failed and is
	 * not fetched again. Those bytes will not change on a retry.
	 *
	 * @param int $post_id Feedback post id.
	 * @return void
	 */
	public static function on_fetch( $post_id ) {
		$post_id = (int) $post_id;
		self::unschedule_fetch( $post_id );

		$post = get_post( $post_id );
		if ( ! self::is_allowed_feedback( $post ) ) {
			return;
		}

		$files = get_post_meta( $post_id, self::META, true );
		if ( ! is_array( $files ) ) {
			return;
		}

		$fetcher = self::fetcher();
		$folder  = self::folder_for( $files );
		$pending = false;

		foreach ( $files as $index => $file ) {
			if ( ! is_array( $file ) ) {
				continue;
			}
			$status = isset( $file['status'] ) ? (string) $file['status'] : '';
			if ( 'pending' !== $status ) {
				continue;
			}
			$files[ $index ] = self::fetch_and_store( $file, $fetcher, $folder );
			if ( isset( $files[ $index ]['status'] ) && 'pending' === $files[ $index ]['status'] ) {
				$pending = true;
			}
		}

		update_post_meta( $post_id, self::META, $files );

		if ( $pending ) {
			self::schedule_fetch( $post_id, time() + HOUR_IN_SECONDS );
		}

		self::maybe_alert( $post, $files );
	}

	/**
	 * Daily pass. Deletes saved copies older than the retention window.
	 *
	 * Does not delete the Jetpack feedback post or Jetpack's own file.
	 *
	 * @return void
	 */
	public static function on_retention() {
		if ( ! class_exists( 'WP_Query' ) ) {
			return;
		}

		$cutoff = time() - ( self::retention_days() * DAY_IN_SECONDS );
		$paged  = 1;

		do {
			$query = new WP_Query(
				array(
					'post_type'      => 'feedback',
					'post_status'    => 'any',
					'posts_per_page' => 50,
					'paged'          => $paged,
					'orderby'        => 'ID',
					'order'          => 'ASC',
					'fields'         => 'ids',
					'meta_key'       => self::META,
				)
			);
			$ids = ( isset( $query->posts ) && is_array( $query->posts ) ) ? $query->posts : array();
			if ( ! $ids ) {
				break;
			}
			foreach ( $ids as $post_id ) {
				if ( is_object( $post_id ) && isset( $post_id->ID ) ) {
					$post_id = $post_id->ID;
				}
				self::purge_post( (int) $post_id, $cutoff );
			}
			if ( count( $ids ) < 50 ) {
				break;
			}
			$paged++;
		} while ( $paged < 100 );
	}

	/**
	 * Schedule the daily retention event once.
	 *
	 * @return void
	 */
	public static function schedule_retention() {
		if ( wp_next_scheduled( self::CRON_RETENTION ) ) {
			return;
		}
		wp_schedule_event( time() + DAY_IN_SECONDS, 'daily', self::CRON_RETENTION );
	}

	/**
	 * REST routes. Both require manage_options. There is no delete route.
	 *
	 * @return void
	 */
	public static function register_routes() {
		register_rest_route(
			self::REST_NAMESPACE,
			'/feedback-files',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'rest_list' ),
				'permission_callback' => array( __CLASS__, 'rest_permission' ),
				'args'                => array(
					'since'    => array(
						'type'     => 'string',
						'required' => false,
					),
					'form'     => array(
						'type'     => 'integer',
						'default'  => self::DEFAULT_FORM_ID,
						'required' => false,
					),
					'page'     => array(
						'type'     => 'integer',
						'default'  => 1,
						'required' => false,
					),
					'per_page' => array(
						'type'     => 'integer',
						'default'  => 20,
						'required' => false,
					),
				),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/feedback-files/(?P<response_id>\d+)/(?P<file_id>\d+)',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'rest_download' ),
				'permission_callback' => array( __CLASS__, 'rest_permission' ),
			)
		);
	}

	/**
	 * @return bool
	 */
	public static function rest_permission() {
		return current_user_can( 'manage_options' );
	}

	/**
	 * List saved and pending files. Stored paths are never included.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function rest_list( $request ) {
		if ( ! self::rest_permission() ) {
			return self::rest_forbidden();
		}

		$form = (int) self::request_param( $request, 'form' );
		if ( $form <= 0 ) {
			$ids  = self::form_ids();
			$form = $ids ? (int) $ids[0] : 0;
		}

		$page = (int) self::request_param( $request, 'page' );
		if ( $page < 1 ) {
			$page = 1;
		}

		$per_page = (int) self::request_param( $request, 'per_page' );
		if ( $per_page < 1 ) {
			$per_page = 20;
		}
		if ( $per_page > 100 ) {
			$per_page = 100;
		}

		$since = self::request_param( $request, 'since' );
		$after = '';
		if ( is_string( $since ) && '' !== $since ) {
			$stamp = strtotime( $since );
			if ( ! $stamp ) {
				return new WP_Error(
					'creature_feedback_files_since',
					'since must be an ISO8601 time.',
					array( 'status' => 400 )
				);
			}
			$after = gmdate( 'Y-m-d H:i:s', $stamp );
		}

		$items = array();
		$total = 0;
		$pages = 0;

		if ( in_array( $form, self::form_ids(), true ) && class_exists( 'WP_Query' ) ) {
			$args = array(
				'post_type'      => 'feedback',
				'post_status'    => 'any',
				'post_parent'    => $form,
				'posts_per_page' => $per_page,
				'paged'          => $page,
				'orderby'        => 'date',
				'order'          => 'DESC',
				'meta_key'       => self::META,
			);
			if ( '' !== $after ) {
				$args['date_query'] = array(
					array(
						'after'     => $after,
						'inclusive' => true,
						'column'    => 'post_date_gmt',
					),
				);
			}

			$query = new WP_Query( $args );
			$posts = ( isset( $query->posts ) && is_array( $query->posts ) ) ? $query->posts : array();
			$total = isset( $query->found_posts ) ? (int) $query->found_posts : count( $posts );
			$pages = isset( $query->max_num_pages ) ? (int) $query->max_num_pages : 0;

			foreach ( $posts as $post ) {
				if ( is_numeric( $post ) ) {
					$post = get_post( (int) $post );
				}
				if ( ! self::is_allowed_feedback( $post ) ) {
					continue;
				}
				$items[] = self::public_response( $post );
			}
		}

		$response = new WP_REST_Response( $items, 200 );
		$response->header( 'X-WP-Total', (string) $total );
		$response->header( 'X-WP-TotalPages', (string) $pages );
		return $response;
	}

	/**
	 * Decrypt one file and return the bytes. Unknown ids and other forms are 404.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function rest_download( $request ) {
		if ( ! self::rest_permission() ) {
			return self::rest_forbidden();
		}

		$prepared = self::prepare_download(
			(int) self::request_param( $request, 'response_id' ),
			(int) self::request_param( $request, 'file_id' )
		);
		if ( is_wp_error( $prepared ) ) {
			return $prepared;
		}

		$response = new WP_REST_Response( $prepared['body'], 200 );
		foreach ( $prepared['headers'] as $name => $value ) {
			$response->header( $name, $value );
		}
		return $response;
	}

	/**
	 * Stream a download as raw bytes so the REST server does not JSON-encode it.
	 *
	 * @param bool              $served  Whether the request was already served.
	 * @param WP_REST_Response  $result  Callback result.
	 * @param WP_REST_Request   $request Request.
	 * @param mixed             $server  REST server.
	 * @return bool
	 */
	public static function serve_download( $served, $result, $request, $server ) {
		unset( $server );
		if ( $served || ! is_object( $result ) || ! is_object( $request ) ) {
			return $served;
		}
		if ( ! method_exists( $request, 'get_route' ) || ! method_exists( $result, 'get_data' ) ) {
			return $served;
		}

		$route = (string) $request->get_route();
		if ( ! preg_match( '#^/creature/v1/feedback-files/\d+/\d+$#', $route ) ) {
			return $served;
		}

		$data = $result->get_data();
		if ( ! is_string( $data ) ) {
			return $served;
		}

		$headers = method_exists( $result, 'get_headers' ) ? $result->get_headers() : array();
		if ( PHP_SAPI !== 'cli' && ! headers_sent() && is_array( $headers ) ) {
			foreach ( $headers as $name => $value ) {
				header( $name . ': ' . $value );
			}
		}

		echo $data;
		return true;
	}

	/**
	 * Sniff an image type from the leading bytes. The HTTP Content-Type is ignored.
	 *
	 * @param string $bytes File bytes.
	 * @return string MIME type, or an empty string when the bytes are not an image.
	 */
	public static function sniff_image_mime( $bytes ) {
		if ( ! is_string( $bytes ) || strlen( $bytes ) < 12 ) {
			return '';
		}
		if ( 0 === strncmp( $bytes, "\xFF\xD8\xFF", 3 ) ) {
			return 'image/jpeg';
		}
		if ( 0 === strncmp( $bytes, "\x89PNG\r\n\x1A\n", 8 ) ) {
			return 'image/png';
		}
		if ( 0 === strncmp( $bytes, 'GIF87a', 6 ) || 0 === strncmp( $bytes, 'GIF89a', 6 ) ) {
			return 'image/gif';
		}
		if ( 0 === strncmp( $bytes, 'RIFF', 4 ) && 'WEBP' === substr( $bytes, 8, 4 ) ) {
			return 'image/webp';
		}
		if ( 'ftyp' === substr( $bytes, 4, 4 ) ) {
			$brand = substr( $bytes, 8, 4 );
			if ( in_array( $brand, array( 'avif', 'avis' ), true ) ) {
				return 'image/avif';
			}
			if ( in_array( $brand, array( 'heic', 'heix', 'heif', 'mif1', 'msf1' ), true ) ) {
				return 'image/heic';
			}
		}
		return '';
	}

	/**
	 * Reject a non-image or a body over 20 MB, and hash the plaintext.
	 *
	 * @param string $body Download body.
	 * @return array{mime: string, sha256: string, size: int}|WP_Error
	 */
	public static function inspect_body( $body ) {
		if ( ! is_string( $body ) || '' === $body ) {
			return new WP_Error( 'creature_feedback_files_body', 'The download was empty.' );
		}
		if ( strlen( $body ) > self::MAX_BYTES ) {
			return new WP_Error( 'creature_feedback_files_too_large', 'The file is larger than 20 MB.' );
		}
		$mime = self::sniff_image_mime( $body );
		if ( '' === $mime ) {
			return new WP_Error( 'creature_feedback_files_mime', 'The file is not an image.' );
		}
		return array(
			'mime'   => $mime,
			'sha256' => hash( 'sha256', $body ),
			'size'   => strlen( $body ),
		);
	}

	/**
	 * Encrypt with a random nonce prepended. Does not log the key or the plaintext.
	 *
	 * @param string $plaintext Bytes to store.
	 * @return string|WP_Error Nonce concatenated with the secretbox ciphertext.
	 */
	public static function encrypt_blob( $plaintext ) {
		if ( ! function_exists( 'sodium_crypto_secretbox' ) ) {
			return new WP_Error( 'creature_feedback_files_sodium', 'libsodium is not available.' );
		}
		if ( ! is_string( $plaintext ) ) {
			return new WP_Error( 'creature_feedback_files_body', 'Nothing to store.' );
		}

		$key = self::key_bytes();
		if ( is_wp_error( $key ) ) {
			return $key;
		}

		$nonce  = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		$cipher = sodium_crypto_secretbox( $plaintext, $nonce, $key );
		return $nonce . $cipher;
	}

	/**
	 * Open a blob written by encrypt_blob.
	 *
	 * @param string $blob Nonce prepended to ciphertext.
	 * @return string|WP_Error
	 */
	public static function decrypt_blob( $blob ) {
		if ( ! function_exists( 'sodium_crypto_secretbox_open' ) ) {
			return new WP_Error( 'creature_feedback_files_sodium', 'libsodium is not available.' );
		}
		if ( ! is_string( $blob ) ) {
			return new WP_Error( 'creature_feedback_files_decrypt', 'Could not read the stored file.' );
		}

		$nonce_len = SODIUM_CRYPTO_SECRETBOX_NONCEBYTES;
		if ( strlen( $blob ) < $nonce_len + SODIUM_CRYPTO_SECRETBOX_MACBYTES ) {
			return new WP_Error( 'creature_feedback_files_decrypt', 'Could not read the stored file.' );
		}

		$key = self::key_bytes();
		if ( is_wp_error( $key ) ) {
			return $key;
		}

		$nonce  = substr( $blob, 0, $nonce_len );
		$cipher = substr( $blob, $nonce_len );
		$plain  = sodium_crypto_secretbox_open( $cipher, $nonce, $key );
		if ( ! is_string( $plain ) ) {
			return new WP_Error( 'creature_feedback_files_decrypt', 'Could not read the stored file.' );
		}
		return $plain;
	}

	/**
	 * First writable directory outside ABSPATH, else uploads/creature-private.
	 *
	 * @return string|WP_Error
	 */
	public static function base_dir() {
		$candidates = apply_filters( 'creature_feedback_files_private_dirs', self::default_private_dirs() );
		if ( is_array( $candidates ) ) {
			foreach ( $candidates as $dir ) {
				$usable = self::usable_private_dir( $dir );
				if ( is_string( $usable ) && '' !== $usable ) {
					return $usable;
				}
			}
		}

		$uploads  = wp_upload_dir();
		$fallback = '';
		if ( is_array( $uploads ) && ! empty( $uploads['basedir'] ) ) {
			$fallback = rtrim( (string) $uploads['basedir'], '/\\' ) . '/creature-private';
		}
		$created = self::prepare_dir( $fallback );
		if ( is_string( $created ) && '' !== $created ) {
			return $created;
		}
		return new WP_Error( 'creature_feedback_files_dir', 'No private directory is writable.' );
	}

	/**
	 * Default private directory: a sibling of the WordPress root.
	 *
	 * @return string[]
	 */
	public static function default_private_dirs() {
		$root   = defined( 'ABSPATH' ) ? self::normalize_path( ABSPATH ) : '';
		$parent = ( '' !== $root ) ? dirname( $root ) : '';
		return array( $parent . '/creature-private' );
	}

	/**
	 * Public list row. Paths and attempt counts stay in post meta.
	 *
	 * @param object $post Feedback post.
	 * @return array
	 */
	public static function public_response( $post ) {
		$files = get_post_meta( (int) $post->ID, self::META, true );
		if ( ! is_array( $files ) ) {
			$files = array();
		}
		$public_files = array();
		foreach ( $files as $file ) {
			if ( is_array( $file ) ) {
				$public_files[] = self::public_file( $file );
			}
		}
		$submitted = isset( $post->post_date_gmt ) ? $post->post_date_gmt : '';
		return array(
			'response_id'   => (int) $post->ID,
			'submitted_gmt' => self::iso_gmt( $submitted ),
			'status'        => self::response_status( $files ),
			'files'         => $public_files,
		);
	}

	/**
	 * @param array $file Stored file row.
	 * @return array
	 */
	public static function public_file( array $file ) {
		$saved = isset( $file['saved_at'] ) ? self::iso_gmt( $file['saved_at'] ) : '';
		return array(
			'file_id'  => isset( $file['file_id'] ) ? (int) $file['file_id'] : 0,
			'name'     => isset( $file['name'] ) ? (string) $file['name'] : '',
			'size'     => isset( $file['size'] ) ? (int) $file['size'] : 0,
			'mime'     => isset( $file['mime'] ) ? (string) $file['mime'] : '',
			'sha256'   => isset( $file['sha256'] ) ? (string) $file['sha256'] : '',
			'saved_at' => $saved,
			'status'   => isset( $file['status'] ) ? (string) $file['status'] : '',
		);
	}

	/**
	 * Roll up file statuses. Pending wins, then failed, then saved, then purged.
	 *
	 * @param array $files Stored rows.
	 * @return string
	 */
	public static function response_status( $files ) {
		$saw_saved  = false;
		$saw_failed = false;
		$saw_purged = false;
		if ( is_array( $files ) ) {
			foreach ( $files as $file ) {
				if ( ! is_array( $file ) ) {
					continue;
				}
				$status = isset( $file['status'] ) ? (string) $file['status'] : '';
				if ( 'pending' === $status ) {
					return 'pending';
				}
				if ( 'failed' === $status ) {
					$saw_failed = true;
				} elseif ( 'saved' === $status ) {
					$saw_saved = true;
				} elseif ( 'purged' === $status ) {
					$saw_purged = true;
				}
			}
		}
		if ( $saw_failed ) {
			return 'failed';
		}
		if ( $saw_saved ) {
			return 'saved';
		}
		if ( $saw_purged ) {
			return 'purged';
		}
		return 'pending';
	}

	/**
	 * Register the WP-CLI command when WP-CLI is loaded.
	 *
	 * @return void
	 */
	public static function register_cli() {
		if ( defined( 'WP_CLI' ) && WP_CLI && class_exists( 'WP_CLI' ) ) {
			WP_CLI::add_command( 'creature-feedback', 'Creature_Feedback_Files_CLI' );
		}
	}

	/**
	 * @param mixed $ids Raw id list.
	 * @return int[]
	 */
	private static function normalize_ids( $ids ) {
		if ( ! is_array( $ids ) ) {
			return array();
		}
		$out = array();
		foreach ( $ids as $id ) {
			$id = (int) $id;
			if ( $id > 0 ) {
				$out[ $id ] = $id;
			}
		}
		return array_values( $out );
	}

	/**
	 * @param mixed $node  Value to walk.
	 * @param array $found Collected rows.
	 * @return void
	 */
	private static function walk_for_files( $node, array &$found ) {
		if ( is_object( $node ) ) {
			if ( method_exists( $node, 'get_value' ) ) {
				self::walk_for_files( $node->get_value(), $found );
				return;
			}
			if ( isset( $node->value ) ) {
				self::walk_for_files( $node->value, $found );
			}
			return;
		}
		if ( ! is_array( $node ) ) {
			return;
		}
		if ( self::push_files_value( $node, $found ) ) {
			foreach ( $node as $key => $child ) {
				if ( 'files' === $key ) {
					continue;
				}
				self::walk_for_files( $child, $found );
			}
			return;
		}
		if ( self::push_raw_file_list( $node, $found ) ) {
			return;
		}
		foreach ( $node as $child ) {
			self::walk_for_files( $child, $found );
		}
	}

	/**
	 * @param array $node  Field value.
	 * @param array $found Collected rows.
	 * @return bool
	 */
	private static function push_files_value( array $node, array &$found ) {
		if ( ! isset( $node['files'] ) || ! is_array( $node['files'] ) ) {
			return false;
		}
		foreach ( $node['files'] as $file ) {
			self::push_file( $file, $found );
		}
		return true;
	}

	/**
	 * Contact_Form_Field stores a file field as a list of JSON strings.
	 *
	 * @param array $node  List of JSON strings.
	 * @param array $found Collected rows.
	 * @return bool
	 */
	private static function push_raw_file_list( array $node, array &$found ) {
		if ( ! $node || ! self::is_list( $node ) ) {
			return false;
		}
		$decoded = array();
		foreach ( $node as $item ) {
			if ( ! is_string( $item ) ) {
				return false;
			}
			$row = json_decode( $item, true );
			if ( ! is_array( $row ) || ! isset( $row['file_id'] ) ) {
				return false;
			}
			$decoded[] = $row;
		}
		foreach ( $decoded as $row ) {
			self::push_file( $row, $found );
		}
		return true;
	}

	/**
	 * @param mixed $file  One file record.
	 * @param array $found Collected rows.
	 * @return void
	 */
	private static function push_file( $file, array &$found ) {
		if ( ! is_array( $file ) ) {
			return;
		}
		$file_id = isset( $file['file_id'] ) ? (int) $file['file_id'] : 0;
		if ( $file_id <= 0 ) {
			return;
		}
		$name = isset( $file['name'] ) ? (string) $file['name'] : '';
		$name = str_replace( array( "\r", "\n", "\0" ), '', $name );
		$size = isset( $file['size'] ) ? (int) $file['size'] : 0;
		if ( $size < 0 ) {
			$size = 0;
		}
		$found[] = array(
			'file_id'  => $file_id,
			'name'     => $name,
			'size'     => $size,
			'status'   => 'pending',
			'attempts' => 0,
		);
	}

	/**
	 * @param array $files Rows.
	 * @return array
	 */
	private static function dedupe_files( array $files ) {
		$out  = array();
		$seen = array();
		foreach ( $files as $file ) {
			if ( ! is_array( $file ) || empty( $file['file_id'] ) ) {
				continue;
			}
			$id = (int) $file['file_id'];
			if ( isset( $seen[ $id ] ) ) {
				continue;
			}
			$seen[ $id ] = true;
			$out[]       = $file;
		}
		return $out;
	}

	/**
	 * @param mixed $content Post content.
	 * @return mixed
	 */
	private static function decode_content( $content ) {
		if ( is_array( $content ) ) {
			return $content;
		}
		if ( ! is_string( $content ) || '' === $content ) {
			return null;
		}
		$decoded = json_decode( $content, true );
		if ( ! is_array( $decoded ) ) {
			$decoded = json_decode( stripslashes( $content ), true );
		}
		return is_array( $decoded ) ? $decoded : null;
	}

	/**
	 * @param array $array Array.
	 * @return bool
	 */
	private static function is_list( array $array ) {
		if ( array() === $array ) {
			return true;
		}
		return array_keys( $array ) === range( 0, count( $array ) - 1 );
	}

	/**
	 * @param int $post_id   Feedback post id.
	 * @param int $timestamp Unix time.
	 * @return void
	 */
	private static function schedule_fetch( $post_id, $timestamp ) {
		$args = array( (int) $post_id );
		if ( wp_next_scheduled( self::CRON_FETCH, $args ) ) {
			return;
		}
		wp_schedule_single_event( (int) $timestamp, self::CRON_FETCH, $args );
	}

	/**
	 * @param int $post_id Feedback post id.
	 * @return void
	 */
	private static function unschedule_fetch( $post_id ) {
		$args      = array( (int) $post_id );
		$timestamp = wp_next_scheduled( self::CRON_FETCH, $args );
		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, self::CRON_FETCH, $args );
		}
	}

	/**
	 * @param array                           $file    Pending row.
	 * @param Creature_Feedback_Files_Fetcher $fetcher Fetcher.
	 * @param string                          $folder  32-hex response folder.
	 * @return array
	 */
	private static function fetch_and_store( array $file, $fetcher, $folder ) {
		$file['attempts'] = isset( $file['attempts'] ) ? (int) $file['attempts'] : 0;
		$file_id          = isset( $file['file_id'] ) ? (int) $file['file_id'] : 0;

		if ( $file_id <= 0 || ! is_object( $fetcher ) || ! method_exists( $fetcher, 'fetch' ) ) {
			$file['attempts']++;
			$file['status'] = 'pending';
			return $file;
		}

		$result = $fetcher->fetch( $file_id );
		if ( is_wp_error( $result ) || ! is_array( $result ) || ! isset( $result['body'] ) || ! is_string( $result['body'] ) ) {
			$file['attempts']++;
			$file['status'] = 'pending';
			return $file;
		}

		// content_type is intentionally ignored. The bytes decide.
		$inspected = self::inspect_body( $result['body'] );
		if ( is_wp_error( $inspected ) ) {
			$file['attempts']++;
			$code = $inspected->get_error_code();
			if ( 'creature_feedback_files_mime' === $code || 'creature_feedback_files_too_large' === $code ) {
				$file['status'] = 'failed';
			} else {
				$file['status'] = 'pending';
			}
			return $file;
		}

		$relative = self::write_ciphertext( $folder, $result['body'] );
		if ( is_wp_error( $relative ) ) {
			$file['attempts']++;
			$file['status'] = 'pending';
			return $file;
		}

		$file['size']     = $inspected['size'];
		$file['mime']     = $inspected['mime'];
		$file['sha256']   = $inspected['sha256'];
		$file['path']     = $relative;
		$file['saved_at'] = gmdate( 'Y-m-d H:i:s' );
		$file['status']   = 'saved';
		return $file;
	}

	/**
	 * @param array $files Stored rows.
	 * @return string
	 */
	private static function folder_for( array $files ) {
		foreach ( $files as $file ) {
			if ( ! is_array( $file ) || empty( $file['path'] ) || ! is_string( $file['path'] ) ) {
				continue;
			}
			$parts  = explode( '/', $file['path'], 2 );
			$folder = $parts[0];
			if ( preg_match( '/^[a-f0-9]{32}$/', $folder ) ) {
				return $folder;
			}
		}
		return bin2hex( random_bytes( 16 ) );
	}

	/**
	 * @param string $folder    32-hex folder.
	 * @param string $plaintext Image bytes.
	 * @return string|WP_Error Relative path.
	 */
	private static function write_ciphertext( $folder, $plaintext ) {
		if ( ! is_string( $folder ) || ! preg_match( '/^[a-f0-9]{32}$/', $folder ) ) {
			return new WP_Error( 'creature_feedback_files_dir', 'Could not store the file.' );
		}

		$base = self::base_dir();
		if ( is_wp_error( $base ) ) {
			return $base;
		}

		$dir = self::prepare_dir( $base . '/' . $folder );
		if ( ! is_string( $dir ) || '' === $dir ) {
			return new WP_Error( 'creature_feedback_files_dir', 'Could not store the file.' );
		}

		$blob = self::encrypt_blob( $plaintext );
		if ( is_wp_error( $blob ) ) {
			return $blob;
		}

		$name = bin2hex( random_bytes( 16 ) ) . '.bin';
		$full = $dir . '/' . $name;
		if ( false === file_put_contents( $full, $blob, LOCK_EX ) ) {
			return new WP_Error( 'creature_feedback_files_write', 'Could not store the file.' );
		}
		chmod( $full, 0640 );
		return $folder . '/' . $name;
	}

	/**
	 * @param string $dir Candidate directory.
	 * @return string Empty when the directory cannot be used.
	 */
	private static function usable_private_dir( $dir ) {
		if ( ! is_string( $dir ) || '' === trim( $dir ) ) {
			return '';
		}
		$dir = self::normalize_path( $dir );
		if ( '' === $dir || '/' === $dir ) {
			return '';
		}
		if ( defined( 'ABSPATH' ) ) {
			$abs = self::normalize_path( ABSPATH );
			if ( '' !== $abs && self::is_inside( $dir, $abs ) ) {
				return '';
			}
		}
		$prepared = self::prepare_dir( $dir );
		return is_string( $prepared ) ? $prepared : '';
	}

	/**
	 * @param string $dir Directory.
	 * @return string Empty when the directory is not writable.
	 */
	private static function prepare_dir( $dir ) {
		if ( ! is_string( $dir ) || '' === $dir || '/' === $dir ) {
			return '';
		}
		if ( ! is_dir( $dir ) ) {
			$parent = dirname( $dir );
			if ( ! is_dir( $parent ) || ! is_writable( $parent ) ) {
				return '';
			}
			if ( ! mkdir( $dir, 0750 ) && ! is_dir( $dir ) ) {
				return '';
			}
		}
		if ( ! is_writable( $dir ) ) {
			return '';
		}
		chmod( $dir, 0750 );
		$index = $dir . '/index.php';
		if ( ! file_exists( $index ) ) {
			file_put_contents( $index, '' );
		}
		return $dir;
	}

	/**
	 * @param string $path Path.
	 * @param string $root Root.
	 * @return bool
	 */
	private static function is_inside( $path, $root ) {
		$path = self::normalize_path( $path );
		$root = self::normalize_path( $root );
		if ( '' === $root ) {
			return false;
		}
		return $path === $root || 0 === strpos( $path, $root . '/' );
	}

	/**
	 * @param string $path Path.
	 * @return string
	 */
	private static function normalize_path( $path ) {
		$path     = str_replace( '\\', '/', (string) $path );
		$absolute = isset( $path[0] ) && '/' === $path[0];
		$parts    = array();
		foreach ( explode( '/', $path ) as $part ) {
			if ( '' === $part || '.' === $part ) {
				continue;
			}
			if ( '..' === $part ) {
				array_pop( $parts );
				continue;
			}
			$parts[] = $part;
		}
		$out = implode( '/', $parts );
		return $absolute ? '/' . $out : $out;
	}

	/**
	 * Base64 option key for a one-time export. Does not print or write a file.
	 *
	 * A defined CREATURE_FEEDBACK_FILES_KEY is refused without reading it.
	 * The first success records KEY_EXPORTED_OPTION (GMT, autoload off).
	 * Later calls refuse unless $force is true, and that timestamp stays.
	 *
	 * @param bool $yes   Operator passed --yes.
	 * @param bool $force Operator passed --force.
	 * @return string|WP_Error Stored base64, or an error that does not contain the key.
	 */
	public static function export_key( $yes, $force ) {
		if ( defined( 'CREATURE_FEEDBACK_FILES_KEY' ) ) {
			return new WP_Error(
				'creature_feedback_files_key_constant',
				'The key comes from the constant CREATURE_FEEDBACK_FILES_KEY. This command only exports the option.'
			);
		}

		if ( ! $yes ) {
			return new WP_Error(
				'creature_feedback_files_export_confirm',
				'Pass --yes to print the key.'
			);
		}

		$exported_at = get_option( self::KEY_EXPORTED_OPTION, false );
		$already     = is_string( $exported_at ) && '' !== $exported_at;
		if ( $already && ! $force ) {
			return new WP_Error(
				'creature_feedback_files_key_exported',
				'The key was already exported. Pass --force to print it again.'
			);
		}

		$raw = self::key_bytes();
		if ( is_wp_error( $raw ) ) {
			return new WP_Error( 'creature_feedback_files_key', 'The stored key is unusable.' );
		}

		$stored = get_option( self::KEY_OPTION, false );
		$check  = is_string( $stored ) ? self::decode_key( $stored ) : null;
		if ( ! is_string( $check ) || ! hash_equals( $raw, $check ) ) {
			return new WP_Error( 'creature_feedback_files_key', 'The stored key is unusable.' );
		}

		if ( ! $already ) {
			$stamp = gmdate( 'Y-m-d H:i:s' );
			$added = add_option( self::KEY_EXPORTED_OPTION, $stamp, '', false );
			$saved = get_option( self::KEY_EXPORTED_OPTION, false );
			if ( ! $added && ( ! is_string( $saved ) || '' === $saved ) ) {
				return new WP_Error(
					'creature_feedback_files_key_exported',
					'The export could not be recorded.'
				);
			}
		}

		return $stored;
	}

	/**
	 * 32-byte key. The constant wins when it is defined. The option is
	 * created once, with autoload off. The key is never logged.
	 *
	 * @return string|WP_Error
	 */
	private static function key_bytes() {
		if ( defined( 'CREATURE_FEEDBACK_FILES_KEY' ) ) {
			$raw = self::decode_key( constant( 'CREATURE_FEEDBACK_FILES_KEY' ) );
			if ( null === $raw ) {
				return new WP_Error(
					'creature_feedback_files_key',
					'CREATURE_FEEDBACK_FILES_KEY must be a base64-encoded 32-byte key.'
				);
			}
			return $raw;
		}

		$stored = get_option( self::KEY_OPTION, null );
		if ( is_string( $stored ) && '' !== $stored ) {
			$raw = self::decode_key( $stored );
			if ( null === $raw ) {
				return new WP_Error( 'creature_feedback_files_key', 'The stored key is unusable.' );
			}
			return $raw;
		}

		$raw     = random_bytes( SODIUM_CRYPTO_SECRETBOX_KEYBYTES );
		$encoded = base64_encode( $raw );
		add_option( self::KEY_OPTION, $encoded, '', false );

		$saved = get_option( self::KEY_OPTION, null );
		$check = is_string( $saved ) ? self::decode_key( $saved ) : null;
		if ( ! is_string( $check ) || ! hash_equals( $raw, $check ) ) {
			return new WP_Error( 'creature_feedback_files_key', 'The key could not be saved.' );
		}
		return $raw;
	}

	/**
	 * @param mixed $encoded Base64 key.
	 * @return string|null
	 */
	private static function decode_key( $encoded ) {
		if ( ! is_string( $encoded ) || '' === $encoded ) {
			return null;
		}
		$raw = base64_decode( $encoded, true );
		if ( ! is_string( $raw ) || SODIUM_CRYPTO_SECRETBOX_KEYBYTES !== strlen( $raw ) ) {
			return null;
		}
		return $raw;
	}

	/**
	 * @param int   $response_id Feedback post id.
	 * @param int   $file_id     Jetpack file id.
	 * @return array|WP_Error
	 */
	private static function prepare_download( $response_id, $file_id ) {
		$post = get_post( (int) $response_id );
		if ( ! self::is_allowed_feedback( $post ) ) {
			return self::not_found();
		}

		$files = get_post_meta( (int) $response_id, self::META, true );
		$file  = self::find_file( $files, (int) $file_id );
		if ( null === $file || empty( $file['status'] ) || 'saved' !== $file['status'] ) {
			return self::not_found();
		}

		$path = self::absolute_path( isset( $file['path'] ) ? $file['path'] : '' );
		if ( '' === $path ) {
			return self::not_found();
		}

		$blob = file_get_contents( $path );
		if ( ! is_string( $blob ) ) {
			return self::not_found();
		}

		$plain = self::decrypt_blob( $blob );
		if ( is_wp_error( $plain ) ) {
			return new WP_Error(
				'creature_feedback_files_decrypt',
				'Could not read the stored file.',
				array( 'status' => 500 )
			);
		}

		$expected = isset( $file['sha256'] ) ? (string) $file['sha256'] : '';
		$actual   = hash( 'sha256', $plain );
		if ( strlen( $expected ) !== strlen( $actual ) || ! hash_equals( $expected, $actual ) ) {
			return new WP_Error(
				'creature_feedback_files_hash',
				'Stored file did not match its checksum.',
				array( 'status' => 500 )
			);
		}

		$mime = isset( $file['mime'] ) ? (string) $file['mime'] : '';
		return array(
			'body'    => $plain,
			'headers' => self::download_headers( $file, $mime, strlen( $plain ) ),
		);
	}

	/**
	 * @param array  $file   Stored row.
	 * @param string $mime   Sniffed MIME.
	 * @param int    $length Plaintext length.
	 * @return array<string, string>
	 */
	private static function download_headers( array $file, $mime, $length ) {
		$mime = str_replace( array( "\r", "\n" ), '', (string) $mime );
		if ( ! preg_match( '#^[a-z0-9.+-]+/[a-z0-9.+-]+$#i', $mime ) ) {
			$mime = 'application/octet-stream';
		}
		$name = self::safe_filename( isset( $file['name'] ) ? $file['name'] : '' );
		return array(
			'Content-Type'              => $mime,
			'Content-Disposition'       => 'attachment; filename="' . $name . '"',
			'Content-Length'            => (string) (int) $length,
			'Cache-Control'             => 'no-store',
			'X-Robots-Tag'              => 'noindex',
			'X-Content-Type-Options'    => 'nosniff',
		);
	}

	/**
	 * @param string $name Original file name.
	 * @return string
	 */
	private static function safe_filename( $name ) {
		$name = str_replace( array( "\r", "\n", '"', "\0" ), '', (string) $name );
		$name = str_replace( '\\', '/', $name );
		$name = basename( $name );
		$name = preg_replace( '/[^A-Za-z0-9._-]+/', '-', (string) $name );
		$name = trim( (string) $name, '.-' );
		if ( '' === $name || strlen( $name ) > 180 ) {
			return 'photo.bin';
		}
		return $name;
	}

	/**
	 * @param mixed $files   Stored rows.
	 * @param int   $file_id Jetpack file id.
	 * @return array|null
	 */
	private static function find_file( $files, $file_id ) {
		if ( ! is_array( $files ) || $file_id <= 0 ) {
			return null;
		}
		foreach ( $files as $file ) {
			if ( is_array( $file ) && isset( $file['file_id'] ) && (int) $file['file_id'] === $file_id ) {
				return $file;
			}
		}
		return null;
	}

	/**
	 * @param string $relative Relative path from the private root.
	 * @return string Absolute path, or empty when it escapes the root.
	 */
	private static function absolute_path( $relative ) {
		if ( ! is_string( $relative ) || ! preg_match( '/^[a-f0-9]{32}\/[a-f0-9]{32}\.bin$/', $relative ) ) {
			return '';
		}
		$base = self::base_dir();
		if ( is_wp_error( $base ) || ! is_string( $base ) ) {
			return '';
		}
		$real_base = realpath( $base );
		$real      = realpath( $base . '/' . $relative );
		if ( ! is_string( $real_base ) || ! is_string( $real ) ) {
			return '';
		}
		$real_base = self::normalize_path( $real_base );
		$real      = self::normalize_path( $real );
		if ( ! self::is_inside( $real, $real_base ) ) {
			return '';
		}
		return $real;
	}

	/**
	 * @return WP_Error
	 */
	private static function not_found() {
		return new WP_Error( 'creature_feedback_files_not_found', 'File not found.', array( 'status' => 404 ) );
	}

	/**
	 * @return WP_Error
	 */
	private static function rest_forbidden() {
		return new WP_Error( 'rest_forbidden', 'Sorry, you are not allowed to do that.', array( 'status' => 403 ) );
	}

	/**
	 * @param mixed  $request Request.
	 * @param string $key     Parameter.
	 * @return mixed
	 */
	private static function request_param( $request, $key ) {
		if ( is_object( $request ) && method_exists( $request, 'get_param' ) ) {
			return $request->get_param( $key );
		}
		if ( is_array( $request ) && array_key_exists( $key, $request ) ) {
			return $request[ $key ];
		}
		return null;
	}

	/**
	 * @param string $mysql GMT datetime.
	 * @return string
	 */
	private static function iso_gmt( $mysql ) {
		if ( ! is_string( $mysql ) || '' === $mysql ) {
			return '';
		}
		$stamp = strtotime( $mysql . ' UTC' );
		if ( ! $stamp ) {
			return '';
		}
		return gmdate( 'Y-m-d\TH:i:s\Z', $stamp );
	}

	/**
	 * @param int   $post_id Feedback post id.
	 * @param int   $cutoff  Unix time. Files saved strictly before this are removed.
	 * @return void
	 */
	private static function purge_post( $post_id, $cutoff ) {
		$files = get_post_meta( $post_id, self::META, true );
		if ( ! is_array( $files ) ) {
			return;
		}

		$changed = false;
		$dirs    = array();
		foreach ( $files as $index => $file ) {
			if ( ! is_array( $file ) || empty( $file['status'] ) || 'saved' !== $file['status'] ) {
				continue;
			}
			$saved = isset( $file['saved_at'] ) ? strtotime( (string) $file['saved_at'] . ' UTC' ) : 0;
			// More than the retention window ago. A file saved on the boundary stays.
			if ( ! $saved || $saved >= $cutoff ) {
				continue;
			}
			$path = isset( $file['path'] ) ? (string) $file['path'] : '';
			$full = self::absolute_path( $path );
			if ( '' !== $full && is_file( $full ) ) {
				if ( ! unlink( $full ) ) {
					continue;
				}
				clearstatcache( true, $full );
			}
			$parts = explode( '/', $path, 2 );
			if ( isset( $parts[0] ) && preg_match( '/^[a-f0-9]{32}$/', $parts[0] ) ) {
				$dirs[ $parts[0] ] = true;
			}
			$files[ $index ]['status'] = 'purged';
			$changed                   = true;
		}

		if ( $changed ) {
			update_post_meta( $post_id, self::META, $files );
		}

		$base = self::base_dir();
		if ( is_wp_error( $base ) || ! is_string( $base ) ) {
			return;
		}
		foreach ( array_keys( $dirs ) as $folder ) {
			if ( is_string( $folder ) && preg_match( '/^[a-f0-9]{32}$/', $folder ) ) {
				self::remove_empty_response_dir( $base . '/' . $folder );
			}
		}
	}

	/**
	 * Remove a response folder that has no ciphertext left. The private root stays.
	 *
	 * @param string $dir Response folder.
	 * @return void
	 */
	private static function remove_empty_response_dir( $dir ) {
		if ( ! is_dir( $dir ) ) {
			return;
		}
		$items = scandir( $dir );
		if ( ! is_array( $items ) ) {
			return;
		}
		foreach ( $items as $item ) {
			if ( '.' === $item || '..' === $item || 'index.php' === $item ) {
				continue;
			}
			return;
		}
		$index = $dir . '/index.php';
		if ( is_file( $index ) ) {
			unlink( $index );
		}
		rmdir( $dir );
	}

	/**
	 * One email once a response is over 3 days old and a file is still unsaved.
	 *
	 * @param object $post  Feedback post.
	 * @param array  $files Stored rows.
	 * @return void
	 */
	private static function maybe_alert( $post, array $files ) {
		if ( ! is_object( $post ) || empty( $post->ID ) ) {
			return;
		}
		if ( ! self::any_unsaved( $files ) ) {
			return;
		}

		$submitted = self::submitted_timestamp( $post );
		if ( ! $submitted || ( time() - $submitted ) <= self::ALERT_AFTER_DAYS * DAY_IN_SECONDS ) {
			return;
		}

		$post_id = (int) $post->ID;
		if ( get_post_meta( $post_id, self::ALERT_META, true ) ) {
			return;
		}

		$to = apply_filters( 'creature_feedback_files_alert_email', get_option( 'admin_email' ) );
		if ( ! is_string( $to ) || false === strpos( $to, '@' ) ) {
			return;
		}

		$subject = sprintf( 'Feedback photos still unsaved for response %d', $post_id );
		$body    = sprintf(
			"Jetpack Form response %d is more than 3 days old and at least one photo has not been saved to the private store.\n\nThis notice is sent once. The feedback post was not changed.\n",
			$post_id
		);
		$sent = wp_mail( $to, $subject, $body );
		if ( $sent ) {
			update_post_meta( $post_id, self::ALERT_META, '1' );
		}
	}

	/**
	 * @param array $files Stored rows.
	 * @return bool
	 */
	private static function any_unsaved( array $files ) {
		foreach ( $files as $file ) {
			if ( ! is_array( $file ) ) {
				continue;
			}
			$status = isset( $file['status'] ) ? (string) $file['status'] : '';
			if ( 'saved' !== $status && 'purged' !== $status ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * @param object $post Feedback post.
	 * @return int
	 */
	private static function submitted_timestamp( $post ) {
		$gmt = '';
		if ( isset( $post->post_date_gmt ) && is_string( $post->post_date_gmt ) && '0000-00-00 00:00:00' !== $post->post_date_gmt ) {
			$gmt = $post->post_date_gmt;
		} elseif ( isset( $post->post_date ) && is_string( $post->post_date ) ) {
			$gmt = $post->post_date;
		}
		$stamp = strtotime( $gmt . ' UTC' );
		return $stamp ? (int) $stamp : 0;
	}
}

/**
 * WP-CLI commands for private feedback copies.
 *
 * `backfill` queues one existing response. `export-key` prints the option key once.
 */
final class Creature_Feedback_Files_CLI {

	/**
	 * Queue a private copy for one existing feedback response.
	 *
	 * Dry-run is the default. Pass --yes to write the queue and schedule the fetch.
	 * There is no REST backfill.
	 *
	 * ## OPTIONS
	 *
	 * --post=<id>
	 * : Feedback post ID.
	 *
	 * [--dry-run]
	 * : Print the files that would be queued. This is the default.
	 *
	 * [--yes]
	 * : Write the queue meta and schedule the fetch.
	 *
	 * @param array $args       Positional args. Unused.
	 * @param array $assoc_args Flags.
	 * @return void
	 */
	public function backfill( $args, $assoc_args ) {
		unset( $args );
		$post_id = isset( $assoc_args['post'] ) ? (int) $assoc_args['post'] : 0;
		if ( $post_id <= 0 ) {
			WP_CLI::error( 'Pass --post=<id> for one existing feedback response.' );
			return;
		}

		$dry    = Creature_Feedback_Files::backfill_is_dry( $assoc_args );
		$result = Creature_Feedback_Files::backfill_post( $post_id, $dry );
		if ( is_wp_error( $result ) ) {
			WP_CLI::error( $result->get_error_message() );
			return;
		}

		if ( ! empty( $result['dry_run'] ) ) {
			WP_CLI::log(
				sprintf(
					'Dry run. %d file(s) on response %d. Pass --yes to queue them. Nothing was written.',
					count( $result['files'] ),
					$post_id
				)
			);
			foreach ( $result['files'] as $file ) {
				WP_CLI::log( $file['file_id'] . ' ' . $file['name'] );
			}
			return;
		}

		WP_CLI::success( sprintf( 'Queued %d file(s) for response %d.', count( $result['files'] ), $post_id ) );
	}

	/**
	 * Print the option key to STDOUT and nothing else.
	 *
	 * Diagnostics are WP_CLI::error, which goes to STDERR. The key is not
	 * logged and is not written to a file. There is no REST route for this.
	 *
	 * ## OPTIONS
	 *
	 * --yes
	 * : Print the key. Required.
	 *
	 * [--force]
	 * : Print the key again after creature_feedback_files_key_exported_at is set.
	 *
	 * @param array $args       Positional args. Unused.
	 * @param array $assoc_args Flags.
	 * @return void
	 */
	public function export_key( $args, $assoc_args ) {
		unset( $args );
		if ( ! is_array( $assoc_args ) ) {
			$assoc_args = array();
		}

		$result = Creature_Feedback_Files::export_key(
			! empty( $assoc_args['yes'] ),
			! empty( $assoc_args['force'] )
		);
		if ( is_wp_error( $result ) ) {
			WP_CLI::error( $result->get_error_message() );
			return;
		}

		fwrite( STDOUT, $result . "\n" );
	}
}

add_action( 'grunion_after_feedback_post_inserted', array( 'Creature_Feedback_Files', 'on_feedback_inserted' ), 10, 4 );
add_action( Creature_Feedback_Files::CRON_FETCH, array( 'Creature_Feedback_Files', 'on_fetch' ), 10, 1 );
add_action( Creature_Feedback_Files::CRON_RETENTION, array( 'Creature_Feedback_Files', 'on_retention' ) );
add_action( 'init', array( 'Creature_Feedback_Files', 'schedule_retention' ) );
add_action( 'rest_api_init', array( 'Creature_Feedback_Files', 'register_routes' ) );
add_filter( 'rest_pre_serve_request', array( 'Creature_Feedback_Files', 'serve_download' ), 10, 4 );

Creature_Feedback_Files::register_cli();
