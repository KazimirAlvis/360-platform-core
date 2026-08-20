<?php

namespace Global360\Platform\Data;

use Global360\Platform\Relationships\RelationshipService;
use Global360\Platform\Support\LegacyMetaAdapter;

final class DoctorRepository {
	/** @var LegacyMetaAdapter */ private $meta;
	/** @var RelationshipService */ private $relationships;
	/** @var LocationRepository */ private $locations;
	/** @var array<int,array<string,mixed>|null> */ private $cache = array();
	public function __construct( LegacyMetaAdapter $meta, RelationshipService $relationships, LocationRepository $locations ) { $this->meta = $meta; $this->relationships = $relationships; $this->locations = $locations; }

	/** @return array<string,mixed>|null */
	public function get( int $post_id ): ?array {
		if ( array_key_exists( $post_id, $this->cache ) ) { return $this->cache[ $post_id ]; }
		$post = get_post( $post_id );
		if ( ! $post instanceof \WP_Post || 'doctor' !== $post->post_type ) { $this->cache[ $post_id ] = null; return null; }
		$clinic_ids = $this->relationships->clinics_for_doctor( $post_id );
		$locations = array();
		foreach ( $clinic_ids as $clinic_id ) { foreach ( $this->locations->for_clinic( $clinic_id ) as $location ) { $location['clinic_id'] = $clinic_id; $locations[] = $location; } }
		$data = array(
			'wp_id' => $post_id, 'permalink' => get_permalink( $post_id ), 'status' => $post->post_status,
			'external_doctor_id' => sanitize_text_field( (string) $this->meta->first( $post_id, array( '_360_doctor_id', 'doctor_id' ) ) ),
			'external_slug' => sanitize_title( (string) $this->meta->first( $post_id, array( '_360_doctor_slug', 'doctor_slug' ), $post->post_name ) ),
			'name' => sanitize_text_field( (string) $this->meta->first( $post_id, array( 'doctor_name' ), $post->post_title ) ),
			'title' => sanitize_text_field( (string) $this->meta->first( $post_id, array( '_360_title', 'doctor_title' ) ) ),
			'bio' => (string) $this->meta->first( $post_id, array( 'doctor_bio' ), $post->post_content ),
			'photo_attachment_id' => absint( $this->meta->first( $post_id, array( '_doctor_photo_id', 'doctor_photo', '_360_doctor_photo_id' ), get_post_thumbnail_id( $post_id ) ) ),
			'clinic_ids' => $clinic_ids, 'locations' => $locations,
			'source_timestamp' => sanitize_text_field( (string) $this->meta->first( $post_id, array( '_360_updated_at', 'doctor_updated_at' ) ) ),
			'source' => metadata_exists( 'post', $post_id, '_360_doctor_id' ) || metadata_exists( 'post', $post_id, '_360_doctor_slug' ) ? 'api' : 'editorial',
			'is_temporary' => (bool) get_post_meta( $post_id, '_360_is_temporary', true ),
		);
		$this->cache[ $post_id ] = apply_filters( 'global360_doctor_view', $data, $post_id );
		return $this->cache[ $post_id ];
	}

	public function forget( int $post_id ): void { unset( $this->cache[ $post_id ] ); }
}
