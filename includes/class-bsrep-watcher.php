<?php
/**
 * Watches for updates and writes them to the log.
 *
 * Two observers, deliberately. The upgrader hooks are precise and know who did
 * it, but they only fire when the update went through WordPress's own upgrader.
 * The sweeper compares a stored version map against reality and catches
 * everything else: WP-CLI, SFTP, a host's own tooling, a plugin swapped by hand.
 * Between them nothing that changes a version number goes unrecorded, and
 * Bsrep_Log::record() drops the double when both see the same update.
 *
 * @package BiscuitSiteReport
 */

defined( 'ABSPATH' ) || exit;

/**
 * Update observers.
 */
class Bsrep_Watcher {

	/**
	 * Option holding the last known version of everything installed.
	 */
	const MAP_OPTION = 'bsrep_version_map';

	/**
	 * Hook everything up.
	 *
	 * @return void
	 */
	public function init() {
		add_action( 'upgrader_process_complete', array( $this, 'on_upgrade' ), 10, 2 );
		add_action( 'automatic_updates_complete', array( $this, 'on_automatic' ), 10, 1 );
		add_action( 'activated_plugin', array( $this, 'on_activate' ), 10, 1 );
		add_action( 'deactivated_plugin', array( $this, 'on_deactivate' ), 10, 1 );
		add_action( 'deleted_plugin', array( $this, 'on_delete' ), 10, 2 );
		add_action( 'bsrep_sweep_versions', array( $this, 'sweep' ) );
	}

	/**
	 * Read the current version of core, every plugin and every theme.
	 *
	 * @return array Map of key => array{ name: string, version: string, type: string }.
	 */
	public function current_map() {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$map = array();

		$map['core:wordpress'] = array(
			'type'    => 'core',
			'name'    => 'WordPress',
			'version' => (string) get_bloginfo( 'version' ),
		);

		foreach ( get_plugins() as $file => $data ) {
			$map[ 'plugin:' . $file ] = array(
				'type'    => 'plugin',
				'name'    => (string) ( $data['Name'] ?? $file ),
				'version' => (string) ( $data['Version'] ?? '' ),
			);
		}

		foreach ( wp_get_themes() as $stylesheet => $theme ) {
			$map[ 'theme:' . $stylesheet ] = array(
				'type'    => 'theme',
				'name'    => (string) $theme->get( 'Name' ),
				'version' => (string) $theme->get( 'Version' ),
			);
		}

		return $map;
	}

	/**
	 * Compare the stored map against reality and log every difference.
	 *
	 * On the very first run there is no stored map, so this records nothing and
	 * just stores the baseline. That is correct: an install is not an update, and
	 * inventing 31 update events on activation day would put a false number on a
	 * client's page.
	 *
	 * @return int Number of events written.
	 */
	public function sweep() {
		$current = $this->current_map();
		$stored  = get_option( self::MAP_OPTION );

		if ( ! is_array( $stored ) || empty( $stored ) ) {
			update_option( self::MAP_OPTION, $current, false );

			return 0;
		}

		$written = 0;

		foreach ( $current as $key => $now ) {
			$was = $stored[ $key ] ?? null;

			if ( null === $was ) {
				$written += Bsrep_Log::record(
					array(
						'event_type'  => $now['type'] . '_install',
						'object_slug' => $this->slug_from_key( $key ),
						'object_name' => $now['name'],
						'version_to'  => $now['version'],
						'context'     => 'observed',
					)
				) ? 1 : 0;

				continue;
			}

			if ( $was['version'] === $now['version'] || '' === $now['version'] ) {
				continue;
			}

			// A version that went backwards is a rollback, not an update. Both are
			// work Biscuit did, but only one belongs in an "updates applied" count.
			$type = version_compare( $now['version'], $was['version'], '>' ) ? '_update' : '_rollback';

			$written += Bsrep_Log::record(
				array(
					'event_type'   => $now['type'] . $type,
					'object_slug'  => $this->slug_from_key( $key ),
					'object_name'  => $now['name'],
					'version_from' => $was['version'],
					'version_to'   => $now['version'],
					'context'      => 'observed',
				)
			) ? 1 : 0;
		}

		foreach ( $stored as $key => $was ) {
			if ( isset( $current[ $key ] ) ) {
				continue;
			}

			$written += Bsrep_Log::record(
				array(
					'event_type'   => $was['type'] . '_delete',
					'object_slug'  => $this->slug_from_key( $key ),
					'object_name'  => $was['name'],
					'version_from' => $was['version'],
					'context'      => 'observed',
				)
			) ? 1 : 0;
		}

		update_option( self::MAP_OPTION, $current, false );

		return $written;
	}

	/**
	 * Strip the type prefix off a map key.
	 *
	 * @param string $key Map key.
	 * @return string
	 */
	protected function slug_from_key( $key ) {
		$parts = explode( ':', $key, 2 );

		return $parts[1] ?? $key;
	}

	/**
	 * Log an update the moment WordPress's own upgrader finishes one.
	 *
	 * The version before the update is read from the stored map rather than from
	 * the filesystem, because by the time this fires the new files are already in
	 * place and the old version is gone.
	 *
	 * @param WP_Upgrader $upgrader   Upgrader instance.
	 * @param array       $hook_extra What was updated.
	 * @return void
	 */
	public function on_upgrade( $upgrader, $hook_extra ) {
		$action = $hook_extra['action'] ?? '';
		$type   = $hook_extra['type'] ?? '';

		if ( 'update' !== $action && 'install' !== $action ) {
			return;
		}

		$stored  = (array) get_option( self::MAP_OPTION, array() );
		$suffix  = 'install' === $action ? '_install' : '_update';
		$context = wp_doing_cron() ? 'automatic' : 'upgrader';

		if ( 'plugin' === $type ) {
			$files = $hook_extra['plugins'] ?? array();

			if ( isset( $hook_extra['plugin'] ) ) {
				$files[] = $hook_extra['plugin'];
			}

			foreach ( array_unique( (array) $files ) as $file ) {
				$this->log_object( 'plugin', $file, $stored, $suffix, $context );
			}

			return;
		}

		if ( 'theme' === $type ) {
			$sheets = $hook_extra['themes'] ?? array();

			if ( isset( $hook_extra['theme'] ) ) {
				$sheets[] = $hook_extra['theme'];
			}

			foreach ( array_unique( (array) $sheets ) as $sheet ) {
				$this->log_object( 'theme', $sheet, $stored, $suffix, $context );
			}

			return;
		}

		if ( 'core' === $type ) {
			$was = $stored['core:wordpress']['version'] ?? '';

			Bsrep_Log::record(
				array(
					'event_type'   => 'core_update',
					'object_slug'  => 'wordpress',
					'object_name'  => 'WordPress',
					'version_from' => $was,
					'version_to'   => (string) get_bloginfo( 'version' ),
					'context'      => $context,
				)
			);
		}
	}

	/**
	 * Write one plugin or theme event, reading the new version off disk.
	 *
	 * @param string $type    plugin or theme.
	 * @param string $slug    Plugin file, or theme stylesheet.
	 * @param array  $stored  Stored version map.
	 * @param string $suffix  _update or _install.
	 * @param string $context How the update was applied.
	 * @return void
	 */
	protected function log_object( $type, $slug, array $stored, $suffix, $context ) {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$was = $stored[ $type . ':' . $slug ]['version'] ?? '';

		if ( 'plugin' === $type ) {
			$all  = get_plugins();
			$data = $all[ $slug ] ?? array();
			$name = (string) ( $data['Name'] ?? $slug );
			$now  = (string) ( $data['Version'] ?? '' );
		} else {
			$theme = wp_get_theme( $slug );
			$name  = (string) $theme->get( 'Name' );
			$now   = (string) $theme->get( 'Version' );
		}

		Bsrep_Log::record(
			array(
				'event_type'   => $type . $suffix,
				'object_slug'  => $slug,
				'object_name'  => $name,
				'version_from' => $was,
				'version_to'   => $now,
				'context'      => $context,
			)
		);
	}

	/**
	 * Refresh the stored map after an automatic update run.
	 *
	 * The upgrader hook above has already logged each one. This only keeps the map
	 * honest so the next sweep does not re-report them.
	 *
	 * @param array $results Results by type.
	 * @return void
	 */
	public function on_automatic( $results ) {
		unset( $results );

		update_option( self::MAP_OPTION, $this->current_map(), false );
	}

	/**
	 * Log a plugin activation.
	 *
	 * @param string $file Plugin file.
	 * @return void
	 */
	public function on_activate( $file ) {
		$this->log_state_change( 'plugin_activate', $file );
	}

	/**
	 * Log a plugin deactivation.
	 *
	 * @param string $file Plugin file.
	 * @return void
	 */
	public function on_deactivate( $file ) {
		$this->log_state_change( 'plugin_deactivate', $file );
	}

	/**
	 * Log a plugin deletion, only when the delete actually succeeded.
	 *
	 * @param string $file    Plugin file.
	 * @param bool   $deleted Whether the delete succeeded.
	 * @return void
	 */
	public function on_delete( $file, $deleted ) {
		if ( ! $deleted ) {
			return;
		}

		$this->log_state_change( 'plugin_delete', $file );
	}

	/**
	 * Shared writer for activate, deactivate and delete.
	 *
	 * @param string $event_type Event type.
	 * @param string $file       Plugin file.
	 * @return void
	 */
	protected function log_state_change( $event_type, $file ) {
		// Never log this plugin's own activation, deactivation or removal. Those are
		// artifacts of the instrumentation, not maintenance done to the client's site.
		if ( plugin_basename( BSREP_FILE ) === $file ) {
			return;
		}

		$stored = (array) get_option( self::MAP_OPTION, array() );
		$known  = $stored[ 'plugin:' . $file ] ?? array();

		Bsrep_Log::record(
			array(
				'event_type'  => $event_type,
				'object_slug' => $file,
				'object_name' => (string) ( $known['name'] ?? $file ),
				'version_to'  => (string) ( $known['version'] ?? '' ),
				'context'     => wp_doing_cron() ? 'automatic' : 'upgrader',
			)
		);
	}
}
