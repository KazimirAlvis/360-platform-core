<?php

namespace Global360\Platform\Support;

final class LegacyMetaAdapter {
	/** @param array<int,string> $keys @return mixed */
	public function first( int $post_id, array $keys, $default = '' ) {
		foreach ( $keys as $key ) {
			if ( metadata_exists( 'post', $post_id, $key ) ) {
				return get_post_meta( $post_id, $key, true );
			}
		}
		return $default;
	}

	/** @param mixed $value @return array<int,mixed> */
	public function id_list( $value ): array {
		$values = is_array( $value ) ? $value : ( '' === $value || null === $value ? array() : array( $value ) );
		return array_values( array_unique( array_filter( array_map( 'absint', $values ) ) ) );
	}

	/** @param mixed $value @return array<int,mixed> */
	public function list_value( $value ): array {
		return is_array( $value ) ? array_values( $value ) : ( '' === $value || null === $value ? array() : array( $value ) );
	}
}
