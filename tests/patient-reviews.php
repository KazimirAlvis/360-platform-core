<?php
/** Local WordPress + real CF7 submission pipeline. Mail is intercepted; no emails leave. */
require dirname( __DIR__, 4 ) . '/wp-load.php';
use Global360\Platform\Reviews\PatientReviews as Reviews;
use Global360\Platform\Reviews\ContactFormIntegration as Integration;
use Global360\Platform\Reviews\Admin;
if ( ! in_array( wp_parse_url( home_url(), PHP_URL_HOST ), array( 'localhost', '127.0.0.1' ), true ) ) { exit( "Local only.\n" ); }
define( 'REST_REQUEST', true );
function review_assert( $ok, $message ) {
	if ( ! $ok ) { throw new RuntimeException( $message ); }
	echo "PASS: $message\n";
}
$fixtures = array();
function review_post( $type, $status, $title ) {
	global $fixtures;
	$id = wp_insert_post( array( 'post_type' => $type, 'post_status' => $status, 'post_title' => $title, 'post_name' => sanitize_title( $title ) ), true );
	if ( is_wp_error( $id ) ) { throw new RuntimeException( $id->get_error_message() ); }
	$fixtures[] = $id;
	return $id;
}
$singleton = new ReflectionProperty( WPCF7_Submission::class, 'instance' );
if ( PHP_VERSION_ID < 80100 ) { $singleton->setAccessible( true ); }
function review_submit( array $data, $form_id ) {
	global $singleton;
	$singleton->setValue( null, null );
	$_POST = wp_slash( $data + array( '_wpcf7_unit_tag' => 'wpcf7-f' . $form_id . '-o1', '_wpcf7_container_post' => $data['clinic-id'] ?? 0 ) );
	$_SERVER['HTTP_USER_AGENT'] = 'Local patient review integration test';
	$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
	return wpcf7_contact_form( $form_id )->submit( array( 'skip_mail' => false ) );
}
$review_form = current( array_filter( WPCF7_ContactForm::find(), array( \Global360\Platform\Reviews\ContactFormIntegration::class, 'matches' ) ) );
review_assert( Integration::matches( $review_form ), 'Review form signature found' );
$admin_id = 0;
$subscriber_id = 0;
$mail_calls = 0;
$mail_success = false;
$clinic = 0;
$table = Reviews::table();
$prefix = 'Review-test-' . uniqid();
$mail_filter = static function ( $pre, $atts ) use ( &$mail_calls, &$mail_success, &$clinic, $table ) {
	global $wpdb;
	++$mail_calls;
	review_assert( (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $table WHERE clinic_id=%d", $clinic ) ) > 0, 'Review is saved before wp_mail runs' );
	return $mail_success;
};
add_filter( 'pre_wp_mail', $mail_filter, 10, 2 );
try {
	$clinic = review_post( 'clinic', 'publish', $prefix . ' clinic' );
	$other = review_post( 'clinic', 'publish', $prefix . ' other clinic' );
	$draft = review_post( 'clinic', 'draft', $prefix . ' draft clinic' );
	$doctor = review_post( 'doctor', 'publish', $prefix . ' doctor' );
	$hidden = review_post( 'doctor', 'draft', $prefix . ' draft doctor' );
	$unrelated = review_post( 'doctor', 'publish', $prefix . ' unrelated doctor' );
	global360_platform()->relationships()->set_clinics_for_doctor( $doctor, array( $clinic ) );
	global360_platform()->relationships()->set_clinics_for_doctor( $hidden, array( $clinic ) );
	global360_platform()->relationships()->set_clinics_for_doctor( $unrelated, array( $other ) );
	$manual = array( array( 'reviewer' => 'Manual reviewer', 'review' => 'Manual text' ) );
	update_post_meta( $clinic, 'clinic_reviews', $manual );
	$data = array( 'clinic-id' => (string) $clinic, 'review-doctor' => (string) $doctor, 'review-rating' => '5 - Excellent', 'review-display-name' => '<b>Local Tester</b>', 'review-email' => 'local-review@example.test', 'review-message' => "Original <b>review</b> text.\nSecond line.", 'review-consent' => '1' );
	$count = static function () use ( $table, $clinic ) { global $wpdb; return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $table WHERE clinic_id=%d", $clinic ) ); };
	foreach ( range( 1, 5 ) as $rating ) {
		$validated = Reviews::validate( array_replace( $data, array( 'review-rating' => (string) $rating ) ) );
		review_assert( ! is_wp_error( $validated ) && $rating === $validated['rating'], "Rating $rating accepted" );
	}
	foreach ( array_keys( $data ) as $field ) {
		$validated = Reviews::validate( array_replace( $data, array( $field => null ) ) );
		review_assert( is_wp_error( $validated ) && in_array( $field, $validated->get_error_codes(), true ), "Null required field $field fails validation" );
	}
	foreach ( array(
		'missing clinic' => array( 'clinic-id' => '999999999' ),
		'draft clinic' => array( 'clinic-id' => (string) $draft ),
		'no selected clinic' => array( 'clinic-id' => '' ),
		'stale doctor after changing clinic' => array( 'clinic-id' => (string) $other ),
		'wrong post type' => array( 'clinic-id' => (string) $doctor ),
		'unrelated doctor' => array( 'review-doctor' => (string) $unrelated ),
		'draft doctor' => array( 'review-doctor' => (string) $hidden ),
		'missing doctor' => array( 'review-doctor' => '' ),
		'unknown doctor' => array( 'review-doctor' => '999999999' ),
		'negative doctor' => array( 'review-doctor' => '-1' ),
		'missing consent' => array( 'review-consent' => '' ),
		'fake consent' => array( 'review-consent' => 'yes' ),
		'out of range rating' => array( 'review-rating' => '6' ),
		'malformed rating' => array( 'review-rating' => '5 garbage' ),
		'missing name' => array( 'review-display-name' => '<b></b>' ),
		'invalid email' => array( 'review-email' => 'not-an-email' ),
		'missing text' => array( 'review-message' => '' ),
		'array injection' => array( 'clinic-id' => array( $clinic ) ),
	) as $case => $changes ) {
		$result = review_submit( array_replace( $data, $changes ), $review_form->id() );
		review_assert( in_array( $result['status'], array( 'validation_failed', 'acceptance_missing' ), true ) && 0 === $count() && 0 === $mail_calls, "$case rejected without storage or mail" );
	}
	add_filter( 'wpcf7_spam', '__return_true', PHP_INT_MAX );
	try { $result = review_submit( $data, $review_form->id() ); }
	finally { remove_filter( 'wpcf7_spam', '__return_true', PHP_INT_MAX ); }
	review_assert( 'spam' === $result['status'] && 0 === $count() && 0 === $mail_calls, 'CF7 spam rejection prevents storage and mail' );
	$result = review_submit( $data, $review_form->id() );
	review_assert( 'mail_failed' === $result['status'] && 1 === $count() && 1 === $mail_calls, 'Notification failure preserves exactly one review' );
	$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE clinic_id=%d", $clinic ) );
	$id = (int) $row->id;
	review_assert( 'pending' === $row->status && 'failed' === $row->notification_status && 0 === (int) $row->moderator_id, 'New submission remains pending after mail failure' );
	review_assert( 5 === (int) $row->rating && 'Local Tester' === $row->display_name && "Original review text.\nSecond line." === $row->review_text && 'local-review@example.test' === $row->email, 'Original sanitized text, rating, and private email stored' );
	review_assert( 1 === (int) $row->consent && '' !== $row->consent_text, 'Consent and consent wording retained' );
	$mail_success = true;
	$result = review_submit( $data, $review_form->id() );
	review_assert( 'mail_sent' === $result['status'] && 1 === $count() && 'sent' === Reviews::get( $id )->notification_status, 'Retry succeeds without duplicate storage' );
	review_assert( 'Thank you. Your review has been submitted and will be reviewed before publication.' === $result['message'], 'Successful submission uses the review-specific confirmation message' );
	$result = review_submit( array_replace( $data, array( 'review-doctor' => '0' ) ), $review_form->id() );
	review_assert( 'mail_sent' === $result['status'] && 2 === $count(), 'Clinic overall is accepted' );
	review_assert( $manual === get_post_meta( $clinic, 'clinic_reviews', true ), 'Manual review metadata untouched' );
	update_post_meta( $clinic, 'clinic_reviews', array() );
	delete_post_meta( $clinic, '_360_reviews' );
	review_assert( 2 === $count(), 'Replacing sync metadata cannot overwrite patient submissions' );
	$other_form_id = review_post( 'wpcf7_contact_form', 'publish', $prefix . ' unrelated form' );
	$before_mail = $mail_calls;
	review_submit( $data, $other_form_id );
	review_assert( 2 === $count(), 'Ordinary CF7 form does not store reviews' );
	// Force storage failure only for the review INSERT, without changing real tables.
	$break_storage = static function ( $query ) use ( $table ) { return 0 === strpos( $query, "INSERT INTO $table " ) ? 'INSERT INTO nonexistent_review_test_table (id) VALUES (1)' : $query; };
	$before_mail = $mail_calls;
	$old_suppress = $wpdb->suppress_errors( true );
	add_filter( 'query', $break_storage );
	try { $result = review_submit( array_replace( $data, array( 'review-message' => 'Storage failure test' ) ), $review_form->id() ); }
	finally { remove_filter( 'query', $break_storage ); $wpdb->suppress_errors( $old_suppress ); }
	review_assert( 'aborted' === $result['status'] && $mail_calls === $before_mail && 2 === $count(), 'Storage failure aborts notification' );
	$admin_id = wp_insert_user( array( 'user_login' => $prefix . '-moderator', 'user_pass' => wp_generate_password(), 'role' => 'administrator' ) );
	$subscriber_id = wp_insert_user( array( 'user_login' => $prefix . '-subscriber', 'user_pass' => wp_generate_password(), 'role' => 'subscriber' ) );
	review_assert( ! is_wp_error( $admin_id ) && ! is_wp_error( $subscriber_id ), 'Temporary staff and subscriber created' );
	wp_set_current_user( $subscriber_id );
	review_assert( is_wp_error( Reviews::moderate( $id, 'approve', wp_create_nonce( 'global360_review_' . $id ) ) ), 'Unauthorized user denied despite valid nonce' );
	wp_set_current_user( 0 );
	review_assert( is_wp_error( Reviews::moderate( $id, 'approve', wp_create_nonce( 'global360_review_' . $id ) ) ), 'Anonymous moderation denied' );
	wp_set_current_user( $admin_id );
	review_assert( is_wp_error( Reviews::moderate( $id, 'approve', 'bad-nonce' ) ), 'Invalid nonce denied' );
	$nonce = wp_create_nonce( 'global360_review_' . $id );
	review_assert( is_wp_error( Reviews::moderate( $id, 'publish', $nonce ) ), 'Unknown moderation action denied' );
	foreach ( array( 'approve' => 'approved', 'reject' => 'rejected', 'hide' => 'hidden' ) as $action => $status ) {
		review_assert( true === Reviews::moderate( $id, $action, $nonce ), "$action permitted for authorized moderator" );
		$updated = Reviews::get( $id );
		review_assert( $status === $updated->status && (int) $updated->moderator_id === $admin_id && ! empty( $updated->moderated_at ), "$status records moderator and timestamp" );
		review_assert( $row->review_text === $updated->review_text && $row->rating === $updated->rating && $row->email === $updated->email, 'Moderation preserves submitted content' );
	}
	wp_set_current_user( 0 );
	review_submit( $data, $review_form->id() );
	review_assert( 2 === $count() && 'hidden' === Reviews::get( $id )->status && (int) Reviews::get( $id )->moderator_id === $admin_id, 'Replay cannot reset moderation or duplicate a hidden review' );
	wp_set_current_user( $admin_id );
	$_GET['review_id'] = $id;
	set_error_handler( static function ( $severity, $message, $file, $line ) {
		if ( E_DEPRECATED === $severity ) { throw new ErrorException( $message, 0, $severity, $file, $line ); }
		return false;
	} );
	try {
		ob_start();
		try { Admin::render(); $html = ob_get_contents(); } finally { ob_end_clean(); }
	} finally { restore_error_handler(); }
	review_assert( false !== strpos( $html, 'local-review@example.test' ) && false !== strpos( $html, 'Submitted review (read-only)' ) && false === strpos( $html, '<textarea' ), 'Authorized detail view exposes private email with read-only review' );
	unset( $_GET['review_id'] );
	// Control only the count query to cover pagination regardless of existing local data.
	foreach ( array( 0, 1, 30, 31 ) as $total ) {
		$count_filter = static function ( $query ) use ( $table, $total ) {
			return "SELECT COUNT(*) FROM $table" === $query ? 'SELECT ' . $total : $query;
		};
		add_filter( 'query', $count_filter );
		set_error_handler( static function ( $severity, $message, $file, $line ) {
			if ( E_DEPRECATED === $severity ) { throw new ErrorException( $message, 0, $severity, $file, $line ); }
			return false;
		} );
		try {
			ob_start();
			try { Admin::render(); $list_html = ob_get_contents(); } finally { ob_end_clean(); }
		} finally { restore_error_handler(); remove_filter( 'query', $count_filter ); }
		review_assert( ( $total > 30 ) === ( false !== strpos( $list_html, 'class="page-numbers"' ) ), "List with $total reviews renders expected pagination without deprecations" );
	}
	wp_set_current_user( 0 );
	$request = new WP_REST_Request( 'GET', '/wp/v2/search' );
	$request->set_param( 'search', 'Original review text' );
	$response = rest_do_request( $request );
	review_assert( false === strpos( wp_json_encode( $response->get_data() ), 'local-review@example.test' ), 'Public REST search does not expose private review data' );
	review_assert( 404 === rest_do_request( new WP_REST_Request( 'GET', '/wp/v2/patient-reviews' ) )->get_status(), 'No public patient review REST endpoint exists' );
	echo "Patient review integration checks passed.\n";
} finally {
	remove_filter( 'pre_wp_mail', $mail_filter );
	wp_set_current_user( 0 );
	if ( $clinic ) { $wpdb->delete( $table, array( 'clinic_id' => $clinic ), array( '%d' ) ); }
	foreach ( array_reverse( $fixtures ) as $id ) { wp_delete_post( $id, true ); }
	require_once ABSPATH . 'wp-admin/includes/user.php';
	foreach ( array( $admin_id, $subscriber_id ) as $id ) { if ( is_int( $id ) && $id > 0 ) { wp_delete_user( $id ); } }
}
