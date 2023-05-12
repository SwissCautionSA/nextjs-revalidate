<?php

namespace NextJsRevalidate;

use NextJsRevalidate;
use WP_Admin_Bar;
use WP_Post;

// Exit if accessed directly.
defined( 'ABSPATH' ) or die( 'Cheatin&#8217; uh?' );

class Revalidate {

	/**
	 * Constructor.
	 */
	function __construct() {
		add_action( 'wp_after_insert_post', [$this, 'on_post_save'], 99 );

		add_filter( 'page_row_actions', [$this, 'add_revalidate_row_action'], 20, 2 );
		add_filter( 'post_row_actions', [$this, 'add_revalidate_row_action'], 20, 2 );
		add_action( 'admin_init', [$this, 'revalidate_row_action'] );
		add_action( 'admin_init', [$this, 'revalidate_all_pages_action'] );
		add_action( 'admin_init', [$this, 'purge_all_progress'] );

		add_action( 'admin_init', [$this, 'register_bulk_actions'] );

		add_action( 'admin_notices', [$this, 'purged_notice'] );
		add_action( 'admin_bar_menu', [$this, 'admin_top_bar_menu'], 100 );
	}

	function on_post_save( $post_id ) {
		// Bail for post type not viewable, nor autosave or revision, as in some cases it saves a draft!
		if ( !is_post_publicly_viewable($post_id) || wp_is_post_revision($post_id) || wp_is_post_autosave($post_id) ) return;

		// Bail early if current request is for saving the metaboxes. (To not duplicate the purge query)
		if ( isset($_REQUEST['meta-box-loader']) ) return;

		// Ensure we do not fire this action twice. Safekeeping
		remove_action( 'wp_after_insert_post', [$this, 'on_post_save'], 99 );

		$this->purge( get_permalink( $post_id ) );
	}

	function purge( $permalink ) {

		$njr = NextJsRevalidate::init();
		if ( !$njr->settings->is_configured() ) return false;

		$revalidate_uri = add_query_arg(
			[
				'path'   => wp_make_link_relative( $permalink ),
				'secret' => $njr->settings->secret
			],
			$njr->settings->url
		);

		$response = wp_remote_get( $revalidate_uri, [ 'timeout' => 60 ] );

		return ( is_wp_error($response)
			? error_log($response->get_error_message())
			: ($response['response']['code'] === 200)
		);
	}

	function purge_all() {

		$njr = NextJsRevalidate::init();
		if ( !$njr->settings->is_configured() ) return false;

		$nodes = [];

		// retrieve all public post types
		$post_types = get_post_types([ 'public' => true ]);
		foreach ($post_types as $post_type) {
			$posts = get_posts([
				'post_type'      => $post_type,
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'post_status'    => 'publish',
			]);

			$nodes = array_merge( $nodes, array_map(function($p) { return ['type' => 'post', 'id' => $p]; }, $posts) );
		}

		// retrieve all public taxonomies
		$taxonomies = get_taxonomies([ 'public' => true ]);
		foreach ($taxonomies as $taxonomy) {
			$terms = get_terms([
				'taxonomy'   => $taxonomy,
				'hide_empty' => false,
				'fields'     => 'ids',
			]);
			$nodes = array_merge( $nodes, array_map(function($t) { return [ 'type' => 'term', 'id' => $t]; }, $terms) );
		}

		update_option( 'nextjs-revalidate-purge_all', [
			'status' => 'running',
			'nodes'  => $nodes,
			'total'  => count($nodes),
		] );

		$njr->cronPurgeAll->start();
	}

	function add_revalidate_row_action( $actions, $post ) {

		if ( $post instanceof WP_Post || is_array( $actions ) ) {

			$njr = NextJsRevalidate::init();
			if ( $njr->settings->is_configured() ) {

				$actions['revalidate'] = sprintf(
					'<a href="%s" aria-label="%s">%s</a>',
					wp_nonce_url(
						add_query_arg(
							[
								'action'    => 'nextjs-revalidate-purge',
								'post'      => $post->ID,
							]
						),
						"nextjs-revalidate-purge_{$post->ID}"
					),
					esc_attr( sprintf( __('Purge cache of post “%s”', 'nextjs-revalidate'), get_the_title($post)) ),
					__('Purge cache', 'nextjs-revalidate'),
				);

			}
		}


		return $actions;
	}

	function revalidate_row_action() {
		if ( ! (isset( $_GET['action'] ) && $_GET['action'] === 'nextjs-revalidate-purge' && isset($_GET['post']))  ) return;

		check_admin_referer( "nextjs-revalidate-purge_{$_GET['post']}" );

		$success = intval($this->purge( get_permalink( $_GET['post'] ) ) );

		$sendback  = $this->get_sendback_url();

		wp_safe_redirect(
			add_query_arg( [ 'nextjs-revalidate-purged' => ($success ? $_GET['post'] : 0) ], $sendback )
		);
		exit;
	}

	/**
	 * Register "Purge caches" bulk action.
	 * All public post types, except "attachment" one
	 */
	function register_bulk_actions() {
		$njr = NextJsRevalidate::init();
		if ( !$njr->settings->is_configured() ) return false;

		$post_types = get_post_types([ 'public' => true ]);

		unset( $post_types['attachment'] );

		foreach ($post_types as $post_type) {
			add_filter( "bulk_actions-edit-$post_type", [$this, 'add_revalidate_bulk_action'], 99 );
			add_filter( "handle_bulk_actions-edit-$post_type",  [$this, 'revalidate_bulk_action'], 10, 3 );
		}
	}

	function add_revalidate_bulk_action( $bulk_actions ) {
		$bulk_actions['nextjs_revalidate-bulk_purge'] = __( 'Purge caches', 'nextjs-revalidate' );
		return $bulk_actions;
	}

	function revalidate_bulk_action( $redirect_url, $action, $post_ids ) {
		if ($action === 'nextjs_revalidate-bulk_purge') {

			$purged = 0;
			foreach ($post_ids as $post_id) {
				if ( intval($this->purge( get_permalink( $post_id ) ) ) ) {
					$purged++;
				}
			}

			$redirect_url = add_query_arg('nextjs-revalidate-bulk-purged', $purged, $redirect_url);
		}

		return $redirect_url;
	}

	function purged_notice() {
		if ( isset( $_GET['nextjs-revalidate-purged'] ) ) {

			$success = boolval($_GET['nextjs-revalidate-purged']);
			printf(
				'<div class="notice notice-%s"><p>%s</p></div>',
				$success ? 'success' : 'error',
				($success
					? sprintf( __( '“%s” cache has been correctly purged.', 'nextjs-revalidate' ), get_the_title($_GET['nextjs-revalidate-purged']) )
					: __( 'The cache could not be purged correctly. Please try again or contact an administrator.', 'nextjs-revalidate' )
				)
			);
		}

		if ( isset($_GET['nextjs-revalidate-bulk-purged']) ) {

			$nb_purged = intval($_GET['nextjs-revalidate-bulk-purged']);
			$success = $nb_purged > 0;

			printf(
				'<div class="notice notice-%s"><p>%s</p></div>',
				$success ? 'success' : 'error',
				($success
					? sprintf( _n( 'Successfully purged %d cache.', 'Successfully purged %d caches.', $nb_purged, 'nextjs-revalidate' ), $nb_purged )
					: __( 'The caches could not be purged correctly. Please try again or contact an administrator.', 'nextjs-revalidate' )
				)
			);
		}

		if ( $this->is_purging_all() ) {

			if ( isset($_GET['nextjs-revalidate-purge-all']) && $_GET['nextjs-revalidate-purge-all'] === 'already-running') {
				printf(
					'<div class="notice notice-warning"><p>%s</p></div>',
					__( 'Purging all caches. is already running. Please wait until the end to restart the purge.', 'nextjs-revalidate' )
				);
			}

			$numbers = $this->get_purge_all_progress_numbers();
			printf(
				'<div class="notice notice-info nextjs-revalidate-purge-all__notice"><p>%s</p></div>',
				sprintf(
					__( 'Purging all caches. Please wait… %s', 'nextjs-revalidate' ),
					sprintf('<span class="nextjs-revalidate-purge-all__progress">%s%% (%d/%s)</span>', $numbers->progress, $numbers->done, $numbers->total )
				)
			);
		}
	}

	function admin_top_bar_menu( WP_Admin_Bar $admin_bar ) {

		$admin_bar->add_menu( [
			'id'     => 'nextjs-revalidate',
			'title'  => _x( 'Purge caches', 'Admin top bar menu', 'nextjs-revalidate'),
			'meta'   => [
				'class' => "nextjs-revalidate",
			]
		] );

		$admin_bar->add_node( [
			'id'     => "nextjs-revalidate-all-pages",
			'parent' => 'nextjs-revalidate',
			'title'  => _x( 'All pages', 'Admin top bar menu', 'nextjs-revalidate' ),
			'href'   => esc_url(
				wp_nonce_url(
					add_query_arg( 'action', 'nextjs-revalidate-purge-all' ),
					'nextjs-revalidate-purge-all'
				)
			),
			'meta'   => [
				'title' => _x( 'Purging all cache may take some time according to the number of pages to purge.', 'Admin top bar menu', 'nextjs-revalidate' ),
			]
		] );

	}

	function get_sendback_url() {
		$sendback  = wp_get_referer();
		if ( ! $sendback ) {
			$sendback = admin_url( 'edit.php' );
			$post_type = get_post_type($_GET['post']);
			if ( ! empty( $post_type ) ) {
				$sendback = add_query_arg( 'post_type', $post_type, $sendback );
			}
		}
		else {
			$sendback = remove_query_arg( [ 'action', 'trashed', 'untrashed', 'deleted', 'ids', 'nextjs-revalidate-purged', 'nextjs-revalidate-bulk-purged' ], $sendback );
		}

		return $sendback;
	}

	function is_purging_all() {
		$purge_all = get_option( 'nextjs-revalidate-purge_all', [] );
		return in_array($purge_all['status'] ?? '', ['running']);
	}

	function get_purge_all_progress_numbers() {
		$purge_all = get_option( 'nextjs-revalidate-purge_all', [] );

		$todo     = count($purge_all['nodes'] ?? []);
		$total    = $purge_all['total'] ?? 0;
		$done     = max(0, $total - $todo);
		return (object)[
			'todo'     => $todo,
			'done'     => $done,
			'total'    => $total,
			'progress' => $total > 0 ? round( $done / $total * 100 ) : 0,
		];
	}

	function revalidate_all_pages_action() {
		if ( ! (isset( $_GET['action'] ) && $_GET['action'] === 'nextjs-revalidate-purge-all')  ) return;

		check_admin_referer( 'nextjs-revalidate-purge-all' );

		$sendback = $this->get_sendback_url();

		if ( $this->is_purging_all() ) $sendback = add_query_arg( ['nextjs-revalidate-purge-all' => 'already-running'], $sendback );
		else $this->purge_all();

		wp_safe_redirect( $sendback );
		exit;
	}

	function purge_all_progress() {
		if ( !isset($_GET['action']) || $_GET['action'] !== 'nextjs-revalidate-purge-all-progress' ) return;

		if ( false === check_ajax_referer( 'nextjs-revalidate-purge_all_progress' ) ) return;

		$purge_all = get_option( 'nextjs-revalidate-purge_all', [] );

		$numbers = $this->get_purge_all_progress_numbers();
		$done = $numbers->done;
		$total = $numbers->total;
		$progress = $numbers->progress;
		switch ( $purge_all['status'] ?? '' ) {
			case 'done':
				$status = 'done';

				break;
			case 'running':
				$status = 'running';

				break;
			default:
				$status = 'not-running';
		}

		wp_send_json_success( [
			'status' => $status,
			'done'   => $done ?? 0,
			'total'  => $total ?? 0,
			'progress'  => $progress ?? 0,
		] );
		exit;
	}
}
