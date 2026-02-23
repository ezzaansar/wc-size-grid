<?php
/**
 * Logo file upload AJAX endpoint.
 *
 * Handles customer logo uploads on the product page via a dedicated
 * WC AJAX endpoint. Files are saved as WordPress attachments.
 *
 * @package WorkwearSizeGrid
 */

defined( 'ABSPATH' ) || exit;

add_action( 'wc_ajax_wsg_upload_logo', 'wsg_handle_logo_upload' );

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
