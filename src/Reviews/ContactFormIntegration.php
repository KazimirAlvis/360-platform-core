<?php
namespace Global360\Platform\Reviews;

final class ContactFormIntegration {
	private static $saved_id = 0;

	public static function boot(): void {
		add_filter( 'wpcf7_validate', array( self::class, 'validate' ), 20, 2 );
		add_action( 'wpcf7_before_send_mail', array( self::class, 'save' ), PHP_INT_MAX, 3 );
		add_action( 'wpcf7_mail_sent', array( self::class, 'sent' ) );
		add_action( 'wpcf7_mail_failed', array( self::class, 'failed' ) );
	}

	public static function matches( $form ): bool {
		$hash = (string) get_option( 'global360_patient_review_form_hash', 'dc5be38' );
		return $form && '' !== $hash && $hash === $form->hash();
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
	}

	public static function sent( $form ): void { self::notification( $form, 'sent' ); }
	public static function failed( $form ): void { self::notification( $form, 'failed' ); }
	private static function notification( $form, string $status ): void {
		if ( ! self::$saved_id || ! self::matches( $form ) ) { return; }
		global $wpdb;
		$wpdb->update( PatientReviews::table(), array( 'notification_status' => $status ), array( 'id' => self::$saved_id ), array( '%s' ), array( '%d' ) );
	}
}
