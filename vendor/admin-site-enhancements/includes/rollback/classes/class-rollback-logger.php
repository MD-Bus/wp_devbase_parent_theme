<?php
/**
 * Rollback logger class.
 *
 * @since 8.7.3
 */

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * Class ASENHA_Rollback_Logger
 */
class ASENHA_Rollback_Logger {

	/**
	 * Log CPT slug.
	 */
	const POST_TYPE = 'asenha_rollback_log';

	/**
	 * Helper instance.
	 *
	 * @var ASENHA_Rollback_Helper
	 */
	private $helper;

	/**
	 * Constructor.
	 *
	 * @param ASENHA_Rollback_Helper $helper Helper instance.
	 */
	public function __construct( $helper ) {
		$this->helper = $helper;
	}

	/**
	 * Initialize hooks.
	 *
	 * @return void
	 */
	public function init() {
		add_action( 'init', array( $this, 'register_post_type' ) );
	}

	/**
	 * Register the internal rollback log CPT.
	 *
	 * @return void
	 */
	public function register_post_type() {
		register_post_type(
			self::POST_TYPE,
			array(
				'labels'              => array(
					'name'          => __( 'Rollback Logs', 'admin-site-enhancements' ),
					'singular_name' => __( 'Rollback Log', 'admin-site-enhancements' ),
				),
				'public'              => false,
				'show_ui'             => false,
				'show_in_menu'        => false,
				'show_in_rest'        => false,
				'has_archive'         => false,
				'exclude_from_search' => true,
				'publicly_queryable'  => false,
				'rewrite'             => false,
				'query_var'           => false,
				'supports'            => array( 'title', 'editor', 'excerpt' ),
				'capability_type'     => 'post',
			)
		);
	}

	/**
	 * Create a rollback log entry.
	 *
	 * @param array $args Log arguments.
	 * @return int|WP_Error
	 */
	public function log_rollback( $args ) {
		$asset_type    = isset( $args['asset_type'] ) ? sanitize_key( $args['asset_type'] ) : 'plugin';
		$asset_name    = isset( $args['asset_name'] ) ? sanitize_text_field( $args['asset_name'] ) : '';
		$asset_slug    = isset( $args['asset_slug'] ) ? sanitize_text_field( $args['asset_slug'] ) : '';
		$from_version  = isset( $args['from_version'] ) ? sanitize_text_field( $args['from_version'] ) : '';
		$to_version    = isset( $args['to_version'] ) ? sanitize_text_field( $args['to_version'] ) : '';
		$note_html     = isset( $args['note_html'] ) ? wp_kses_post( $args['note_html'] ) : '';
		$source        = isset( $args['source'] ) ? sanitize_key( $args['source'] ) : 'wordpress_org';
		$user_id       = isset( $args['user_id'] ) ? absint( $args['user_id'] ) : get_current_user_id();
		$asset_label   = ( 'theme' === $asset_type ) ? __( 'Theme', 'admin-site-enhancements' ) : __( 'Plugin', 'admin-site-enhancements' );
		$entry_title   = sprintf(
			/* translators: 1: asset type label, 2: asset name, 3: target version */
			__( '%1$s rollback: %2$s to %3$s', 'admin-site-enhancements' ),
			$asset_label,
			$asset_name,
			$to_version
		);

		$post_id = wp_insert_post(
			array(
				'post_type'    => self::POST_TYPE,
				'post_status'  => 'publish',
				'post_title'   => $entry_title,
				'post_content' => $note_html,
				'post_excerpt' => $this->helper->get_note_excerpt( $note_html ),
			),
			true
		);

		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		update_post_meta( $post_id, '_asenha_rollback_asset_type', $asset_type );
		update_post_meta( $post_id, '_asenha_rollback_asset_name', $asset_name );
		update_post_meta( $post_id, '_asenha_rollback_asset_slug', $asset_slug );
		update_post_meta( $post_id, '_asenha_rollback_from_version', $from_version );
		update_post_meta( $post_id, '_asenha_rollback_to_version', $to_version );
		update_post_meta( $post_id, '_asenha_rollback_source', $source );
		update_post_meta( $post_id, '_asenha_rollback_user_id', $user_id );

		return $post_id;
	}

	/**
	 * Update the note on an existing rollback log entry.
	 *
	 * @param int    $post_id   Log post ID.
	 * @param string $note_html Updated note HTML.
	 * @return int|WP_Error
	 */
	public function update_log_note( $post_id, $note_html ) {
		$post_id   = absint( $post_id );
		$note_html = wp_kses_post( $note_html );

		return wp_update_post(
			array(
				'ID'           => $post_id,
				'post_content' => $note_html,
				'post_excerpt' => $this->helper->get_note_excerpt( $note_html ),
			),
			true
		);
	}

	/**
	 * Query rollback log entries.
	 *
	 * @param int $paged Current page number.
	 * @return WP_Query
	 */
	public function get_logs( $paged = 1 ) {
		return new WP_Query(
			array(
				'post_type'      => self::POST_TYPE,
				'post_status'    => 'publish',
				'posts_per_page' => 20,
				'paged'          => max( 1, absint( $paged ) ),
				'orderby'        => 'date',
				'order'          => 'DESC',
			)
		);
	}

	/**
	 * Convert a log entry into a render-friendly row payload.
	 *
	 * @param WP_Post $post Post object.
	 * @return array
	 */
	public function get_log_row( $post ) {
		$user_id   = absint( get_post_meta( $post->ID, '_asenha_rollback_user_id', true ) );
		$user      = $user_id ? get_userdata( $user_id ) : false;
		$user_name = $user ? $user->display_name : __( 'Unknown user', 'admin-site-enhancements' );

		return array(
			'post_id'      => $post->ID,
			'asset_type'   => get_post_meta( $post->ID, '_asenha_rollback_asset_type', true ),
			'asset_name'   => get_post_meta( $post->ID, '_asenha_rollback_asset_name', true ),
			'asset_slug'   => get_post_meta( $post->ID, '_asenha_rollback_asset_slug', true ),
			'from_version' => get_post_meta( $post->ID, '_asenha_rollback_from_version', true ),
			'to_version'   => get_post_meta( $post->ID, '_asenha_rollback_to_version', true ),
			'source'       => get_post_meta( $post->ID, '_asenha_rollback_source', true ),
			'user_name'    => $user_name,
			'date'         => get_the_date( '', $post ),
			'time'         => get_the_time( '', $post ),
			'excerpt'      => $post->post_excerpt,
			'note_html'    => $post->post_content,
		);
	}
}
