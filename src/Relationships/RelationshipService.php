<?php

namespace Global360\Platform\Relationships;

use Global360\Platform\Support\LegacyMetaAdapter;

final class RelationshipService {
	/** @var LegacyMetaAdapter */
	private $meta;
	/** @var array<int,array<int,int>> */
	private $doctor_clinics = array();
	/** @var array<int,array<int,int>>|null */
	private $clinic_doctors;
	/** @var bool */
	private $index_built = false;

	public function __construct( LegacyMetaAdapter $meta ) { $this->meta = $meta; }

	/** @return array<int,int> */
	public function clinics_for_doctor( int $doctor_id ): array {
		if ( isset( $this->doctor_clinics[ $doctor_id ] ) ) { return $this->doctor_clinics[ $doctor_id ]; }
		$ids = $this->meta->id_list( $this->meta->first( $doctor_id, array( 'clinic_id' ), array() ) );
		foreach ( array( '_360_clinic_post_id', 'clinic_post_id' ) as $key ) {
			$value = absint( get_post_meta( $doctor_id, $key, true ) );
			if ( $value ) { $ids[] = $value; }
		}
		$ids = array_values( array_unique( array_filter( $ids, function ( $id ) { return 'clinic' === get_post_type( $id ); } ) ) );
		$this->doctor_clinics[ $doctor_id ] = $ids;
		return $ids;
	}

	/** @return array<int,int> */
	public function doctors_for_clinic( int $clinic_id ): array {
		$this->build_index();
		return $this->clinic_doctors[ $clinic_id ] ?? array();
	}

	/** Build both relationship directions once with post and meta caches primed. */
	private function build_index(): void {
		if ( $this->index_built ) { return; }

		$this->index_built    = true;
		$this->clinic_doctors = array();
		$doctors = get_posts(
			array(
				'post_type'              => 'doctor',
				'post_status'            => array( 'publish', 'draft', 'pending', 'private' ),
				'posts_per_page'         => -1,
				'no_found_rows'          => true,
				'update_post_meta_cache' => true,
				'update_post_term_cache' => false,
			)
		);

		$clinic_ids = array();
		foreach ( $doctors as $doctor ) {
			$doctor_id = (int) $doctor->ID;
			$ids = $this->meta->id_list( $this->meta->first( $doctor_id, array( 'clinic_id' ), array() ) );
			foreach ( array( '_360_clinic_post_id', 'clinic_post_id' ) as $key ) {
				$value = absint( get_post_meta( $doctor_id, $key, true ) );
				if ( $value ) { $ids[] = $value; }
			}
			$ids = array_values( array_unique( array_filter( $ids ) ) );
			$this->doctor_clinics[ $doctor_id ] = $ids;
			$clinic_ids = array_merge( $clinic_ids, $ids );
		}

		$clinic_ids = array_values( array_unique( array_map( 'absint', $clinic_ids ) ) );
		$valid_clinics = empty( $clinic_ids ) ? array() : get_posts(
			array(
				'post_type'              => 'clinic',
				'post_status'            => array( 'publish', 'draft', 'pending', 'private' ),
				'posts_per_page'         => -1,
				'post__in'               => $clinic_ids,
				'no_found_rows'          => true,
				'update_post_meta_cache' => true,
				'update_post_term_cache' => false,
			)
		);
		$valid_ids = array_fill_keys( array_map( static function ( $clinic ) { return (int) $clinic->ID; }, $valid_clinics ), true );

		foreach ( $this->doctor_clinics as $doctor_id => $ids ) {
			$ids = array_values( array_filter( $ids, static function ( $id ) use ( $valid_ids ) { return isset( $valid_ids[ $id ] ); } ) );
			$this->doctor_clinics[ $doctor_id ] = $ids;
			foreach ( $ids as $clinic_id ) { $this->clinic_doctors[ $clinic_id ][] = $doctor_id; }
		}
	}

	public function forget_index(): void {
		$this->doctor_clinics = array();
		$this->clinic_doctors = null;
		$this->index_built = false;
	}

	/**
	 * Persist the current compatibility relationship keys through one contract.
	 *
	 * @param array<int,int> $clinic_ids
	 */
	public function set_clinics_for_doctor( int $doctor_id, array $clinic_ids ): void {
		$clinic_ids = array_values( array_unique( array_filter( array_map( 'absint', $clinic_ids ) ) ) );
		if ( empty( $clinic_ids ) ) {
			delete_post_meta( $doctor_id, 'clinic_id' );
			delete_post_meta( $doctor_id, '_360_clinic_post_id' );
			delete_post_meta( $doctor_id, 'clinic_post_id' );
		} else {
			update_post_meta( $doctor_id, 'clinic_id', $clinic_ids );
			update_post_meta( $doctor_id, '_360_clinic_post_id', $clinic_ids[0] );
			update_post_meta( $doctor_id, 'clinic_post_id', $clinic_ids[0] );
		}
		$this->forget_index();
		$this->doctor_clinics[ $doctor_id ] = $clinic_ids;
	}
}
