<?php
/**
 * Cogito RAR – Loader
 *
 * Registers all plugin-wide actions and filters in a secure,
 * WordPress-compliant manner.
 *
 * @package Cogito_RAR
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Abort if called directly.
}

class Cogito_Loader {

	/** @var array<int, array<string, mixed>> */
	private array $actions = [];

	/** @var array<int, array<string, mixed>> */
	private array $filters = [];

	/**
	 * Queue an action hook.
	 *
	 * @param string $hook          Hook name.
	 * @param object $component     Instance containing the callback.
	 * @param string $callback      Method name on the component.
	 * @param int    $priority      Priority.
	 * @param int    $accepted_args Number of args.
	 */
	public function add_action(
		string $hook,
		object $component,
		string $callback,
		int $priority      = 10,
		int $accepted_args = 1
	): void {
		$this->actions[] = compact( 'hook', 'component', 'callback', 'priority', 'accepted_args' );
	}

	/**
	 * Queue a filter hook.
	 *
	 * @param string $hook          Hook name.
	 * @param object $component     Instance containing the callback.
	 * @param string $callback      Method name on the component.
	 * @param int    $priority      Priority.
	 * @param int    $accepted_args Number of args.
	 */
	public function add_filter(
		string $hook,
		object $component,
		string $callback,
		int $priority      = 10,
		int $accepted_args = 1
	): void {
		$this->filters[] = compact( 'hook', 'component', 'callback', 'priority', 'accepted_args' );
	}

	/**
	 * Register all queued hooks with WordPress.
	 */
	public function run(): void {

		// Register actions.
		foreach ( $this->actions as $a ) {
			add_action(
				$a['hook'],
				[ $a['component'], $a['callback'] ],
				$a['priority'],
				$a['accepted_args']
			);
		}

		// Register filters.
		foreach ( $this->filters as $f ) {
			add_filter(
				$f['hook'],
				[ $f['component'], $f['callback'] ],
				$f['priority'],
				$f['accepted_args']
			);
		}
	}
}
