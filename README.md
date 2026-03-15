# MK Analytics Data

A high-performance WordPress plugin for integrating Google Analytics 4 data and remote content management.

## 📋 Overview

**MK Analytics Data** is a WordPress plugin that provides:

- 🔍 **GA4 Analytics Integration** - High-performance syncing of most-clicked articles data from Google Analytics 4
- 📥 **Remote Content Importer** - Automated import of remote content into WordPress
- ⚡ **Performance Optimized** - Built-in caching, transients, and efficient data synchronization
- 🔄 **Scheduled Syncing** - Configurable cron jobs for automatic data updates
- 🐛 **Debug & Logging System** - Comprehensive logging for monitoring and troubleshooting

## ✨ Features

### Google Analytics 4 Integration
- Direct integration with Google Analytics Data API
- Automatic fetching of page view and interaction data
- Per-post analytics storage
- Configurable date range for data retrieval
- Performance-optimized queries

### Remote Content Importer
- Automated remote content synchronization
- Configurable import intervals via WordPress cron
- Seamless content updates

### Caching & Performance
- Multi-layer caching strategy using WordPress object cache
- Database-level persistence as fallback
- Transient-based temporary storage
- Optimized for high-traffic sites

### Debug & Monitoring
- Built-in debug mode
- Comprehensive logging system
- Database-stored logs with configurable retention
- Log levels: INFO, OK, WARN, ERROR

### API Security
- Endpoint protection settings
- API authentication configuration
- Operation mode controls

## 🚀 Installation

1. Upload the plugin to your WordPress installation:
   ```
   wp-content/plugins/mk-analytics-data/
   ```

2. Activate the plugin through the WordPress admin dashboard

3. Install dependencies:
   ```bash
   composer install
   ```

4. Configure Google Analytics API credentials through plugin settings

## ⚙️ Configuration

### Available Options

| Setting | Option Key | Description |
|---------|-----------|-------------|
| GA4 Cron Interval | `mk_cron_interval_hours` | Hours between GA4 data syncs |
| Import Cron Interval | `mk_import_cron_interval_hours` | Hours between content imports |
| GA4 Date Range | `mk_ga4_date_range` | Date range for analytics queries |
| Operation Mode | `mk_operation_mode` | Plugin operating mode |
| Debug Mode | `mk_debug_enabled` | Enable/disable debug logging |
| API Auth | `mk_api_auth` | API authentication settings |

### Debug Mode

Enable debug mode to start logging:
```php
update_option( 'mk_debug_enabled', true );
```

View logs in the WordPress database in the `wp_options` table under `mk_debug_log`.

## 📦 Dependencies

- PHP 7.4+
- WordPress 5.0+
- Google Analytics Data PHP Client Library (^0.23.2)
  - Includes support for Firebase JWT authentication
  - gRPC bindings for API communication

## 🔄 How It Works

1. **GA4 Sync** - Plugin connects to Google Analytics API and fetches most-clicked articles
2. **Data Storage** - Analytics data is stored per-post for quick retrieval
3. **Caching Layer** - Data is cached using WordPress transients with database fallback
4. **Remote Import** - Scheduled cron job imports remote content on defined intervals
5. **Logging** - All operations are logged for debugging and monitoring

## 🔐 Security

- API authentication handled through Google Cloud credentials
- Endpoint protection with configurable auth settings
- Proper capability checks within WordPress admin
- Sanitized and validated data throughout

## 🛠 Development

### Debugging

Enable debug mode and monitor logs:
```php
mk_log( 'GA4_FETCH', 'INFO', 'Syncing analytics data', [] );
```

### Hooks & Filters

The plugin provides WordPress cron hooks for integration:
- `mk_ga4_cron_sync` - GA4 data synchronization
- `mk_import_cron_run` - Remote content import

## 📝 Version History

- **v3.5** (Current) - Stable release with full GA4 integration and content importer

## 📄 License

Please refer to the LICENSE file for license information.

## 🤝 Contributing

Contributions are welcome! Please submit issues and pull requests.

## 📞 Support

For issues specific to this plugin, please check WordPress plugin documentation and Google Analytics Data API documentation.

---

Built with ❤️ for meksone.com
