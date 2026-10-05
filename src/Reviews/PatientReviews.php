<?php
namespace Global360\Platform\Reviews;

/** Private, immutable submission records. Deliberately outside clinic/API metadata. */
final class PatientReviews {
	const CAPABILITY = 'moderate_patient_reviews';
	const VERSION = '2';

	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . '360_patient_reviews';
	}

	public static function install(): void {
		if ( self::VERSION === get_option( 'global360_patient_reviews_schema' ) ) { return; }
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$table = self::table();
		$collation = $wpdb->get_charset_collate();
		dbDelta( "CREATE TABLE $table (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			submission_key char(64) NOT NULL,
			form_id bigint(20) unsigned NOT NULL,
			clinic_id bigint(20) unsigned NOT NULL,
			doctor_id bigint(20) unsigned NOT NULL DEFAULT 0,
			rating tinyint(3) unsigned NOT NULL,
			display_name varchar(200) NOT NULL,
			email varchar(254) NOT NULL,
			review_text longtext NOT NULL,
			consent tinyint(1) unsigned NOT NULL,
			consent_text text NOT NULL,
			submitted_at datetime NOT NULL,
			status varchar(20) NOT NULL DEFAULT 'pending',
			previous_status varchar(20) NOT NULL DEFAULT '',
			moderated_at datetime DEFAULT NULL,
			moderator_id bigint(20) unsigned NOT NULL DEFAULT 0,
			notification_status varchar(20) NOT NULL DEFAULT 'pending',
			previous_status varchar(20) NOT NULL DEFAULT '',
			PRIMARY KEY  (id),
			UNIQUE KEY submission_key (submission_key),
			KEY status_date (status,submitted_at),
			KEY clinic_id (clinic_id)
		) $collation;" );
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) !== $table ) { return; }
		if ( ! $wpdb->get_var( "SHOW COLUMNS FROM $table LIKE 'previous_status'" ) ) { return; }
		$role = get_role( 'administrator' );
		if ( $role ) { $role->add_cap( self::CAPABILITY ); }
		update_option( 'global360_patient_reviews_schema', self::VERSION, false );
	}

	/** Return sanitized data or field-specific errors. Input must be unslashed. */
	public static function validate( array $input ) {
		$errors = new \WP_Error();
		$get = static function ( $key ) use ( $input ) {
			return isset( $input[$key] ) && is_scalar( $input[$key] ) ? trim( (string) $input[$key] ) : '';
		};
		$clinic_raw = $get( 'clinic-id' );
		$doctor_raw = $get( 'review-doctor' );
		$clinic_id = ctype_digit( $clinic_raw ) ? (int) $clinic_raw : 0;
		$doctor_id = ctype_digit( $doctor_raw ) ? (int) $doctor_raw : -1;
		$clinic = get_post( $clinic_id );
		if ( ! $clinic_id || ! $clinic || 'clinic' !== $clinic->post_type || 'publish' !== $clinic->post_status || $clinic->post_password ) {
			$errors->add( 'clinic-id', 'Choose a published clinic.' );
		}
		$doctor = $doctor_id > 0 ? get_post( $doctor_id ) : null;
		if ( $doctor_id < 0 || ( $doctor_id > 0 && ( ! $doctor || 'doctor' !== $doctor->post_type || 'publish' !== $doctor->post_status || $doctor->post_password || ! in_array( $clinic_id, global360_platform()->relationships()->clinics_for_doctor( $doctor_id ), true ) ) ) ) {
			$errors->add( 'review-doctor', 'Choose a published doctor at this clinic, or Clinic overall.' );
		}
		$rating_raw = $get( 'review-rating' );
		$ratings = array( '1 - Poor' => 1, '2 - Fair' => 2, '3 - Good' => 3, '4 - Very good' => 4, '5 - Excellent' => 5 );
		$rating = $ratings[$rating_raw] ?? ( preg_match( '/^[1-5]$/D', $rating_raw ) ? (int) $rating_raw : 0 );
		if ( ! $rating ) { $errors->add( 'review-rating', 'Choose a rating from 1 to 5.' ); }
		$name = sanitize_text_field( $get( 'review-display-name' ) );
		$email_raw = $get( 'review-email' );
		$email = sanitize_email( $email_raw );
		$text = sanitize_textarea_field( $get( 'review-message' ) );
		if ( '' === $name || strlen( $name ) > 200 ) { $errors->add( 'review-display-name', 'Enter a display name of at most 200 bytes.' ); }
		if ( ! is_email( $email_raw ) || $email !== $email_raw || strlen( $email ) > 254 ) { $errors->add( 'review-email', 'Enter a valid email address.' ); }
		if ( '' === $text || strlen( $text ) > 20000 ) { $errors->add( 'review-message', 'Enter review text of at most 20,000 bytes.' ); }
		if ( '1' !== $get( 'review-consent' ) ) { $errors->add( 'review-consent', 'Publishing consent is required.' ); }
		if ( $errors->has_errors() ) { return $errors; }
		return array( 'clinic_id' => $clinic_id, 'doctor_id' => $doctor_id, 'rating' => $rating, 'display_name' => $name, 'email' => $email, 'review_text' => $text, 'consent' => 1 );
	}

	/** Atomic duplicate prevention, including retries after failed notification. */
	public static function store( array $input, int $form_id, string $consent_text ) {
		$data = self::validate( $input );
		if ( is_wp_error( $data ) ) { return $data; }
		global $wpdb;
		$table = self::table();
		$key = hash_hmac( 'sha256', wp_json_encode( array( $form_id, $data ) ), wp_salt( 'auth' ) );
		$sql = $wpdb->prepare(
			"INSERT INTO $table (submission_key,form_id,clinic_id,doctor_id,rating,display_name,email,review_text,consent,consent_text,submitted_at)
			 VALUES (%s,%d,%d,%d,%d,%s,%s,%s,1,%s,%s)
			 ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id)",
			$key, $form_id, $data['clinic_id'], $data['doctor_id'], $data['rating'], $data['display_name'], $data['email'], $data['review_text'], sanitize_textarea_field( $consent_text ), current_time( 'mysql', true )
		);
		if ( false === $wpdb->query( $sql ) ) { return new \WP_Error( 'storage', 'Your review could not be saved. Please try again.' ); }
		$id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $table WHERE submission_key=%s", $key ) );
		return $id ?: new \WP_Error( 'storage', 'Your review could not be saved. Please try again.' );
	}

	public static function get( int $id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE id=%d', $id ) );
	}

	/** Only moderation columns can be changed through the staff interface. */
	public static function moderate( int $id, string $action, string $nonce ) {
		if ( ! current_user_can( self::CAPABILITY ) ) { return new \WP_Error( 'forbidden', 'You cannot moderate patient reviews.' ); }
		if ( ! wp_verify_nonce( $nonce, 'global360_review_' . $id ) ) { return new \WP_Error( 'nonce', 'Invalid moderation request. Reload the review and try again.' ); }
		$statuses = array( 'approve' => 'approved', 'reject' => 'rejected', 'hide' => 'hidden' );
		$row = self::get( $id );
		if ( ! isset( $statuses[$action] ) || ! $row || 'trash' === $row->status ) { return new \WP_Error( 'invalid', 'Invalid review or moderation action.' ); }
		global $wpdb;
		$result = $wpdb->update( self::table(), array( 'status' => $statuses[$action], 'moderated_at' => current_time( 'mysql', true ), 'moderator_id' => get_current_user_id() ), array( 'id' => $id, 'status' => $row->status ), array( '%s', '%s', '%d' ), array( '%d', '%s' ) );
		if ( false === $result ) { return new \WP_Error( 'storage', 'The moderation change could not be saved.' ); }
		do_action( 'global360_patient_review_moderated', $id, $statuses[$action] );
		return true;
	}
	/** Custom-table trash lifecycle. Content remains immutable until explicit deletion. */
	public static function trash_action( int $id, string $action, string $nonce, bool $confirmed = false ) {
		if ( ! current_user_can( self::CAPABILITY ) ) { return new \WP_Error( 'forbidden', 'You cannot manage patient reviews.' ); }
		if ( ! in_array( $action, array( 'trash', 'restore', 'delete' ), true ) || ! wp_verify_nonce( $nonce, 'global360_review_' . $action . '_' . $id ) ) {
			return new \WP_Error( 'nonce', 'Invalid review action. Reload and try again.' );
		}
		$row = self::get( $id );
		if ( ! $row ) { return new \WP_Error( 'missing', 'Review not found.' ); }
		$states = array( 'pending', 'approved', 'rejected', 'hidden' );
		if ( ( 'trash' === $action && ! in_array( $row->status, $states, true ) ) || ( 'trash' !== $action && 'trash' !== $row->status ) ) {
			return new \WP_Error( 'state', 'This action is not available for the current review status.' );
		}
		global $wpdb;
		$table = self::table();
		if ( 'delete' === $action ) {
			if ( ! $confirmed ) { return new \WP_Error( 'confirmation', 'Confirm permanent deletion first.' ); }
			$result = $wpdb->delete( $table, array( 'id' => $id, 'status' => 'trash' ), array( '%d', '%s' ) );
			$status = 'deleted';
		} else {
			$status = 'trash' === $action ? 'trash' : $row->previous_status;
			if ( 'restore' === $action && ! in_array( $status, $states, true ) ) { return new \WP_Error( 'state', 'Previous moderation status is unavailable.' ); }
			$result = $wpdb->update( $table, array( 'status' => $status, 'previous_status' => 'trash' === $action ? $row->status : '', 'moderated_at' => current_time( 'mysql', true ), 'moderator_id' => get_current_user_id() ), array( 'id' => $id, 'status' => $row->status ), array( '%s', '%s', '%s', '%d' ), array( '%d', '%s' ) );
		}
		if ( 1 !== $result ) { return new \WP_Error( 'storage', 'Review changed or could not be saved. Reload and try again.' ); }
		do_action( 'global360_patient_review_moderated', $id, $status );
		return true;
	}

}
