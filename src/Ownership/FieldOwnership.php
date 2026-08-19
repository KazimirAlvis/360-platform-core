<?php

namespace Global360\Platform\Ownership;

final class FieldOwnership {
	public const API_MANAGED = 'api_managed';
	public const EDITORIAL = 'editorial';
	public const DERIVED = 'derived';
	public const AI_OWNED = 'ai_owned';

	/** @return array<string,array<string,string>> */
	public function all(): array {
		return array(
			'clinic' => array(
				'external_organization_id'=>self::API_MANAGED,'name'=>self::API_MANAGED,'bio'=>self::API_MANAGED,'phone'=>self::API_MANAGED,'website'=>self::API_MANAGED,
				'assessment_id'=>self::API_MANAGED,'addresses'=>self::API_MANAGED,'state_codes'=>self::DERIVED,'city'=>self::DERIVED,'coordinates'=>self::DERIVED,
				'logo_attachment_id'=>self::API_MANAGED,'clinic_info'=>self::API_MANAGED,'reviews'=>self::API_MANAGED,'doctor_ids'=>self::API_MANAGED,
				'source_timestamp'=>self::API_MANAGED,'editor_notes'=>self::EDITORIAL,'ai_extensions'=>self::AI_OWNED,
			),
			'doctor' => array(
				'external_doctor_id'=>self::API_MANAGED,'external_slug'=>self::API_MANAGED,'name'=>self::API_MANAGED,'title'=>self::API_MANAGED,'bio'=>self::API_MANAGED,
				'photo_attachment_id'=>self::API_MANAGED,'clinic_ids'=>self::API_MANAGED,'locations'=>self::DERIVED,'source_timestamp'=>self::API_MANAGED,
				'editor_notes'=>self::EDITORIAL,'ai_extensions'=>self::AI_OWNED,
			),
		);
	}

	public function for_field( string $entity_type, string $field ): string { return (string) ( $this->all()[ $entity_type ][ $field ] ?? '' ); }
	/** @return array<string,string> */
	public function for_entity( string $entity_type ): array { return $this->all()[ $entity_type ] ?? array(); }
}
