# WP Cron Cleanup Panel

A lightweight WordPress MU-Plugin that adds a dedicated admin panel for inspecting and deleting orphaned WP-Cron events and Action Scheduler jobs — with bonus memory usage tracking, large option detection, and large file scanning.

> Originally built to solve a **ThumbPress / image-sizes runaway cron loop** causing 130%+ CPU on a multi-site Docker server.

---

## Features

| Feature | Description |
|---|---|
| ⏰ WP-Cron Inspector | List and delete orphaned cron events by hook name |
| 📋 Action Scheduler Inspector | List and delete pending/running AS jobs by hook |
| 🗑 Delete All | One-click delete all matched jobs across both systems |
| 📊 Memory Usage | Current, peak, and PHP memory limit |
| 🗃 Large Options | Lists `wp_options` rows larger than 10 KB |
| 📁 Large Files | Scans uploads, plugins, themes for files > 5 MB with full path |

---

## Installation

### Method 1: MU-Plugin (Recommended)

1. Copy `cron-cleanup-panel.php` to your `wp-content/mu-plugins/` folder.
2. If the folder doesn't exist, create it.
3. The panel activates automatically — no activation step needed.

```bash
cp cron-cleanup-panel.php /path/to/wp-content/mu-plugins/
```

### Method 2: Docker Container

```bash
docker cp cron-cleanup-panel.php orangecitytw-php-1:/var/www/html/wp-content/mu-plugins/
```

### Method 3: Regular Plugin

1. Copy `cron-cleanup-panel.php` to `wp-content/plugins/cron-cleanup-panel/`.
2. Activate via **Plugins > Installed Plugins**.

---

## Usage

1. Log in to **WordPress Admin**.
2. Click **Cron Cleanup** in the left sidebar.
3. The panel shows:
   - Current PHP memory usage.
   - All matching WP-Cron events (by target hook keywords).
   - All matching Action Scheduler jobs.
   - Large `wp_options` rows (garbage data indicator).
   - Large files in uploads / plugins / themes with full server paths.
4. Use **Delete All Related Jobs** to bulk-remove, or delete individual rows.

---

## Configuration

The target hook keywords are defined at the top of the class. Edit them to match your use case:

```php
private array $targets = [ 'thumbpress', 'image-sizes', 'optimize_img' ];
```

To scan different directories or change the large file threshold, edit `get_large_files()`:

```php
private function get_large_files( int $min_mb = 5, int $limit = 30 ): array {
```

To change the large option threshold (default 10 KB), edit `get_large_options()`:

```php
private function get_large_options( int $threshold = 10240 ): array {
```

---

## Security

- Only accessible to users with `manage_options` capability (Administrators).
- All delete actions are protected by WordPress nonces (`wp_nonce_url` / `check_admin_referer`).
- No data is exposed publicly — the panel is only visible in `wp-admin`.

---

## Compatibility

| Item | Requirement |
|---|---|
| WordPress | 6.0+ |
| PHP | 8.0+ |
| Action Scheduler | Optional (auto-detected) |
| WooCommerce | Not required |

---

## Background

This tool was built after diagnosing a production server where:

- `orangecitytw-php-1` was hitting **134% CPU**
- `yilanmartcom-php-1` was hitting **136% CPU**
- MySQL was at **131% CPU** being dragged by PHP workers
- Root cause: **ThumbPress `thumbpress_optimize_img` hooks stacking up** in both WP-Cron and Action Scheduler, with 45+ orphaned events that couldn't be deleted from the standard Scheduled Actions UI

Standard approaches (WP Crontrol, Action Scheduler UI) couldn't handle the volume. This panel provides direct database-level cleanup with a safe, nonce-protected interface.

---

## License

MIT — free to use, modify, and distribute.
