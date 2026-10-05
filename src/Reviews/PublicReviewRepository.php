<?php
namespace Global360\Platform\Reviews;

/** Approved-only public projection. Never select private submission columns here. */
final class PublicReviewRepository {
	/** Reusable by pages or future clinic/doctor components; no HTML or REST side effects. */
	public function query( array $args = array() ): array {
		global $wpdb;
		$page = max( 1, min( 1000000, (int) ( $args['page'] ?? 1 ) ) );
		$per_page = max( 1, min( 50, (int) ( $args['per_page'] ?? 9 ) ) );
		$table = PatientReviews::table();
		$from = "FROM $table r INNER JOIN {$wpdb->posts} c ON c.ID=r.clinic_id AND c.post_type='clinic' AND c.post_status='publish' AND c.post_password=''";
		$where = " WHERE r.status='approved'";
		foreach ( array( 'clinic_id', 'doctor_id' ) as $field ) {
			if ( isset( $args[$field] ) ) { $where .= $wpdb->prepare( " AND r.$field=%d", max( 0, (int) $args[$field] ) ); }
		}
		$total = (int) $wpdb->get_var( "SELECT COUNT(*) $from $where" );
		$pages = (int) ceil( $total / $per_page );
		$page = min( $page, max( 1, $pages ) );
		// A deleted/unpublished doctor must not leak a private post title.
		$join = " LEFT JOIN {$wpdb->posts} d ON d.ID=r.doctor_id AND d.post_type='doctor' AND d.post_status='publish' AND d.post_password=''";
		$sql = "SELECT r.id,r.clinic_id,r.doctor_id,r.display_name,r.rating,r.review_text,r.submitted_at,c.post_title AS clinic_name,d.post_title AS doctor_name $from $join $where ORDER BY r.submitted_at DESC,r.id DESC";
		$rows = $wpdb->get_results( $wpdb->prepare( $sql . ' LIMIT %d OFFSET %d', $per_page, ( $page - 1 ) * $per_page ), ARRAY_A );
		$items = array_map( static function ( $row ) {
			foreach ( array( 'id', 'clinic_id', 'doctor_id', 'rating' ) as $field ) { $row[$field] = (int) $row[$field]; }
			$row['clinic_name'] = (string) $row['clinic_name'];
			$row['doctor_name'] = (string) ( $row['doctor_name'] ?? '' );
			$row['clinic_url'] = self::public_permalink( $row['clinic_id'], 'clinic' );
			$row['doctor_url'] = self::public_permalink( $row['doctor_id'], 'doctor' );
			return $row;
		}, $rows ?: array() );
		return array( 'items' => $items, 'total' => $total, 'pages' => $pages, 'page' => $page, 'per_page' => $per_page );
	}

	/** Resolve the stored record, independent of clinic relationships or display names. */
	private static function public_permalink( int $id, string $type ): string {
		$post = $id ? get_post( $id ) : null;
		if ( ! $post || $post->post_type !== $type || $post->post_status !== 'publish' || $post->post_password || ! is_post_publicly_viewable( $post ) ) {
			return '';
		}
		return (string) ( get_permalink( $post ) ?: '' );
	}

	public static function boot(): void {
		add_action( 'template_redirect', static function () {
			if ( ! is_page( 'patient-reviews' ) ) { return; }
			if ( ! defined( 'DONOTCACHEPAGE' ) ) { define( 'DONOTCACHEPAGE', true ); }
			nocache_headers();
			if ( function_exists( 'wpfc_exclude_current_page' ) ) { wpfc_exclude_current_page(); }
		} );
		add_action( 'global360_patient_review_moderated', array( self::class, 'purge_page' ) );
	}

	public static function purge_page(): void {
		$page = get_page_by_path( 'patient-reviews' );
		if ( $page ) {
			clean_post_cache( $page->ID );
			// WP Fastest Cache removes desktop/mobile directories, including descendants.
			do_action( 'wpfc_clear_post_cache_by_id', false, $page->ID );
		}
	}
}
