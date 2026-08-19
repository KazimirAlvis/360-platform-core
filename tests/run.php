<?php

// Focused pure-contract tests. WordPress integration assertions live in local-integration.php.
define( 'ABSPATH', __DIR__ . '/' );
function sanitize_title( $value ) { return trim( preg_replace( '/[^a-z0-9]+/', '-', strtolower( $value ) ), '-' ); }
require dirname( __DIR__ ) . '/src/Geography/StateRegistry.php';
require dirname( __DIR__ ) . '/src/Ownership/FieldOwnership.php';

function core_expect( $condition, $message ) { if ( ! $condition ) { fwrite( STDERR, "FAIL: $message\n" ); exit( 1 ); } }
$states = new \Global360\Platform\Geography\StateRegistry();
core_expect( 'CA' === $states->normalize( 'California' ), 'full state name normalizes' );
core_expect( 'DC' === $states->normalize( 'District of Columbia' ), 'DC name normalizes' );
core_expect( 'DC' === $states->from_slug( 'washington-dc' ), 'Washington DC slug normalizes' );
core_expect( ! $states->is_valid( 'Atlantis' ), 'invalid state rejected' );
$ownership = new \Global360\Platform\Ownership\FieldOwnership();
core_expect( 'api_managed' === $ownership->for_field( 'clinic', 'phone' ), 'clinic phone ownership' );
core_expect( 'derived' === $ownership->for_field( 'doctor', 'locations' ), 'doctor locations ownership' );
core_expect( 'ai_owned' === $ownership->for_field( 'doctor', 'ai_extensions' ), 'AI namespace reserved' );
echo "Platform Core focused tests passed.\n";
