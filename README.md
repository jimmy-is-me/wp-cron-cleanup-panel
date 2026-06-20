# WP Cron Cleanup Panel

A lightweight WordPress MU-Plugin that adds a dedicated admin panel for inspecting and deleting WP-Cron events and Action Scheduler jobs — with memory usage tracking, CPU/RAM pressure analysis, stuck/looping job detection, large option detection, and large file scanning.

---

## Features

| Feature | Description |
|---|---|
| ⏰ WP-Cron Inspector | List WP-Cron events; can switch between target-only and all hooks |
| 📋 Action Scheduler Inspector | List Action Scheduler jobs; supports **manual single delete** and can switch between target-only and all hooks |
| 🗑 Delete All | Bulk delete **target matched jobs only** |
| 🔥 CPU / RAM Pressure Analysis | Autoload total, AS hotspots, stuck jobs, repeatedly failing jobs, WP-Cron overdue summary |
| 🗃 Large Options | Shows only options larger than **1 MB**, top 10 |
| 📁 Large Files | Scans uploads/plugins/themes for files > 5 MB, full path, top 20 |

---

## Important behavior

- **Delete All Matched Jobs** only deletes hooks matching:

```php
private array $targets = [ 'thumbpress', 'image-sizes', 'optimize_img' ];
```

- The list views can now **switch to show all hooks**, not just targets.
- Manual delete works for both **single WP-Cron** and **single Action Scheduler** rows.
- **WP-Cron Overdue** summary only shows hooks overdue for more than **7 days**.
- **Large Options** only shows rows over **1 MB**, maximum **10** entries.

---

## Installation

### MU-Plugin (Recommended)

```bash
cp cron-cleanup-panel.php /path/to/wp-content/mu-plugins/
```

If `mu-plugins` does not exist, create it first.

### Docker

```bash
docker cp cron-cleanup-panel.php orangecitytw-php-1:/var/www/html/wp-content/mu-plugins/
docker cp cron-cleanup-panel.php yilanmartcom-php-1:/var/www/html/wp-content/mu-plugins/
```

### Regular Plugin

Copy into `wp-content/plugins/cron-cleanup-panel/` and activate it from wp-admin.

---

## Usage

1. Open **Cron Cleanup** in wp-admin.
2. Use the top toggle:
   - **只看 Targets**
   - **顯示全部 Hook**
3. Review the sections:
   - Memory Usage
   - CPU / RAM Pressure Analysis
   - WP-Cron Events
   - Action Scheduler
   - Large Options
   - Large Files
4. Use row-level **Delete** to remove a single cron or Action Scheduler job.
5. Use **Delete All Related Jobs** to bulk delete only target-matched hooks.

---

## Thresholds

```php
private int $stuck_threshold = 600;      // 10 minutes
private int $cron_overdue_days = 7;      // overdue summary threshold
```

```php
private function get_large_options( int $threshold = 1048576, int $limit = 10 ): array
private function get_large_files( int $min_mb = 5, int $limit = 20 ): array
```

---

## Changelog

### v1.2.1
- Added manual single delete helper for Action Scheduler rows
- Added target/all-hooks toggle for WP-Cron and Action Scheduler list views
- WP-Cron overdue summary now only shows hooks overdue for more than 7 days
- Large Options changed to >1 MB and top 10 only
- Delete All section now clearly explains that bulk delete only affects target hooks
- Added target column to both WP-Cron and Action Scheduler tables

### v1.2.0
- Large Files limited to top 20
- WP-Cron overdue detection
- Action Scheduler stuck and looping detection
- CPU/RAM pressure analysis

---

## License

MIT
