<?php
/**
 * Logo file upload AJAX endpoint and orphan cleanup.
 *
 * Handles customer logo uploads on the product page via a dedicated
 * WC AJAX endpoint. Files are saved as WordPress attachments.
 * Includes a daily cron to remove orphaned uploads not tied to any order.
 *
 * @package WorkwearSizeGrid
 */

defined( 'ABSPATH' ) || exit;

add_action( 'wc_ajax_wsg_upload_logo', 'wsg_handle_logo_upload' );

/* ───────────────────────────────────────────
 * Orphan logo cleanup cron
 * ─────────────────────────────────────────── */

add_action( 'init', 'wsg_schedule_logo_cleanup' );
add_action( 'wsg_cleanup_orphan_logos', 'wsg_cleanup_orphan_logo_uploads' );

/**
 * Schedule daily cleanup of orphaned logo uploads.
 *
 * @return void
 */
function wsg_schedule_logo_cleanup() {
	if ( ! wp_next_scheduled( 'wsg_cleanup_orphan_logos' ) ) {
		wp_schedule_event( time(), 'daily', 'wsg_cleanup_orphan_logos' );
	}
}

/**
 * Delete logo uploads older than 30 days that aren't referenced by any order.
 *
 * Processes up to 50 attachments per run to avoid long-running queries.
 * Retention period can be adjusted via the `wsg_logo_cleanup_days` filter.
 *
 * @return void
 */
function wsg_cleanup_orphan_logo_uploads() {
	global $wpdb;

	$days   = (int) apply_filters( 'wsg_logo_cleanup_days', 30 );
	$cutoff = gmdate( 'Y-m-d H:i:s', strtotime( "-{$days} days" ) );

	// Find logo uploads older than the retention period.
	$attachment_ids = $wpdb->get_col(
		$wpdb->prepare(
			"SELECT p.ID FROM {$wpdb->posts} p
			 INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
			 WHERE p.post_type = 'attachment'
			   AND pm.meta_key = '_wsg_logo_upload'
			   AND pm.meta_value = 'yes'
			   AND p.post_date_gmt < %s
			 LIMIT 50",
			$cutoff
		)
	);

	if ( empty( $attachment_ids ) ) {
		return;
	}

	$order_items_table = $wpdb->prefix . 'woocommerce_order_itemmeta';

	foreach ( $attachment_ids as $att_id ) {
		// Check if any order line item references this attachment.
		$in_use = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT 1 FROM {$order_items_table}
				 WHERE meta_key = '_wsg_logo_attachment_id'
				   AND meta_value = %s
				 LIMIT 1",
				$att_id
			)
		);

		if ( ! $in_use ) {
			wp_delete_attachment( (int) $att_id, true );
		}
	}
}

/**
 * Handle logo file upload via AJAX.
 *
 * Validates file type and size, uploads via wp_handle_upload(),
 * creates a WP attachment, and returns the attachment data.
 *
 * @return void Sends JSON response.
 */
function wsg_handle_logo_upload() {

	check_ajax_referer( 'wsg_nonce', 'security' );

	if ( empty( $_FILES['logo_file'] ) ) {
		wp_send_json_error( array( 'message' => __( 'No file uploaded.', 'wsg' ) ) );
	}

	$file = $_FILES['logo_file']; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput

	/* --- Validate file size (5 MB default) --- */
	$max_size = apply_filters( 'wsg_logo_max_file_size', 5 * MB_IN_BYTES );
	if ( $file['size'] > $max_size ) {
		wp_send_json_error( array( 'message' => __( 'File is too large. Maximum size is 5 MB.', 'wsg' ) ) );
	}

	/* --- Allowed MIME types --- */
	$allowed_mimes = array(
		'jpg|jpeg|jpe' => 'image/jpeg',
		'png'          => 'image/png',
		'gif'          => 'image/gif',
		'webp'         => 'image/webp',
	);

	require_once ABSPATH . 'wp-admin/includes/file.php';
	require_once ABSPATH . 'wp-admin/includes/image.php';
	require_once ABSPATH . 'wp-admin/includes/media.php';

	/* --- Server-side MIME validation --- */
	$filetype = wp_check_filetype_and_ext( $file['tmp_name'], $file['name'], $allowed_mimes );
	if ( empty( $filetype['type'] ) ) {
		wp_send_json_error( array( 'message' => __( 'Invalid file type. Please upload a JPG, PNG, GIF, or WebP image.', 'wsg' ) ) );
	}

	/* --- Upload --- */
	$upload = wp_handle_upload(
		$file,
		array(
			'test_form' => false,
			'mimes'     => $allowed_mimes,
		)
	);

	if ( isset( $upload['error'] ) ) {
		wp_send_json_error( array( 'message' => $upload['error'] ) );
	}

	/* --- Create WP attachment --- */
	$attachment_id = wp_insert_attachment(
		array(
			'post_mime_type' => $upload['type'],
			'post_title'     => sanitize_file_name( pathinfo( $upload['file'], PATHINFO_FILENAME ) ),
			'post_content'   => '',
			'post_status'    => 'inherit',
		),
		$upload['file']
	);

	if ( is_wp_error( $attachment_id ) ) {
		wp_send_json_error( array( 'message' => __( 'Failed to save file.', 'wsg' ) ) );
	}

	$metadata = wp_generate_attachment_metadata( $attachment_id, $upload['file'] );
	wp_update_attachment_metadata( $attachment_id, $metadata );

	/* --- Tag for future cleanup --- */
	update_post_meta( $attachment_id, '_wsg_logo_upload', 'yes' );

	wp_send_json_success(
		array(
			'attachment_id' => $attachment_id,
			'url'           => wp_get_attachment_url( $attachment_id ),
			'thumbnail'     => wp_get_attachment_image_url( $attachment_id, 'thumbnail' ),
		)
	);
}
