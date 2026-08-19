<?php

namespace Global360\Platform\Context;

final class SiteContext {
	public const OPTION_KEY = '360_global_settings';
	/** @var array<string,mixed>|null */ private $settings;

	/** @return array<string,mixed> */
	public function all(): array {
		if ( null === $this->settings ) {
			$value = get_option( self::OPTION_KEY, array() );
			$this->settings = is_array( $value ) ? $value : array();
		}
		return $this->settings;
	}

	/** @return mixed */
	public function get( string $key, $default = null ) { return $this->all()[ $key ] ?? $default; }
	public function site_name(): string { return sanitize_text_field( (string) $this->get( 'site_name', get_bloginfo( 'name' ) ) ); }
	public function primary_condition(): string { return sanitize_text_field( (string) $this->get( 'primary_condition', '' ) ); }
	public function primary_treatment(): string { return sanitize_text_field( (string) $this->get( 'primary_treatment', '' ) ); }
	/** @return array<string,string> */
	public function brand_colors(): array { return array( 'primary' => (string) $this->get( 'primary_color', '' ), 'secondary' => (string) $this->get( 'secondary_color', '' ) ); }
	/** @return array<string,mixed> */
	public function assessment(): array { return array( 'id' => $this->get( 'assessment_id', '' ), 'button_text' => $this->get( 'assessment_button_text', '' ) ); }
	/** @return array<string,mixed> */
	public function contact(): array { return array( 'email' => $this->get( 'contact_email', '' ), 'phone' => $this->get( 'contact_phone', '' ), 'email_label' => $this->get( 'contact_email_label', '' ) ); }
	/** @return array<string,mixed> */
	public function schema(): array { return array( 'medical_specialty' => $this->get( 'medical_specialty', '' ), 'primary_condition' => $this->primary_condition(), 'related_conditions' => $this->get( 'related_conditions', '' ), 'primary_treatment' => $this->primary_treatment(), 'related_treatments' => $this->get( 'related_treatments', '' ), 'social_links' => $this->get( 'social_links', array() ) ); }
}
