<?php

namespace Global360\Platform\Content;

final class PostTypes {
	/** @return array<string,array<string,mixed>> */
	public static function definitions(): array {
		return array(
			'clinic' => array(
				'labels' => array(
					'name' => 'Clinics', 'singular_name' => 'Clinic', 'add_new_item' => 'Add New Clinic',
					'edit_item' => 'Edit Clinic', 'new_item' => 'New Clinic', 'view_item' => 'View Clinic',
					'search_items' => 'Search Clinics', 'not_found' => 'No clinics found',
					'not_found_in_trash' => 'No clinics in trash', 'all_items' => 'All Clinics',
				),
				'public' => true, 'show_in_rest' => false, 'has_archive' => false,
				'rewrite' => array( 'slug' => 'clinics' ), 'supports' => array( 'title', 'thumbnail' ),
			),
			'doctor' => array(
				'labels' => array(
					'name' => 'Doctors', 'singular_name' => 'Doctor', 'add_new_item' => 'Add New Doctor',
					'edit_item' => 'Edit Doctor', 'new_item' => 'New Doctor', 'view_item' => 'View Doctor',
					'search_items' => 'Search Doctors', 'not_found' => 'No doctors found',
					'not_found_in_trash' => 'No doctors in trash', 'all_items' => 'All Doctors',
				),
				'public' => true, 'show_in_rest' => false, 'has_archive' => false,
				'rewrite' => array( 'slug' => 'doctors' ), 'supports' => array( 'title', 'thumbnail' ),
			),
		);
	}

	public static function register(): void {
		foreach ( self::definitions() as $post_type => $args ) {
			if ( ! post_type_exists( $post_type ) ) {
				register_post_type( $post_type, $args );
			}
		}
	}
}
