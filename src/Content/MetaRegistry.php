<?php

namespace Global360\Platform\Content;

final class MetaRegistry {
	public static function register(): void {
		register_post_meta( 'clinic', 'clinic_states', array( 'type' => 'array', 'single' => false, 'show_in_rest' => false ) );
		register_post_meta( 'doctor', 'clinic_id', array( 'type' => 'array', 'single' => false, 'show_in_rest' => false ) );
	}
}
