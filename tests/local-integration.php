<?php

$wp_load = dirname( __DIR__, 4 ) . '/wp-load.php';
if ( ! file_exists( $wp_load ) ) { fwrite( STDERR, "WordPress bootstrap not found.\n" ); exit( 1 ); }
require_once $wp_load;
if ( ! function_exists( 'global360_platform' ) ) { require_once dirname( __DIR__ ) . '/360-platform-core.php'; }

function platform_expect( $condition, $message ) {
	if ( ! $condition ) { fwrite( STDERR, "FAIL: $message\n" ); exit( 1 ); }
	echo "PASS: $message\n";
}

\Global360\Platform\Content\PostTypes::register();
$clinic_type = get_post_type_object( 'clinic' );
$doctor_type = get_post_type_object( 'doctor' );
platform_expect( $clinic_type && $doctor_type, 'Core registers Clinic and Doctor CPTs' );
platform_expect( 'clinics' === $clinic_type->rewrite['slug'] && 'doctors' === $doctor_type->rewrite['slug'], 'rewrite slugs remain stable' );
platform_expect( false === $clinic_type->has_archive && false === $doctor_type->has_archive, 'archive behavior remains stable' );
platform_expect( false === $clinic_type->show_in_rest && false === $doctor_type->show_in_rest, 'REST/editor behavior remains deferred' );
$clinic_before = $clinic_type;
\Global360\Platform\Content\PostTypes::register();
platform_expect( $clinic_before === get_post_type_object( 'clinic' ), 'repeated registration does not replace an existing CPT' );

$clinic_id = wp_insert_post( array( 'post_type'=>'clinic', 'post_status'=>'publish', 'post_title'=>'Platform Core Test Clinic', 'post_name'=>'platform-core-test-clinic' ), true );
$doctor_id = wp_insert_post( array( 'post_type'=>'doctor', 'post_status'=>'publish', 'post_title'=>'Platform Core Test Doctor', 'post_name'=>'platform-core-test-doctor' ), true );
platform_expect( ! is_wp_error( $clinic_id ) && ! is_wp_error( $doctor_id ), 'test Clinic and Doctor created without migration' );

try {
	update_post_meta( $clinic_id, 'organization_id', 'legacy-org' );
	update_post_meta( $clinic_id, '_cpt360_clinic_phone', '555-0100' );
	update_post_meta( $clinic_id, 'clinic_addresses', array( array( 'street'=>'1 Main St', 'city'=>'Washington', 'state'=>'DC', 'zip'=>'20001', 'lat'=>'38.9', 'lng'=>'-77.0' ) ) );
	update_post_meta( $clinic_id, 'clinic_states', array( 'District of Columbia' ) );
	update_post_meta( $doctor_id, 'doctor_id', 'legacy-doctor' );
	update_post_meta( $doctor_id, 'doctor_name', 'Platform Core Test Doctor' );
	global360_platform()->relationships()->set_clinics_for_doctor( $doctor_id, array( $clinic_id ) );

	$clinic = global360_platform()->clinics()->get( $clinic_id );
	$doctor = global360_platform()->doctors()->get( $doctor_id );
	platform_expect( 'legacy-org' === $clinic['external_organization_id'] && '555-0100' === $clinic['phone'], 'Clinic normalized view reads legacy aliases' );
	platform_expect( array( 'DC' ) === $clinic['state_codes'], 'Clinic state normalization includes DC' );
	platform_expect( in_array( $doctor_id, $clinic['doctor_ids'], true ), 'Clinic to Doctors relationship resolves' );
	platform_expect( array( $clinic_id ) === $doctor['clinic_ids'], 'Doctor to Clinics relationship resolves' );
	platform_expect( 'DC' === $doctor['locations'][0]['state'], 'Doctor locations derive from linked Clinic' );
	platform_expect( false === array_key_exists( '_360_phone', $clinic ), 'normalized Clinic contract hides aliases' );
	platform_expect( global360_platform()->site_context()->all() === (array) get_option( '360_global_settings', array() ), 'SiteContext wraps existing option without migration' );
	platform_expect( 'api_managed' === global360_platform()->ownership()->for_field( 'doctor', 'clinic_ids' ), 'field ownership lookup works' );
	platform_expect( false !== strpos( get_permalink( $clinic_id ), '/clinics/platform-core-test-clinic' ), 'Clinic permalink remains stable' );
	platform_expect( false !== strpos( get_permalink( $doctor_id ), '/doctors/platform-core-test-doctor' ), 'Doctor permalink remains stable' );

	$existing_clinic = get_posts( array( 'post_type'=>'clinic', 'post_status'=>'any', 'posts_per_page'=>1, 'fields'=>'ids', 'exclude'=>array( $clinic_id ) ) );
	if ( $existing_clinic ) { platform_expect( null !== global360_platform()->clinics()->get( (int) $existing_clinic[0] ), 'existing Clinic retrieval works' ); }
	$existing_doctor = get_posts( array( 'post_type'=>'doctor', 'post_status'=>'any', 'posts_per_page'=>1, 'fields'=>'ids', 'exclude'=>array( $doctor_id ) ) );
	if ( $existing_doctor ) { platform_expect( null !== global360_platform()->doctors()->get( (int) $existing_doctor[0] ), 'existing Doctor retrieval works' ); }
} finally {
	wp_delete_post( (int) $doctor_id, true );
	wp_delete_post( (int) $clinic_id, true );
}

echo "Platform Core local integration tests passed and test records were removed.\n";
