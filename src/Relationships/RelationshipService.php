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
		if ( null === $this->clinic_doctors ) {
			$this->clinic_doctors = array();
			$doctor_ids = get_posts( array( 'post_type' => 'doctor', 'post_status' => array( 'publish', 'draft', 'pending', 'private' ), 'posts_per_page' => -1, 'fields' => 'ids', 'no_found_rows' => true ) );
			foreach ( array_map( 'absint', $doctor_ids ) as $doctor_id ) {
				foreach ( $this->clinics_for_doctor( $doctor_id ) as $linked_clinic_id ) {
					$this->clinic_doctors[ $linked_clinic_id ][] = $doctor_id;
				}
			}
		}
		return $this->clinic_doctors[ $clinic_id ] ?? array();
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
		$this->doctor_clinics[ $doctor_id ] = $clinic_ids;
		$this->clinic_doctors = null;
	}
}
