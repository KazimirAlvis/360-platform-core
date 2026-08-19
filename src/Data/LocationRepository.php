<?php

namespace Global360\Platform\Data;

use Global360\Platform\Geography\StateRegistry;
use Global360\Platform\Support\LegacyMetaAdapter;

final class LocationRepository {
	/** @var LegacyMetaAdapter */ private $meta;
	/** @var StateRegistry */ private $states;
	public function __construct( LegacyMetaAdapter $meta, StateRegistry $states ) { $this->meta = $meta; $this->states = $states; }

	/** @return array<int,array<string,mixed>> */
	public function for_clinic( int $clinic_id ): array {
		$raw = $this->meta->first( $clinic_id, array( 'clinic_addresses', '_360_addresses' ), array() );
		$rows = is_array( $raw ) ? $raw : ( '' !== trim( (string) $raw ) ? array( array( 'full_address' => (string) $raw ) ) : array() );
		$locations = array();
		foreach ( $rows as $row ) {
			if ( is_string( $row ) ) { $row = array( 'full_address' => $row ); }
			if ( ! is_array( $row ) ) { continue; }
			$state = $this->states->normalize( (string) ( $row['state'] ?? $row['state_code'] ?? '' ) );
			$locations[] = array(
				'street' => sanitize_text_field( (string) ( $row['street'] ?? $row['address'] ?? '' ) ),
				'line2' => sanitize_text_field( (string) ( $row['line2'] ?? '' ) ),
				'city' => sanitize_text_field( (string) ( $row['city'] ?? '' ) ),
				'state' => $state,
				'state_name' => $this->states->name( $state ),
				'zip' => sanitize_text_field( (string) ( $row['zip'] ?? $row['postal_code'] ?? '' ) ),
				'latitude' => (string) ( $row['lat'] ?? $row['latitude'] ?? '' ),
				'longitude' => (string) ( $row['lng'] ?? $row['longitude'] ?? '' ),
				'full_address' => sanitize_text_field( (string) ( $row['full_address'] ?? $row['formatted_address'] ?? '' ) ),
			);
		}
		if ( empty( $locations ) ) {
			$state = $this->states->normalize( (string) $this->meta->first( $clinic_id, array( 'clinic_state', '_cpt360_clinic_state', 'state' ) ) );
			$locations[] = array(
				'street' => '', 'line2' => '', 'city' => sanitize_text_field( (string) $this->meta->first( $clinic_id, array( 'clinic_city', '_cpt360_clinic_city', 'city' ) ) ),
				'state' => $state, 'state_name' => $this->states->name( $state ), 'zip' => sanitize_text_field( (string) $this->meta->first( $clinic_id, array( 'clinic_zip', '_cpt360_clinic_zip', 'zip' ) ) ),
				'latitude' => (string) $this->meta->first( $clinic_id, array( 'clinic_lat', 'clinic_latitude', '_cpt360_clinic_lat', 'lat', 'latitude' ) ),
				'longitude' => (string) $this->meta->first( $clinic_id, array( 'clinic_lng', 'clinic_longitude', '_cpt360_clinic_lng', 'lng', 'longitude' ) ),
				'full_address' => sanitize_text_field( (string) $this->meta->first( $clinic_id, array( 'clinic_address' ) ) ),
			);
		}
		return array_values( array_filter( $locations, function ( $location ) { return (bool) array_filter( $location ); } ) );
	}
}
