# WP Cron Cleanup Panel

A lightweight WordPress MU-Plugin that adds a dedicated admin panel for inspecting and deleting orphaned WP-Cron events and Action Scheduler jobs — with memory usage tracking, CPU/RAM pressure analysis, stuck/looping job detection, large option detection, and large file scanning.

> Originally built to solve a **ThumbPress / image-sizes runaway cron loop** causing 130%+ CPU on a multi-site Docker server.

---

## Features

| Feature | Description |
|---|---|
| ⏰ WP-Cron Inspector | List and delete matched cron events; highlights overdue/stuck jobs |
| 📋 Action Scheduler Inspector | List and delete matched AS jobs; shows stuck and looping status |
| 🔥 CPU / RAM Pressure Analysis | Autoload option total size, AS hotspots by hook, stuck jobs, repeatedly failing jobs, WP-Cron overdue summary |
| 📊 Memory Usage | Current, peak, PHP limit with visual bar |
| 🗑 Delete All | One-click delete all matched jobs across both systems |
| 🗃 Large Options | Lists `wp_options` rows larger than 10 KB |
| 📁 Large Files | Scans uploads/plugins/themes for files > 5 MB — shows **full server path**, top 20 only |

---

## Installation

### Method 1: MU-Plugin (Recommended)

```bash
cp cron-cleanup-panel.php /path/to/wp-content/mu-plugins/
```

If `mu-plugins` doesn't exist, create it. The panel activates automatically — no activation step needed.

### Method 2: Docker Container

```bash
docker cp cron-cleanup-panel.php orangecitytw-php-1:/var/www/html/wp-content/mu-plugins/
# Repeat for other sites
docker cp cron-cleanup-panel.php yilanmartcom-php-1:/var/www/html/wp-content/mu-plugins/
```

### Method 3: Regular Plugin

Copy to `wp-content/plugins/cron-cleanup-panel/` and activate via **Plugins > Installed Plugins**.

---

## Usage

1. Log in to **WordPress Admin**.
2. Click **Cron Cleanup** in the left sidebar.
3. Review sections top-to-bottom:
   - **Memory Usage** — visual bar shows current PHP memory pressure.
   - **CPU / RAM Pressure Analysis** — autoload bloat, AS hotspots, stuck jobs, failing jobs, overdue WP-Cron.
   - **WP-Cron Events** — matched events with overdue indicator (yellow = stuck).
   - **Action Scheduler** — matched events with status badges; red = stuck, yellow = looping.
   - **Large Options** — garbage data in `wp_options`.
   - **Large Files** — top 20 heaviest files with full path.
4. Use **Delete All Related Jobs** to bulk-remove, or delete individual rows inline.

---

## Configuration

**Target hook keywords** — edit to match your use case:

```php
private array $targets = [ 'thumbpress', 'image-sizes', 'optimize_img' ];
```

**Stuck threshold** — jobs running longer than this (seconds) are flagged as stuck:

```php
private int $stuck_threshold = 600; // 10 minutes
```

**Large file threshold / limit:**

```php
private function get_large_files( int $min_mb = 5, int $limit = 20 ): array
```

**Large option threshold:**

```php
private function get_large_options( int $threshold = 10240 ): array // 10 KB
```

---

## Color Legend

| Color | Meaning |
|---|---|
| 🔴 Red row | Stuck job (in-progress > 10 min) |
| 🟡 Yellow row | Looping / overdue job |
| 🟢 Green | OK |
| Status badge | `pending` / `in-progress` / `complete` / `failed` / `canceled` |

---

## Security

- Only accessible to `manage_options` capability (Administrators).
- All delete actions protected by WordPress nonces.
- No data exposed publicly.

---

## Compatibility

| Item | Requirement |
|---|---|
| WordPress | 6.0+ |
| PHP | 8.0+ |
| Action Scheduler | Optional (auto-detected) |
| WooCommerce | Not required |

---

## Changelog

### v1.2.0
- Large Files limited to top 20
- WP-Cron overdue detection (highlights jobs stuck past threshold)
- Action Scheduler stuck (in-progress > 10 min) and looping (attempts > 3) detection
- CPU/RAM Pressure section: autoload total, AS hotspots aggregated by hook, stuck jobs, repeatedly failing jobs, WP-Cron overdue summary
- Memory bar visualization
- Status badges for AS events

### v1.1.0
- Initial release with cron cleanup, large option/file scanning, memory display

---

## Background

Built after diagnosing a production server where:

- `orangecitytw-php-1` → **134% CPU**
- `yilanmartcom-php-1` → **136% CPU**
- MySQL → **131% CPU** (dragged by PHP workers)

Root cause: **ThumbPress `thumbpress_optimize_img` hooks stacking up** in both WP-Cron and Action Scheduler, with 45+ orphaned events that couldn't be deleted from the standard Scheduled Actions UI.

---

## License

MIT — free to use, modify, and distribute.
