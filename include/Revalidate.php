<?php

namespace NextJsRevalidate;

use NextJsRevalidate;
use NextJsRevalidate\Traits\SendbackUrl;
use WP_Post;

// Exit if accessed directly.
defined( 'ABSPATH' ) or die( 'Cheatin&#8217; uh?' );

class Revalidate {
	use SendbackUrl;

	/**
	 * Constructor.
	 */
	function __construct() {
		add_action( 'wp_after_insert_post', [$this, 'on_post_save'], 99 );

		add_filter( 'page_row_actions', [$this, 'add_revalidate_row_action'], 20, 2 );
		add_filter( 'post_row_actions', [$this, 'add_revalidate_row_action'], 20, 2 );
		add_action( 'admin_init', [$this, 'revalidate_row_action'] );

		add_action( 'admin_init', [$this, 'register_bulk_actions'] );

		add_action( 'admin_notices', [$this, 'purged_notice'] );
	}

	function __get( $name ) {
		if ( $name === 'njr' ) return NextJsRevalidate::init();
		return null;
	}

	function on_post_save( $post_id ) {
		// Bail for post type not viewable, nor autosave or revision, as in some cases it saves a draft!
		if ( !is_post_publicly_viewable($post_id) || wp_is_post_revision($post_id) || wp_is_post_autosave($post_id) ) return;

		// Bail early if current request is for saving the metaboxes. (To not duplicate the purge query)
		if ( isset($_REQUEST['meta-box-loader']) ) return;

		// Ensure we do not fire this action twice. Safekeeping
		remove_action( 'wp_after_insert_post', [$this, 'on_post_save'], 99 );

		$this->njr->queue->add_item( get_permalink( $post_id ) );
	}

	function purge( $permalink ) {

		$njr = NextJsRevalidate::init();
		if ( !$njr->settings->is_configured() ) return false;

		try {
			$response = wp_remote_get(
				$this->build_revalidate_uri( $permalink ),
				[ 'timeout' => 60 ]
			);

			if ( is_wp_error($response) ) throw new \Exception("Unable to revalidate $permalink", 1);
			return $response['response']['code'] === 200;
		} catch (\Throwable $th) {
			return false;
		}
	}

	function build_revalidate_uri( $permalink ) {
		$njr = NextJsRevalidate::init();
		return add_query_arg(
			[
				'path'   => wp_make_link_relative( $permalink ),
				'secret' => $njr->settings->secret
			],
			$njr->settings->url
		);
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

		$permalink = get_permalink( $_GET['post'] );

		/**
		 * Filters the permalink to be added to the purge queue.
		 * Return false to prevent the permalink to be added to the purge queue.
		 *
		 * @param string|false $permalink The post permalink. False if the post is not public.
		 * @param int          $post_id   The post ID.
		 */
		$permalink = apply_filters( 'nextjs_revalidate_purge_action_permalink', $permalink, $_GET['post'] );

		if ( false !== $permalink ) $is_added = $this->njr->queue->add_item( $permalink );

		$sendback  = $this->get_sendback_url();

		wp_safe_redirect(
			add_query_arg( [ 'nextjs-revalidate-purged' => $_GET['post'] ], $sendback )
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
				$permalink = get_permalink( $post_id );

				/**
				 * Filters the permalink to be added to the purge queue.
				 * Return false to prevent the permalink to be added to the purge queue.
				 *
				 * @param string|false $permalink The post permalink. False if the post is not public.
				 * @param int          $post_id   The post ID.
				 */
				$permalink = apply_filters( 'nextjs_revalidate_purge_action_permalink', $permalink, $_GET['post'] );

				if ( false !== $permalink ) {
					$this->njr->queue->add_item( $permalink );
					$purged++;
				}
			}

			$redirect_url = add_query_arg('nextjs-revalidate-bulk-purged', $purged, $this->get_sendback_url($redirect_url));
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
					? sprintf( __( '“%s” cache will be purged shortly.', 'nextjs-revalidate' ), get_the_title($_GET['nextjs-revalidate-purged']) )
					: __( 'Unable to purge cache. Please try again or contact an administrator.', 'nextjs-revalidate' )
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
					? sprintf( _n( '%d cache will be purged shortly.', '%d caches will be purged shortly.', $nb_purged, 'nextjs-revalidate' ), $nb_purged )
					: __( 'Unable to purge cache. Please try again or contact an administrator.', 'nextjs-revalidate' )
				)
			);
		}
	}


}
