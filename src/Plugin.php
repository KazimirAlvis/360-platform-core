<?php

namespace Global360\Platform;

use Global360\Platform\Content\MetaRegistry;
use Global360\Platform\Content\PostTypes;
use Global360\Platform\Context\SiteContext;
use Global360\Platform\Data\ClinicRepository;
use Global360\Platform\Data\DoctorRepository;
use Global360\Platform\Data\LocationRepository;
use Global360\Platform\Geography\StateRegistry;
use Global360\Platform\Ownership\FieldOwnership;
use Global360\Platform\Relationships\RelationshipService;
use Global360\Platform\Support\LegacyMetaAdapter;

final class Plugin {
	/** @var self|null */
	private static $instance;
	/** @var array<string,object> */
	private $services = array();
	/** @var bool */
	private $booted = false;

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public function boot(): void {
		if ( $this->booted ) {
			return;
		}
		$this->booted = true;
		add_action( 'init', array( PostTypes::class, 'register' ), 5 );
		add_action( 'init', array( MetaRegistry::class, 'register' ), 6 );
	}

	public static function activate(): void {
		PostTypes::register();
		MetaRegistry::register();
		update_option( 'global360_platform_core_version', GLOBAL360_PLATFORM_VERSION, false );
		flush_rewrite_rules();
	}

	public static function deactivate(): void {
		flush_rewrite_rules();
	}

	public function clinics(): ClinicRepository {
		return $this->service( 'clinics', function () { return new ClinicRepository( $this->legacy_meta(), $this->relationships(), $this->locations(), $this->states() ); } );
	}

	public function doctors(): DoctorRepository {
		return $this->service( 'doctors', function () { return new DoctorRepository( $this->legacy_meta(), $this->relationships(), $this->locations() ); } );
	}

	public function locations(): LocationRepository {
		return $this->service( 'locations', function () { return new LocationRepository( $this->legacy_meta(), $this->states() ); } );
	}

	public function states(): StateRegistry {
		return $this->service( 'states', function () { return new StateRegistry(); } );
	}

	public function relationships(): RelationshipService {
		return $this->service( 'relationships', function () { return new RelationshipService( $this->legacy_meta() ); } );
	}

	public function site_context(): SiteContext {
		return $this->service( 'site_context', function () { return new SiteContext(); } );
	}

	public function ownership(): FieldOwnership {
		return $this->service( 'ownership', function () { return new FieldOwnership(); } );
	}

	public function legacy_meta(): LegacyMetaAdapter {
		return $this->service( 'legacy_meta', function () { return new LegacyMetaAdapter(); } );
	}

	/**
	 * Publish the stable entity-updated event after a canonical writer succeeds.
	 *
	 * @param array<string,mixed> $changes
	 */
	public function entity_updated( string $entity_type, int $post_id, array $changes = array() ): void {
		do_action( 'global360_entity_updated', $entity_type, $post_id, $changes );
	}

	/** @param array<int,mixed> $candidates @param array<string,mixed> $context @return array<int,mixed> */
	public function internal_link_candidates( array $candidates, array $context = array() ): array {
		return (array) apply_filters( 'global360_internal_link_candidates', $candidates, $context );
	}

	/** @param array<int,mixed> $graph @param array<string,mixed> $context @return array<int,mixed> */
	public function schema_graph( array $graph, array $context = array() ): array {
		return (array) apply_filters( 'global360_schema_graph', $graph, $context );
	}

	/** @return mixed */
	private function service( string $name, callable $factory ) {
		if ( ! isset( $this->services[ $name ] ) ) {
			$this->services[ $name ] = $factory();
		}
		return $this->services[ $name ];
	}
}
