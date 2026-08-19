<?php

namespace Global360\Platform\Geography;

final class StateRegistry {
	/** @return array<string,string> */
	public function all(): array {
		return array(
			'AL'=>'Alabama','AK'=>'Alaska','AZ'=>'Arizona','AR'=>'Arkansas','CA'=>'California','CO'=>'Colorado','CT'=>'Connecticut','DE'=>'Delaware','DC'=>'District of Columbia',
			'FL'=>'Florida','GA'=>'Georgia','HI'=>'Hawaii','ID'=>'Idaho','IL'=>'Illinois','IN'=>'Indiana','IA'=>'Iowa','KS'=>'Kansas','KY'=>'Kentucky','LA'=>'Louisiana',
			'ME'=>'Maine','MD'=>'Maryland','MA'=>'Massachusetts','MI'=>'Michigan','MN'=>'Minnesota','MS'=>'Mississippi','MO'=>'Missouri','MT'=>'Montana','NE'=>'Nebraska',
			'NV'=>'Nevada','NH'=>'New Hampshire','NJ'=>'New Jersey','NM'=>'New Mexico','NY'=>'New York','NC'=>'North Carolina','ND'=>'North Dakota','OH'=>'Ohio','OK'=>'Oklahoma',
			'OR'=>'Oregon','PA'=>'Pennsylvania','RI'=>'Rhode Island','SC'=>'South Carolina','SD'=>'South Dakota','TN'=>'Tennessee','TX'=>'Texas','UT'=>'Utah','VT'=>'Vermont',
			'VA'=>'Virginia','WA'=>'Washington','WV'=>'West Virginia','WI'=>'Wisconsin','WY'=>'Wyoming',
		);
	}

	/** @return array<string,string> */
	public function slug_map(): array {
		$map = array();
		foreach ( $this->all() as $code => $name ) {
			$map[ sanitize_title( $name ) ] = $code;
		}
		$map['washington-dc'] = 'DC';
		return $map;
	}

	public function normalize( string $value ): string {
		$value = trim( $value );
		if ( '' === $value ) { return ''; }
		$upper = strtoupper( str_replace( '.', '', $value ) );
		if ( isset( $this->all()[ $upper ] ) ) { return $upper; }
		$name_map = array_change_key_case( array_flip( $this->all() ), CASE_LOWER );
		$lower = strtolower( $value );
		if ( isset( $name_map[ $lower ] ) ) { return $name_map[ $lower ]; }
		return $this->from_slug( sanitize_title( $value ) );
	}

	public function from_slug( string $slug ): string {
		return (string) ( $this->slug_map()[ sanitize_title( $slug ) ] ?? '' );
	}

	public function name( string $value ): string {
		$code = $this->normalize( $value );
		return (string) ( $this->all()[ $code ] ?? '' );
	}

	public function is_valid( string $value ): bool {
		return '' !== $this->normalize( $value );
	}
}
