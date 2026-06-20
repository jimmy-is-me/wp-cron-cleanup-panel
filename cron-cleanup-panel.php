<?php
/*
Plugin Name: Cron Cleanup Panel
Plugin URI:  https://github.com/jimmy-is-me/wp-cron-cleanup-panel
Description: WordPress admin panel to inspect and delete WP-Cron and Action Scheduler jobs. Includes memory usage, large option detection, large file scanner, stuck job detection, and CPU/RAM pressure analysis.
Version:     1.2.1
Author:      jimmy-is-me
License:     MIT
*/

if ( ! defined( 'ABSPATH' ) ) exit;

class Cron_Cleanup_Panel {

	private array  $targets = [ 'thumbpress', 'image-sizes', 'optimize_img' ];
	private string $page    = 'cron-cleanup-panel';
	private int    $stuck_threshold = 600;
	private int    $cron_overdue_days = 7;

	public function __construct() {
		add_action( 'admin_menu',                   [ $this, 'register_menu' ] );
		add_action( 'admin_post_ccp_delete_all',    [ $this, 'handle_delete_all' ] );
		add_action( 'admin_post_ccp_delete_single', [ $this, 'handle_delete_single' ] );
	}

	public function register_menu(): void {
		add_menu_page(
			'Cron Cleanup',
			'Cron Cleanup',
			'manage_options',
			$this->page,
			[ $this, 'render' ],
			'dashicons-warning',
			80
		);
	}

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

	private function status_badge( string $status ): string {
		$map = [
			'pending'     => 'background:#f0ad4e;color:#fff',
			'in-progress' => 'background:#d9534f;color:#fff',
			'complete'    => 'background:#5cb85c;color:#fff',
			'failed'      => 'background:#c9302c;color:#fff',
			'canceled'    => 'background:#aaa;color:#fff',
		];
		$style = $map[ $status ] ?? 'background:#eee;color:#333';
		return '<span style="padding:2px 7px;border-radius:3px;font-size:11px;' . $style . '">' . esc_html( $status ) . '</span>';
	}

	private function as_table(): ?string {
		global $wpdb;
		$table = $wpdb->prefix . 'actionscheduler_actions';
		return $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table ? $table : null;
	}

	private function get_cron_events( bool $targets_only = true ): array {
		$out   = [];
		$now   = time();
		$crons = _get_cron_array();
		if ( ! is_array( $crons ) ) return $out;

		foreach ( $crons as $ts => $events ) {
			foreach ( $events as $hook => $args_list ) {
				if ( $targets_only && ! $this->is_target( $hook ) ) continue;
				foreach ( $args_list as $sig => $ev ) {
					$overdue_sec = $now - (int) $ts;
					$out[] = [
						'timestamp'   => (int) $ts,
						'datetime'    => date_i18n( 'Y-m-d H:i:s', (int) $ts ),
						'hook'        => $hook,
						'sig'         => $sig,
						'schedule'    => $ev['schedule'] ?? 'single',
						'args'        => $ev['args'] ?? [],
						'is_target'   => $this->is_target( $hook ),
						'overdue_sec' => $overdue_sec,
						'is_stuck'    => $overdue_sec > $this->stuck_threshold,
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
		if ( empty( $crons[ $ts ][ $hook ] ) ) unset( $crons[ $ts ][ $hook ] );
		if ( empty( $crons[ $ts ] ) ) unset( $crons[ $ts ] );
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

	private function get_as_events( bool $targets_only = true ): array {
		global $wpdb;
		$table = $this->as_table();
		if ( ! $table ) return [];

		if ( $targets_only ) {
			$likes  = array_map( fn() => 'hook LIKE %s', $this->targets );
			$params = array_map( fn( $t ) => '%' . $wpdb->esc_like( $t ) . '%', $this->targets );
			$sql = $wpdb->prepare(
				"SELECT action_id, hook, status, scheduled_date_gmt, last_attempt_gmt, attempts, args
				 FROM {$table}
				 WHERE " . implode( ' OR ', $likes ) . "
				 ORDER BY scheduled_date_gmt ASC LIMIT 500",
				$params
			);
		} else {
			$sql = "SELECT action_id, hook, status, scheduled_date_gmt, last_attempt_gmt, attempts, args
					FROM {$table}
					ORDER BY scheduled_date_gmt ASC LIMIT 500";
		}

		$rows = $wpdb->get_results( $sql, ARRAY_A ) ?: [];
		$now = time();

		foreach ( $rows as &$r ) {
			$started = strtotime( $r['last_attempt_gmt'] ?? '' );
			$r['is_target'] = $this->is_target( $r['hook'] );
			$r['is_stuck'] = (
				$r['status'] === 'in-progress' &&
				$started &&
				( $now - $started ) > $this->stuck_threshold
			);
			$r['is_looping'] = (
				(int) ( $r['attempts'] ?? 0 ) > 3 &&
				$r['status'] !== 'complete'
			);
		}
		unset( $r );

		return $rows;
	}

	private function delete_all_as(): int {
		global $wpdb;
		$table = $this->as_table();
		if ( ! $table ) return 0;
		$likes  = array_map( fn() => 'hook LIKE %s', $this->targets );
		$params = array_map( fn( $t ) => '%' . $wpdb->esc_like( $t ) . '%', $this->targets );
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE " . implode( ' OR ', $likes ), $params ) );
		return (int) $wpdb->rows_affected;
	}

	private function delete_as_event( int $action_id ): int {
		global $wpdb;
		$table = $this->as_table();
		if ( ! $table || $action_id <= 0 ) return 0;
		return (int) $wpdb->delete( $table, [ 'action_id' => $action_id ], [ '%d' ] );
	}

	private function get_pressure_report(): array {
		global $wpdb;
		$report = [];

		$autoload_total = (int) $wpdb->get_var(
			"SELECT SUM(LENGTH(option_value)) FROM {$wpdb->options} WHERE autoload = 'yes'"
		);
		$autoload_top = $wpdb->get_results(
			"SELECT option_name, LENGTH(option_value) AS bytes
			 FROM {$wpdb->options}
			 WHERE autoload = 'yes'
			 ORDER BY bytes DESC LIMIT 20",
			ARRAY_A
		) ?: [];
		$report['autoload_total'] = size_format( $autoload_total );
		$report['autoload_top']   = $autoload_top;

		$as_table = $this->as_table();
		$report['as_hotspots'] = [];
		$report['as_stuck']    = [];
		$report['as_failing']  = [];

		if ( $as_table ) {
			$report['as_hotspots'] = $wpdb->get_results(
				"SELECT hook,
				        COUNT(*) AS total,
				        SUM(attempts) AS total_attempts,
				        SUM(CASE WHEN status='failed' THEN 1 ELSE 0 END) AS failed_cnt,
				        SUM(CASE WHEN status='in-progress' THEN 1 ELSE 0 END) AS running_cnt,
				        SUM(CASE WHEN status='pending' THEN 1 ELSE 0 END) AS pending_cnt
				 FROM {$as_table}
				 GROUP BY hook
				 ORDER BY total DESC
				 LIMIT 20",
				ARRAY_A
			) ?: [];

			$stuck_ts = gmdate( 'Y-m-d H:i:s', time() - $this->stuck_threshold );
			$report['as_stuck'] = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT action_id, hook, last_attempt_gmt, attempts
					 FROM {$as_table}
					 WHERE status = 'in-progress'
					 AND last_attempt_gmt < %s
					 ORDER BY last_attempt_gmt ASC LIMIT 20",
					$stuck_ts
				), ARRAY_A
			) ?: [];

			$report['as_failing'] = $wpdb->get_results(
				"SELECT action_id, hook, attempts, last_attempt_gmt
				 FROM {$as_table}
				 WHERE status = 'failed' AND attempts > 3
				 ORDER BY attempts DESC LIMIT 20",
				ARRAY_A
			) ?: [];
		}

		$crons = _get_cron_array();
		$overdue = [];
		$now = time();
		$cron_overdue_threshold = $this->cron_overdue_days * DAY_IN_SECONDS;
		if ( is_array( $crons ) ) {
			foreach ( $crons as $ts => $events ) {
				if ( $now - (int) $ts < $cron_overdue_threshold ) continue;
				foreach ( $events as $hook => $args_list ) {
					$overdue[ $hook ] = ( $overdue[ $hook ] ?? 0 ) + count( $args_list );
				}
			}
		}
		arsort( $overdue );
		$report['cron_overdue'] = array_slice( $overdue, 0, 20, true );

		return $report;
	}

	private function get_large_options( int $threshold = 1048576, int $limit = 10 ): array {
		global $wpdb;
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT option_name, LENGTH(option_value) AS bytes
				 FROM {$wpdb->options}
				 WHERE LENGTH(option_value) > %d
				 ORDER BY bytes DESC LIMIT %d",
				$threshold,
				$limit
			), ARRAY_A
		) ?: [];
	}

	private function get_large_files( int $min_mb = 5, int $limit = 20 ): array {
		$dirs = [
			WP_CONTENT_DIR . '/uploads',
			WP_CONTENT_DIR . '/plugins',
			WP_CONTENT_DIR . '/themes',
		];
		$min = $min_mb * 1024 * 1024;
		$result = [];

		foreach ( $dirs as $dir ) {
			if ( ! is_dir( $dir ) ) continue;
			$it = new RecursiveIteratorIterator(
				new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ),
				RecursiveIteratorIterator::LEAVES_ONLY
			);
			foreach ( $it as $file ) {
				if ( ! $file->isFile() ) continue;
				$size = $file->getSize();
				if ( $size < $min ) continue;
				$result[] = [
					'path'     => $file->getPathname(),
					'size'     => $size,
					'size_fmt' => size_format( $size ),
				];
			}
		}

		usort( $result, fn( $a, $b ) => $b['size'] <=> $a['size'] );
		return array_slice( $result, 0, $limit );
	}

	public function handle_delete_single(): void {
		$this->check_auth();
		$type = sanitize_text_field( $_GET['type'] ?? '' );
		$deleted = 0;

		if ( $type === 'cron' ) {
			$deleted = $this->delete_cron_event(
				sanitize_text_field( $_GET['hook'] ?? '' ),
				sanitize_text_field( $_GET['sig'] ?? '' ),
				(int) ( $_GET['timestamp'] ?? 0 )
			);
		} elseif ( $type === 'as' ) {
			$deleted = $this->delete_as_event( (int) ( $_GET['action_id'] ?? 0 ) );
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

	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Permission denied' );

		$show_all = isset( $_GET['show_all'] ) && $_GET['show_all'] === '1';
		$cron_events   = $this->get_cron_events( ! $show_all );
		$as_events     = $this->get_as_events( ! $show_all );
		$pressure      = $this->get_pressure_report();
		$large_options = $this->get_large_options();
		$large_files   = $this->get_large_files();
		$mem = [
			'current' => size_format( memory_get_usage( true ) ),
			'peak'    => size_format( memory_get_peak_usage( true ) ),
			'limit'   => ini_get( 'memory_limit' ),
		];
		?>
		<div class="wrap">
		<h1>⚙️ Cron Cleanup Panel <span style="font-size:13px;font-weight:normal;color:#888">v1.2.1</span></h1>

		<?php if ( isset( $_GET['cron_removed'] ) || isset( $_GET['as_removed'] ) || isset( $_GET['deleted'] ) ) : ?>
			<div class="notice notice-success is-dismissible"><p>
				✅ 完成：WP-Cron 刪除 <strong><?php echo (int) ( $_GET['cron_removed'] ?? $_GET['deleted'] ?? 0 ); ?></strong> 筆，
				Action Scheduler 刪除 <strong><?php echo (int) ( $_GET['as_removed'] ?? 0 ); ?></strong> 筆。
			</p></div>
		<?php endif; ?>

		<p>
			<a class="button <?php echo $show_all ? '' : 'button-primary'; ?>" href="<?php echo esc_url( admin_url( 'admin.php?page=' . $this->page ) ); ?>">只看 Targets</a>
			<a class="button <?php echo $show_all ? 'button-primary' : ''; ?>" href="<?php echo esc_url( admin_url( 'admin.php?page=' . $this->page . '&show_all=1' ) ); ?>">顯示全部 Hook</a>
		</p>

		<?php $this->section_memory( $mem ); ?>
		<?php $this->section_pressure( $pressure ); ?>
		<?php $this->section_delete_all(); ?>
		<?php $this->section_cron( $cron_events ); ?>
		<?php $this->section_as( $as_events ); ?>
		<?php $this->section_large_options( $large_options ); ?>
		<?php $this->section_large_files( $large_files ); ?>
		</div>
		<?php
	}

	private function section_memory( array $mem ): void {
		$limit_bytes = wp_convert_hr_to_bytes( $mem['limit'] );
		$curr_bytes  = memory_get_usage( true );
		$pct = $limit_bytes > 0 ? round( $curr_bytes / $limit_bytes * 100 ) : 0;
		$bar_color = $pct >= 80 ? '#d9534f' : ( $pct >= 60 ? '#f0ad4e' : '#5cb85c' );
		?>
		<h2>📊 Memory Usage</h2>
		<table class="widefat striped" style="max-width:520px">
			<tbody>
				<tr><th width="120">Current</th><td><?php echo esc_html( $mem['current'] ); ?> (<?php echo $pct; ?>%)
					<div style="background:#eee;border-radius:3px;height:8px;width:200px;display:inline-block;vertical-align:middle;margin-left:8px">
						<div style="background:<?php echo $bar_color; ?>;height:8px;border-radius:3px;width:<?php echo $pct; ?>%"></div>
					</div>
				</td></tr>
				<tr><th>Peak</th><td><?php echo esc_html( $mem['peak'] ); ?></td></tr>
				<tr><th>PHP Limit</th><td><?php echo esc_html( $mem['limit'] ); ?></td></tr>
			</tbody>
		</table>
		<?php
	}

	private function section_pressure( array $p ): void { ?>
		<h2 style="margin-top:28px">🔥 CPU / RAM Pressure Analysis</h2>
		<h3>Autoload Options Total</h3>
		<p>每次 WordPress 啟動都會載入所有 autoload option，總大小愈大代表啟動記憶體消耗愈多。</p>
		<p><strong><?php echo esc_html( $p['autoload_total'] ); ?></strong></p>

		<?php if ( ! empty( $p['autoload_top'] ) ) : ?>
		<h4>Top 20 Autoload Options</h4>
		<table class="widefat striped">
			<thead><tr><th>Option Name</th><th>Size</th></tr></thead>
			<tbody>
			<?php foreach ( $p['autoload_top'] as $r ) : ?>
			<tr>
				<td><code><?php echo esc_html( $r['option_name'] ); ?></code></td>
				<td><?php echo esc_html( size_format( (int) $r['bytes'] ) ); ?></td>
			</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<?php endif; ?>

		<?php if ( ! empty( $p['as_hotspots'] ) ) : ?>
		<h3 style="margin-top:20px">Action Scheduler Hotspots（全部 hook）</h3>
		<p>total 數量高、running_cnt &gt; 0、failed_cnt 高的 hook 是最可能造成 CPU 衝高的來源。</p>
		<table class="widefat striped">
			<thead><tr><th>Hook</th><th>Total</th><th>Pending</th><th>Running</th><th>Failed</th><th>Attempts</th></tr></thead>
			<tbody>
			<?php foreach ( $p['as_hotspots'] as $r ) : $row_style = ( (int) $r['running_cnt'] > 0 || (int) $r['failed_cnt'] > 5 ) ? 'background:#fff3cd' : ''; ?>
			<tr style="<?php echo $row_style; ?>">
				<td><code><?php echo esc_html( $r['hook'] ); ?></code></td>
				<td><?php echo (int) $r['total']; ?></td>
				<td><?php echo (int) $r['pending_cnt']; ?></td>
				<td><?php echo (int) $r['running_cnt'] > 0 ? '<strong style="color:#d9534f">' . (int) $r['running_cnt'] . '</strong>' : 0; ?></td>
				<td><?php echo (int) $r['failed_cnt'] > 0 ? '<strong style="color:#c9302c">' . (int) $r['failed_cnt'] . '</strong>' : 0; ?></td>
				<td><?php echo (int) $r['total_attempts']; ?></td>
			</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<?php endif; ?>

		<?php if ( ! empty( $p['as_stuck'] ) ) : ?>
		<h3 style="margin-top:20px">⚠️ Stuck Jobs（in-progress > <?php echo (int) ( $this->stuck_threshold / 60 ); ?> 分鐘）</h3>
		<table class="widefat striped">
			<thead><tr><th>ID</th><th>Hook</th><th>Last Attempt (GMT)</th><th>Attempts</th></tr></thead>
			<tbody>
			<?php foreach ( $p['as_stuck'] as $r ) : ?>
			<tr style="background:#f8d7da">
				<td><?php echo (int) $r['action_id']; ?></td>
				<td><code><?php echo esc_html( $r['hook'] ); ?></code></td>
				<td><?php echo esc_html( $r['last_attempt_gmt'] ); ?></td>
				<td><?php echo (int) $r['attempts']; ?></td>
			</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<?php endif; ?>

		<?php if ( ! empty( $p['as_failing'] ) ) : ?>
		<h3 style="margin-top:20px">❌ Repeatedly Failing Jobs（attempts > 3）</h3>
		<table class="widefat striped">
			<thead><tr><th>ID</th><th>Hook</th><th>Attempts</th><th>Last Attempt (GMT)</th></tr></thead>
			<tbody>
			<?php foreach ( $p['as_failing'] as $r ) : ?>
			<tr style="background:#fff3cd">
				<td><?php echo (int) $r['action_id']; ?></td>
				<td><code><?php echo esc_html( $r['hook'] ); ?></code></td>
				<td><strong><?php echo (int) $r['attempts']; ?></strong></td>
				<td><?php echo esc_html( $r['last_attempt_gmt'] ); ?></td>
			</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<?php endif; ?>

		<?php if ( ! empty( $p['cron_overdue'] ) ) : ?>
		<h3 style="margin-top:20px">⏳ WP-Cron Overdue（積壓超過 <?php echo (int) $this->cron_overdue_days; ?> 天未執行）</h3>
		<table class="widefat striped">
			<thead><tr><th>Hook</th><th>Count</th></tr></thead>
			<tbody>
			<?php foreach ( $p['cron_overdue'] as $hook => $cnt ) : ?>
			<tr style="background:#fff3cd">
				<td><code><?php echo esc_html( $hook ); ?></code></td>
				<td><?php echo (int) $cnt; ?></td>
			</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<?php endif; ?>
	<?php }

	private function section_delete_all(): void { ?>
		<h2 style="margin-top:28px">🗑 Delete All Matched Jobs</h2>
		<p>Targets: <code><?php echo esc_html( implode( ', ', $this->targets ) ); ?></code>（只刪這些 target；列表可切換顯示全部 hook）</p>
		<a class="button button-primary"
		   href="<?php echo $this->nonce_url( [ 'action' => 'ccp_delete_all' ] ); ?>"
		   onclick="return confirm('確定刪除所有 target 相關的 cron 與 Action Scheduler 任務？')">
			Delete All Related Jobs
		</a>
	<?php }

	private function section_cron( array $events ): void {
		$stuck = array_filter( $events, fn( $e ) => $e['is_stuck'] );
		?>
		<h2 style="margin-top:28px">⏰ WP-Cron Events
			<span style="font-weight:normal;font-size:13px">(<?php echo count( $events ); ?> 筆</span>
			<?php if ( $stuck ) : ?><span style="color:#d9534f;font-size:13px"> ／ ⚠️ <?php echo count( $stuck ); ?> 筆過期積壓</span><?php endif; ?>)
		</h2>
		<table class="widefat striped">
			<thead><tr><th>Datetime</th><th>Hook</th><th>Target</th><th>Schedule</th><th>Overdue</th><th>Args</th><th>Action</th></tr></thead>
			<tbody>
			<?php if ( empty( $events ) ) : ?>
			<tr><td colspan="7">✅ No cron events found.</td></tr>
			<?php else : foreach ( $events as $r ) : $row_bg = $r['is_stuck'] ? 'background:#fff3cd' : ''; ?>
			<tr style="<?php echo $row_bg; ?>">
				<td><?php echo esc_html( $r['datetime'] ); ?></td>
				<td><code><?php echo esc_html( $r['hook'] ); ?></code></td>
				<td><?php echo $r['is_target'] ? '✅' : '—'; ?></td>
				<td><?php echo esc_html( $r['schedule'] ); ?></td>
				<td><?php echo $r['overdue_sec'] > 0 ? '<span style="color:' . ( $r['is_stuck'] ? '#d9534f' : '#888' ) . '">' . human_time_diff( $r['timestamp'], time() ) . ' ago</span>' : '<span style="color:#5cb85c">future</span>'; ?></td>
				<td style="max-width:180px;word-break:break-all"><code><?php echo esc_html( wp_json_encode( $r['args'] ) ); ?></code></td>
				<td><a class="button button-small" onclick="return confirm('Delete this WP-Cron event?')" href="<?php echo $this->nonce_url( [ 'action' => 'ccp_delete_single', 'type' => 'cron', 'hook' => $r['hook'], 'sig' => $r['sig'], 'timestamp' => $r['timestamp'] ] ); ?>">Delete</a></td>
			</tr>
			<?php endforeach; endif; ?>
			</tbody>
		</table>
	<?php }

	private function section_as( array $events ): void {
		$stuck = array_filter( $events, fn( $e ) => $e['is_stuck'] );
		$looping = array_filter( $events, fn( $e ) => $e['is_looping'] );
		?>
		<h2 style="margin-top:28px">📋 Action Scheduler
			<span style="font-weight:normal;font-size:13px">(<?php echo count( $events ); ?> 筆</span>
			<?php if ( $stuck ) : ?><span style="color:#d9534f;font-size:13px"> ／ ⚠️ <?php echo count( $stuck ); ?> stuck</span><?php endif; ?>
			<?php if ( $looping ) : ?><span style="color:#f0ad4e;font-size:13px"> ／ 🔄 <?php echo count( $looping ); ?> looping</span><?php endif; ?>)
		</h2>
		<table class="widefat striped">
			<thead><tr><th>Scheduled (GMT)</th><th>Hook</th><th>Target</th><th>Status</th><th>Attempts</th><th>Last Attempt</th><th>Args</th><th>Action</th></tr></thead>
			<tbody>
			<?php if ( empty( $events ) ) : ?>
			<tr><td colspan="8">✅ No Action Scheduler events found.</td></tr>
			<?php else : foreach ( $events as $r ) : $bg = $r['is_stuck'] ? 'background:#f8d7da' : ( $r['is_looping'] ? 'background:#fff3cd' : '' ); ?>
			<tr style="<?php echo $bg; ?>">
				<td><?php echo esc_html( $r['scheduled_date_gmt'] ); ?></td>
				<td><code><?php echo esc_html( $r['hook'] ); ?></code></td>
				<td><?php echo $r['is_target'] ? '✅' : '—'; ?></td>
				<td><?php echo $this->status_badge( $r['status'] ); ?></td>
				<td><?php echo (int) ( $r['attempts'] ?? 0 ); ?></td>
				<td><?php echo esc_html( $r['last_attempt_gmt'] ?? '-' ); ?></td>
				<td style="max-width:160px;word-break:break-all"><code><?php echo esc_html( $r['args'] ); ?></code></td>
				<td><a class="button button-small" onclick="return confirm('Delete this Action Scheduler event?')" href="<?php echo $this->nonce_url( [ 'action' => 'ccp_delete_single', 'type' => 'as', 'action_id' => $r['action_id'] ] ); ?>">Delete</a></td>
			</tr>
			<?php endforeach; endif; ?>
			</tbody>
		</table>
	<?php }

	private function section_large_options( array $options ): void { ?>
		<h2 style="margin-top:28px">🗃 Large Options (>1 MB, top 10)</h2>
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
		<h2 style="margin-top:28px">📁 Large Files (>5 MB, top 20) — uploads / plugins / themes</h2>
		<table class="widefat striped">
			<thead><tr><th>Full Path</th><th>Size</th></tr></thead>
			<tbody>
			<?php if ( empty( $files ) ) : ?>
			<tr><td colspan="2">✅ No large files found.</td></tr>
			<?php else : foreach ( $files as $r ) : ?>
			<tr>
				<td style="word-break:break-all"><code><?php echo esc_html( $r['path'] ); ?></code></td>
				<td style="white-space:nowrap"><?php echo esc_html( $r['size_fmt'] ); ?></td>
			</tr>
			<?php endforeach; endif; ?>
			</tbody>
		</table>
	<?php }
}

new Cron_Cleanup_Panel();
