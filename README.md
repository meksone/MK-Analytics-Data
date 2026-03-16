# MK Analytics Data

A high-performance WordPress plugin for integrating Google Analytics 4 data and remote content management.

## 📋 Overview

**MK Analytics Data** is a private WordPress plugin that provides:

- 📊 **GA4 Analytics Integration** — Syncs most-clicked articles data from Google Analytics 4
- 📥 **Remote Content Importer** — Automated import of posts from remote sites via REST API
- ⚡ **Redis-safe Caching** — Dual-layer cache (transient + DB fallback) survives Redis flushes
- 🔄 **Scheduled Syncing** — Independent cron jobs for GA4 sync and remote import
- 🌐 **REST API** — Three endpoints to expose analytics and popular posts data
- 🔐 **Endpoint Protection** — Optional HTTP Basic Auth on all REST endpoints
- 🔁 **Self-updating** — GitHub Releases-based auto-update via WordPress dashboard

---

## 🌐 REST API Endpoints

All endpoints live under `/wp-json/mk/v1/` and support optional HTTP Basic Auth (configured in plugin settings).

### `GET /wp-json/mk/v1/popular-links`

Returns a flat array of permalink URLs for the current top 10 most-viewed posts.

**Purpose:** Lightweight endpoint for widgets, sidebars, or external sites that only need the post URLs.

**Response:**
```json
[
  "https://example.com/post-title/",
  "https://example.com/another-post/",
  ...
]
```

---

### `GET /wp-json/mk/v1/popular-posts`

Returns full post data for the top 10 most-viewed posts, including content, image, and GA4 analytics.

**Purpose:** Main feed endpoint for remote sites that import posts via the Remote Content Importer. Includes all fields needed for a complete import with analytics metadata.

**Response:**
```json
[
  {
    "title": "Post Title",
    "content": "<p>Full HTML content...</p>",
    "image": "https://example.com/image.jpg",
    "date": "January 1, 2025",
    "url": "https://example.com/post-title/",
    "original_url": "",
    "analytics": {
      "views": 1240,
      "sessions": 980,
      "active_users": 860,
      "new_users": 310,
      "avg_time_seconds": 154,
      "avg_time_human": "2m 34s",
      "bounce_rate": 0.3821,
      "engagement_rate": 0.6179,
      "date_range": "30daysAgo",
      "fetched_at": "2025-01-01 12:00:00"
    }
  }
]
```

---

### `GET /wp-json/mk/v1/analytics`

Returns raw GA4 analytics data for all tracked posts with summary metadata.

**Purpose:** Analytics dashboard, reporting tools, or any consumer that needs the full metrics dataset without post content.

**Response:**
```json
{
  "status": "ok",
  "date_range": "30daysAgo",
  "fetched_at": "2025-01-01 12:00:00",
  "count": 10,
  "data": [
    {
      "post_id": 42,
      "title": "Post Title",
      "url": "https://example.com/post-title/",
      "views": 1240,
      "sessions": 980,
      "active_users": 860,
      "new_users": 310,
      "avg_time_seconds": 154,
      "avg_time_human": "2m 34s",
      "bounce_rate": 0.3821,
      "engagement_rate": 0.6179,
      "date_range": "30daysAgo",
      "fetched_at": "2025-01-01 12:00:00"
    }
  ]
}
```

---

### Authentication

When endpoint protection is enabled in plugin settings, all three endpoints require credentials.

**Option A — HTTP Basic Auth header:**
```
Authorization: Basic base64(username:password)
```

**Option B — Query string:**
```
/wp-json/mk/v1/popular-posts?mk_user=USERNAME&mk_pass=PASSWORD
```

---

## 🚀 Installation

1. Upload the plugin folder to:
   ```
   wp-content/plugins/mk-analytics-data/
   ```

2. Activate through the WordPress admin dashboard.

3. Install Composer dependencies:
   ```bash
   composer install
   ```

4. Configure under **Tools → MK Analytics**.

---

## ⚙️ Configuration

### Settings (Tools → MK Analytics)

| Setting | Option Key | Description |
|---------|-----------|-------------|
| GA4 Property ID | `mk_ga4_property_id` | Numeric GA4 property ID |
| GA4 Date Range | `mk_ga4_date_range` | `1daysAgo` / `7daysAgo` / `14daysAgo` / `30daysAgo` |
| GA4 Credentials | `mk_ga4_credentials_json` | Service Account JSON (or `credentials.json` file) |
| Operation Mode | `mk_operation_mode` | `ga4_only` / `import_only` / `both` |
| GA4 Cron Interval | `mk_cron_interval_hours` | Hours between GA4 syncs (1–168) |
| Import Cron Interval | `mk_import_cron_interval_hours` | Hours between remote imports (1–168) |
| Remote Sources | `mk_remote_sources` | Array of remote endpoint URLs with post type and auth |
| API Auth | `mk_api_auth` | `enabled`, `username`, `password` for endpoint protection |
| Debug Mode | `mk_debug_enabled` | Enables operation logging |

### Credentials

**Option A (file):** Place `credentials.json` (Google Service Account) in the plugin folder:
```
wp-content/plugins/mk-analytics-data/credentials.json
```

**Option B (database):** Paste the JSON content in the **Configurazione** tab. The file takes priority if both are present.

---

## 📦 Dependencies

- **PHP 8.3** (mandatory — the Google Analytics Data PHP Client Library requires PHP 8.3 or higher)
- WordPress 5.0+
- Google Analytics Data PHP Client Library (`google/analytics-data`)

---

## 🔄 How It Works

1. **GA4 Sync** — Connects to the Google Analytics Data API and fetches the top 50 page paths by views for the configured date range. Resolves paths to post IDs and stores the top 10.
2. **Data Storage** — Analytics metrics are saved per-post as post meta (`_mk_ga4_*`) and in a single `wp_options` store (`mk_ga4_analytics_store`).
3. **Cache Layer** — Popular post IDs are cached in a transient (fast path) and in `wp_options` (DB fallback). If Redis flushes, the DB fallback re-warms the transient automatically.
4. **Remote Import** — Fetches `/wp-json/mk/v1/popular-posts` from configured remote sites and creates local posts with all analytics metadata.
5. **REST API** — Exposes the three endpoints above for consumption by other sites or tools.
6. **Self-update** — Checks `github.com/meksone/MK-Analytics-Data/releases/latest` every 12 hours and surfaces updates through the standard WordPress update flow.

---

## 🔐 Security

- Google API auth via Service Account credentials (file or database)
- Optional HTTP Basic Auth on all REST endpoints
- Nonce validation on all admin AJAX actions
- Capability checks (`manage_options`) on all admin operations
- Sanitised and validated inputs throughout

---

## 📝 Version History

| Version | Notes |
|---------|-------|
| **3.5.9** | Fixed `fix_source_dir` to use file-presence detection instead of `hook_extra`; fixed GitHub ZIP download URL to use direct archive link instead of API `zipball_url` |
| **3.5.8** | Added `MK_GITHUB_USER`, `MK_GITHUB_REPO`, `MK_PLUGIN_SLUG`, `MK_PLUGIN_VERSION` constants; added `MK_GitHub_Updater` class for GitHub Releases-based self-update |
| **3.5.7** | Added AJAX handler `mk_save_date_range` to auto-persist GA4 date range on radio change without requiring manual form save |
| **3.5.6** | Fixed Composer autoloader loading path |
| **3.5.5** | Version bump |
| **3.5.4** | Version bump |
| **3.5** | Initial stable release — full GA4 integration, remote content importer, dual-layer cache, cron system, debug log, dashboard widget, REST API with optional auth |

---

## 📄 License

This plugin is licensed under the **GNU General Public License v2 or later** (GPL-2.0+), the same license as WordPress itself.

You are free to use, study, modify, and distribute this software under the terms of the GPL. A copy of the license is available at:
https://www.gnu.org/licenses/gpl-2.0.html
