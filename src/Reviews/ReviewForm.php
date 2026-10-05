<?php
namespace Global360\Platform\Reviews;

/** Shared CF7 form choices and public directory lookup. No patient records are read. */
final class ReviewForm {
	public static function boot(): void {
		add_filter( 'wpcf7_form_class_attr', static function ( $classes ) {
			return ContactFormIntegration::matches( \WPCF7_ContactForm::get_current() ) ? $classes . ' global360-patient-review-form' : $classes;
		} );
		add_filter( 'wpcf7_form_tag', array( self::class, 'tag' ) );
		add_filter( 'wpcf7_form_elements', array( self::class, 'elements' ) );
		add_filter( 'rest_post_dispatch', array( self::class, 'browser_schema' ), 10, 3 );
		add_action( 'rest_api_init', static function () {
			register_rest_route( 'global360/v1', '/review-clinics/(?P<id>[0-9]+)/doctors', array(
				'methods' => 'GET', 'permission_callback' => '__return_true',
				'callback' => array( self::class, 'endpoint' ),
			) );
		} );
	}

	public static function clinics(): array {
		return get_posts( array( 'post_type' => 'clinic', 'post_status' => 'publish', 'has_password' => false,
			'posts_per_page' => -1, 'orderby' => 'title', 'order' => 'ASC' ) );
	}

	public static function doctors( int $clinic_id ): array {
		$clinic = get_post( $clinic_id );
		if ( ! $clinic_id || ! $clinic || 'clinic' !== $clinic->post_type || 'publish' !== $clinic->post_status || $clinic->post_password ) { return array(); }
		$ids = global360_platform()->relationships()->doctors_for_clinic( $clinic_id );
		return $ids ? get_posts( array( 'post_type' => 'doctor', 'post_status' => 'publish', 'has_password' => false,
			'post__in' => $ids, 'posts_per_page' => -1, 'orderby' => 'title', 'order' => 'ASC' ) ) : array();
	}

	public static function endpoint( $request ) {
		$id = (int) $request['id'];
		$clinic = get_post( $id );
		if ( ! $id || ! $clinic || 'clinic' !== $clinic->post_type || 'publish' !== $clinic->post_status || $clinic->post_password ) {
			return new \WP_Error( 'invalid_clinic', 'Choose a published clinic.', array( 'status' => 404 ) );
		}
		$response = new \WP_REST_Response( array( 'clinic_id' => $id, 'doctors' => array_map( static function ( $doctor ) {
			return array( 'id' => (int) $doctor->ID, 'name' => wp_specialchars_decode( wp_strip_all_tags( get_the_title( $doctor ) ), ENT_QUOTES ) );
		}, self::doctors( $id ) ) ) );
		$response->header( 'Cache-Control', 'no-store' );
		return $response;
	}

	public static function tag( $tag ) {
		if ( ! ContactFormIntegration::matches( \WPCF7_ContactForm::get_current() ) ) { return $tag; }
		$tag = new \WPCF7_FormTag( $tag );
		if ( 'select' !== $tag->basetype || ! in_array( $tag->name, array( 'clinic-id', 'review-doctor' ), true ) ) { return $tag; }
		$tag->values = array( '' );
		$tag->labels = array( 'clinic-id' === $tag->name ? 'Select a clinic' : 'Select a clinic first' );
		if ( 'clinic-id' === $tag->name ) {
			$posts = self::clinics();
			if ( ! $posts ) { $tag->labels[0] = 'No clinics are currently available'; }
		} else {
			// CF7 scans the form during POST validation. Never trust a URL/referrer.
			$raw = $_POST['clinic-id'] ?? '';
			$clinic_id = is_string( $raw ) && ctype_digit( $raw ) ? (int) $raw : 0;
			$posts = self::doctors( $clinic_id );
			if ( $clinic_id ) {
				$tag->values[] = '0';
				$tag->labels[] = 'Clinic overall / No specific doctor';
			}
		}
		foreach ( $posts as $post ) {
			$tag->values[] = (string) $post->ID;
			$tag->labels[] = get_the_title( $post );
		}
		$tag->raw_values = $tag->values;
		$tag->pipes = new \WPCF7_Pipes( array() );
		$tag->options = array_values( array_filter( $tag->options, static function ( $option ) {
			return ! in_array( $option, array( 'first_as_label', 'include_blank', 'multiple' ), true ) && ! preg_match( '/^(default|data):/', $option );
		} ) );
		return $tag;
	}

	public static function elements( $html ) {
		$form = \WPCF7_ContactForm::get_current();
		if ( ! ContactFormIntegration::matches( $form ) ) { return $html; }
		do_action( 'global360_review_form_rendering', $form );
		wp_enqueue_script( 'global360-review-form', plugins_url( 'assets/js/review-form.js', GLOBAL360_PLATFORM_FILE ), array(), filemtime( GLOBAL360_PLATFORM_PATH . 'assets/js/review-form.js' ), true );
		// Disabled in the delivered HTML as well as JS, avoiding stale initial choices.
		$html = preg_replace( '/<select\b(?=[^>]*\bname="review-doctor")([^>]*)>/i', '<select disabled$1>', $html );
		return '<div data-global360-review-form data-doctors-url="' . esc_url( rest_url( 'global360/v1/review-clinics/' ) ) . '">' . $html
			. '<p data-review-doctor-status role="status" aria-live="polite">Choose a clinic to see its doctors.</p>'
			. '<button type="button" data-review-doctor-retry hidden>Retry loading doctors</button>'
			. '<noscript>Please enable JavaScript to choose a clinic and doctor and submit your review.</noscript></div>';
	}

	public static function browser_schema( $response, $server, $request ) {
		if ( 'GET' !== $request->get_method() || ! preg_match( '#^/contact-form-7/v1/contact-forms/(\d+)/feedback/schema$#', $request->get_route(), $matches ) ) { return $response; }
		if ( ! ContactFormIntegration::matches( wpcf7_contact_form( (int) $matches[1] ) ) || 200 !== $response->get_status() ) { return $response; }
		$data = $response->get_data();
		if ( isset( $data['rules'] ) ) {
			// Browser schema has no selected clinic; POST schema still validates membership.
			$data['rules'] = array_values( array_filter( $data['rules'], static function ( $rule ) {
				return ! ( 'enum' === ( $rule['rule'] ?? '' ) && 'review-doctor' === ( $rule['field'] ?? '' ) );
			} ) );
			$response->set_data( $data );
		}
		return $response;
	}
}
