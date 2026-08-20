<?php

namespace Global360\Platform\Data;

use Global360\Platform\Geography\StateRegistry;
use Global360\Platform\Relationships\RelationshipService;
use Global360\Platform\Support\LegacyMetaAdapter;

final class ClinicRepository {
	/** @var LegacyMetaAdapter */ private $meta;
	/** @var RelationshipService */ private $relationships;
	/** @var LocationRepository */ private $locations;
	/** @var StateRegistry */ private $states;
	/** @var array<int,array<string,mixed>|null> */ private $cache = array();
	public function __construct( LegacyMetaAdapter $meta, RelationshipService $relationships, LocationRepository $locations, StateRegistry $states ) { $this->meta = $meta; $this->relationships = $relationships; $this->locations = $locations; $this->states = $states; }

	/** @return array<string,mixed>|null */
	public function get( int $post_id ): ?array {
		if ( array_key_exists( $post_id, $this->cache ) ) { return $this->cache[ $post_id ]; }
		$post = get_post( $post_id );
		if ( ! $post instanceof \WP_Post || 'clinic' !== $post->post_type ) { $this->cache[ $post_id ] = null; return null; }
		$addresses = $this->locations->for_clinic( $post_id );
		$state_codes = array();
		foreach ( $this->meta->list_value( $this->meta->first( $post_id, array( 'clinic_states', '_360_states' ), array() ) ) as $state ) {
			$normalized = $this->states->normalize( (string) $state ); if ( $normalized ) { $state_codes[] = $normalized; }
		}
		foreach ( $addresses as $address ) { if ( ! empty( $address['state'] ) ) { $state_codes[] = $address['state']; } }
		$data = array(
			'wp_id' => $post_id, 'permalink' => get_permalink( $post_id ), 'status' => $post->post_status,
			'external_organization_id' => sanitize_text_field( (string) $this->meta->first( $post_id, array( '_360_organization_id', 'organization_id', 'clinic_organization_id' ) ) ),
			'name' => (string) $post->post_title,
			'bio' => (string) $this->meta->first( $post_id, array( 'clinic_bio', '_cpt360_clinic_bio' ), $post->post_content ),
			'phone' => sanitize_text_field( (string) $this->meta->first( $post_id, array( '_360_phone', 'clinic_phone', '_cpt360_clinic_phone' ) ) ),
			'website' => esc_url_raw( (string) $this->meta->first( $post_id, array( '_360_website_url', 'clinic_website', '_clinic_website_url' ) ) ),
			'assessment_id' => sanitize_text_field( (string) $this->meta->first( $post_id, array( '_360_assessment_id', 'clinic_assessment_id', '_cpt360_assessment_id' ) ) ),
			'addresses' => $addresses, 'city' => (string) ( $addresses[0]['city'] ?? '' ),
			'state_codes' => array_values( array_unique( $state_codes ) ),
			'coordinates' => array( 'latitude' => (string) ( $addresses[0]['latitude'] ?? '' ), 'longitude' => (string) ( $addresses[0]['longitude'] ?? '' ) ),
			'logo_attachment_id' => absint( $this->meta->first( $post_id, array( '_clinic_logo_id', 'clinic_logo', '_360_clinic_logo_id' ), get_post_thumbnail_id( $post_id ) ) ),
			'clinic_info' => $this->meta->list_value( $this->meta->first( $post_id, array( 'clinic_info', '_360_clinic_info' ), array() ) ),
			'reviews' => $this->meta->list_value( $this->meta->first( $post_id, array( 'clinic_reviews', '_360_reviews' ), array() ) ),
			'doctor_ids' => $this->relationships->doctors_for_clinic( $post_id ),
			'source_timestamp' => sanitize_text_field( (string) $this->meta->first( $post_id, array( '_360_updated_at', 'clinic_updated_at' ) ) ),
			'source' => metadata_exists( 'post', $post_id, '_360_organization_id' ) ? 'api' : 'editorial',
			'is_temporary' => (bool) get_post_meta( $post_id, '_360_is_temporary', true ),
		);
		$this->cache[ $post_id ] = apply_filters( 'global360_clinic_view', $data, $post_id );
		return $this->cache[ $post_id ];
	}

	public function forget( int $post_id ): void { unset( $this->cache[ $post_id ] ); }
}
