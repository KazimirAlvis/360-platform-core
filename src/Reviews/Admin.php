<?php
namespace Global360\Platform\Reviews;

final class Admin {
	public static function boot(): void {
		add_action( 'admin_menu', static function () {
			add_menu_page( 'Patient Reviews', 'Patient Reviews', PatientReviews::CAPABILITY, 'global360-patient-reviews', array( self::class, 'render' ), 'dashicons-star-filled', 26 );
		} );
		add_action( 'admin_post_global360_moderate_review', array( self::class, 'handle' ) );
	}

	public static function handle(): void {
		if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) { wp_die( 'POST required.', '', array( 'response' => 405 ) ); }
		$id = isset( $_POST['review_id'] ) && is_scalar( $_POST['review_id'] ) ? absint( $_POST['review_id'] ) : 0;
		$action = isset( $_POST['moderation'] ) && is_string( $_POST['moderation'] ) ? sanitize_key( $_POST['moderation'] ) : '';
		$nonce = isset( $_POST['_wpnonce'] ) && is_string( $_POST['_wpnonce'] ) ? wp_unslash( $_POST['_wpnonce'] ) : '';
		$result = in_array( $action, array( 'trash', 'restore', 'delete' ), true )
			? PatientReviews::trash_action( $id, $action, $nonce, isset( $_POST['confirm_delete'] ) && '1' === $_POST['confirm_delete'] )
			: PatientReviews::moderate( $id, $action, $nonce );
		if ( is_wp_error( $result ) ) { wp_die( esc_html( $result->get_error_message() ), '', array( 'response' => 403 ) ); }
		wp_safe_redirect( admin_url( 'delete' === $action ? 'admin.php?page=global360-patient-reviews&view=trash' : 'admin.php?page=global360-patient-reviews&review_id=' . $id . '&updated=1' ) );
		exit;
	}

	private static function trash_controls( $row ): void {
		$id = (int) $row->id;
		$action = 'trash' === $row->status ? 'restore' : 'trash';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="global360_moderate_review"><input type="hidden" name="review_id" value="' . $id . '">';
		wp_nonce_field( 'global360_review_' . $action . '_' . $id );
		echo '<button class="button-link" name="moderation" value="' . esc_attr( $action ) . '">' . ( 'restore' === $action ? 'Restore' : 'Move to Trash' ) . '</button></form>';
		if ( 'trash' === $row->status ) {
			echo '<a href="' . esc_url( admin_url( 'admin.php?page=global360-patient-reviews&review_id=' . $id . '&confirm_delete=1' ) ) . '">Delete Permanently</a>';
		}
	}

	private static function entity( int $id, string $fallback ): string {
		return $id ? ( get_the_title( $id ) ?: 'Deleted record' ) . ' (#' . $id . ')' : $fallback;
	}

	public static function render(): void {
		if ( ! current_user_can( PatientReviews::CAPABILITY ) ) { wp_die( 'You cannot view patient reviews.', '', array( 'response' => 403 ) ); }
		nocache_headers();
		echo '<div class="wrap"><h1>Patient Reviews</h1><p>Approved reviews appear on the Patient Reviews page. Pending, rejected, hidden, and trashed reviews remain private.</p>';
		$id = isset( $_GET['review_id'] ) && is_scalar( $_GET['review_id'] ) ? absint( $_GET['review_id'] ) : 0;
		if ( $id ) {
			$row = PatientReviews::get( $id );
			if ( ! $row ) { echo '<p>Review not found.</p></div>'; return; }
			echo '<p><a href="' . esc_url( admin_url( 'admin.php?page=global360-patient-reviews' ) ) . '">Back to reviews</a></p>';
			if ( isset( $_GET['updated'] ) ) { echo '<div class="notice notice-success"><p>Moderation saved.</p></div>'; }
			if ( 'trash' === $row->status && isset( $_GET['confirm_delete'] ) ) {
				echo '<h2>Delete review permanently?</h2><p>This permanently removes the review and its private submission data. This cannot be undone.</p><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="global360_moderate_review"><input type="hidden" name="review_id" value="' . (int) $id . '">';
				wp_nonce_field( 'global360_review_delete_' . $id );
				echo '<p><label><input type="checkbox" name="confirm_delete" value="1" required> I confirm permanent deletion of this review.</label></p><button class="button" name="moderation" value="delete">Delete Permanently</button> <a href="' . esc_url( admin_url( 'admin.php?page=global360-patient-reviews&review_id=' . $id ) ) . '">Cancel</a></form>';
			}
			$moderator = $row->moderator_id ? get_userdata( $row->moderator_id ) : null;
			$fields = array(
				'Clinic' => self::entity( (int) $row->clinic_id, '' ),
				'Doctor' => self::entity( (int) $row->doctor_id, 'Clinic overall' ),
				'Rating (read-only)' => $row->rating . ' / 5',
				'Display name' => $row->display_name,
				'Private email' => $row->email,
				'Submitted (UTC)' => $row->submitted_at,
				'Status' => ucfirst( $row->status ),
				'Notification' => ucfirst( $row->notification_status ),
				'Publishing consent' => $row->consent ? 'Yes — ' . $row->consent_text : 'No',
				'Last moderated (UTC)' => $row->moderated_at ?: 'Not moderated',
				'Moderator' => $row->moderator_id ? ( $moderator ? $moderator->display_name : 'Deleted user' ) . ' (#' . $row->moderator_id . ')' : 'None',
			);
			echo '<table class="widefat striped"><tbody>';
			foreach ( $fields as $label => $value ) { echo '<tr><th scope="row">' . esc_html( $label ) . '</th><td>' . esc_html( $value ) . '</td></tr>'; }
			echo '</tbody></table><h2>Submitted review (read-only)</h2><div class="card">' . nl2br( esc_html( $row->review_text ) ) . '</div>';
			if ( 'trash' !== $row->status ) {
				echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="global360_moderate_review"><input type="hidden" name="review_id" value="' . (int) $id . '">';
				wp_nonce_field( 'global360_review_' . $id );
				foreach ( array( 'approve' => 'Approve', 'reject' => 'Reject', 'hide' => 'Hide' ) as $action => $label ) {
					echo '<button class="button" type="submit" name="moderation" value="' . esc_attr( $action ) . '">' . esc_html( $label ) . '</button> ';
				}
				echo '</form>';
			}
			self::trash_controls( $row );
			echo '</div>';
			return;
		}
		global $wpdb;
		$page = isset( $_GET['paged'] ) && is_scalar( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1;
		$table = PatientReviews::table();
		$trash = isset( $_GET['view'] ) && 'trash' === $_GET['view'];
		$where = $trash ? " WHERE status='trash'" : " WHERE status<>'trash'";
		echo '<p><a href="' . esc_url( admin_url( 'admin.php?page=global360-patient-reviews' ) ) . '">All active reviews</a> | <a href="' . esc_url( admin_url( 'admin.php?page=global360-patient-reviews&view=trash' ) ) . '">Trash</a></p><h2>' . ( $trash ? 'Trash' : 'All active reviews' ) . '</h2>';
		$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $table$where" );
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT id,clinic_id,doctor_id,rating,display_name,submitted_at,status FROM $table$where ORDER BY submitted_at DESC,id DESC LIMIT 30 OFFSET %d", ( $page - 1 ) * 30 ) );
		echo '<table class="widefat striped"><thead><tr><th>Clinic</th><th>Doctor</th><th>Rating</th><th>Display name</th><th>Submitted (UTC)</th><th>Status</th><th>Review</th></tr></thead><tbody>';
		foreach ( $rows as $row ) {
			echo '<tr>';
			foreach ( array( self::entity( (int) $row->clinic_id, '' ), self::entity( (int) $row->doctor_id, 'Clinic overall' ), $row->rating . ' / 5', $row->display_name, $row->submitted_at, ucfirst( $row->status ) ) as $value ) { echo '<td>' . esc_html( $value ) . '</td>'; }
			echo '<td><a href="' . esc_url( admin_url( 'admin.php?page=global360-patient-reviews&review_id=' . (int) $row->id ) ) . '">View / Moderate</a>';
			self::trash_controls( $row );
			echo '</td></tr>';
		}
		if ( ! $rows ) { echo '<tr><td colspan="7">No patient reviews yet.</td></tr>'; }
		echo '</tbody></table>';
		$pagination = paginate_links( array( 'base' => admin_url( 'admin.php?page=global360-patient-reviews' . ( $trash ? '&view=trash' : '' ) . '&paged=%#%' ), 'total' => max( 1, (int) ceil( $total / 30 ) ), 'current' => $page ) );
		// paginate_links() returns null when there is only one page.
		echo wp_kses_post( $pagination ?? '' );
		echo '</div>';
	}
}
