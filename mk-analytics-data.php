<?php
/**
 * Plugin Name: MK Analytics Data
 * Description: High-performance GA4 most-clicked articles + Remote Content Importer
 * Version: 3.5.3
 */

if ( ! defined( 'ABSPATH' ) ) exit;

// ─────────────────────────────────────────────
// CONSTANTS
// ─────────────────────────────────────────────
define( 'MK_CRON_HOOK',         'mk_ga4_cron_sync' );        // GA4 sync cron hook
define( 'MK_CRON_OPTION',        'mk_cron_interval_hours' );   // GA4 sync interval (hours)
define( 'MK_IMPORT_CRON_HOOK',   'mk_import_cron_run' );       // Remote import cron hook
define( 'MK_IMPORT_CRON_OPTION', 'mk_import_cron_interval_hours' ); // Import interval (hours)
define( 'MK_DEBUG_OPTION', 'mk_debug_enabled' );
define( 'MK_LOG_OPTION',   'mk_debug_log' );
define( 'MK_LOG_MAX',      200 );   // max log entries kept in DB
define( 'MK_CACHE_KEY',        'mk_popular_post_ids' );         // object-cache transient key
define( 'MK_CACHE_DB_OPTION',  'mk_popular_post_ids_store' );   // wp_options persistent fallback
define( 'MK_ANALYTICS_OPTION', 'mk_ga4_analytics_store' );      // per-post analytics data store
define( 'MK_DATE_RANGE_OPT',   'mk_ga4_date_range' );           // GA4 date range option
define( 'MK_OP_MODE_OPT',      'mk_operation_mode' );           // operation mode option
define( 'MK_API_AUTH_OPT',     'mk_api_auth' );                 // endpoint protection settings
define( 'MK_GITHUB_USER',    'meksone' );                         // GitHub username/org
define( 'MK_GITHUB_REPO',    'https://github.com/meksone/MK-Analytics-Data' );  // GitHub repository name
define( 'MK_PLUGIN_SLUG',    'mk-analytics-data/mk-analytics-data.php' ); // WP plugin slug
define( 'MK_PLUGIN_VERSION', '3.5.3' );                         // Must match the Version header above

// 1. Load Composer Autoloader
$mk_autoload = plugin_dir_path( __FILE__ ) . 'vendor/autoload.php';
if ( file_exists( $mk_autoload ) ) {
    require_once $mk_autoload;
}

// ─────────────────────────────────────────────
// 2. DEBUG / LOG SYSTEM
// ─────────────────────────────────────────────

/** Is debug mode currently on? */
function mk_debug_on() {
    return (bool) get_option( MK_DEBUG_OPTION, false );
}

/**
 * Append a log entry (only when debug is enabled).
 *
 * @param string $context  Short label, e.g. 'GA4_FETCH', 'TRANSIENT', 'CRON'
 * @param string $level    'INFO' | 'OK' | 'WARN' | 'ERROR'
 * @param string $message  Human-readable message
 * @param mixed  $data     Optional extra data (will be json-encoded)
 */
function mk_log( $context, $level, $message, $data = null ) {
    if ( ! mk_debug_on() ) return;

    $log = get_option( MK_LOG_OPTION, array() );
    if ( ! is_array($log) ) $log = array();

    $entry = array(
        'ts'      => current_time('Y-m-d H:i:s'),
        'context' => strtoupper($context),
        'level'   => strtoupper($level),
        'msg'     => $message,
    );
    if ( $data !== null ) {
        $entry['data'] = is_string($data) ? $data : wp_json_encode($data);
    }

    array_unshift( $log, $entry );                          // newest first
    $log = array_slice( $log, 0, MK_LOG_MAX );              // trim to max
    update_option( MK_LOG_OPTION, $log, false );            // autoload=false
}

/** Erase the entire log */
function mk_log_clear() {
    update_option( MK_LOG_OPTION, array(), false );
}


// ─────────────────────────────────────────────
// 2b. REDIS-SAFE PERSISTENT CACHE LAYER
//
// Problem: when a persistent object cache (Redis/Memcached) is active,
// set_transient() stores data ONLY in Redis — not in wp_options.
// If Redis flushes or restarts the data is gone, and the plugin
// shows an empty list with no obvious error.
//
// Solution: always write to wp_options as the source of truth,
// then layer a short-lived transient on top as a fast read path.
// Reads check the transient first (fast); if missing, fall back to
// the DB option and re-warm the transient automatically.
// ─────────────────────────────────────────────

/**
 * Save the popular post IDs.
 * Writes to BOTH wp_options (persistent) and a transient (fast path).
 *
 * @param array $ids        Array of post IDs.
 * @param int   $ttl_hours  Cache lifetime in hours (used for the transient).
 */
function mk_cache_set( array $ids, int $ttl_hours = 12 ) {
    $ttl = $ttl_hours * HOUR_IN_SECONDS;

    // Always persist to the database — survives Redis flushes/restarts.
    $db_ok = update_option( MK_CACHE_DB_OPTION, array(
        'ids'     => $ids,
        'expires' => time() + $ttl,
        'saved'   => current_time('Y-m-d H:i:s'),
    ), false ); // autoload = false

    // Also set a transient as a fast-read layer (may go to Redis — that's fine).
    $transient_ok = set_transient( MK_CACHE_KEY, $ids, $ttl );

    mk_log( 'CACHE_SET', $db_ok ? 'OK' : 'ERROR',
        'Scrittura cache completata.',
        array(
            'post_count'       => count($ids),
            'ttl_hours'        => $ttl_hours,
            'db_option_ok'     => $db_ok,
            'transient_ok'     => $transient_ok,
            'using_ext_cache'  => wp_using_ext_object_cache(),
        )
    );

    return $db_ok; // DB write is the authoritative result
}

/**
 * Read the popular post IDs.
 * Fast path: transient (Redis/Memcached if available).
 * Fallback: wp_options DB record (always available), auto-rewarms transient.
 *
 * @return array|false  Array of post IDs, or false if nothing is stored / expired.
 */
function mk_cache_get() {
    // Fast path — try transient first.
    $from_transient = get_transient( MK_CACHE_KEY );
    if ( $from_transient !== false ) {
        mk_log( 'CACHE_GET', 'INFO', 'Hit: transient (fast path).', array('post_count' => count($from_transient)) );
        return $from_transient;
    }

    // Fallback — read from wp_options.
    $store = get_option( MK_CACHE_DB_OPTION, false );
    if ( ! $store || empty($store['ids']) || empty($store['expires']) ) {
        mk_log( 'CACHE_GET', 'WARN', 'Miss: né transient né DB option trovati.' );
        return false;
    }

    if ( time() > (int) $store['expires'] ) {
        mk_log( 'CACHE_GET', 'WARN', 'Miss: DB option trovato ma scaduto.',
            array('expired_at' => date('Y-m-d H:i:s', $store['expires'])) );
        return false;
    }

    $ids = $store['ids'];
    // Re-warm the transient so the next read hits the fast path.
    $remaining_ttl = (int) $store['expires'] - time();
    set_transient( MK_CACHE_KEY, $ids, $remaining_ttl );

    mk_log( 'CACHE_GET', 'WARN',
        'Fallback: dati recuperati da DB option (transient mancante, probabilmente Redis flush). Transient riscritto.',
        array(
            'post_count'    => count($ids),
            'remaining_ttl' => $remaining_ttl . 's',
            'saved_at'      => $store['saved'] ?? 'n/a',
        )
    );

    return $ids;
}

/**
 * Delete the popular post IDs from both layers.
 */
function mk_cache_delete() {
    delete_transient( MK_CACHE_KEY );
    delete_option( MK_CACHE_DB_OPTION );
    mk_log( 'CACHE_DELETE', 'INFO', 'Cache svuotata da entrambi i layer (transient + DB option).' );
}

// ─────────────────────────────────────────────
// 3. CRON SYSTEM
// ─────────────────────────────────────────────
add_filter( 'cron_schedules', function( $schedules ) {
    // GA4 sync schedule
    $hours_ga4 = max( 1, (int) get_option( MK_CRON_OPTION, 12 ) );
    $schedules['mk_custom_hours'] = array(
        'interval' => $hours_ga4 * HOUR_IN_SECONDS,
        'display'  => sprintf( 'Ogni %d ore (MK GA4 Sync)', $hours_ga4 ),
    );
    // Remote import schedule
    $hours_imp = max( 1, (int) get_option( MK_IMPORT_CRON_OPTION, 24 ) );
    $schedules['mk_import_hours'] = array(
        'interval' => $hours_imp * HOUR_IN_SECONDS,
        'display'  => sprintf( 'Ogni %d ore (MK Import)', $hours_imp ),
    );
    return $schedules;
} );

// GA4 cron — skips if mode is import_only
add_action( MK_CRON_HOOK, function() {
    if ( get_option( MK_OP_MODE_OPT, 'both' ) !== 'import_only' ) {
        mk_fetch_ga4_top_posts();
    }
} );
// Import cron — skips if mode is ga4_only
add_action( MK_IMPORT_CRON_HOOK, function() {
    if ( get_option( MK_OP_MODE_OPT, 'both' ) !== 'ga4_only' ) {
        mk_import_remote_content();
    }
} );

function mk_schedule_cron() {
    mk_unschedule_cron();
    $ok = wp_schedule_event( time(), 'mk_custom_hours', MK_CRON_HOOK );
    mk_log( 'CRON', $ok !== false ? 'OK' : 'ERROR',
        $ok !== false ? 'Cron job pianificato.' : 'wp_schedule_event() ha restituito false.',
        array( 'interval_h' => get_option(MK_CRON_OPTION, 12) )
    );
}

function mk_unschedule_cron() {
    wp_clear_scheduled_hook( MK_CRON_HOOK );
    mk_log( 'CRON', 'INFO', 'Cron job rimosso dalla coda WP-Cron.' );
}

function mk_cron_status() {
    $next = wp_next_scheduled( MK_CRON_HOOK );
    return array(
        'active'     => (bool) $next,
        'next_ts'    => $next,
        'next_human' => $next ? human_time_diff( time(), $next ) : null,
        'interval_h' => (int) get_option( MK_CRON_OPTION, 12 ),
    );
}

// ── Import cron helpers ──────────────────────────────────────────────────────
function mk_schedule_import_cron() {
    mk_unschedule_import_cron();
    $ok = wp_schedule_event( time(), 'mk_import_hours', MK_IMPORT_CRON_HOOK );
    mk_log( 'IMPORT_CRON', $ok !== false ? 'OK' : 'ERROR',
        $ok !== false ? 'Import cron pianificato.' : 'wp_schedule_event() ha restituito false.',
        array( 'interval_h' => get_option( MK_IMPORT_CRON_OPTION, 24 ) )
    );
}

function mk_unschedule_import_cron() {
    wp_clear_scheduled_hook( MK_IMPORT_CRON_HOOK );
    mk_log( 'IMPORT_CRON', 'INFO', 'Import cron rimosso dalla coda WP-Cron.' );
}

function mk_import_cron_status() {
    $next = wp_next_scheduled( MK_IMPORT_CRON_HOOK );
    return array(
        'active'     => (bool) $next,
        'next_ts'    => $next,
        'next_human' => $next ? human_time_diff( time(), $next ) : null,
        'interval_h' => (int) get_option( MK_IMPORT_CRON_OPTION, 24 ),
    );
}

// ─────────────────────────────────────────────
// 4. SETTINGS REGISTRATION
// ─────────────────────────────────────────────
add_action( 'admin_menu', function() {
    add_management_page(
        'MK Analytics Settings',
        'MK Analytics',
        'manage_options',
        'mk-analytics-settings',
        'mk_analytics_settings_page_html'
    );
});

add_action( 'admin_init', function() {
    // Group 1: main configuration — GA4 ID, credentials, remote sources
    register_setting( 'mk_analytics_options', 'mk_ga4_property_id' );
    register_setting( 'mk_analytics_options', 'mk_remote_sources', array(
        'sanitize_callback' => function( $raw ) {
            if ( ! is_array($raw) ) return array();
            $clean = array();
            foreach ( $raw as $item ) {
                $url = isset($item['url']) ? esc_url_raw( trim($item['url']) ) : '';
                if ( empty($url) ) continue;  // drop empty rows
                $clean[] = array(
                    'url'       => $url,
                    'post_type' => ! empty($item['post_type']) ? sanitize_key($item['post_type']) : 'post',
                    'cat'       => isset($item['cat']) ? absint($item['cat']) : 0,
                    'username'  => sanitize_text_field( $item['username'] ?? '' ),
                    'password'  => sanitize_text_field( $item['password'] ?? '' ),
                );
            }
            return array_values( $clean );  // reindex 0,1,2,...
        },
    ) );
    register_setting( 'mk_analytics_options', 'mk_ga4_credentials_json' );
    // Group 1c: operation mode — isolated so auto-submit never touches other settings
    register_setting( 'mk_analytics_mode', MK_OP_MODE_OPT, array(
        'sanitize_callback' => function( $v ) {
            return in_array( $v, array('ga4_only','import_only','both'), true ) ? $v : 'both';
        },
    ) );

    register_setting( 'mk_analytics_options', MK_API_AUTH_OPT, array(
        'sanitize_callback' => function( $v ) {
            return array(
                'enabled'  => ! empty($v['enabled']) ? 1 : 0,
                'username' => sanitize_text_field( $v['username'] ?? '' ),
                'password' => sanitize_text_field( $v['password'] ?? '' ),
            );
        },
    ) );

    // Group 1b: GA4 date range and operation mode (part of main config form)
    register_setting( 'mk_analytics_options', MK_DATE_RANGE_OPT, array(
        'sanitize_callback' => function( $v ) {
            return in_array( $v, array('1daysAgo','7daysAgo','14daysAgo','30daysAgo'), true ) ? $v : '30daysAgo';
        },
    ) );

    // Group 2: cron interval — isolated so saving it never touches group 1
    register_setting( 'mk_analytics_cron', MK_CRON_OPTION, array(
        'sanitize_callback' => function( $val ) {
            $val = (int) $val;
            return ( $val >= 1 && $val <= 168 ) ? $val : 12;
        },
    ) );

    // Group 3: import cron interval
    register_setting( 'mk_analytics_import', MK_IMPORT_CRON_OPTION, array(
        'sanitize_callback' => function( $val ) {
            $val = (int) $val;
            return ( $val >= 1 && $val <= 168 ) ? $val : 24;
        },
    ) );

    // Group 4: debug toggle — isolated so saving it never touches other groups
    register_setting( 'mk_analytics_debug', MK_DEBUG_OPTION, array(
        'sanitize_callback' => function( $val ) { return $val ? 1 : 0; },
    ) );
} );

// ─────────────────────────────────────────────
// 5. SETTINGS PAGE HTML
// ─────────────────────────────────────────────
function mk_analytics_settings_page_html() {
    $sources          = get_option( 'mk_remote_sources', array() );
    $credentials_json = get_option( 'mk_ga4_credentials_json', '' );
    $credentials_file = plugin_dir_path( __FILE__ ) . 'credentials.json';
    $has_file_creds   = file_exists( $credentials_file );
    $has_option_creds = ! empty( $credentials_json );

    $cron           = mk_cron_status();
    $cron_import    = mk_import_cron_status();
    $transient_data = mk_cache_get();
    $has_transient  = ( $transient_data !== false );

    $debug_on       = mk_debug_on();
    $log            = get_option( MK_LOG_OPTION, array() );

    // Level → colour map for log table
    $level_colors = array(
        'OK'    => '#46b450',
        'INFO'  => '#0073aa',
        'WARN'  => '#ffb900',
        'ERROR' => '#dc3232',
    );
    ?>
    <div class="wrap">
        <h1>MK Analytics &amp; Content Importer</h1>

        <style>
        .mk-panel {
            background:#fff;
            border:1px solid #ddd;
            border-radius:8px;
            padding:20px 24px;
            margin-bottom:20px;
        }
        .mk-panel h2.mk-panel-title {
            font-size:14px;font-weight:700;text-transform:uppercase;
            letter-spacing:.05em;color:#888;margin:0 0 16px;padding:0 0 10px;
            border-bottom:1px solid #f0f0f0;
        }
        .mk-action-cards {
            display:flex;gap:16px;flex-wrap:wrap;margin-top:4px;
        }
        .mk-action-card {
            flex:1;min-width:200px;
            background:#f9f9f9;border:1px solid #e5e5e5;border-radius:8px;
            padding:16px 18px;display:flex;flex-direction:column;gap:10px;
        }
        .mk-action-card.mk-action-danger { border-left:3px solid #dc3232; }
        .mk-action-card strong { font-size:13px; }
        .mk-action-card p { margin:0;color:#555;font-size:13px;line-height:1.5; }
        .mk-cred-toggle { cursor:pointer;color:#0073aa;font-size:12px;text-decoration:underline;background:none;border:none;padding:0; }
        .mk-cron-cards { display:flex;gap:20px;flex-wrap:wrap;align-items:flex-start; }
        .mk-cron-card {
            flex:1;min-width:300px;max-width:500px;
            background:#fff;border:1px solid #ddd;border-radius:8px;padding:20px;
        }
        </style>

        <div class="nav-tab-wrapper">
            <a href="#settings" class="nav-tab nav-tab-active">Configurazione</a>
            <a href="#cron" class="nav-tab">
                Cron Job
                <span style="display:inline-block;width:9px;height:9px;border-radius:50%;
                    background:<?php echo $cron['active'] ? '#46b450' : '#dc3232'; ?>;
                    margin-left:5px;vertical-align:middle;"
                    title="GA4 Sync: <?php echo $cron['active'] ? 'Attivo' : 'Inattivo'; ?>"></span>
                <span style="display:inline-block;width:9px;height:9px;border-radius:50%;
                    background:<?php echo $cron_import['active'] ? '#46b450' : '#dc3232'; ?>;
                    margin-left:2px;vertical-align:middle;"
                    title="Import: <?php echo $cron_import['active'] ? 'Attivo' : 'Inattivo'; ?>"></span>
            </a>
            <a href="#debug" class="nav-tab">
                Debug &amp; Log
                <?php if ( $debug_on ) : ?>
                <span style="display:inline-block;background:#ffb900;color:#000;font-size:10px;
                    font-weight:700;padding:1px 5px;border-radius:3px;margin-left:4px;vertical-align:middle;">ON</span>
                <?php endif; ?>
            </a>
            <a href="#guide" class="nav-tab">Guida GCP</a>
        </div>

        <!-- ══════════════════════════════════
             TAB: CONFIGURAZIONE
        ══════════════════════════════════ -->
        <div id="mk-tab-settings" class="mk-tab-content">

            <!-- PANEL: OPERATION MODE — standalone form, auto-saves on change -->
            <?php $op_mode = get_option( MK_OP_MODE_OPT, 'both' ); ?>
            <form action="options.php" method="post" id="mk-mode-form">
                <?php settings_fields( 'mk_analytics_mode' ); ?>
                <div class="mk-panel" style="margin-bottom:16px;">
                    <h2 class="mk-panel-title">&#9881; Modalità Operativa</h2>
                    <div style="display:flex;align-items:center;gap:16px;flex-wrap:wrap;">
                        <div style="display:flex;gap:0;border:1px solid #ddd;border-radius:6px;overflow:hidden;">
                            <?php
                            $modes = array(
                                'ga4_only'    => array('icon'=>'&#128200;', 'label'=>'Solo GA4',     'title'=>'Sincronizza solo i dati GA4, non importa da sorgenti remote'),
                                'import_only' => array('icon'=>'&#128256;', 'label'=>'Solo Import',  'title'=>'Importa solo da sorgenti remote, non tocca GA4'),
                                'both'        => array('icon'=>'&#9881;',   'label'=>'Entrambi',     'title'=>'Esegui sia GA4 sync che importazione remota'),
                            );
                            foreach ( $modes as $val => $cfg ) :
                                $active = $op_mode === $val;
                            ?>
                            <label id="mk-mode-label-<?php echo $val; ?>"
                                   title="<?php echo esc_attr($cfg['title']); ?>"
                                   style="display:flex;align-items:center;gap:6px;padding:9px 16px;cursor:pointer;
                                          font-size:13px;font-weight:<?php echo $active ? '700' : '400'; ?>;
                                          background:<?php echo $active ? '#0073aa' : '#f9f9f9'; ?>;
                                          color:<?php echo $active ? '#fff' : '#555'; ?>;
                                          border-right:1px solid #ddd;white-space:nowrap;transition:background .15s,color .15s;">
                                <input type="radio" name="<?php echo MK_OP_MODE_OPT; ?>"
                                       value="<?php echo esc_attr($val); ?>"
                                       <?php checked($op_mode, $val); ?>
                                       style="display:none;">
                                <?php echo $cfg['icon']; ?> <?php echo esc_html($cfg['label']); ?>
                            </label>
                            <?php endforeach; ?>
                        </div>
                        <p class="description" style="margin:0;font-size:12px;">
                            <?php
                            $mode_descs = array(
                                'ga4_only'    => 'Mostra solo impostazioni GA4. Import remoto disabilitato.',
                                'import_only' => 'Mostra solo sorgenti remote. GA4 disabilitato.',
                                'both'        => 'Tutte le funzionalità attive.',
                            );
                            echo esc_html( $mode_descs[$op_mode] ?? '' );
                            ?>
                        </p>
                    </div>
                </div>
            </form>

            <form action="options.php" method="post">
                <?php settings_fields( 'mk_analytics_options' ); ?>

                <!-- PANEL: GA4 -->
                <div class="mk-panel" id="mk-panel-ga4">
                    <h2 class="mk-panel-title">&#128200; GA4 Esportazione</h2>
                    <table class="form-table" style="margin:0;">
                        <tr valign="top">
                            <th scope="row" style="width:200px;">GA4 Property ID</th>
                            <td>
                                <input type="text" name="mk_ga4_property_id"
                                       value="<?php echo esc_attr( get_option('mk_ga4_property_id') ); ?>"
                                       class="regular-text" />
                                <p class="description">Numeric Property ID (solo cifre).</p>
                            </td>
                        </tr>
                        <tr valign="top">
                            <th scope="row">Intervallo Dati GA4</th>
                            <td>
                                <?php $date_range = get_option( MK_DATE_RANGE_OPT, '30daysAgo' ); ?>
                                <div style="display:flex;gap:0;border:1px solid #ddd;border-radius:6px;overflow:hidden;max-width:380px;">
                                    <?php
                                    $ranges = array(
                                        '1daysAgo'  => 'Ieri',
                                        '7daysAgo'  => 'Ultimi 7 gg',
                                        '14daysAgo' => 'Ultime 2 sett.',
                                        '30daysAgo' => 'Ultimo mese',
                                    );
                                    foreach ( $ranges as $val => $lbl ) :
                                        $active = $date_range === $val;
                                    ?>
                                    <label style="flex:1;text-align:center;padding:8px 4px;cursor:pointer;font-size:12px;font-weight:<?php echo $active ? '700' : '400'; ?>;
                                                  background:<?php echo $active ? '#0073aa' : '#f9f9f9'; ?>;
                                                  color:<?php echo $active ? '#fff' : '#555'; ?>;
                                                  border-right:1px solid #ddd;transition:all .15s;" class="mk-range-label">
                                        <input type="radio" name="<?php echo MK_DATE_RANGE_OPT; ?>" value="<?php echo esc_attr($val); ?>"
                                               <?php checked($date_range, $val); ?>
                                               style="display:none;"
                                               onchange="(function(el){
                                                   el.closest('.mk-range-picker').querySelectorAll('label').forEach(function(l){
                                                       var inp = l.querySelector('input');
                                                       l.style.background = inp.checked ? '#0073aa' : '#f9f9f9';
                                                       l.style.color      = inp.checked ? '#fff'    : '#555';
                                                       l.style.fontWeight = inp.checked ? '700'     : '400';
                                                   });
                                                   var fd = new FormData();
                                                   fd.append('action', 'mk_save_date_range');
                                                   fd.append('nonce', '<?php echo wp_create_nonce('mk_date_range_nonce'); ?>');
                                                   fd.append('value', el.value);
                                                   fetch('<?php echo admin_url('admin-ajax.php'); ?>', {method:'POST', body:fd});
                                               })(this)">
                                        <?php echo esc_html($lbl); ?>
                                    </label>
                                    <?php endforeach; ?>
                                </div>
                                <div class="mk-range-picker" style="display:none;"></div>
                                <script>
                                (function(){
                                    var pickers = document.querySelectorAll('[name="<?php echo MK_DATE_RANGE_OPT; ?>"]');
                                    if (pickers.length) {
                                        pickers[0].closest('div').classList.add('mk-range-picker');
                                    }
                                })();
                                </script>
                                <p class="description" style="margin-top:6px;">
                                    Periodo di riferimento per il ranking e le metriche GA4.
                                    La prossima sincronizzazione userà questo intervallo.
                                </p>
                            </td>
                        </tr>

                        <tr valign="top">
                            <th scope="row">Service Account Credentials</th>
                            <td>
                                <?php
                                $has_creds = $has_file_creds || $has_option_creds;
                                if ( $has_file_creds ) : ?>
                                    <p style="margin:0 0 8px;">
                                        <span style="color:#46b450;font-weight:700;">&#10003;</span>
                                        File <code>credentials.json</code> presente nella cartella plugin.
                                    </p>
                                <?php elseif ( $has_option_creds ) : ?>
                                    <p style="margin:0 0 8px;">
                                        <span style="color:#46b450;font-weight:700;">&#10003;</span>
                                        Credenziali salvate nel database.
                                    </p>
                                <?php else : ?>
                                    <p style="margin:0 0 8px;">
                                        <span style="color:#dc3232;font-weight:700;">&#10007;</span>
                                        Nessuna credenziale trovata.
                                    </p>
                                <?php endif; ?>

                                <?php if ( $has_creds ) : ?>
                                <button type="button" class="mk-cred-toggle" id="mk-cred-toggle-btn"
                                    onclick="(function(){
                                        var box = document.getElementById('mk-cred-box');
                                        var btn = document.getElementById('mk-cred-toggle-btn');
                                        var show = box.style.display === 'none';
                                        box.style.display = show ? 'block' : 'none';
                                        btn.textContent = show ? '&#9650; Nascondi JSON' : '&#9660; Modifica / Sostituisci JSON';
                                    })()">&#9660; Modifica / Sostituisci JSON</button>
                                <div id="mk-cred-box" style="display:none;margin-top:8px;">
                                <?php else : ?>
                                <div id="mk-cred-box" style="margin-top:4px;">
                                <?php endif; ?>
                                    <textarea name="mk_ga4_credentials_json" rows="5" class="large-text code"
                                        placeholder='Incolla qui il contenuto del file credentials.json...'
                                        style="font-family:monospace;font-size:11px;resize:vertical;"
                                    ><?php echo esc_textarea( $credentials_json ); ?></textarea>
                                    <p class="description" style="margin-top:4px;">
                                        Incolla il JSON del Service Account. Se è presente il file fisico, quello ha priorità.
                                    </p>
                                </div>
                            </td>
                        </tr>
                    </table>

                    <!-- ENDPOINT PROTECTION -->
                    <?php $api_auth = get_option( MK_API_AUTH_OPT, array('enabled'=>0,'username'=>'','password'=>'') ); ?>
                    <hr style="margin:20px 0 16px;">
                    <h3 style="margin:0 0 12px;font-size:13px;">&#128274; Protezione Endpoint REST</h3>
                    <p style="color:#555;font-size:13px;margin-bottom:12px;">
                        Se abilitata, tutti e tre gli endpoint <code>/mk/v1/*</code> richiedono HTTP Basic Auth.
                        Imposta le stesse credenziali nella sezione Import per accedere a endpoint protetti.
                    </p>
                    <label style="display:flex;align-items:center;gap:8px;margin-bottom:12px;cursor:pointer;">
                        <input type="checkbox" name="<?php echo MK_API_AUTH_OPT; ?>[enabled]" value="1"
                               id="mk-api-auth-toggle"
                               <?php checked( ! empty($api_auth['enabled']) ); ?>
                               onchange="document.getElementById('mk-api-auth-fields').style.display = this.checked ? 'block' : 'none';">
                        <strong>Abilita protezione con password</strong>
                    </label>
                    <div id="mk-api-auth-fields" style="display:<?php echo ! empty($api_auth['enabled']) ? 'block' : 'none'; ?>;padding:12px 14px;background:#f9f9f9;border:1px solid #e5e5e5;border-radius:6px;max-width:440px;">
                        <table style="border-collapse:collapse;">
                            <tr>
                                <td style="padding:4px 12px 4px 0;"><label for="mk-api-user"><span class="mk-src-label" style="margin:0;">Username</span></label></td>
                                <td><input type="text"     id="mk-api-user" name="<?php echo MK_API_AUTH_OPT; ?>[username]"
                                           value="<?php echo esc_attr($api_auth['username'] ?? ''); ?>"
                                           class="regular-text" autocomplete="off" /></td>
                            </tr>
                            <tr>
                                <td style="padding:4px 12px 4px 0;"><label for="mk-api-pass"><span class="mk-src-label" style="margin:0;">Password</span></label></td>
                                <td><input type="password" id="mk-api-pass" name="<?php echo MK_API_AUTH_OPT; ?>[password]"
                                           value="<?php echo esc_attr($api_auth['password'] ?? ''); ?>"
                                           class="regular-text" autocomplete="new-password" /></td>
                            </tr>
                        </table>
                        <p class="description" style="margin-top:8px;">
                            HTTP Basic: <code>Authorization: Basic base64(user:pass)</code><br>
                            Oppure query string: <code>?mk_user=...&amp;mk_pass=...</code>
                        </p>
                    </div>
                </div>

                <!-- PANEL: REMOTE SOURCES -->
                <div class="mk-panel" id="mk-panel-sources">
                    <h2 class="mk-panel-title">&#128256; Sorgenti Remote (Importazione)</h2>
                    <p style="margin:0 0 14px;color:#555;font-size:13px;">Endpoint JSON di altri siti da cui importare i post popolari.</p>
                    <?php
                    // Build list of all public post types for the select
                    $mk_post_types = get_post_types( array('public' => true), 'objects' );
                    ?>
                    <style>
                    .mk-source-row { background:#fff;border:1px solid #e5e5e5;border-radius:6px;padding:14px 16px;margin-bottom:10px; }
                    .mk-source-row .mk-src-main { display:flex;gap:10px;align-items:flex-start;flex-wrap:wrap; }
                    .mk-source-row .mk-src-meta { display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin-top:8px;padding-top:8px;border-top:1px solid #f0f0f0; }
                    .mk-source-row .mk-src-auth { display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin-top:6px; }
                    .mk-source-row .mk-src-auth-toggle { font-size:11px;color:#0073aa;cursor:pointer;background:none;border:none;padding:0;text-decoration:underline; }
                    .mk-src-label { font-size:11px;color:#888;margin-bottom:2px;display:block; }
                    </style>

                    <div id="mk-sources-list">
                    <?php
                    $mk_src_list = ! empty($sources) ? $sources : array( array('url'=>'','post_type'=>'post','cat'=>'','username'=>'','password'=>'') );
                    foreach ( $mk_src_list as $index => $source ) :
                        $src_pt  = ! empty($source['post_type']) ? $source['post_type'] : 'post';
                        $src_cat = ! empty($source['cat']) ? (int)$source['cat'] : 0;
                        // Get categories/terms for this post type
                        $src_taxes = get_object_taxonomies( $src_pt, 'objects' );
                        $src_tax   = null;
                        foreach ( $src_taxes as $t ) { if ( $t->hierarchical ) { $src_tax = $t; break; } }
                        $src_terms = $src_tax ? get_terms(array('taxonomy'=>$src_tax->name,'hide_empty'=>false,'orderby'=>'name')) : array();
                        $has_auth  = ! empty($source['username']);
                    ?>
                    <div class="mk-source-row" data-index="<?php echo $index; ?>">
                        <div class="mk-src-main">
                            <div style="flex:1;min-width:240px;">
                                <span class="mk-src-label">URL Endpoint (JSON)</span>
                                <input type="url" name="mk_remote_sources[<?php echo $index; ?>][url]"
                                       value="<?php echo esc_url($source['url']); ?>"
                                       class="large-text" style="width:100%;" />
                            </div>
                            <button type="button" class="button button-small remove-source" style="margin-top:18px;color:#dc3232;border-color:#dc3232;">&#10005; Rimuovi</button>
                        </div>
                        <div class="mk-src-meta">
                            <div>
                                <span class="mk-src-label">Tipo di contenuto</span>
                                <select name="mk_remote_sources[<?php echo $index; ?>][post_type]"
                                        class="mk-pt-select" data-index="<?php echo $index; ?>"
                                        style="min-width:140px;">
                                    <?php foreach ( $mk_post_types as $pt ) : ?>
                                    <option value="<?php echo esc_attr($pt->name); ?>"
                                        <?php selected($src_pt, $pt->name); ?>>
                                        <?php echo esc_html($pt->labels->singular_name . ' (' . $pt->name . ')'); ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="mk-cat-wrapper" data-index="<?php echo $index; ?>">
                                <span class="mk-src-label">
                                    <?php echo $src_tax ? esc_html($src_tax->labels->singular_name) : 'Categoria'; ?>
                                </span>
                                <select name="mk_remote_sources[<?php echo $index; ?>][cat]"
                                        style="min-width:160px;">
                                    <option value="0"><?php echo $src_tax ? '— Nessuna —' : '(nessuna tassonomia)'; ?></option>
                                    <?php if ( $src_tax && ! empty($src_terms) && ! is_wp_error($src_terms) ) : ?>
                                        <?php foreach ( $src_terms as $term ) : ?>
                                        <option value="<?php echo esc_attr($term->term_id); ?>"
                                            <?php selected($src_cat, $term->term_id); ?>>
                                            <?php echo esc_html( str_repeat('— ', $term->depth ?? 0) . $term->name ); ?>
                                        </option>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </select>
                            </div>
                        </div>
                        <div class="mk-src-auth">
                            <button type="button" class="mk-src-auth-toggle">
                                <?php echo $has_auth ? '&#9650; Nascondi credenziali' : '&#128274; Credenziali accesso (opzionale)'; ?>
                            </button>
                            <div class="mk-auth-fields" style="display:<?php echo $has_auth ? 'flex' : 'none'; ?>;gap:8px;align-items:center;flex-wrap:wrap;margin-top:6px;">
                                <div>
                                    <span class="mk-src-label">Username</span>
                                    <input type="text" name="mk_remote_sources[<?php echo $index; ?>][username]"
                                           value="<?php echo esc_attr($source['username'] ?? ''); ?>"
                                           class="regular-text" autocomplete="off" style="width:160px;" />
                                </div>
                                <div>
                                    <span class="mk-src-label">Password</span>
                                    <input type="password" name="mk_remote_sources[<?php echo $index; ?>][password]"
                                           value="<?php echo esc_attr($source['password'] ?? ''); ?>"
                                           class="regular-text" autocomplete="off" style="width:160px;" />
                                </div>
                                <p class="description" style="margin:0;font-size:11px;">
                                    Usate per HTTP Basic Auth sull'endpoint remoto.
                                </p>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                    </div><!-- /mk-sources-list -->

                    <p style="margin-top:10px;">
                        <button type="button" class="button button-small" id="add-source-row">+ Aggiungi Sorgente</button>
                    </p>

                </div>

                <?php submit_button( 'Salva Configurazione' ); ?>
            </form>

            <!-- MANUAL ACTIONS ROW -->
            <div class="mk-panel" style="margin-top:4px;">
                <h2 class="mk-panel-title">&#9881; Azioni Manuali</h2>
                <div class="mk-action-cards">

                    <div class="mk-action-card">
                        <strong>&#128200; Sincronizza GA4</strong>
                        <p>Aggiorna subito i post popolari da Google Analytics 4.</p>
                        <a href="<?php echo wp_nonce_url( admin_url('admin-post.php?action=mk_manual_sync'), 'mk_sync_action' ); ?>"
                           class="button button-primary" style="align-self:flex-start;">Sincronizza ora</a>
                    </div>

                    <div class="mk-action-card">
                        <strong>&#128256; Importazione Remota</strong>
                        <p>Scarica subito i post da tutte le sorgenti configurate.</p>
                        <a href="<?php echo wp_nonce_url( admin_url('admin-post.php?action=mk_manual_import'), 'mk_import_action' ); ?>"
                           class="button button-secondary" style="align-self:flex-start;">Avvia Importazione</a>
                    </div>

                    <div class="mk-action-card mk-action-danger">
                        <strong>&#128465; Cache Post Popolari</strong>
                        <?php
                        $ck_fast  = get_transient( MK_CACHE_KEY );
                        $ck_db    = get_option( MK_CACHE_DB_OPTION, false );
                        $ck_db_ok = $ck_db && ! empty($ck_db['ids']) && isset($ck_db['expires']) && time() < (int)$ck_db['expires'];
                        if ( $ck_fast !== false && ! empty($ck_fast) ) : ?>
                            <p><span style="color:#46b450;">&#10003;</span> Transient attivo &mdash; <strong><?php echo count($ck_fast); ?></strong> post.</p>
                        <?php elseif ( $ck_db_ok ) : ?>
                            <p><span style="color:#ffb900;">&#9888;</span> DB fallback attivo &mdash; <strong><?php echo count($ck_db['ids']); ?></strong> post (Redis flush?).</p>
                        <?php else : ?>
                            <p style="color:#888;font-style:italic;">Cache vuota.</p>
                        <?php endif; ?>
                        <a href="<?php echo wp_nonce_url( admin_url('admin-post.php?action=mk_clear_transient'), 'mk_clear_transient_action' ); ?>"
                           class="button" style="align-self:flex-start;border-color:#dc3232;color:#dc3232;"
                           onclick="return confirm('Svuotare la cache?');">Svuota Cache</a>
                    </div>

                </div>
            </div>
        </div>

        <!-- ══════════════════════════════════
             TAB: CRON JOB
        ══════════════════════════════════ -->
        <div id="mk-tab-cron" class="mk-tab-content" style="display:none;">

            <h2 class="title">Gestione Cron Job</h2>
            <p style="color:#555;margin-bottom:20px;">Configura e controlla i due job automatici in modo indipendente.</p>

            <div class="mk-cron-cards">

            <!-- ── PANEL 1: GA4 SYNC ── -->
            <div class="mk-cron-card">
                <h3 style="margin-top:0;border-bottom:1px solid #eee;padding-bottom:10px;">
                    &#128200; Cron GA4 Sync
                </h3>
                <?php if ( $cron['active'] ) :
                    $next_fmt = get_date_from_gmt( date('Y-m-d H:i:s', $cron['next_ts']), 'd/m/Y H:i:s' );
                ?>
                <div style="background:#edfaee;border-left:3px solid #46b450;padding:10px 14px;margin-bottom:14px;border-radius:6px;">
                    <strong><span style="color:#46b450;">&#9679;</span> ATTIVO</strong>
                    &mdash; ogni <strong><?php echo esc_html($cron['interval_h']); ?>h</strong>,
                    prossima <strong>tra <?php echo esc_html($cron['next_human']); ?></strong>
                    <span style="color:#888;font-size:11px;">(<?php echo esc_html($next_fmt); ?>)</span>
                </div>
                <?php else : ?>
                <div style="background:#fef7f7;border-left:3px solid #dc3232;padding:10px 14px;margin-bottom:14px;border-radius:6px;">
                    <strong><span style="color:#dc3232;">&#9679;</span> NON ATTIVO</strong>
                    &mdash; recupero automatico GA4 non pianificato.
                </div>
                <?php endif; ?>
                <form action="options.php" method="post" style="margin-bottom:14px;">
                    <?php settings_fields( 'mk_analytics_cron' ); ?>
                    <label style="display:flex;align-items:center;gap:8px;">
                        <span style="color:#555;white-space:nowrap;">Ogni</span>
                        <input type="number" name="<?php echo MK_CRON_OPTION; ?>"
                               value="<?php echo esc_attr( $cron['interval_h'] ); ?>"
                               min="1" max="168" step="1" class="small-text" style="width:65px;" />
                        <span style="color:#555;">ore</span>
                    </label>
                    <p style="margin:10px 0 0;">
                        <?php submit_button( 'Salva Intervallo GA4', 'secondary', 'submit_ga4_interval', false ); ?>
                    </p>
                </form>
                <a href="<?php echo wp_nonce_url( admin_url('admin-post.php?action=mk_cron_schedule'), 'mk_cron_schedule_action' ); ?>"
                   class="button button-primary" style="margin-right:6px;">
                    &#9654;&nbsp;<?php echo $cron['active'] ? 'Ripianifica' : 'Attiva'; ?>
                </a>
                <?php if ( $cron['active'] ) : ?>
                <a href="<?php echo wp_nonce_url( admin_url('admin-post.php?action=mk_cron_delete'), 'mk_cron_delete_action' ); ?>"
                   class="button" style="border-color:#dc3232;color:#dc3232;"
                   onclick="return confirm('Eliminare il Cron GA4 Sync?');">&#128465;&nbsp;Elimina</a>
                <?php else : ?>
                <button class="button" disabled style="opacity:.4;cursor:not-allowed;">&#128465;&nbsp;Elimina</button>
                <?php endif; ?>
            </div>

            <!-- ── PANEL 2: REMOTE IMPORT ── -->
            <div class="mk-cron-card">
                <h3 style="margin-top:0;border-bottom:1px solid #eee;padding-bottom:10px;">
                    &#128256; Cron Import Remoto
                </h3>
                <?php if ( $cron_import['active'] ) :
                    $next_imp_fmt = get_date_from_gmt( date('Y-m-d H:i:s', $cron_import['next_ts']), 'd/m/Y H:i:s' );
                ?>
                <div style="background:#edfaee;border-left:3px solid #46b450;padding:10px 14px;margin-bottom:14px;border-radius:6px;">
                    <strong><span style="color:#46b450;">&#9679;</span> ATTIVO</strong>
                    &mdash; ogni <strong><?php echo esc_html($cron_import['interval_h']); ?>h</strong>,
                    prossima <strong>tra <?php echo esc_html($cron_import['next_human']); ?></strong>
                    <span style="color:#888;font-size:11px;">(<?php echo esc_html($next_imp_fmt); ?>)</span>
                </div>
                <?php else : ?>
                <div style="background:#fef7f7;border-left:3px solid #dc3232;padding:10px 14px;margin-bottom:14px;border-radius:6px;">
                    <strong><span style="color:#dc3232;">&#9679;</span> NON ATTIVO</strong>
                    &mdash; importazione automatica remota non pianificata.
                </div>
                <?php endif; ?>
                <form action="options.php" method="post" style="margin-bottom:14px;">
                    <?php settings_fields( 'mk_analytics_import' ); ?>
                    <label style="display:flex;align-items:center;gap:8px;">
                        <span style="color:#555;white-space:nowrap;">Ogni</span>
                        <input type="number" name="<?php echo MK_IMPORT_CRON_OPTION; ?>"
                               value="<?php echo esc_attr( $cron_import['interval_h'] ); ?>"
                               min="1" max="168" step="1" class="small-text" style="width:65px;" />
                        <span style="color:#555;">ore</span>
                    </label>
                    <p style="margin:10px 0 0;">
                        <?php submit_button( 'Salva Intervallo Import', 'secondary', 'submit_import_interval', false ); ?>
                    </p>
                </form>
                <a href="<?php echo wp_nonce_url( admin_url('admin-post.php?action=mk_import_cron_schedule'), 'mk_import_cron_schedule_action' ); ?>"
                   class="button button-primary" style="margin-right:6px;">
                    &#9654;&nbsp;<?php echo $cron_import['active'] ? 'Ripianifica' : 'Attiva'; ?>
                </a>
                <?php if ( $cron_import['active'] ) : ?>
                <a href="<?php echo wp_nonce_url( admin_url('admin-post.php?action=mk_import_cron_delete'), 'mk_import_cron_delete_action' ); ?>"
                   class="button" style="border-color:#dc3232;color:#dc3232;"
                   onclick="return confirm('Eliminare il Cron Import Remoto?');">&#128465;&nbsp;Elimina</a>
                <?php else : ?>
                <button class="button" disabled style="opacity:.4;cursor:not-allowed;">&#128465;&nbsp;Elimina</button>
                <?php endif; ?>
            </div>

            </div><!-- /mk-cron-cards -->
        </div>

        <!-- ══════════════════════════════════
             TAB: DEBUG & LOG
             Order: 1) Toggle  2) Log  3) Snapshot
        ══════════════════════════════════ -->
        <div id="mk-tab-debug" class="mk-tab-content" style="display:none;">

            <h2 class="title">Debug &amp; Log</h2>

            <!-- 1. DEBUG TOGGLE -->
            <div class="mk-panel" style="max-width:620px;margin-bottom:20px;">
                <h2 class="mk-panel-title">&#128295; Modalità Debug</h2>
                <?php if ( $debug_on ) : ?>
                <div style="background:#fff8e5;border:1px solid #ffb900;border-radius:6px;padding:10px 14px;margin-bottom:14px;">
                    <strong style="color:#826200;">&#9888; Debug ATTIVO</strong> —
                    <span style="color:#555;">tutte le operazioni vengono registrate. Disabilita in produzione.</span>
                </div>
                <?php else : ?>
                <div style="background:#f5f5f5;border:1px solid #ddd;border-radius:6px;padding:10px 14px;margin-bottom:14px;">
                    <strong>Debug DISATTIVO</strong> — <span style="color:#555;">nessun log viene scritto.</span>
                </div>
                <?php endif; ?>
                <form action="options.php" method="post">
                    <?php settings_fields( 'mk_analytics_debug' ); ?>
                    <label style="display:flex;align-items:center;gap:10px;cursor:pointer;">
                        <div style="position:relative;display:inline-block;width:48px;height:26px;">
                            <input type="checkbox" name="<?php echo MK_DEBUG_OPTION; ?>" value="1"
                                   id="mk-debug-toggle" <?php checked( $debug_on ); ?>
                                   style="opacity:0;width:0;height:0;position:absolute;">
                            <span id="mk-toggle-track" style="position:absolute;inset:0;border-radius:26px;cursor:pointer;
                                background:<?php echo $debug_on ? '#46b450' : '#ccc'; ?>;transition:background .2s;">
                                <span id="mk-toggle-thumb" style="position:absolute;top:3px;
                                    left:<?php echo $debug_on ? '25px' : '3px'; ?>;
                                    width:20px;height:20px;border-radius:50%;background:#fff;
                                    box-shadow:0 1px 3px rgba(0,0,0,.3);transition:left .2s;"></span>
                            </span>
                        </div>
                        <span style="font-size:14px;font-weight:600;">
                            <?php echo $debug_on ? 'Disabilita Debug' : 'Abilita Debug'; ?>
                        </span>
                    </label>
                    <p style="margin:12px 0 0;">
                        <?php submit_button( 'Salva impostazione Debug', 'secondary', 'submit_debug', false ); ?>
                    </p>
                </form>
            </div>

            <!-- 2. LOG TABLE -->
            <div class="mk-panel" style="max-width:960px;margin-bottom:20px;">
                <h2 class="mk-panel-title" style="display:flex;align-items:center;justify-content:space-between;">
                    <span>&#128220; Log Eventi <span style="font-weight:400;font-size:11px;color:#aaa;text-transform:none;letter-spacing:0;">&nbsp;ultimi <?php echo MK_LOG_MAX; ?> &mdash; più recente in cima</span></span>
                    <?php if ( ! empty($log) ) : ?>
                    <a href="<?php echo wp_nonce_url( admin_url('admin-post.php?action=mk_clear_log'), 'mk_clear_log_action' ); ?>"
                       class="button button-small" style="font-size:11px;font-weight:400;text-transform:none;letter-spacing:0;"
                       onclick="return confirm('Svuotare il log?');">Svuota Log</a>
                    <?php endif; ?>
                </h2>
                <?php if ( ! $debug_on ) : ?>
                    <p style="color:#826200;background:#fff8e5;padding:10px 12px;border-radius:6px;font-size:13px;">
                        &#9888; Debug disattivo: nessun nuovo evento sarà registrato.
                    </p>
                <?php endif; ?>
                <?php if ( empty($log) ) : ?>
                    <p style="color:#aaa;font-style:italic;font-size:13px;">Nessun evento nel log.</p>
                <?php else : ?>
                <div style="overflow-x:auto;border-radius:6px;border:1px solid #eee;">
                <table class="widefat" style="font-size:12px;font-family:monospace;border:none;">
                    <thead>
                        <tr style="background:#fafafa;">
                            <th style="width:140px;">Timestamp</th>
                            <th style="width:110px;">Context</th>
                            <th style="width:58px;">Level</th>
                            <th>Messaggio</th>
                            <th style="width:260px;">Dati</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ( $log as $entry ) :
                            $lc = $level_colors[ $entry['level'] ] ?? '#888';
                        ?>
                        <tr>
                            <td style="white-space:nowrap;color:#888;"><?php echo esc_html($entry['ts']); ?></td>
                            <td style="font-weight:700;"><?php echo esc_html($entry['context']); ?></td>
                            <td><span style="color:<?php echo $lc; ?>;font-weight:700;"><?php echo esc_html($entry['level']); ?></span></td>
                            <td style="font-family:sans-serif;font-size:12px;"><?php echo esc_html($entry['msg']); ?></td>
                            <td style="color:#666;word-break:break-all;font-size:11px;">
                                <?php echo isset($entry['data']) ? esc_html($entry['data']) : '—'; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                </div>
                <?php endif; ?>
            </div>

            <!-- 3. SYSTEM SNAPSHOT -->
            <div class="mk-panel" style="max-width:760px;">
                <h2 class="mk-panel-title" style="display:flex;align-items:center;justify-content:space-between;">
                    <span>&#128203; Snapshot Sistema</span>
                    <a href="<?php echo wp_nonce_url( admin_url('admin-post.php?action=mk_run_snapshot'), 'mk_run_snapshot_action' ); ?>"
                       class="button button-small" style="font-size:11px;font-weight:400;text-transform:none;letter-spacing:0;">&#8635; Aggiorna</a>
                </h2>
                <?php
                $snap = mk_system_snapshot();
                $snap_colors = array( 'OK' => '#46b450', 'WARN' => '#ffb900', 'FAIL' => '#dc3232' );
                ?>
                <div style="overflow:hidden;border-radius:6px;border:1px solid #eee;">
                <table class="widefat striped" style="border:none;margin:0;">
                    <thead>
                        <tr style="background:#fafafa;">
                            <th style="width:210px;">Componente</th>
                            <th style="width:70px;">Stato</th>
                            <th>Dettaglio</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ( $snap as $row ) :
                            $c = $snap_colors[ $row['status'] ] ?? '#888';
                        ?>
                        <tr>
                            <td><strong style="font-size:12px;"><?php echo esc_html($row['label']); ?></strong></td>
                            <td><span style="color:<?php echo $c; ?>;font-weight:700;font-size:12px;"><?php echo esc_html($row['status']); ?></span></td>
                            <td style="font-family:monospace;font-size:11px;color:#555;"><?php echo esc_html($row['detail']); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                </div>
            </div>
        </div>

        <!-- ══════════════════════════════════
             TAB: GUIDA GCP
        ══════════════════════════════════ -->
        <div id="mk-tab-guide" class="mk-tab-content"
             style="display:none;max-width:800px;background:#fff;padding:24px;border:1px solid #ddd;border-radius:8px;margin-top:20px;">
            <h2 style="margin-top:0;">Guida alla creazione del progetto Google Cloud (GCP)</h2>
            <p>Per far funzionare il modulo GA4, puoi caricare il file <code>credentials.json</code> tramite FTP
               <strong>oppure</strong> incollarne il contenuto direttamente nella scheda Configurazione.</p>
            <ol style="line-height:2;">
                <li>Vai sulla <strong><a href="https://console.cloud.google.com/" target="_blank">Google Cloud Console</a></strong>.</li>
                <li>Crea un nuovo progetto (es. "Meksone Analytics").</li>
                <li>Nel menu laterale, vai su <strong>APIs &amp; Services &gt; Library</strong>.</li>
                <li>Cerca e abilita la <strong>"Google Analytics Data API"</strong>.</li>
                <li>Vai su <strong>APIs &amp; Services &gt; Credentials</strong>.</li>
                <li>Clicca su <strong>Create Credentials</strong> e scegli <strong>Service Account</strong>.</li>
                <li>Segui i passaggi e clicca su <strong>Done</strong>.</li>
                <li>Nella lista dei Service Accounts, clicca sull'email appena creata.</li>
                <li>Vai nella tab <strong>Keys</strong>, clicca su <strong>Add Key &gt; Create new key</strong> e scegli <strong>JSON</strong>.</li>
                <li><strong>Opzione A (FTP):</strong> Rinomina il file in <code>credentials.json</code> e caricalo in:<br>
                    <code>/wp-content/plugins/mk-analytics-data/credentials.json</code></li>
                <li><strong>Opzione B (Database):</strong> Apri il JSON con un editor, copialo e incollalo nel campo della scheda <em>Configurazione</em>.</li>
                <li><strong>IMPORTANTE:</strong> Aggiungi l'email del Service Account come Viewer in
                    <strong>GA4 &gt; Admin &gt; Property Access Management</strong>.</li>
            </ol>
        </div>

        <!-- JAVASCRIPT -->
        <script>
        (function(){
            var tabMap = {
                '#settings' : 'mk-tab-settings',
                '#cron'     : 'mk-tab-cron',
                '#debug'    : 'mk-tab-debug',
                '#guide'    : 'mk-tab-guide'
            };

            document.querySelectorAll('.nav-tab').forEach(function(tab) {
                tab.addEventListener('click', function(e) {
                    e.preventDefault();
                    document.querySelectorAll('.nav-tab').forEach(function(t){ t.classList.remove('nav-tab-active'); });
                    this.classList.add('nav-tab-active');
                    document.querySelectorAll('.mk-tab-content').forEach(function(c){ c.style.display = 'none'; });
                    var id = tabMap[ this.getAttribute('href') ];
                    if (id) document.getElementById(id).style.display = 'block';
                });
            });

            // ── Debug toggle animation ────────────────────────────────────────
            var cb = document.getElementById('mk-debug-toggle');
            if (cb) {
                cb.addEventListener('change', function(){
                    var track = document.getElementById('mk-toggle-track');
                    var thumb = document.getElementById('mk-toggle-thumb');
                    if (this.checked) {
                        track.style.background = '#46b450';
                        thumb.style.left = '25px';
                    } else {
                        track.style.background = '#ccc';
                        thumb.style.left = '3px';
                    }
                });
            }

            // ── Operation mode: persist immediately + show/hide panels ──────
            function mkApplyMode(mode) {
                var ga4Panel = document.getElementById('mk-panel-ga4');
                var srcPanel = document.getElementById('mk-panel-sources');
                if (!ga4Panel || !srcPanel) return;
                ga4Panel.style.display = (mode === 'import_only') ? 'none' : 'block';
                srcPanel.style.display = (mode === 'ga4_only')    ? 'none' : 'block';

                // Update description text
                var descs = {
                    'ga4_only':    'Mostra solo impostazioni GA4. Import remoto disabilitato.',
                    'import_only': 'Mostra solo sorgenti remote. GA4 disabilitato.',
                    'both':        'Tutte le funzionalità attive.'
                };
                var descEl = document.querySelector('#mk-mode-form .description');
                if (descEl) descEl.textContent = descs[mode] || '';

                // Update label styles in the mode picker
                document.querySelectorAll('[name="<?php echo MK_OP_MODE_OPT; ?>"]').forEach(function(r) {
                    var lbl = r.closest('label');
                    if (!lbl) return;
                    var active = r.value === mode;
                    lbl.style.background = active ? '#0073aa' : '#f9f9f9';
                    lbl.style.color      = active ? '#fff'    : '#555';
                    lbl.style.fontWeight = active ? '700'     : '400';
                });
            }

            // Apply correct panel visibility on page load (reading the checked radio)
            (function(){
                var checked = document.querySelector('[name="<?php echo MK_OP_MODE_OPT; ?>"]:checked');
                if (checked) mkApplyMode(checked.value);
            })();

            // On change: update UI immediately, then auto-submit the mode form to persist
            document.querySelectorAll('[name="<?php echo MK_OP_MODE_OPT; ?>"]').forEach(function(radio) {
                radio.addEventListener('change', function() {
                    mkApplyMode(this.value);
                    // Submit just the mode form so the value is saved without a full page reload
                    var form = document.getElementById('mk-mode-form');
                    if (form) form.submit();
                });
            });

            // ── Dynamic category reload when post type changes ────────────────
            document.addEventListener('change', function(e) {
                if (!e.target || !e.target.classList.contains('mk-pt-select')) return;
                var idx    = e.target.dataset.index;
                var pt     = e.target.value;
                var wrap   = document.querySelector('.mk-cat-wrapper[data-index="'+idx+'"]');
                if (!wrap) return;
                wrap.innerHTML = '<span class="mk-src-label">Categoria</span>'
                               + '<span style="color:#888;font-size:12px;">caricamento...</span>';
                fetch('<?php echo admin_url('admin-ajax.php'); ?>?action=mk_get_terms_for_pt&post_type=' + encodeURIComponent(pt) + '&nonce=<?php echo wp_create_nonce('mk_terms_nonce'); ?>')
                    .then(function(r){ return r.json(); })
                    .then(function(data) {
                        var label = data.tax_label || 'Categoria';
                        var html  = '<span class="mk-src-label">' + label + '</span>';
                        html += '<select name="mk_remote_sources['+idx+'][cat]" style="min-width:160px;">';
                        html += '<option value="0">— Nessuna —</option>';
                        if (data.terms && data.terms.length) {
                            data.terms.forEach(function(t){
                                html += '<option value="'+t.id+'">'+t.label+'</option>';
                            });
                        }
                        html += '</select>';
                        wrap.innerHTML = html;
                    })
                    .catch(function(){ wrap.innerHTML = '<span class="mk-src-label">Categoria</span><span style="color:#dc3232;font-size:12px;">Errore caricamento</span>'; });
            });

            // ── Add source row — monotonic counter ────────────────────────────
            // Pre-render post type options via PHP into a JS array (safe, no PHP inside JS strings)
            var mkPostTypeOptions = (function(){
                var opts = '';
                <?php foreach ( $mk_post_types as $mk_pt_obj ) : ?>
                opts += '<option value="' + <?php echo json_encode( esc_attr($mk_pt_obj->name) ); ?> + '">'
                      + <?php echo json_encode( esc_html($mk_pt_obj->labels->singular_name . ' (' . $mk_pt_obj->name . ')') ); ?>
                      + '</option>';
                <?php endforeach; ?>
                return opts;
            })();

            var addBtn = document.getElementById('add-source-row');
            if (addBtn) {
                var mkSourceCounter = (function(){
                    var max = -1;
                    document.querySelectorAll('#mk-sources-list [data-index]').forEach(function(el){
                        var n = parseInt(el.dataset.index, 10);
                        if (!isNaN(n)) max = Math.max(max, n);
                    });
                    return max + 1;
                })();

                addBtn.addEventListener('click', function() {
                    var idx  = mkSourceCounter++;
                    var list = document.getElementById('mk-sources-list');
                    var div  = document.createElement('div');
                    div.className = 'mk-source-row';
                    div.dataset.index = String(idx);

                    // Build auth toggle with a data-attribute to avoid quote nesting
                    var authBtn = document.createElement('button');
                    authBtn.type = 'button';
                    authBtn.className = 'mk-src-auth-toggle';
                    authBtn.textContent = '\uD83D\uDD12 Credenziali accesso (opzionale)';
                    authBtn.addEventListener('click', function() {
                        var box  = this.parentNode.querySelector('.mk-auth-fields');
                        var show = box.style.display === 'none';
                        box.style.display = show ? 'flex' : 'none';
                        this.textContent  = show ? '\u25B2 Nascondi credenziali' : '\uD83D\uDD12 Credenziali accesso (opzionale)';
                    });

                    div.innerHTML =
                        '<div class="mk-src-main">'
                        + '<div style="flex:1;min-width:240px;">'
                        + '<span class="mk-src-label">URL Endpoint (JSON)</span>'
                        + '<input type="url" name="mk_remote_sources[' + idx + '][url]" value="" class="large-text" style="width:100%;" />'
                        + '</div>'
                        + '<button type="button" class="button button-small remove-source" style="margin-top:18px;color:#dc3232;border-color:#dc3232;">\u2715 Rimuovi</button>'
                        + '</div>'
                        + '<div class="mk-src-meta" style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin-top:8px;padding-top:8px;border-top:1px solid #f0f0f0;">'
                        + '<div>'
                        + '<span class="mk-src-label">Tipo di contenuto</span>'
                        + '<select name="mk_remote_sources[' + idx + '][post_type]" class="mk-pt-select" data-index="' + idx + '" style="min-width:140px;">'
                        + mkPostTypeOptions
                        + '</select>'
                        + '</div>'
                        + '<div class="mk-cat-wrapper" data-index="' + idx + '">'
                        + '<span class="mk-src-label">Categoria</span>'
                        + '<select name="mk_remote_sources[' + idx + '][cat]" style="min-width:160px;"><option value="0">\u2014 Nessuna \u2014</option></select>'
                        + '</div>'
                        + '</div>'
                        + '<div class="mk-src-auth" style="margin-top:6px;">'
                        + '<div class="mk-auth-fields" style="display:none;gap:8px;align-items:center;flex-wrap:wrap;margin-top:6px;">'
                        + '<div><span class="mk-src-label">Username</span><input type="text"     name="mk_remote_sources[' + idx + '][username]" value="" class="regular-text" autocomplete="off" style="width:160px;" /></div>'
                        + '<div><span class="mk-src-label">Password</span><input type="password" name="mk_remote_sources[' + idx + '][password]" value="" class="regular-text" autocomplete="off" style="width:160px;" /></div>'
                        + '</div>'
                        + '</div>';

                    // Append auth button before the auth-fields div
                    var authDiv = div.querySelector('.mk-src-auth');
                    authDiv.insertBefore(authBtn, authDiv.firstChild);

                    list.appendChild(div);
                });
            }

            // ── Remove source row ─────────────────────────────────────────────
            document.addEventListener('click', function(e){
                if (e.target && e.target.classList.contains('remove-source')) {
                    e.target.closest('.mk-source-row').remove();
                }
                // Auth toggle (delegated, works for both existing and new rows)
                if (e.target && e.target.classList.contains('mk-src-auth-toggle')) {
                    var box  = e.target.closest('.mk-src-auth').querySelector('.mk-auth-fields');
                    if (!box) return;
                    var show = box.style.display === 'none';
                    box.style.display = show ? 'flex' : 'none';
                    e.target.textContent = show ? '\u25B2 Nascondi credenziali' : '\uD83D\uDD12 Credenziali accesso (opzionale)';
                }
            });
        })();
        </script>
    </div>
    <?php
}

// ─────────────────────────────────────────────
// 6. SYSTEM SNAPSHOT
// Returns an array of status rows for the debug tab.
// ─────────────────────────────────────────────
function mk_system_snapshot() {
    $rows = array();

    // --- Credentials ---
    $cred_file = plugin_dir_path( __FILE__ ) . 'credentials.json';
    if ( file_exists($cred_file) ) {
        $rows[] = array('label' => 'Credentials', 'status' => 'OK',   'detail' => 'File credentials.json trovato nella cartella plugin.');
    } elseif ( ! empty( get_option('mk_ga4_credentials_json') ) ) {
        $decoded = json_decode( get_option('mk_ga4_credentials_json'), true );
        if ( json_last_error() === JSON_ERROR_NONE ) {
            $rows[] = array('label' => 'Credentials', 'status' => 'OK',   'detail' => 'JSON credenziali valido salvato nel database.');
        } else {
            $rows[] = array('label' => 'Credentials', 'status' => 'FAIL', 'detail' => 'JSON nel database non valido: ' . json_last_error_msg());
        }
    } else {
        $rows[] = array('label' => 'Credentials', 'status' => 'FAIL', 'detail' => 'Nessuna credenziale trovata (né file né database).');
    }

    // --- GA4 Property ID ---
    $pid = get_option('mk_ga4_property_id');
    if ( ! empty($pid) && ctype_digit((string)$pid) ) {
        $rows[] = array('label' => 'GA4 Property ID', 'status' => 'OK',   'detail' => $pid);
    } elseif ( ! empty($pid) ) {
        $rows[] = array('label' => 'GA4 Property ID', 'status' => 'WARN', 'detail' => 'Valore presente ma non numerico: ' . esc_html($pid));
    } else {
        $rows[] = array('label' => 'GA4 Property ID', 'status' => 'FAIL', 'detail' => 'Non configurato.');
    }

    // --- Cache (dual-layer: transient + DB option) ---
    $t_fast = get_transient( MK_CACHE_KEY );
    $t_db   = get_option( MK_CACHE_DB_OPTION, false );
    $t_db_valid = $t_db && ! empty($t_db['ids']) && isset($t_db['expires']) && time() < (int)$t_db['expires'];

    if ( $t_fast !== false && ! empty($t_fast) ) {
        $rows[] = array('label' => 'Cache (transient)', 'status' => 'OK',
            'detail' => count($t_fast) . ' post ID — fast path (transient/Redis).');
    } elseif ( $t_db_valid ) {
        $rows[] = array('label' => 'Cache (transient)', 'status' => 'WARN',
            'detail' => 'Transient mancante (Redis flush?), ma DB fallback OK: ' . count($t_db['ids']) . ' post ID. Verrà riscritto al prossimo accesso.');
    } elseif ( $t_fast !== false ) {
        $rows[] = array('label' => 'Cache (transient)', 'status' => 'WARN',
            'detail' => 'Transient esiste ma è vuoto.');
    } else {
        $rows[] = array('label' => 'Cache (transient)', 'status' => 'WARN',
            'detail' => 'Nessun dato in cache (né transient né DB option). Esegui una sincronizzazione GA4.');
    }

    if ( $t_db_valid ) {
        $rows[] = array('label' => 'Cache (DB fallback)', 'status' => 'OK',
            'detail' => count($t_db['ids']) . ' post ID — salvato il ' . ($t_db['saved'] ?? 'n/a') . ', scade ' . date('d/m/Y H:i', $t_db['expires']) . '.');
    } else {
        $rows[] = array('label' => 'Cache (DB fallback)', 'status' => $t_db ? 'WARN' : 'WARN',
            'detail' => $t_db ? 'DB option trovato ma scaduto (expires: ' . date('d/m/Y H:i', $t_db['expires'] ?? 0) . ').' : 'Nessun DB option trovato. Esegui una sincronizzazione GA4.');
    }

    // --- Object Cache ---
    if ( wp_using_ext_object_cache() ) {
        $rows[] = array('label' => 'Object Cache', 'status' => 'OK',
            'detail' => 'Redis/Memcached attivo. Il plugin usa un doppio layer (transient + DB option) per garantire persistenza.');
    } else {
        $rows[] = array('label' => 'Object Cache', 'status' => 'OK',
            'detail' => 'Cache WP standard (database). Transient e DB option entrambi su MySQL.');
    }

    // --- Cron ---
    $cron = mk_cron_status();
    if ( $cron['active'] ) {
        $rows[] = array('label' => 'Cron Job', 'status' => 'OK',
            'detail' => 'Attivo. Prossima esecuzione tra ' . $cron['next_human'] . ' (intervallo: ' . $cron['interval_h'] . 'h).');
    } else {
        $rows[] = array('label' => 'Cron Job', 'status' => 'WARN', 'detail' => 'Non pianificato.');
    }

    // --- WP-Cron disabled? ---
    if ( defined('DISABLE_WP_CRON') && DISABLE_WP_CRON ) {
        $rows[] = array('label' => 'DISABLE_WP_CRON', 'status' => 'WARN',
            'detail' => 'Costante definita a true in wp-config.php. WP-Cron non si attiva su richieste HTTP; serve un cron di sistema.');
    } else {
        $rows[] = array('label' => 'DISABLE_WP_CRON', 'status' => 'OK', 'detail' => 'Non definita. WP-Cron funziona normalmente.');
    }

    // --- Composer autoload ---
    $autoload = plugin_dir_path( __FILE__ ) . 'vendor/autoload.php';
    if ( file_exists($autoload) ) {
        $rows[] = array('label' => 'Composer Autoload', 'status' => 'OK',   'detail' => 'vendor/autoload.php trovato.');
    } else {
        $rows[] = array('label' => 'Composer Autoload', 'status' => 'FAIL', 'detail' => 'vendor/autoload.php non trovato! Esegui "composer install".');
    }

    // --- Google SDK class ---
    if ( class_exists('\Google\Analytics\Data\V1beta\Client\BetaAnalyticsDataClient') ) {
        $rows[] = array('label' => 'Google Analytics SDK', 'status' => 'OK',   'detail' => 'BetaAnalyticsDataClient disponibile.');
    } else {
        $rows[] = array('label' => 'Google Analytics SDK', 'status' => 'FAIL', 'detail' => 'Classe SDK non trovata. Controlla vendor/.');
    }

    // --- Remote sources ---
    $sources = get_option('mk_remote_sources', array());
    $src_count = count( array_filter($sources, fn($s) => ! empty($s['url'])) );
    $rows[] = array('label' => 'Sorgenti Remote', 'status' => $src_count > 0 ? 'OK' : 'WARN',
        'detail' => $src_count > 0 ? $src_count . ' sorgent' . ($src_count === 1 ? 'e configurata.' : 'i configurate.') : 'Nessuna sorgente remota configurata.');

    // --- PHP version ---
    $rows[] = array('label' => 'PHP Version', 'status' => version_compare(PHP_VERSION, '7.4', '>=') ? 'OK' : 'WARN',
        'detail' => PHP_VERSION);

    // --- WP version ---
    $rows[] = array('label' => 'WordPress Version', 'status' => 'OK', 'detail' => get_bloginfo('version'));

    return $rows;
}

// ─────────────────────────────────────────────
// 7. CREDENTIALS HELPER
// ─────────────────────────────────────────────
function mk_get_credentials_config() {
    $credentials_file = plugin_dir_path( __FILE__ ) . 'credentials.json';
    if ( file_exists( $credentials_file ) ) {
        mk_log('CREDENTIALS', 'INFO', 'Usando file credentials.json dalla cartella plugin.');
        return $credentials_file;
    }

    $credentials_json = get_option( 'mk_ga4_credentials_json', '' );
    if ( ! empty( $credentials_json ) ) {
        $decoded = json_decode( $credentials_json, true );
        if ( json_last_error() === JSON_ERROR_NONE ) {
            mk_log('CREDENTIALS', 'INFO', 'Usando credenziali dal database (wp_options).');
            return $decoded;
        }
        mk_log('CREDENTIALS', 'ERROR', 'JSON credenziali nel database non valido.', json_last_error_msg());
    }

    mk_log('CREDENTIALS', 'ERROR', 'Nessuna credenziale trovata.');
    return null;
}

// ─────────────────────────────────────────────
// 8. GA4 FETCH
// ─────────────────────────────────────────────
/**
 * Format seconds into a human-readable time string (e.g. "2m 34s").
 */
function mk_format_duration( $seconds ) {
    $s = (int) round( $seconds );
    if ( $s < 60 ) return $s . 's';
    $m = (int) floor( $s / 60 );
    $r = $s % 60;
    return $r > 0 ? "{$m}m {$r}s" : "{$m}m";
}

function mk_fetch_ga4_top_posts() {
    mk_log('GA4_FETCH', 'INFO', 'Avvio fetch dati GA4.');

    $property_id = get_option( 'mk_ga4_property_id' );
    $credentials = mk_get_credentials_config();
    $date_range  = get_option( MK_DATE_RANGE_OPT, '30daysAgo' );

    // Validate date_range value
    $allowed_ranges = array('1daysAgo','7daysAgo','14daysAgo','30daysAgo');
    if ( ! in_array($date_range, $allowed_ranges, true) ) $date_range = '30daysAgo';

    if ( empty( $property_id ) ) {
        mk_log('GA4_FETCH', 'ERROR', 'GA4 Property ID non configurato.');
        return 'error:missing_config';
    }
    if ( empty( $credentials ) ) {
        mk_log('GA4_FETCH', 'ERROR', 'Credenziali mancanti o non valide.');
        return 'error:missing_config';
    }

    mk_log('GA4_FETCH', 'INFO', 'Avvio richiesta API.', array('property_id' => $property_id, 'date_range' => $date_range));

    try {
        $client  = new \Google\Analytics\Data\V1beta\Client\BetaAnalyticsDataClient(['credentials' => $credentials]);
        $request = (new \Google\Analytics\Data\V1beta\RunReportRequest())
            ->setProperty( 'properties/' . $property_id )
            ->setDateRanges([
                new \Google\Analytics\Data\V1beta\DateRange([
                    'start_date' => $date_range,
                    'end_date'   => 'today',
                ])
            ])
            ->setDimensions([
                new \Google\Analytics\Data\V1beta\Dimension(['name' => 'pagePath']),
            ])
            ->setMetrics([
                new \Google\Analytics\Data\V1beta\Metric(['name' => 'screenPageViews']),
                new \Google\Analytics\Data\V1beta\Metric(['name' => 'sessions']),
                new \Google\Analytics\Data\V1beta\Metric(['name' => 'activeUsers']),
                new \Google\Analytics\Data\V1beta\Metric(['name' => 'newUsers']),
                new \Google\Analytics\Data\V1beta\Metric(['name' => 'averageSessionDuration']),
                new \Google\Analytics\Data\V1beta\Metric(['name' => 'bounceRate']),
                new \Google\Analytics\Data\V1beta\Metric(['name' => 'engagementRate']),
            ])
            ->setLimit(50);

        $response  = $client->runReport($request);
        $row_count = $response->getRowCount();
        mk_log('GA4_FETCH', 'INFO', 'Risposta API ricevuta.', array('rows_returned' => $row_count, 'date_range' => $date_range));

        $popular_ids    = [];
        $analytics_data = [];  // keyed by post_id

        foreach ( $response->getRows() as $row ) {
            $path    = $row->getDimensionValues()[0]->getValue();
            $post_id = url_to_postid( home_url( $path ) );

            if ( $post_id > 0 && get_post_type($post_id) === 'post' ) {
                if ( in_array($post_id, $popular_ids) ) continue;

                // Parse all metric values (order matches the setMetrics() call above)
                $mv = $row->getMetricValues();
                $views       = (int)   $mv[0]->getValue();
                $sessions    = (int)   $mv[1]->getValue();
                $users       = (int)   $mv[2]->getValue();
                $new_users   = (int)   $mv[3]->getValue();
                $avg_time    = (float) $mv[4]->getValue();
                $bounce      = (float) $mv[5]->getValue();
                $engagement  = (float) $mv[6]->getValue();

                $popular_ids[]             = $post_id;
                $analytics_data[$post_id]  = array(
                    'post_id'          => $post_id,
                    'views'            => $views,
                    'sessions'         => $sessions,
                    'active_users'     => $users,
                    'new_users'        => $new_users,
                    'avg_time_seconds' => round($avg_time),
                    'avg_time_human'   => mk_format_duration($avg_time),
                    'bounce_rate'      => round($bounce, 4),
                    'engagement_rate'  => round($engagement, 4),
                    'date_range'       => $date_range,
                    'fetched_at'       => current_time('Y-m-d H:i:s'),
                );

                // Persist as post meta so themes/other plugins can use it
                update_post_meta( $post_id, '_mk_ga4_views',          $views );
                update_post_meta( $post_id, '_mk_ga4_sessions',       $sessions );
                update_post_meta( $post_id, '_mk_ga4_active_users',   $users );
                update_post_meta( $post_id, '_mk_ga4_new_users',      $new_users );
                update_post_meta( $post_id, '_mk_ga4_avg_time',       round($avg_time) );
                update_post_meta( $post_id, '_mk_ga4_avg_time_human', mk_format_duration($avg_time) );
                update_post_meta( $post_id, '_mk_ga4_bounce_rate',    round($bounce, 4) );
                update_post_meta( $post_id, '_mk_ga4_engagement_rate',round($engagement, 4) );
                update_post_meta( $post_id, '_mk_ga4_date_range',     $date_range );
                update_post_meta( $post_id, '_mk_ga4_fetched_at',     current_time('Y-m-d H:i:s') );

                mk_log('GA4_FETCH', 'INFO', 'Post trovato: ' . $path, array(
                    'post_id' => $post_id,
                    'views'   => $views,
                    'avg_time'=> mk_format_duration($avg_time),
                ));
            } else {
                mk_log('GA4_FETCH', 'INFO', 'Path ignorato: ' . $path);
            }

            if ( count($popular_ids) >= 10 ) break;
        }

        mk_log('GA4_FETCH', 'INFO', 'Post popolari identificati: ' . count($popular_ids), $popular_ids);

        if ( ! empty($popular_ids) ) {
            // Save enriched analytics data to its own wp_options key
            update_option( MK_ANALYTICS_OPTION, array(
                'data'       => $analytics_data,
                'date_range' => $date_range,
                'fetched_at' => current_time('Y-m-d H:i:s'),
            ), false );

            $hours  = (int) get_option( MK_CRON_OPTION, 12 );
            $result = mk_cache_set( $popular_ids, $hours );
            return $result ? 'success' : 'error:cache_write_failed';
        }

        mk_log('GA4_FETCH', 'WARN', 'Nessun post popolare trovato.');

    } catch ( \Exception $e ) {
        mk_log('GA4_FETCH', 'ERROR', 'Eccezione API: ' . $e->getMessage(), array(
            'class' => get_class($e),
            'code'  => $e->getCode(),
        ));
        return 'api_error:' . $e->getMessage();
    }

    return 'error';
}

// ─────────────────────────────────────────────
// 9. IMPORT LOGIC
// ─────────────────────────────────────────────
function mk_import_remote_content() {
    $sources = get_option( 'mk_remote_sources', array() );
    if ( empty( $sources ) ) {
        mk_log('IMPORT', 'WARN', 'Nessuna sorgente remota configurata.');
        return 'error:no_sources_configured';
    }

    $imported_count = 0;
    mk_log('IMPORT', 'INFO', 'Avvio importazione da ' . count($sources) . ' sorgenti.');

    foreach ( $sources as $source ) {
        if ( empty( $source['url'] ) ) continue;

        mk_log('IMPORT', 'INFO', 'Fetch sorgente: ' . $source['url']);
        $fetch_args = array('timeout' => 30);
        if ( ! empty($source['username']) && ! empty($source['password']) ) {
            $fetch_args['headers'] = array(
                'Authorization' => 'Basic ' . base64_encode( $source['username'] . ':' . $source['password'] ),
            );
            mk_log('IMPORT', 'INFO', 'Usando autenticazione Basic per: ' . $source['url']);
        }
        $response = wp_remote_get( $source['url'], $fetch_args );

        if ( is_wp_error($response) ) {
            mk_log('IMPORT', 'ERROR', 'Fetch fallito: ' . $response->get_error_message(), array('url' => $source['url']));
            continue;
        }

        $http_code = wp_remote_retrieve_response_code($response);
        $posts     = json_decode( wp_remote_retrieve_body($response), true );

        if ( empty($posts) || ! is_array($posts) ) {
            mk_log('IMPORT', 'WARN', 'Risposta vuota o non JSON.', array('http_code' => $http_code, 'url' => $source['url']));
            continue;
        }

        mk_log('IMPORT', 'INFO', count($posts) . ' post ricevuti da sorgente.', array('url' => $source['url']));

        foreach ( $posts as $remote_post ) {
            $title         = html_entity_decode( $remote_post['title'] );
            $existing_post = get_posts(array('title' => $title, 'post_type' => 'post', 'numberposts' => 1));
            if ( ! empty($existing_post) ) {
                mk_log('IMPORT', 'INFO', 'Post già esistente, skip: ' . $title);
                continue;
            }

            $src_post_type = ! empty($source['post_type']) ? $source['post_type'] : 'post';
            $insert_args   = array(
                'post_title'   => $title,
                'post_content' => $remote_post['content'],
                'post_status'  => 'publish',
                'post_type'    => $src_post_type,
            );
            // Taxonomy assignment: 'post' uses post_category, CPTs use tax_input
            if ( ! empty($source['cat']) ) {
                if ( $src_post_type === 'post' ) {
                    $insert_args['post_category'] = array( (int)$source['cat'] );
                } else {
                    // For CPTs find the first registered hierarchical taxonomy
                    $cpt_taxes = get_object_taxonomies( $src_post_type, 'objects' );
                    foreach ( $cpt_taxes as $tax ) {
                        if ( $tax->hierarchical ) {
                            $insert_args['tax_input'][ $tax->name ] = array( (int)$source['cat'] );
                            break;
                        }
                    }
                }
            }
            $new_post_id = wp_insert_post( $insert_args );

            if ( $new_post_id && ! is_wp_error($new_post_id) ) {
                $imported_count++;
                mk_log('IMPORT', 'OK', 'Post importato: ' . $title, array('new_post_id' => $new_post_id));

                if ( ! empty($remote_post['url']) ) {
                    update_post_meta( $new_post_id, '_mk_original_url', esc_url_raw($remote_post['url']) );
                }

                // Write analytics meta from remote payload (same keys as local GA4 sync)
                $an = ! empty($remote_post['analytics']) && is_array($remote_post['analytics'])
                      ? $remote_post['analytics'] : array();

                if ( ! empty($an) ) {
                    $fields = array(
                        '_mk_ga4_views'           => 'views',
                        '_mk_ga4_sessions'        => 'sessions',
                        '_mk_ga4_active_users'    => 'active_users',
                        '_mk_ga4_new_users'       => 'new_users',
                        '_mk_ga4_avg_time'        => 'avg_time_seconds',
                        '_mk_ga4_avg_time_human'  => 'avg_time_human',
                        '_mk_ga4_bounce_rate'     => 'bounce_rate',
                        '_mk_ga4_engagement_rate' => 'engagement_rate',
                        '_mk_ga4_date_range'      => 'date_range',
                        '_mk_ga4_fetched_at'      => 'fetched_at',
                    );
                    foreach ( $fields as $meta_key => $payload_key ) {
                        if ( isset($an[$payload_key]) ) {
                            update_post_meta( $new_post_id, $meta_key, $an[$payload_key] );
                        }
                    }
                    mk_log('IMPORT', 'OK', 'Metadati analytics scritti per: ' . $title, array(
                        'post_id' => $new_post_id,
                        'views'   => $an['views'] ?? null,
                        'avg_time'=> $an['avg_time_human'] ?? null,
                    ));
                } else {
                    mk_log('IMPORT', 'INFO', 'Nessun dato analytics nel payload remoto per: ' . $title);
                }

                if ( ! empty($remote_post['image']) ) {
                    mk_upload_remote_image( $remote_post['image'], $new_post_id );
                }
            } else {
                mk_log('IMPORT', 'ERROR', 'wp_insert_post() fallito per: ' . $title,
                    is_wp_error($new_post_id) ? $new_post_id->get_error_message() : 'return false');
            }
        }
    }

    $result = ($imported_count > 0) ? "success:imported_$imported_count" : "error:no_new_posts";
    mk_log('IMPORT', $imported_count > 0 ? 'OK' : 'WARN', 'Importazione completata.', array('imported' => $imported_count));
    return $result;
}

// ─────────────────────────────────────────────
// 10. IMAGE HELPER
// ─────────────────────────────────────────────
function mk_upload_remote_image( $image_url, $post_id ) {
    require_once( ABSPATH . 'wp-admin/includes/image.php' );
    require_once( ABSPATH . 'wp-admin/includes/file.php' );
    require_once( ABSPATH . 'wp-admin/includes/media.php' );

    $clean_url  = strtok( $image_url, '?' );
    $tmp        = download_url( $image_url );
    if ( is_wp_error($tmp) ) {
        mk_log('IMAGE', 'ERROR', 'download_url() fallito.', array('url' => $image_url, 'error' => $tmp->get_error_message()));
        return;
    }

    $file_array = array('name' => basename($clean_url), 'tmp_name' => $tmp);
    $id         = media_handle_sideload( $file_array, $post_id );
    if ( ! is_wp_error($id) ) {
        set_post_thumbnail( $post_id, $id );
        mk_log('IMAGE', 'OK', 'Immagine allegata.', array('post_id' => $post_id, 'attachment_id' => $id));
    } else {
        mk_log('IMAGE', 'ERROR', 'media_handle_sideload() fallito.', array('url' => $image_url, 'error' => $id->get_error_message()));
    }
}

// ─────────────────────────────────────────────
// 11. REST API
// ─────────────────────────────────────────────
add_action( 'rest_api_init', function() {
    $perm = 'mk_api_permission_check';
    register_rest_route( 'mk/v1', '/popular-links', array(
        'methods'             => 'GET',
        'callback'            => 'mk_get_popular_permalinks_endpoint',
        'permission_callback' => $perm,
    ));
    register_rest_route( 'mk/v1', '/popular-posts', array(
        'methods'             => 'GET',
        'callback'            => 'mk_get_popular_posts_detailed_endpoint',
        'permission_callback' => $perm,
    ));
    register_rest_route( 'mk/v1', '/analytics', array(
        'methods'             => 'GET',
        'callback'            => 'mk_get_analytics_endpoint',
        'permission_callback' => $perm,
    ));
});

/**
 * REST permission callback: open if auth disabled, otherwise require HTTP Basic credentials.
 */
function mk_api_permission_check( WP_REST_Request $request ) {
    $auth = get_option( MK_API_AUTH_OPT, array() );
    if ( empty($auth['enabled']) ) return true;

    $user = $request->get_header('Authorization') ?? '';
    // Support both header and query-string fallback
    if ( empty($user) ) {
        $u = $request->get_param('mk_user') ?? '';
        $p = $request->get_param('mk_pass') ?? '';
    } else {
        // Parse "Basic base64(user:pass)"
        if ( stripos($user, 'Basic ') === 0 ) {
            $decoded = base64_decode( substr($user, 6) );
            list($u, $p) = array_pad( explode(':', $decoded, 2), 2, '' );
        } else {
            $u = $p = '';
        }
    }

    if ( $u === $auth['username'] && $p === $auth['password'] ) {
        return true;
    }

    return new WP_Error(
        'mk_unauthorized',
        'Credenziali non valide.',
        array( 'status' => 401 )
    );
}

function mk_get_popular_permalinks_endpoint() {
    $ids   = mk_get_popular_list();
    $links = array_filter( array_map('get_permalink', $ids) );
    return rest_ensure_response( array_values($links) );
}

function mk_get_popular_posts_detailed_endpoint() {
    $ids   = mk_get_popular_list();
    $store = get_option( MK_ANALYTICS_OPTION, array() );
    $ad    = ! empty($store['data']) ? $store['data'] : array();
    $data  = array();

    foreach ( $ids as $id ) {
        $post = get_post($id);
        if ( ! $post ) continue;

        $a = isset($ad[$id]) ? $ad[$id] : array();
        $data[] = array(
            'title'        => get_the_title($id),
            'content'      => apply_filters('the_content', $post->post_content),
            'image'        => get_the_post_thumbnail_url($id, 'full') ?: '',
            'date'         => get_the_date('', $id),
            'url'          => get_permalink($id),
            'original_url' => get_post_meta($id, '_mk_original_url', true) ?: '',
            'analytics'    => array(
                'views'            => $a['views']            ?? null,
                'sessions'         => $a['sessions']         ?? null,
                'active_users'     => $a['active_users']     ?? null,
                'new_users'        => $a['new_users']        ?? null,
                'avg_time_seconds' => $a['avg_time_seconds'] ?? null,
                'avg_time_human'   => $a['avg_time_human']   ?? null,
                'bounce_rate'      => $a['bounce_rate']      ?? null,
                'engagement_rate'  => $a['engagement_rate']  ?? null,
                'date_range'       => $a['date_range']       ?? null,
                'fetched_at'       => $a['fetched_at']       ?? null,
            ),
        );
    }
    return rest_ensure_response($data);
}

function mk_get_analytics_endpoint() {
    $store = get_option( MK_ANALYTICS_OPTION, array() );
    if ( empty($store['data']) ) {
        return rest_ensure_response( array(
            'status'     => 'empty',
            'message'    => 'Nessun dato analytics disponibile. Esegui una sincronizzazione GA4.',
            'fetched_at' => null,
            'data'       => array(),
        ) );
    }

    $out = array();
    foreach ( $store['data'] as $post_id => $a ) {
        $out[] = array(
            'post_id'          => (int) $post_id,
            'title'            => get_the_title( $post_id ),
            'url'              => get_permalink( $post_id ),
            'views'            => $a['views'],
            'sessions'         => $a['sessions'],
            'active_users'     => $a['active_users'],
            'new_users'        => $a['new_users'],
            'avg_time_seconds' => $a['avg_time_seconds'],
            'avg_time_human'   => $a['avg_time_human'],
            'bounce_rate'      => $a['bounce_rate'],
            'engagement_rate'  => $a['engagement_rate'],
            'date_range'       => $a['date_range'],
            'fetched_at'       => $a['fetched_at'],
        );
    }

    return rest_ensure_response( array(
        'status'     => 'ok',
        'date_range' => $store['date_range'] ?? null,
        'fetched_at' => $store['fetched_at'] ?? null,
        'count'      => count($out),
        'data'       => $out,
    ) );
}

// ─────────────────────────────────────────────
// 12. ADMIN POST ACTIONS
// ─────────────────────────────────────────────
function mk_redirect( $msg ) {
    wp_redirect( admin_url('tools.php?page=mk-analytics-settings&mk_msg=' . urlencode($msg)) );
    exit;
}

add_action( 'admin_post_mk_manual_sync', function() {
    check_admin_referer('mk_sync_action');
    $mode = get_option( MK_OP_MODE_OPT, 'both' );
    if ( $mode === 'import_only' ) {
        mk_redirect('error:ga4_disabled_by_mode');
        return;
    }
    mk_redirect( mk_fetch_ga4_top_posts() );
});

// AJAX: save date range immediately on radio change (avoids relying on main form submit)
add_action( 'wp_ajax_mk_save_date_range', function() {
    check_ajax_referer( 'mk_date_range_nonce', 'nonce' );
    if ( ! current_user_can('manage_options') ) wp_send_json_error( 'Unauthorized', 403 );
    $val     = sanitize_text_field( $_POST['value'] ?? '' );
    $allowed = array( '1daysAgo', '7daysAgo', '14daysAgo', '30daysAgo' );
    if ( ! in_array( $val, $allowed, true ) ) wp_send_json_error( 'Invalid value', 400 );
    update_option( MK_DATE_RANGE_OPT, $val );
    wp_send_json_success();
} );

add_action( 'admin_post_mk_manual_import', function() {
    check_admin_referer('mk_import_action');
    $mode = get_option( MK_OP_MODE_OPT, 'both' );
    if ( $mode === 'ga4_only' ) {
        mk_redirect('error:import_disabled_by_mode');
        return;
    }
    mk_redirect( mk_import_remote_content() );
});

add_action( 'admin_post_mk_clear_transient', function() {
    check_admin_referer('mk_clear_transient_action');
    if ( ! current_user_can('manage_options') ) wp_die('Unauthorized');
    mk_cache_delete();
    mk_log('TRANSIENT', 'INFO', 'Transient svuotato manualmente dall\'amministratore.');
    mk_redirect('transient_cleared');
});

add_action( 'admin_post_mk_cron_schedule', function() {
    check_admin_referer('mk_cron_schedule_action');
    if ( ! current_user_can('manage_options') ) wp_die('Unauthorized');
    mk_schedule_cron();
    mk_redirect('cron_scheduled');
});

add_action( 'admin_post_mk_cron_delete', function() {
    check_admin_referer('mk_cron_delete_action');
    if ( ! current_user_can('manage_options') ) wp_die('Unauthorized');
    mk_unschedule_cron();
    mk_redirect('cron_deleted');
});

/** Schedule / reschedule the import cron */
add_action( 'admin_post_mk_import_cron_schedule', function() {
    check_admin_referer('mk_import_cron_schedule_action');
    if ( ! current_user_can('manage_options') ) wp_die('Unauthorized');
    mk_schedule_import_cron();
    mk_redirect('import_cron_scheduled');
});

/** Delete the import cron */
add_action( 'admin_post_mk_import_cron_delete', function() {
    check_admin_referer('mk_import_cron_delete_action');
    if ( ! current_user_can('manage_options') ) wp_die('Unauthorized');
    mk_unschedule_import_cron();
    mk_redirect('import_cron_deleted');
});

add_action( 'admin_post_mk_clear_log', function() {
    check_admin_referer('mk_clear_log_action');
    if ( ! current_user_can('manage_options') ) wp_die('Unauthorized');
    mk_log_clear();
    mk_redirect('log_cleared');
});

// Snapshot refresh just redirects back (the snapshot is computed live)
add_action( 'admin_post_mk_run_snapshot', function() {
    check_admin_referer('mk_run_snapshot_action');
    mk_redirect('snapshot_refreshed');
});

// ─────────────────────────────────────────────
// 13. ADMIN NOTICES
// ─────────────────────────────────────────────
add_action( 'admin_notices', function() {
    if ( ! isset($_GET['mk_msg']) ) return;
    $msg    = esc_html( $_GET['mk_msg'] );
    $labels = array(
        'transient_cleared'  => '&#10003; Cache transient svuotata.',
        'cron_scheduled'     => '&#10003; Cron Job pianificato correttamente.',
        'cron_deleted'       => '&#10003; Cron Job eliminato.',
        'success'                  => '&#10003; Sincronizzazione GA4 completata.',
        'error:cache_write_failed' => '&#9888; GA4 sincronizzato ma scrittura cache DB fallita. Controlla i permessi di wp_options.',
        'log_cleared'        => '&#10003; Log svuotato.',
        'snapshot_refreshed'     => '&#10003; Snapshot aggiornato.',
        'import_cron_scheduled'  => '&#10003; Cron Import Remoto pianificato.',
        'import_cron_deleted'         => '&#10003; Cron Import Remoto eliminato.',
        'error:ga4_disabled_by_mode'  => '&#9888; GA4 sync disabilitato dalla modalità operativa (Import Only).',
        'error:import_disabled_by_mode' => '&#9888; Import disabilitato dalla modalità operativa (GA4 Only).',
    );
    $class   = ( strpos($msg, 'error') !== false ) ? 'notice-error' : 'notice-success';
    $display = isset($labels[$msg]) ? $labels[$msg] : $msg;
    echo "<div class='notice $class is-dismissible'><p><strong>MK Analytics:</strong> $display</p></div>";
});



// ─────────────────────────────────────────────
// 16b. AJAX: get terms for a post type (used by sources UI)
// ─────────────────────────────────────────────
add_action( 'wp_ajax_mk_get_terms_for_pt', function() {
    check_ajax_referer( 'mk_terms_nonce', 'nonce' );
    if ( ! current_user_can('manage_options') ) wp_die('Unauthorized');

    $pt = sanitize_key( $_GET['post_type'] ?? 'post' );

    // Find first hierarchical taxonomy for this post type
    $taxes = get_object_taxonomies( $pt, 'objects' );
    $tax   = null;
    foreach ( $taxes as $t ) {
        if ( $t->hierarchical ) { $tax = $t; break; }
    }

    if ( ! $tax ) {
        wp_send_json_success( array('tax_label' => 'Categoria', 'terms' => array()) );
        return;
    }

    $terms = get_terms( array('taxonomy' => $tax->name, 'hide_empty' => false, 'orderby' => 'name') );
    $out   = array();
    if ( ! is_wp_error($terms) ) {
        foreach ( $terms as $term ) {
            $out[] = array(
                'id'    => $term->term_id,
                'label' => str_repeat('— ', $term->depth ?? 0) . $term->name,
            );
        }
    }

    wp_send_json_success( array(
        'tax_label' => $tax->labels->singular_name,
        'terms'     => $out,
    ) );
} );

// ─────────────────────────────────────────────
// 16. DASHBOARD WIDGET
// ─────────────────────────────────────────────
add_action( 'wp_dashboard_setup', function() {
    wp_add_dashboard_widget(
        'mk_analytics_dashboard_widget',
        '&#128200; MK Analytics',
        'mk_dashboard_widget_render'
    );
} );

function mk_dashboard_widget_render() {
    // ── Collect all data ────────────────────────────────────────────────────
    $cron        = mk_cron_status();
    $cron_import = mk_import_cron_status();

    $ck_fast  = get_transient( MK_CACHE_KEY );
    $ck_db    = get_option( MK_CACHE_DB_OPTION, false );
    $ck_db_ok = $ck_db && ! empty($ck_db['ids'])
                       && isset($ck_db['expires'])
                       && time() < (int) $ck_db['expires'];

    $cache_count   = 0;
    $cache_label   = '';
    $cache_status  = 'empty'; // 'ok' | 'fallback' | 'empty'

    if ( $ck_fast !== false && ! empty($ck_fast) ) {
        $cache_count  = count( $ck_fast );
        $cache_status = 'ok';
        $cache_label  = 'Transient attivo';
    } elseif ( $ck_db_ok ) {
        $cache_count  = count( $ck_db['ids'] );
        $cache_status = 'fallback';
        $cache_label  = 'DB fallback (Redis flush?)';
    }

    $last_fetch   = isset($ck_db['saved']) ? $ck_db['saved'] : null;
    $settings_url = admin_url('tools.php?page=mk-analytics-settings');

    // ── Pill helper (inline) ────────────────────────────────────────────────
    $pill = function( $text, $color, $bg ) {
        return "<span style='display:inline-block;padding:2px 8px;border-radius:20px;"
             . "background:{$bg};color:{$color};font-size:11px;font-weight:700;'>{$text}</span>";
    };

    // ── Status colours ──────────────────────────────────────────────────────
    $green_bg  = '#edfaee'; $green_fg  = '#1a7a1e';
    $yellow_bg = '#fff8e5'; $yellow_fg = '#826200';
    $red_bg    = '#fef7f7'; $red_fg    = '#a00';
    $grey_bg   = '#f0f0f0'; $grey_fg   = '#555';

    ?>
    <style>
    #mk_analytics_dashboard_widget .mk-dw-row {
        display:flex;align-items:center;justify-content:space-between;
        padding:9px 0;border-bottom:1px solid #f0f0f0;font-size:13px;
    }
    #mk_analytics_dashboard_widget .mk-dw-row:last-child { border-bottom:none; }
    #mk_analytics_dashboard_widget .mk-dw-label { color:#555;flex-shrink:0;margin-right:8px; }
    #mk_analytics_dashboard_widget .mk-dw-value { text-align:right;font-weight:600; }
    #mk_analytics_dashboard_widget .mk-dw-footer {
        margin-top:10px;padding-top:8px;border-top:1px solid #f0f0f0;
        display:flex;align-items:center;justify-content:space-between;font-size:11px;color:#999;
    }
    </style>

    <div id="mk_analytics_dashboard_widget" style="margin:-4px 0 0;">

        <!-- VIEWS HEADLINE (shown only when analytics data exists) -->
        <?php
        $mk_an_store   = get_option( MK_ANALYTICS_OPTION, array() );
        $mk_total_views = 0;
        $mk_op_mode_cur = get_option( MK_OP_MODE_OPT, 'both' );
        $mk_range_labels = array(
            '1daysAgo'  => 'ieri',
            '7daysAgo'  => 'ultimi 7 gg',
            '14daysAgo' => 'ultime 2 sett.',
            '30daysAgo' => 'ultimo mese',
        );
        if ( ! empty($mk_an_store['data']) ) {
            foreach ( $mk_an_store['data'] as $mk_an_row ) {
                $mk_total_views += (int)($mk_an_row['views'] ?? 0);
            }
        }
        ?>
        <?php if ( $mk_total_views > 0 ) : ?>
        <div class="mk-dw-row">
            <span class="mk-dw-label">&#128065; Visualizzazioni totali</span>
            <span class="mk-dw-value">
                <strong style="font-size:15px;"><?php echo number_format($mk_total_views); ?></strong>
                <span style="font-size:11px;color:#aaa;font-weight:400;">
                    &mdash; <?php echo esc_html( $mk_range_labels[ $mk_an_store['date_range'] ?? '30daysAgo' ] ?? '' ); ?>
                </span>
            </span>
        </div>
        <?php endif; ?>

        <!-- MODE ROW -->
        <div class="mk-dw-row">
            <span class="mk-dw-label">&#9881; Modalità</span>
            <span class="mk-dw-value">
                <?php
                $mode_labels = array('ga4_only'=>'Solo GA4','import_only'=>'Solo Import','both'=>'GA4 + Import');
                echo $pill( $mode_labels[$mk_op_mode_cur] ?? $mk_op_mode_cur, '#fff', '#0073aa' );
                ?>
            </span>
        </div>

        <!-- CACHE ROW -->
        <div class="mk-dw-row">
            <span class="mk-dw-label">&#128230; Post in cache</span>
            <span class="mk-dw-value">
                <?php if ( $cache_status === 'ok' ) : ?>
                    <?php echo $pill( $cache_count . ' post', $green_fg, $green_bg ); ?>
                    <span style="font-size:11px;color:#888;font-weight:400;"> &mdash; <?php echo esc_html($cache_label); ?></span>
                <?php elseif ( $cache_status === 'fallback' ) : ?>
                    <?php echo $pill( $cache_count . ' post', $yellow_fg, $yellow_bg ); ?>
                    <span style="font-size:11px;color:#888;font-weight:400;"> &mdash; <?php echo esc_html($cache_label); ?></span>
                <?php else : ?>
                    <?php echo $pill( 'Cache vuota', $red_fg, $red_bg ); ?>
                <?php endif; ?>
            </span>
        </div>

        <!-- LAST FETCH ROW -->
        <div class="mk-dw-row">
            <span class="mk-dw-label">&#128337; Ultimo fetch GA4</span>
            <span class="mk-dw-value">
                <?php if ( $last_fetch ) : ?>
                    <span style="font-weight:400;color:#333;">
                        <?php echo esc_html( $last_fetch ); ?>
                    </span>
                    <span style="font-size:11px;color:#aaa;font-weight:400;">
                        &mdash; <?php echo esc_html( human_time_diff( strtotime($last_fetch), current_time('timestamp') ) ); ?> fa
                    </span>
                <?php else : ?>
                    <?php echo $pill( 'Mai eseguito', $grey_fg, $grey_bg ); ?>
                <?php endif; ?>
            </span>
        </div>

        <!-- CRON GA4 ROW -->
        <div class="mk-dw-row">
            <span class="mk-dw-label">&#9881; Cron GA4 Sync</span>
            <span class="mk-dw-value">
                <?php if ( $cron['active'] ) : ?>
                    <?php echo $pill( 'Attivo', $green_fg, $green_bg ); ?>
                    <span style="font-size:11px;color:#888;font-weight:400;">
                        &mdash; tra <?php echo esc_html( $cron['next_human'] ); ?>
                        &middot; ogni <?php echo esc_html( $cron['interval_h'] ); ?>h
                    </span>
                <?php else : ?>
                    <?php echo $pill( 'Non attivo', $red_fg, $red_bg ); ?>
                <?php endif; ?>
            </span>
        </div>

        <!-- CRON IMPORT ROW -->
        <div class="mk-dw-row">
            <span class="mk-dw-label">&#128256; Cron Import</span>
            <span class="mk-dw-value">
                <?php if ( $cron_import['active'] ) : ?>
                    <?php echo $pill( 'Attivo', $green_fg, $green_bg ); ?>
                    <span style="font-size:11px;color:#888;font-weight:400;">
                        &mdash; tra <?php echo esc_html( $cron_import['next_human'] ); ?>
                        &middot; ogni <?php echo esc_html( $cron_import['interval_h'] ); ?>h
                    </span>
                <?php else : ?>
                    <?php echo $pill( 'Non attivo', $red_fg, $red_bg ); ?>
                <?php endif; ?>
            </span>
        </div>

        <!-- FOOTER -->
        <div class="mk-dw-footer">
            <span>MK Analytics v<?php echo esc_html( get_plugin_data( __FILE__ )['Version'] ?? '—' ); ?></span>
            <a href="<?php echo esc_url( $settings_url ); ?>" style="color:#0073aa;">&#9881; Impostazioni</a>
        </div>

    </div>
    <?php
}
// ─────────────────────────────────────────────
// 14. PLUGIN ACTIVATION / DEACTIVATION
// ─────────────────────────────────────────────
register_activation_hook( __FILE__, function() {
    if ( ! wp_next_scheduled( MK_CRON_HOOK ) ) {
        mk_schedule_cron();
    }
    if ( ! wp_next_scheduled( MK_IMPORT_CRON_HOOK ) ) {
        mk_schedule_import_cron();
    }
});

register_deactivation_hook( __FILE__, function() {
    mk_unschedule_cron();
    mk_unschedule_import_cron();
});

// ─────────────────────────────────────────────
// 15. UTILITIES
// ─────────────────────────────────────────────
function mk_get_popular_list() {
    return mk_cache_get() ?: [];
}

// ─────────────────────────────────────────────
// 17. GITHUB SELF-UPDATER
// Hooks into the WordPress update system so that new releases published on
// GitHub appear in the dashboard exactly like official plugin updates.
// Configure MK_GITHUB_USER and MK_GITHUB_REPO at the top of this file.
// ─────────────────────────────────────────────
class MK_GitHub_Updater {

    private $plugin_file;     // 'mk-analytics-data/mk-analytics-data.php'
    private $plugin_dir;      // 'mk-analytics-data'
    private $github_user;
    private $github_repo;
    private $current_version;
    private $cache_key = 'mk_gh_release_cache';

    public function __construct() {
        $this->plugin_file     = MK_PLUGIN_SLUG;
        $this->plugin_dir      = dirname( MK_PLUGIN_SLUG );
        $this->github_user     = MK_GITHUB_USER;
        $this->github_repo     = MK_GITHUB_REPO;
        $this->current_version = MK_PLUGIN_VERSION;

        add_filter( 'pre_set_site_transient_update_plugins', array( $this, 'check_for_update' ) );
        add_filter( 'plugins_api',                           array( $this, 'plugin_info' ), 10, 3 );
        add_filter( 'upgrader_source_selection',             array( $this, 'fix_source_dir' ), 10, 4 );
        add_action( 'upgrader_process_complete',             array( $this, 'clear_cache' ), 10, 2 );
    }

    /**
     * Fetch the latest release from the GitHub API, with a 12-hour transient cache.
     * Returns the decoded JSON array, or false on failure (network error, 4xx/5xx, etc.).
     */
    private function fetch_release() {
        $cached = get_transient( $this->cache_key );
        if ( false !== $cached ) return $cached;

        $url      = "https://api.github.com/repos/{$this->github_user}/{$this->github_repo}/releases/latest";
        $response = wp_remote_get( $url, array(
            'timeout'    => 10,
            'user-agent' => 'WordPress/' . get_bloginfo('version') . '; ' . home_url(),
        ) );

        if ( is_wp_error($response) || (int) wp_remote_retrieve_response_code($response) !== 200 ) {
            return false; // fail silently — leave original transient untouched
        }

        $data = json_decode( wp_remote_retrieve_body($response), true );
        if ( empty( $data['tag_name'] ) ) return false;

        set_transient( $this->cache_key, $data, 12 * HOUR_IN_SECONDS );
        return $data;
    }

    /**
     * Inject an update object into the WordPress plugins update transient when a
     * newer version is available on GitHub.
     */
    public function check_for_update( $transient ) {
        if ( empty( $transient->checked ) ) return $transient;

        $release = $this->fetch_release();
        if ( ! $release ) return $transient;

        $remote_version = ltrim( $release['tag_name'], 'v' );

        if ( version_compare( $this->current_version, $remote_version, '<' ) ) {
            $transient->response[ $this->plugin_file ] = (object) array(
                'id'            => "github.com/{$this->github_user}/{$this->github_repo}",
                'slug'          => $this->plugin_dir,
                'plugin'        => $this->plugin_file,
                'new_version'   => $remote_version,
                'url'           => "https://github.com/{$this->github_user}/{$this->github_repo}",
                'package'       => $release['zipball_url'] ?? '',
                'icons'         => array(),
                'banners'       => array(),
                'banners_rtl'   => array(),
                'tested'        => '',
                'requires_php'  => '',
                'compatibility' => new stdClass(),
            );
        }

        return $transient;
    }

    /**
     * Return plugin metadata (version, changelog, download link) for the
     * "View version details" popup in the WordPress updates screen.
     */
    public function plugin_info( $result, $action, $args ) {
        if ( 'plugin_information' !== $action ) return $result;
        if ( ( $args->slug ?? '' ) !== $this->plugin_dir ) return $result;

        $release = $this->fetch_release();
        if ( ! $release ) return $result;

        $remote_version = ltrim( $release['tag_name'], 'v' );

        return (object) array(
            'name'          => 'MK Analytics Data',
            'slug'          => $this->plugin_dir,
            'version'       => $remote_version,
            'author'        => '<a href="https://github.com/' . esc_attr($this->github_user) . '">'
                               . esc_html($this->github_user) . '</a>',
            'homepage'      => "https://github.com/{$this->github_user}/{$this->github_repo}",
            'download_link' => $release['zipball_url'] ?? '',
            'trunk'         => $release['zipball_url'] ?? '',
            'last_updated'  => $release['published_at'] ?? '',
            'requires'      => '5.0',
            'tested'        => get_bloginfo('version'),
            'sections'      => array(
                'description' => 'High-performance GA4 most-clicked articles + Remote Content Importer.',
                'changelog'   => isset( $release['body'] )
                                 ? '<pre>' . esc_html( $release['body'] ) . '</pre>'
                                 : '',
            ),
        );
    }

    /**
     * After WordPress extracts the GitHub ZIP (named OWNER-REPO-{hash}/), rename the
     * folder to match the expected plugin directory name (mk-analytics-data/).
     * Without this rename WordPress loses track of the plugin after the update.
     */
    public function fix_source_dir( $source, $remote_source, $_upgrader, $hook_extra = array() ) {
        global $wp_filesystem;

        if ( empty( $hook_extra['plugin'] ) || $hook_extra['plugin'] !== $this->plugin_file ) {
            return $source;
        }

        $corrected = trailingslashit( $remote_source ) . $this->plugin_dir . '/';

        if ( trailingslashit( $source ) === $corrected ) {
            return $source; // already the correct folder name — nothing to do
        }

        if ( $wp_filesystem->is_dir( $corrected ) ) {
            $wp_filesystem->delete( $corrected, true ); // remove stale copy if present
        }

        if ( ! $wp_filesystem->move( $source, $corrected ) ) {
            return new WP_Error(
                'mk_updater_rename_fail',
                'Could not rename extracted plugin directory to ' . $this->plugin_dir . '.'
            );
        }

        return $corrected;
    }

    /** Delete the cached release data after a successful update. */
    public function clear_cache( $upgrader, $hook_extra ) {
        if ( ! empty( $hook_extra['plugin'] ) && $hook_extra['plugin'] === $this->plugin_file ) {
            delete_transient( $this->cache_key );
        }
    }
}

if ( is_admin() ) {
    new MK_GitHub_Updater();
}