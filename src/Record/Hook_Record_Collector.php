<?php
declare( strict_types=1 );

namespace Rarst\Laps\Record;

use Rarst\Laps\Event\Hook_Event_Config_Interface;
use Symfony\Component\Stopwatch\Stopwatch;

/**
 * Processes events based on hooked starts and stops.
 */
class Hook_Record_Collector extends Stopwatch_Record_Collector {

	/** @var Hook_Event_Config_Interface[] $event_configs */
	protected $event_configs = [];

	/** @var array $events */
	protected $events = [];

	/**
	 * @param Stopwatch                     $stopwatch     Stopwatch instance.
	 * @param Hook_Event_Config_Interface[] $event_configs Starts and stops configuration.
	 */
	public function __construct( Stopwatch $stopwatch, array $event_configs ) {

		parent::__construct( $stopwatch );

		$this->start( 'Plugins Load', 'plugin' );
		$this->add_events( $event_configs['core']->get_events() );
		unset( $event_configs['core'] );
		$this->event_configs = $event_configs;

		add_action( 'after_setup_theme', [ $this, 'after_setup_theme' ], 15 );
	}

	/**
	 * Hook events by name and priority from array.
	 *
	 * @param array $stops Starts and stops to hook.
	 */
	public function add_events( array $stops ): void {

		$this->events = array_merge( $this->events, $stops );

		/**
		 * @var int|string $key
		 * @var array      $data
		 */
		foreach ( $stops as $key => $data ) {

			if ( is_int( $key ) ) {
				/** @var array{0:string} $data */
				$this->add_event( ...$data );
				continue;
			}

			/** @var int $priority */
			foreach ( array_keys( $data ) as $priority ) {
				add_action( $key, [ $this, 'tick' ], $priority );
			}
		}
	}

	/**
	 * Add a start/stop pair of hook event.
	 *
	 * @param string      $event          Hook event name.
	 * @param string      $category       Hook event category.
	 * @param string      $start          Start hook name. Pass empty string to ignore.
	 * @param string|null $stop           Stop hook name (defaults to start name). Pass empty string to ignore.
	 * @param int         $start_priority Start hook priority (defaults to -1).
	 * @param int         $stop_priority  Stop hook priority (defaults to max int).
	 */
	private function add_event(
		string $event,
		string $category,
		string $start,
		?string $stop = null,
		int $start_priority = - 1,
		int $stop_priority = PHP_INT_MAX
	): void {
		if ( null === $stop ) {
			$stop = $start;
		}

		$collector = $this;

		if ( '' !== $start ) {
			add_action( $start, function ( $input = null ) use ( $collector, $event, $category ) {

				if ( 'Sidebar' === $event ) {
					$event = $input;
				}

				$collector->start( $event, $category );

				return $input;
			}, $start_priority );
		}

		if ( '' !== $stop ) {
			add_action( $stop, function ( $input = null ) use ( $collector, $event ) {

				if ( 'Sidebar' === $event ) {
					$event = $input;
				}

				$collector->stop( $event );

				return $input;
			}, $stop_priority );
		}
	}

	/**
	 * When theme is done possibly add vendor-specific events.
	 */
	public function after_setup_theme(): void {

		foreach ( $this->event_configs as $config ) {
			$this->add_events( $config->get_events() );
		}
	}

	/**
	 * Mark action for the event on Stopwatch.
	 *
	 * @deprecated 3.0:4.0 Deprecated in favor of the new format.
	 * @codeCoverageIgnore
	 *
	 * @param mixed $input Pass through if added to filter.
	 *
	 * @return mixed
	 */
	public function tick( $input = null ) {

		global $wp_filter;

		/** @var string $filter_name */
		$filter_name     = current_filter();
		/**
		 * @var \WP_Hook|array $filter_instance
		 * @var array<string,\WP_hook|array> $wp_filter
		 */
		$filter_instance = $wp_filter[ $filter_name ];
		/** @var int $priority */
		$priority        = $filter_instance instanceof \WP_Hook ? $filter_instance->current_priority() : key( $filter_instance );

		// See https://core.trac.wordpress.org/ticket/41185 on broken priority, but more general sanity check.
		if ( empty( $this->events[ $filter_name ][ $priority ] ) ) {
			return $input;
		}

		$event = \wp_parse_args( $this->events[ $filter_name ][ $priority ], [
			'action'   => 'start',
			'category' => null,
		] );

		if ( 'start' === $event['action'] ) {
			$this->start( $event['event'], $event['category'] );
		} else {
			$this->stop( $event['event'] );
		}

		return $input;
	}

	/**
	 * @return Stopwatch_Record[]
	 */
	public function get_records(): array {

		$this->stop( 'Toolbar' );
		$this->stop( 'Footer Hook' );

		return parent::get_records();
	}
}
