<?php
require dirname( __DIR__, 4 ) . '/wp-load.php';
use Global360\Platform\Reviews\ReviewForm;
if ( ! in_array( wp_parse_url( home_url(), PHP_URL_HOST ), array( 'localhost', '127.0.0.1' ), true ) ) { exit( "Local only.\n" ); }
function shared_expect( $ok, $message ) { if ( ! $ok ) { throw new RuntimeException( $message ); } echo "PASS: $message\n"; }
function shared_get( $path ) {
	$r = wp_remote_get( home_url( $path ), array( 'redirection' => 0 ) );
	shared_expect( ! is_wp_error( $r ), "HTTP $path" );
	return $r;
}
$fixtures = array();
function shared_fixture( $type, $status, $suffix ) {
	global $fixtures;
	$id = wp_insert_post( array( 'post_type' => $type, 'post_status' => $status, 'post_title' => 'Shared review fixture ' . $suffix, 'post_name' => 'shared-review-' . uniqid() ), true );
	shared_expect( ! is_wp_error( $id ), 'Fixture created' );
	$fixtures[] = $id;
	return $id;
}
try {
	$a = shared_fixture( 'clinic', 'publish', 'A' );
	$b = shared_fixture( 'clinic', 'publish', 'B' );
	$draft = shared_fixture( 'clinic', 'draft', 'draft' );
	$doctor = shared_fixture( 'doctor', 'publish', 'doctor' );
	$hidden = shared_fixture( 'doctor', 'draft', 'hidden doctor' );
	global360_platform()->relationships()->set_clinics_for_doctor( $doctor, array( $a ) );
	global360_platform()->relationships()->set_clinics_for_doctor( $hidden, array( $a ) );
	$r = shared_get( '/leave-a-review/' );
	shared_expect( 200 === wp_remote_retrieve_response_code( $r ), 'Shared page returns 200' );
	$html = wp_remote_retrieve_body( $r );
	$dom = new DOMDocument(); @$dom->loadHTML( $html ); $x = new DOMXPath( $dom );
	shared_expect( 1 === $x->query( '//select[@name="clinic-id"]' )->length && 0 === $x->query( '//input[@name="clinic-id"]' )->length, 'Clinic field is a dropdown, not hidden' );
	shared_expect( 'true' === $x->evaluate( 'string(//select[@name="clinic-id"]/@aria-required)' ), 'Clinic is required' );
	shared_expect( 1 === $x->query( '//select[@name="review-doctor"][@disabled]' )->length, 'Doctor is disabled in initial HTML' );
	foreach ( array( $a, $b ) as $id ) { shared_expect( 1 === $x->query( '//select[@name="clinic-id"]/option[@value="' . $id . '"]' )->length, 'Published clinic offered with stable ID' ); }
	shared_expect( 0 === $x->query( '//select[@name="clinic-id"]/option[@value="' . $draft . '"]' )->length, 'Draft clinic excluded' );
	shared_expect( false !== strpos( $html, 'global360-review-form-js' ) && false !== strpos( $html, 'contact-form-7-js' ), 'Shared page loads form behavior and CF7 scripts' );
	foreach ( array( 'review-rating', 'review-display-name', 'review-email', 'review-message', 'review-consent' ) as $name ) { shared_expect( $x->query( '//*[@name="' . $name . '"]' )->length > 0, "$name preserved" ); }
	foreach ( array( $a => array( $doctor ), $b => array() ) as $id => $expected ) {
		$r = shared_get( '/wp-json/global360/v1/review-clinics/' . $id . '/doctors' );
		$data = json_decode( wp_remote_retrieve_body( $r ), true );
		shared_expect( 200 === wp_remote_retrieve_response_code( $r ) && $id === $data['clinic_id'] && $expected === array_column( $data['doctors'], 'id' ), 'Endpoint isolates published doctors, supports empty clinic' );
		shared_expect( array( 'clinic_id', 'doctors' ) === array_keys( $data ), 'Endpoint exposes only directory data' );
		foreach ( $data['doctors'] as $entry ) { shared_expect( array( 'id', 'name' ) === array_keys( $entry ), 'Doctor payload contains only ID and name' ); }
	}
	foreach ( array( $draft, $doctor, 0, 999999999 ) as $id ) {
		$r = shared_get( '/wp-json/global360/v1/review-clinics/' . $id . '/doctors' );
		shared_expect( 404 === wp_remote_retrieve_response_code( $r ), 'Invalid or unpublished clinic lookup returns 404' );
	}
	foreach ( array( get_post_field( 'post_name', $a ), 'missing-shared-review-fixture' ) as $slug ) {
		$r = shared_get( '/clinics/' . $slug . '/review-form/' );
		shared_expect( 404 === wp_remote_retrieve_response_code( $r ) && false === strpos( wp_remote_retrieve_body( $r ), 'data-global360-review-form' ), 'Removed clinic-specific route returns 404 without form' );
	}
	$r = shared_get( '/clinics/' . get_post_field( 'post_name', $a ) . '/' );
	shared_expect( 200 === wp_remote_retrieve_response_code( $r ) && false === strpos( wp_remote_retrieve_body( $r ), 'global360-review-form-js' ), 'Normal clinic page unchanged and has no shared-form script' );
	$form = wpcf7_get_contact_form_by_hash( 'dc5be38' );
	$r = shared_get( '/wp-json/contact-form-7/v1/contact-forms/' . $form->id() . '/feedback/schema' );
	$rules = json_decode( wp_remote_retrieve_body( $r ), true )['rules'];
	$required = array();
	foreach ( $rules as $rule ) {
		if ( 'required' === $rule['rule'] ) { $required[] = $rule['field']; }
		shared_expect( !( 'enum' === $rule['rule'] && 'review-doctor' === ( $rule['field'] ?? '' ) ), 'Browser schema permits changing doctor choices' );
	}
	shared_expect( in_array( 'clinic-id', $required, true ) && in_array( 'review-doctor', $required, true ), 'Both dropdowns remain required in browser schema' );
} finally { foreach ( array_reverse( $fixtures ) as $id ) { wp_delete_post( $id, true ); } }
