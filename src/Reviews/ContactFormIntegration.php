<?php
namespace Global360\Platform\Reviews;

final class ContactFormIntegration {
	private static $saved_id = 0;
	private static $mail_form_id = 0;
	private static $mail_names = array();

	public static function boot(): void {
		add_filter( 'wpcf7_mail_tag_replaced', array( self::class, 'mail_tag' ), 20, 4 );
		add_filter( 'wpcf7_validate', array( self::class, 'validate' ), 20, 2 );
		add_action( 'wpcf7_before_send_mail', array( self::class, 'save' ), PHP_INT_MAX, 3 );
		add_action( 'wpcf7_mail_sent', array( self::class, 'sent' ) );
		add_action( 'wpcf7_mail_failed', array( self::class, 'failed' ) );
	}

	/** Identify actual CF7 tags, independent of ID, title, or containing page. */
	public static function matches( $form ): bool {
		static $scanning = false;
		static $signatures = array();
		if ( ! $form instanceof \WPCF7_ContactForm || $scanning ) { return false; }
		$markup = (string) $form->prop( 'form' );
		$key = hash( 'sha256', $markup );
		if ( array_key_exists( $key, $signatures ) ) { return $signatures[$key]; }
		// CF7 invokes our tag filter while scanning. Isolate its mutable scanner
		// and guard re-entry so detection cannot corrupt the ongoing render.
		$manager = clone \WPCF7_FormTagsManager::get_instance();
		$scanning = true;
		try { $tags = $manager->scan( $markup ); }
		finally { $scanning = false; }
		$names = array_map( static function ( $tag ) { return $tag->name; }, $tags );
		$required = array( 'clinic-id', 'review-doctor', 'review-rating', 'review-display-name', 'review-email', 'review-message', 'review-consent' );
		return $signatures[$key] = ! array_diff( $required, $names );
	}

	private static function input(): array {
		// Validate the original field shapes; CF7 converts select/radio to arrays.
		return wp_unslash( $_POST );
	}

	public static function validate( $result, $tags ) {
		if ( ! self::matches( \WPCF7_ContactForm::get_current() ) ) { return $result; }
		$validated = PatientReviews::validate( self::input() );
		if ( is_wp_error( $validated ) ) {
			foreach ( $validated->get_error_codes() as $field ) {
				$result->invalidate( $field, $validated->get_error_message( $field ) );
			}
		}
		return $result;
	}

	public static function save( $form, &$abort, $submission ): void {
		self::$saved_id = 0;
		self::$mail_form_id = 0;
		self::$mail_names = array();
		if ( $abort || ! self::matches( $form ) ) { return; }
		$consent = '';
		foreach ( $form->scan_form_tags() as $tag ) {
			if ( 'review-consent' === $tag->name ) { $consent = wp_strip_all_tags( $tag->content ); }
		}
		$id = PatientReviews::store( self::input(), (int) $form->id(), $consent );
		if ( is_wp_error( $id ) ) {
			$abort = true;
			$submission->set_response( $id->get_error_message() );
			return;
		}
		self::$saved_id = $id;
		// Storage revalidates the IDs. Resolve names only from that saved record.
		$row = PatientReviews::get( $id );
		if ( ! $row ) {
			$abort = true;
			$submission->set_response( 'Your review was saved, but its notification could not be prepared.' );
			return;
		}
		$clinic = global360_platform()->clinics()->get( (int) $row->clinic_id );
		$doctor = $row->doctor_id ? global360_platform()->doctors()->get( (int) $row->doctor_id ) : null;
		self::$mail_form_id = (int) $form->id();
		self::$mail_names = array(
			'review-clinic-name' => $clinic['name'] ?? '',
			'review-doctor-name' => $row->doctor_id ? ( $doctor['name'] ?? '' ) : 'Clinic overall / No specific doctor',
		);

	}

	/** Override even forged posted name fields; special tags alone can be bypassed by POST. */
	public static function mail_tag( $replaced, $submitted, $html, $tag ) {
		$name = $tag->field_name();
		$form = \WPCF7_ContactForm::get_current();
		if ( ! in_array( $name, array( 'review-clinic-name', 'review-doctor-name' ), true ) || ! self::matches( $form ) ) { return $replaced; }
		$value = self::$mail_form_id === (int) $form->id() ? ( self::$mail_names[$name] ?? '' ) : '';
		$value = sanitize_text_field( wp_specialchars_decode( (string) $value, ENT_QUOTES ) );
		return $html ? esc_html( $value ) : $value;
	}

	public static function sent( $form ): void { self::notification( $form, 'sent' ); }
	public static function failed( $form ): void { self::notification( $form, 'failed' ); }
	private static function notification( $form, string $status ): void {
		if ( ! self::$saved_id || ! self::matches( $form ) ) { return; }
		global $wpdb;
		$wpdb->update( PatientReviews::table(), array( 'notification_status' => $status ), array( 'id' => self::$saved_id ), array( '%s' ), array( '%d' ) );
	}
}
