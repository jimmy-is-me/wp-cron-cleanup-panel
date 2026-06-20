<?php
/*
Plugin Name: Cron Cleanup Panel
Plugin URI:  https://github.com/jimmy-is-me/wp-cron-cleanup-panel
Description: WordPress admin panel to inspect and delete orphaned WP-Cron and Action Scheduler jobs. Includes memory usage, large option detection, and large file scanner.
Version:     1.1.0
Author:      jimmy-is-me
License:     MIT
*/

if ( ! defined( 'ABSPATH' ) ) exit;

class Cron_Cleanup_Panel {

	private array $targets = [ 'thumbpress', 'image-sizes', 'optimize_img' ];
	private string $page   = 'cron-cleanup-panel';

	public function __construct() {
		add_action( 'admin_menu',              [ $this, 'register_menu' ] );
		add_action( 'admin_post_ccp_delete_all',    [ $this, 'handle_delete_all' ] );
		add_action( 'admin_post_ccp_delete_single', [ $this, 'handle_delete_single' ] );
	}

	// ─── Menu ────────────────────────────────────────────────────────────────

	public function register_menu(): void {
		add_menu_page(
			'Cron Cleanup', 'Cron Cleanup',
			'manage_options', $this->page,
			[ $this, 'render' ],
			'dashicons-schedule', 80
		);
	}

	// ─── Helpers ─────────────────────────────────────────────────────────────

	private function is_target( string $hook ): bool {
		foreach ( $this->targets as $t ) {
			if ( stripos( $hook, $t ) !== false ) return true;
		}
		return false;
	}

	private function nonce_url( array $args ): string {
		return esc_url( wp_nonce_url( add_query_arg( $args, admin_url( 'admin-post.php' ) ), 'ccp_action' ) );
	}

	private function redirect( array $args ): void {
		wp_safe_redirect( add_query_arg( array_merge( [ 'page' => $this->page ], $args ), admin_url( 'admin.php' ) ) );
		exit;
	}

	private function check_auth(): void {
		if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Permission denied' );
		check_admin_referer( 'ccp_action' );
	}

	// ─── Data: WP-Cron ───────────────────────────────────────────────────────

	private function get_cron_events(): array {
		$out   = [];
		$crons = _get_cron_array();
		if ( ! is_array( $crons ) ) return $out;

		foreach ( $crons as $ts => $events ) {
			foreach ( $events as $hook => $args_list ) {
				if ( ! $this->is_target( $hook ) ) continue;
				foreach ( $args_list as $sig => $ev ) {
					$out[] = [
						'timestamp' => (int) $ts,
						'datetime'  => date_i18n( 'Y-m-d H:i:s', (int) $ts ),
						'hook'      => $hook,
						'sig'       => $sig,
						'schedule'  => $ev['schedule'] ?? 'single',
						'args'      => $ev['args'] ?? [],
					];
				}
			}
		}

		usort( $out, fn( $a, $b ) => $a['timestamp'] <=> $b['timestamp'] );
		return $out;
	}

	private function delete_cron_event( string $hook, string $sig, int $ts ): int {
		$crons = _get_cron_array();
		if ( ! isset( $crons[ $ts ][ $hook ][ $sig ] ) ) return 0;
		unset( $crons[ $ts ][ $hook ][ $sig ] );
		if ( empty( $crons[ $ts ][ $hook ] ) )  unset( $crons[ $ts ][ $hook ] );
		if ( empty( $crons[ $ts ] ) )            unset( $crons[ $ts ] );
		_set_cron_array( $crons );
		return 1;
	}

	private function delete_all_cron(): int {
		$crons   = _get_cron_array();
		$removed = 0;
		if ( ! is_array( $crons ) ) return 0;

		foreach ( $crons as $ts => $events ) {
			foreach ( $events as $hook => $args_list ) {
				if ( ! $this->is_target( $hook ) ) continue;
				$removed += count( $args_list );
				unset( $crons[ $ts ][ $hook ] );
			}
			if ( empty( $crons[ $ts ] ) ) unset( $crons[ $ts ] );
		}

		_set_cron_array( $crons );
		return $removed;
	}

	// ─── Data: Action Scheduler ───────────────────────────────────────────────

	private function as_table(): ?string {
		global $wpdb;
		$t = $wpdb->prefix . 'actionscheduler_actions';
		return $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $t ) ) === $t ? $t : null;
	}

	private function get_as_events(): array {
		global $wpdb;
		$table = $this->as_table();
		if ( ! $table ) return [];

		$likes  = array_map( fn() => 'hook LIKE %s', $this->targets );
		$params = array_map( fn( $t ) => '%' . $wpdb->esc_like( $t ) . '%', $this->targets );

		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT action_id, hook, status, scheduled_date_gmt, args FROM {$table}
				 WHERE " . implode( ' OR ', $likes ) . "
				 ORDER BY scheduled_date_gmt ASC LIMIT 500",
				$params
			), ARRAY_A
		) ?: [];
	}

	private function delete_all_as(): int {
		global $wpdb;
		$table = $this->as_table();
		if ( ! $table ) return 0;

		$likes  = array_map( fn() => 'hook LIKE %s', $this->targets );
		$params = array_map( fn( $t ) => '%' . $wpdb->esc_like( $t ) . '%', $this->targets );

		$wpdb->query( $wpdb->prepare(
			"DELETE FROM {$table} WHERE " . implode( ' OR ', $likes ), $params
		) );
		return (int) $wpdb->rows_affected;
	}

	// ─── Data: Large Options ──────────────────────────────────────────────────

	private function get_large_options( int $threshold = 10240 ): array {
		global $wpdb;
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT option_name, LENGTH(option_value) AS bytes
				 FROM {$wpdb->options}
				 WHERE LENGTH(option_value) > %d
				 ORDER BY bytes DESC LIMIT 50",
				$threshold
			), ARRAY_A
		) ?: [];
	}

	// ─── Data: Large Files ────────────────────────────────────────────────────

	private function get_large_files( int $min_mb = 5, int $limit = 30 ): array {
		$dirs   = [ WP_CONTENT_DIR . '/uploads', WP_CONTENT_DIR . '/plugins', WP_CONTENT_DIR . '/themes' ];
		$min    = $min_mb * 1024 * 1024;
		$result = [];

		foreach ( $dirs as $dir ) {
			if ( ! is_dir( $dir ) ) continue;
			$iterator = new RecursiveIteratorIterator(
				new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ),
				RecursiveIteratorIterator::LEAVES_ONLY
			);

			foreach ( $iterator as $file ) {
				if ( ! $file->isFile() ) continue;
				$size = $file->getSize();
				if ( $size < $min ) continue;
				$result[] = [
					'path' => $file->getPathname(),
					'size' => $size,
					'size_fmt' => size_format( $size ),
				];
			}
		}

		usort( $result, fn( $a, $b ) => $b['size'] <=> $a['size'] );
		return array_slice( $result, 0, $limit );
	}

	// ─── Action Handlers ─────────────────────────────────────────────────────

	public function handle_delete_single(): void {
		$this->check_auth();
		$type = sanitize_text_field( $_GET['type'] ?? '' );

		if ( $type === 'cron' ) {
			$deleted = $this->delete_cron_event(
				sanitize_text_field( $_GET['hook'] ?? '' ),
				sanitize_text_field( $_GET['sig']  ?? '' ),
				(int) ( $_GET['timestamp'] ?? 0 )
			);
		} elseif ( $type === 'as' ) {
			global $wpdb;
			$table = $this->as_table();
			$id    = (int) ( $_GET['action_id'] ?? 0 );
			$deleted = ( $table && $id > 0 ) ? (int) $wpdb->delete( $table, [ 'action_id' => $id ], [ '%d' ] ) : 0;
		} else {
			$deleted = 0;
		}

		$this->redirect( [ 'deleted' => $deleted ] );
	}

	public function handle_delete_all(): void {
		$this->check_auth();
		$this->redirect( [
			'cron_removed' => $this->delete_all_cron(),
			'as_removed'   => $this->delete_all_as(),
		] );
	}

	// ─── Render ───────────────────────────────────────────────────────────────

	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Permission denied' );

		$cron_events   = $this->get_cron_events();
		$as_events     = $this->get_as_events();
		$mem           = [
			'current' => size_format( memory_get_usage( true ) ),
			'peak'    => size_format( memory_get_peak_usage( true ) ),
			'limit'   => ini_get( 'memory_limit' ),
		];
		$large_options = $this->get_large_options();
		$large_files   = $this->get_large_files();
		?>
		<div class="wrap">
			<h1>⚙️ Cron Cleanup Panel</h1>

			<?php if ( isset( $_GET['cron_removed'] ) || isset( $_GET['as_removed'] ) || isset( $_GET['deleted'] ) ) : ?>
				<div class="notice notice-success is-dismissible"><p>
					✅ 完成：WP-Cron 刪除 <strong><?php echo (int) ( $_GET['cron_removed'] ?? $_GET['deleted'] ?? 0 ); ?></strong> 筆，
					Action Scheduler 刪除 <strong><?php echo (int) ( $_GET['as_removed'] ?? 0 ); ?></strong> 筆。
				</p></div>
			<?php endif; ?>

			<?php $this->section_memory( $mem ); ?>
			<?php $this->section_delete_all(); ?>
			<?php $this->section_cron( $cron_events ); ?>
			<?php $this->section_as( $as_events ); ?>
			<?php $this->section_large_options( $large_options ); ?>
			<?php $this->section_large_files( $large_files ); ?>
		</div>
		<?php
	}

	// ─── Sections ─────────────────────────────────────────────────────────────

	private function section_memory( array $mem ): void { ?>
		<h2>📊 Memory Usage</h2>
		<table class="widefat striped" style="max-width:500px">
			<tbody>
				<tr><th>Current</th><td><?php echo esc_html( $mem['current'] ); ?></td></tr>
				<tr><th>Peak</th><td><?php echo esc_html( $mem['peak'] ); ?></td></tr>
				<tr><th>PHP Limit</th><td><?php echo esc_html( $mem['limit'] ); ?></td></tr>
			</tbody>
		</table>
	<?php }

	private function section_delete_all(): void { ?>
		<h2 style="margin-top:24px">🗑 Delete All Matched Jobs</h2>
		<p>Targets: <code><?php echo esc_html( implode( ', ', $this->targets ) ); ?></code></p>
		<a class="button button-primary"
		   href="<?php echo $this->nonce_url( [ 'action' => 'ccp_delete_all' ] ); ?>"
		   onclick="return confirm('確定刪除所有相關 cron 與 Action Scheduler 任務？')">
			Delete All Related Jobs
		</a>
	<?php }

	private function section_cron( array $events ): void { ?>
		<h2 style="margin-top:24px">⏰ WP-Cron Events <span style="font-weight:normal;font-size:13px">(<?php echo count( $events ); ?> 筆)</span></h2>
		<table class="widefat striped">
			<thead><tr><th>Datetime</th><th>Hook</th><th>Schedule</th><th>Args</th><th>Action</th></tr></thead>
			<tbody>
			<?php if ( empty( $events ) ) : ?>
				<tr><td colspan="5">✅ No matching cron events.</td></tr>
			<?php else : foreach ( $events as $r ) : ?>
				<tr>
					<td><?php echo esc_html( $r['datetime'] ); ?></td>
					<td><code><?php echo esc_html( $r['hook'] ); ?></code></td>
					<td><?php echo esc_html( $r['schedule'] ); ?></td>
					<td style="max-width:200px;word-break:break-all"><code><?php echo esc_html( wp_json_encode( $r['args'] ) ); ?></code></td>
					<td><a class="button button-small" onclick="return confirm('Delete?')"
						href="<?php echo $this->nonce_url( [ 'action' => 'ccp_delete_single', 'type' => 'cron', 'hook' => $r['hook'], 'sig' => $r['sig'], 'timestamp' => $r['timestamp'] ] ); ?>">Delete</a></td>
				</tr>
			<?php endforeach; endif; ?>
			</tbody>
		</table>
	<?php }

	private function section_as( array $events ): void { ?>
		<h2 style="margin-top:24px">📋 Action Scheduler <span style="font-weight:normal;font-size:13px">(<?php echo count( $events ); ?> 筆)</span></h2>
		<table class="widefat striped">
			<thead><tr><th>Scheduled (GMT)</th><th>Hook</th><th>Status</th><th>Args</th><th>Action</th></tr></thead>
			<tbody>
			<?php if ( empty( $events ) ) : ?>
				<tr><td colspan="5">✅ No matching Action Scheduler events.</td></tr>
			<?php else : foreach ( $events as $r ) : ?>
				<tr>
					<td><?php echo esc_html( $r['scheduled_date_gmt'] ); ?></td>
					<td><code><?php echo esc_html( $r['hook'] ); ?></code></td>
					<td><?php echo esc_html( $r['status'] ); ?></td>
					<td style="max-width:200px;word-break:break-all"><code><?php echo esc_html( $r['args'] ); ?></code></td>
					<td><a class="button button-small" onclick="return confirm('Delete?')"
						href="<?php echo $this->nonce_url( [ 'action' => 'ccp_delete_single', 'type' => 'as', 'action_id' => $r['action_id'] ] ); ?>">Delete</a></td>
				</tr>
			<?php endforeach; endif; ?>
			</tbody>
		</table>
	<?php }

	private function section_large_options( array $options ): void { ?>
		<h2 style="margin-top:24px">🗃 Large Options (>10 KB)</h2>
		<table class="widefat striped">
			<thead><tr><th>Option Name</th><th>Size</th></tr></thead>
			<tbody>
			<?php if ( empty( $options ) ) : ?>
				<tr><td colspan="2">✅ No oversized options found.</td></tr>
			<?php else : foreach ( $options as $r ) : ?>
				<tr>
					<td><code><?php echo esc_html( $r['option_name'] ); ?></code></td>
					<td><?php echo esc_html( size_format( (int) $r['bytes'] ) ); ?></td>
				</tr>
			<?php endforeach; endif; ?>
			</tbody>
		</table>
	<?php }

	private function section_large_files( array $files ): void { ?>
		<h2 style="margin-top:24px">📁 Large Files (>5 MB) — uploads / plugins / themes</h2>
		<table class="widefat striped">
			<thead><tr><th>Path</th><th>Size</th></tr></thead>
			<tbody>
			<?php if ( empty( $files ) ) : ?>
				<tr><td colspan="2">✅ No large files found.</td></tr>
			<?php else : foreach ( $files as $r ) : ?>
				<tr>
					<td style="word-break:break-all"><code><?php echo esc_html( $r['path'] ); ?></code></td>
					<td><?php echo esc_html( $r['size_fmt'] ); ?></td>
				</tr>
			<?php endforeach; endif; ?>
			</tbody>
		</table>
	<?php }
}

new Cron_Cleanup_Panel();
