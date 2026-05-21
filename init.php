<?php
/**
 * Plugin Name: Really Simple Cache
 * Description: Super lightweight output cache with HTML/CSS/JS minification and auto-defer scripts.
 * Version: 2.6.1
 * Author: UnicornPanel.net
 */

if (!defined('ABSPATH')) exit;
require_once __DIR__ . '/includes/class-rsc-remove-unused-css.php';
require_once __DIR__ . '/includes/class-rsc-script-deferrer.php';
require_once __DIR__ . '/includes/class-rsc-script-delayer.php';
require_once __DIR__ . '/includes/class-rsc-minifier.php';
require_once __DIR__ . '/includes/class-rsc-asset-combiner.php';

if (!function_exists('rsc_get_cache_key')) {
    /**
     * Build a stable cache key for a URI + host + scheme.
     */
    function rsc_get_cache_key($uri, $host = null, $scheme = null) {
        $uri = is_string($uri) && $uri !== '' ? $uri : '/';
        $host = $host ?: (wp_parse_url(home_url('/'), PHP_URL_HOST) ?: '');
        $scheme = $scheme ?: (is_ssl() ? 'https' : 'http');

        $host = strtolower(trim((string) $host));
        $scheme = strtolower(trim((string) $scheme));

        return md5($scheme . '://' . $host . $uri);
    }
}

if (!function_exists('rsc_get_debug_log_file')) {
    function rsc_get_debug_log_file() {
        $upload = wp_upload_dir();
        return trailingslashit($upload['basedir']) . 'really-simple-cache/debug.log';
    }
}

if (!function_exists('rsc_write_debug_log')) {
    function rsc_write_debug_log($event, $context = [], $level = 'INFO') {
        $event = trim((string) $event);
        if ($event === '') {
            return;
        }

        $log_file = rsc_get_debug_log_file();
        $dir = dirname($log_file);
        if (!is_dir($dir) && !wp_mkdir_p($dir)) {
            return;
        }

        if (!is_array($context)) {
            $context = ['value' => $context];
        }

        $normalized = [];
        foreach ($context as $key => $value) {
            if (is_scalar($value) || $value === null) {
                $normalized[$key] = $value;
                continue;
            }

            if (is_array($value) || is_object($value)) {
                $normalized[$key] = wp_json_encode($value);
                continue;
            }

            $normalized[$key] = gettype($value);
        }

        $timestamp = gmdate('Y-m-d H:i:s');
        $level = strtoupper(preg_replace('/[^A-Z]/i', '', (string) $level));
        if ($level === '') {
            $level = 'INFO';
        }

        $line = sprintf(
            "[%s UTC] [%s] %s | %s\n",
            $timestamp,
            $level,
            $event,
            wp_json_encode($normalized)
        );

        if (@file_put_contents($log_file, $line, FILE_APPEND | LOCK_EX) === false) {
            return;
        }

        $raw = @file_get_contents($log_file);
        if (!is_string($raw) || $raw === '') {
            return;
        }

        $lines = preg_split('/\r\n|\r|\n/', trim($raw));
        if (!is_array($lines) || count($lines) <= 1000) {
            return;
        }

        $lines = array_slice($lines, -1000);
        @file_put_contents($log_file, implode("\n", $lines) . "\n", LOCK_EX);
    }
}

class ReallySimpleCache {

    private $cache_dir;
    private $cache_url;
    private $settings;
    private $rucss;
    private $script_deferrer;
    private $script_delayer;
    private $minifier;
    private $asset_combiner;

    public function __construct() {
        $upload = wp_upload_dir();

        $this->cache_dir = trailingslashit($upload['basedir']) . 'really-simple-cache/';
        $this->cache_url = trailingslashit($upload['baseurl']) . 'really-simple-cache/';
        $this->settings = $this->load_settings();

        add_action('init', [$this, 'create_dirs']);

        add_action('template_redirect', [$this, 'serve_cache'], 0);
        add_action('template_redirect', [$this, 'start_buffer'], 1);
        add_action('shutdown', [$this, 'end_buffer'], 9999);

        add_action('save_post', [$this, 'purge_all_cache']);
        add_action('deleted_post', [$this, 'purge_all_cache']);
        add_action('trashed_post', [$this, 'purge_all_cache']);
        add_action('clean_post_cache', [$this, 'purge_all_cache']);
        add_action('wp_update_nav_menu', [$this, 'purge_all_cache']);
        add_action('switch_theme', [$this, 'purge_all_cache']);
        add_action('customize_save_after', [$this, 'purge_all_cache']);
        add_action('comment_post', [$this, 'purge_all_cache']);
        add_action('edit_comment', [$this, 'purge_all_cache']);
        add_action('wp_set_comment_status', [$this, 'purge_all_cache']);
        add_action('rsc_download_font_css', [$this, 'handle_font_css_download'], 10, 1);

        add_filter('get_avatar_url', [$this, 'filter_avatar_url'], 10, 3);

        if (is_admin()) {
            add_action('admin_menu', [$this, 'register_settings_page']);
            add_action('admin_init', [$this, 'register_settings']);
            add_action('admin_post_rsc_clear_debug_log', [$this, 'handle_clear_debug_log']);
            add_action('wp_ajax_rsc_refresh_debug_log', [$this, 'ajax_refresh_debug_log']);
        }

        //$this->log('Plugin initialized', [
        //    'cache_dir' => $this->cache_dir,
        //]);
    }

    private function defaults() {
        return [
            'enable_page_cache' => 1,
            'cache_ttl' => 3600,
            'minify_html' => 1,
            'minify_css' => 1,
            'minify_js' => 1,
            'defer_scripts' => 1,
            'delay_scripts' => 0,
            'debug_footer' => 1,
            'combine_css' => 0,
            'combine_js' => 0,
            'local_avatars' => 0,
            'local_fonts' => 0,
            'remove_unused_css' => 0,
            'asset_ttl' => 604800,
            'rucss_max_css_kb' => 512,
            'excluded_pages' => '',
            'excluded_css' => '',
            'excluded_js' => '',
            'excluded_delay_js' => '',
            'rucss_excluded_pages' => '',
            'rucss_keep_selectors' => '',
        ];
    }

    private function load_settings() {
        $stored = get_option('rsc_settings', []);
        if (!is_array($stored)) {
            $stored = [];
        }

        return wp_parse_args($stored, $this->defaults());
    }

    private function setting_enabled($key) {
        return !empty($this->settings[$key]);
    }

    private function setting_int($key, $fallback = 0) {
        if (!isset($this->settings[$key])) {
            return (int) $fallback;
        }

        return (int) $this->settings[$key];
    }

    private function log($event, $context = [], $level = 'INFO') {
        if (!is_array($context)) {
            $context = ['value' => $context];
        }

        if (!isset($context['request_uri'])) {
            $context['request_uri'] = isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '';
        }

        rsc_write_debug_log($event, $context, $level);
    }

    private function get_debug_log_contents($max_lines = 300) {
        $file = rsc_get_debug_log_file();
        if (!is_file($file) || !is_readable($file)) {
            return '';
        }

        $raw = @file_get_contents($file);
        if (!is_string($raw) || $raw === '') {
            return '';
        }

        $lines = preg_split('/\r\n|\r|\n/', trim($raw));
        if (!is_array($lines) || empty($lines)) {
            return '';
        }

        $slice = array_slice($lines, -max(1, (int) $max_lines));
        return implode("\n", $slice);
    }

    public function register_settings_page() {
        add_options_page(
            'RS Cache',
            'RS Cache',
            'manage_options',
            'rsc-settings',
            [$this, 'render_settings_page']
        );
    }

    public function register_settings() {
        register_setting('rsc_settings_group', 'rsc_settings', [$this, 'sanitize_settings']);
    }

    public function handle_clear_debug_log() {
        if (!current_user_can('manage_options')) {
            wp_die('No permission.');
        }

        check_admin_referer('rsc_clear_debug_log');

        $file = rsc_get_debug_log_file();
        if (is_file($file)) {
            @file_put_contents($file, '');
        }

        $this->log('Debug log cleared by admin user', [
            'user_id' => get_current_user_id(),
        ]);

        wp_safe_redirect(admin_url('options-general.php?page=rsc-settings&debug_log_cleared=1'));
        exit;
    }

    public function ajax_refresh_debug_log() {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'No permission.'], 403);
        }

        check_ajax_referer('rsc_refresh_debug_log');

        wp_send_json_success([
            'log' => $this->get_debug_log_contents(300),
        ]);
    }

    public function sanitize_settings($input) {
        $defaults = $this->defaults();
        $input = is_array($input) ? $input : [];

        $settings = [
            'enable_page_cache' => empty($input['enable_page_cache']) ? 0 : 1,
            'minify_html' => empty($input['minify_html']) ? 0 : 1,
            'minify_css' => empty($input['minify_css']) ? 0 : 1,
            'minify_js' => empty($input['minify_js']) ? 0 : 1,
            'defer_scripts' => empty($input['defer_scripts']) ? 0 : 1,
            'delay_scripts' => empty($input['delay_scripts']) ? 0 : 1,
            'debug_footer' => empty($input['debug_footer']) ? 0 : 1,
            'combine_css' => empty($input['combine_css']) ? 0 : 1,
            'combine_js' => empty($input['combine_js']) ? 0 : 1,
            'local_avatars' => empty($input['local_avatars']) ? 0 : 1,
            'local_fonts' => empty($input['local_fonts']) ? 0 : 1,
            'remove_unused_css' => empty($input['remove_unused_css']) ? 0 : 1,
            'cache_ttl' => isset($input['cache_ttl']) ? max(60, (int) $input['cache_ttl']) : $defaults['cache_ttl'],
            'asset_ttl' => isset($input['asset_ttl']) ? max(3600, (int) $input['asset_ttl']) : $defaults['asset_ttl'],
            'rucss_max_css_kb' => isset($input['rucss_max_css_kb']) ? max(32, (int) $input['rucss_max_css_kb']) : $defaults['rucss_max_css_kb'],
            'excluded_pages' => isset($input['excluded_pages']) ? sanitize_textarea_field($input['excluded_pages']) : '',
            'excluded_css' => isset($input['excluded_css']) ? sanitize_textarea_field($input['excluded_css']) : '',
            'excluded_js' => isset($input['excluded_js']) ? sanitize_textarea_field($input['excluded_js']) : '',
            'excluded_delay_js' => isset($input['excluded_delay_js']) ? sanitize_textarea_field($input['excluded_delay_js']) : '',
            'rucss_excluded_pages' => isset($input['rucss_excluded_pages']) ? sanitize_textarea_field($input['rucss_excluded_pages']) : '',
            'rucss_keep_selectors' => isset($input['rucss_keep_selectors']) ? sanitize_textarea_field($input['rucss_keep_selectors']) : '',
        ];

        $this->settings = wp_parse_args($settings, $defaults);
        $this->purge_all_cache();
        $this->log('Settings saved and cache purged', [
            'user_id' => get_current_user_id(),
            'enable_page_cache' => (int) $this->settings['enable_page_cache'],
            'minify_html' => (int) $this->settings['minify_html'],
            'minify_css' => (int) $this->settings['minify_css'],
            'minify_js' => (int) $this->settings['minify_js'],
            'defer_scripts' => (int) $this->settings['defer_scripts'],
            'delay_scripts' => (int) $this->settings['delay_scripts'],
            'combine_css' => (int) $this->settings['combine_css'],
            'combine_js' => (int) $this->settings['combine_js'],
            'local_avatars' => (int) $this->settings['local_avatars'],
            'local_fonts' => (int) $this->settings['local_fonts'],
            'remove_unused_css' => (int) $this->settings['remove_unused_css'],
        ]);

        return $this->settings;
    }

    public function render_settings_page() {
        if (!current_user_can('manage_options')) {
            return;
        }

        $s = $this->settings;
        $debug_log = $this->get_debug_log_contents(300);
        $refresh_nonce = wp_create_nonce('rsc_refresh_debug_log');
        ?>
        <div class="wrap rsc-settings-wrap">
            <h1>RS Cache</h1>
            <p>Performance controls for page caching, asset optimization, and local third-party assets.</p>

            <form method="post" action="options.php">
                <?php settings_fields('rsc_settings_group'); ?>

                <div class="rsc-grid">
                    <div class="rsc-card">
                        <h2>Core Cache</h2>
                        <?php $this->render_toggle('enable_page_cache', 'Enable Page Cache', 'Enable or disable full-page guest caching.'); ?>
                        <?php $this->render_toggle('debug_footer', 'Show Debug Footer', 'Adds a small cache timestamp comment to cached HTML.'); ?>

                        <label class="rsc-field">
                            <span>Page Cache TTL (seconds)</span>
                            <input type="number" min="60" step="60" name="rsc_settings[cache_ttl]" value="<?php echo esc_attr((int) $s['cache_ttl']); ?>" />
                        </label>
                    </div>

                    <div class="rsc-card">
                        <h2>HTML / CSS / JS</h2>
                        <?php $this->render_toggle('minify_html', 'Minify HTML', 'Removes extra whitespace between tags.'); ?>
                        <?php $this->render_toggle('minify_css', 'Minify CSS', 'Minifies external and inline CSS.'); ?>
                        <?php $this->render_toggle('minify_js', 'Minify JS', 'Minifies external and inline JavaScript.'); ?>
                        <?php $this->render_toggle('defer_scripts', 'Defer Scripts', 'Adds defer to eligible scripts.'); ?>
                        <?php $this->render_toggle('delay_scripts', 'Delay Scripts', 'Delays eligible external scripts until user interaction or timeout.'); ?>
                    </div>

                    <div class="rsc-card">
                        <h2>Additional Optimizations</h2>
                        <?php $this->render_toggle('combine_css', 'Combine CSS Files', 'Bundles same-domain stylesheet files into combined output.'); ?>
                        <?php $this->render_toggle('combine_js', 'Combine JS Files', 'Bundles same-domain head scripts into a combined file.'); ?>
                        <?php $this->render_toggle('local_avatars', 'Store Gravatar Avatars Locally', 'Caches Gravatar image responses in local uploads.'); ?>
                        <?php $this->render_toggle('local_fonts', 'Store Bunny and Google Fonts Locally', 'Downloads and rewrites Google/Bunny font stylesheets and font files.'); ?>
                        <?php $this->render_toggle('remove_unused_css', 'Remove Unused CSS', 'Static pruning for same-domain external CSS. Off by default.'); ?>

                        <label class="rsc-field">
                            <span>Remote Asset TTL (seconds)</span>
                            <input type="number" min="3600" step="3600" name="rsc_settings[asset_ttl]" value="<?php echo esc_attr((int) $s['asset_ttl']); ?>" />
                        </label>
                    </div>

                    <div class="rsc-card rsc-card--full">
                        <h2>Exclusions</h2>
                        <p class="rsc-help">Use one pattern per line. Wildcards are supported, for example: <code>/checkout*</code> or <code>*jquery*</code>.</p>

                        <label class="rsc-field">
                            <span>Excluded Pages (allow wildcards)</span>
                            <textarea name="rsc_settings[excluded_pages]" rows="4" placeholder="/checkout*&#10;/my-account*"><?php echo esc_textarea((string) $s['excluded_pages']); ?></textarea>
                        </label>

                        <label class="rsc-field">
                            <span>Excluded CSS (allow wildcards)</span>
                            <textarea name="rsc_settings[excluded_css]" rows="4" placeholder="*/wp-content/plugins/some-plugin/*&#10;*critical.css*"><?php echo esc_textarea((string) $s['excluded_css']); ?></textarea>
                        </label>

                        <label class="rsc-field">
                            <span>Excluded JavaScript (allow wildcards)</span>
                            <textarea name="rsc_settings[excluded_js]" rows="4" placeholder="*gtag/js*&#10;*recaptcha*"><?php echo esc_textarea((string) $s['excluded_js']); ?></textarea>
                        </label>

                        <label class="rsc-field">
                            <span>Excluded Delayed JavaScript (allow wildcards)</span>
                            <textarea name="rsc_settings[excluded_delay_js]" rows="4" placeholder="*jquery*&#10;*recaptcha*"><?php echo esc_textarea((string) $s['excluded_delay_js']); ?></textarea>
                        </label>
                    </div>

                    <div class="rsc-card rsc-card--full">
                        <h2>Remove Unused CSS</h2>
                        <p class="rsc-help">This feature is conservative, but JavaScript-driven UIs may still need safelisted selectors to avoid style regressions.</p>

                        <label class="rsc-field">
                            <span>RU-CSS Excluded Pages (allow wildcards)</span>
                            <textarea name="rsc_settings[rucss_excluded_pages]" rows="4" placeholder="/checkout*&#10;/my-account*"><?php echo esc_textarea((string) $s['rucss_excluded_pages']); ?></textarea>
                        </label>

                        <label class="rsc-field">
                            <span>RU-CSS Keep Selectors (Safelist, one pattern per line)</span>
                            <textarea name="rsc_settings[rucss_keep_selectors]" rows="4" placeholder=".is-active&#10;#mobile-menu-open&#10;*[data-state=*]"><?php echo esc_textarea((string) $s['rucss_keep_selectors']); ?></textarea>
                        </label>

                        <label class="rsc-field">
                            <span>Max CSS Size Per File (KB)</span>
                            <input type="number" min="32" step="32" name="rsc_settings[rucss_max_css_kb]" value="<?php echo esc_attr((int) $s['rucss_max_css_kb']); ?>" />
                        </label>
                    </div>

                    <div class="rsc-card rsc-card--full">
                        <h2>Debug Log</h2>
                        <p class="rsc-help">Latest log lines (file capped at 1000 lines): <code><?php echo esc_html(rsc_get_debug_log_file()); ?></code></p>
                        <textarea class="rsc-debug-log" readonly><?php echo esc_textarea($debug_log); ?></textarea>
                        <p>
                            <button
                                type="button"
                                class="button rsc-refresh-log"
                                data-ajax-url="<?php echo esc_url(admin_url('admin-ajax.php')); ?>"
                                data-nonce="<?php echo esc_attr($refresh_nonce); ?>"
                            >Refresh Log</button>
                        </p>
                    </div>
                </div>

                <?php submit_button('Save RS Cache Settings'); ?>
            </form>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <?php wp_nonce_field('rsc_clear_debug_log'); ?>
                <input type="hidden" name="action" value="rsc_clear_debug_log" />
                <?php submit_button('Clear Debug Log', 'delete', 'submit', false); ?>
            </form>
        </div>

        <style>
            .rsc-settings-wrap p { max-width: 900px; }
            .rsc-grid {
                display: grid;
                grid-template-columns: repeat(auto-fit, minmax(320px, 1fr));
                gap: 30px;
                margin-top: 18px;
                max-width: 1200px;
            }
            .rsc-card {
                background: linear-gradient(155deg, #ffffff 0%, #f7fafc 100%);
                border: 1px solid #d8e1ea;
                border-radius: 14px;
                padding: 30px;
                box-shadow: 0 6px 24px rgba(18, 52, 86, 0.06);
            }
            .rsc-card h2 {
                margin-top: 0;
                font-size: 18px;
            }
            .rsc-card--full {
                grid-column: 1 / -1;
            }
            .rsc-toggle {
                display: grid;
                grid-template-columns: 1fr auto;
                gap: 10px;
                align-items: center;
                padding: 10px 0;
                border-bottom: 1px solid #ecf1f5;
            }
            .rsc-toggle:last-of-type { border-bottom: 0; }
            .rsc-toggle strong { display: block; margin-bottom: 3px; }
            .rsc-toggle small { color: #516170; }
            .rsc-switch {
                position: relative;
                display: inline-block;
                width: 54px;
                height: 30px;
            }
            .rsc-switch input {
                opacity: 0;
                width: 0;
                height: 0;
            }
            .rsc-slider {
                position: absolute;
                inset: 0;
                cursor: pointer;
                border-radius: 30px;
                background: #ccd7e2;
                transition: .25s ease;
            }
            .rsc-slider:before {
                content: "";
                position: absolute;
                left: 4px;
                top: 4px;
                width: 22px;
                height: 22px;
                border-radius: 50%;
                background: #fff;
                transition: .25s ease;
                box-shadow: 0 1px 6px rgba(0, 0, 0, .2);
            }
            .rsc-switch input:checked + .rsc-slider {
                background: linear-gradient(120deg, #1f9d55, #1aff85);
            }
            .rsc-switch input:checked + .rsc-slider:before {
                transform: translateX(24px);
            }
            .rsc-field {
                display: grid;
                gap: 8px;
                margin-top: 14px;
            }
            .rsc-field span {
                font-weight: 600;
            }
            .rsc-field input[type="number"] {
                max-width: 220px;
            }
            .rsc-field textarea {
                width: 100%;
                min-height: 96px;
                font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", "Courier New", monospace;
            }
            .rsc-help {
                margin: 0 0 14px;
                color: #516170;
            }
            .rsc-debug-log {
                width: 100%;
                min-height: 320px;
                font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", "Courier New", monospace;
                white-space: pre;
            }
        </style>
        <script>
            (function () {
                var logBox = document.querySelector('.rsc-debug-log');
                var refreshButton = document.querySelector('.rsc-refresh-log');

                function scrollLogToBottom() {
                    if (!logBox) return;
                    logBox.scrollTop = logBox.scrollHeight;
                }

                scrollLogToBottom();

                if (!refreshButton || !logBox) {
                    return;
                }

                refreshButton.addEventListener('click', function () {
                    var ajaxUrl = refreshButton.getAttribute('data-ajax-url');
                    var nonce = refreshButton.getAttribute('data-nonce');
                    if (!ajaxUrl || !nonce) {
                        return;
                    }

                    var originalLabel = refreshButton.textContent;
                    refreshButton.disabled = true;
                    refreshButton.textContent = 'Refreshing...';

                    var body = new URLSearchParams();
                    body.set('action', 'rsc_refresh_debug_log');
                    body.set('_ajax_nonce', nonce);

                    fetch(ajaxUrl, {
                        method: 'POST',
                        credentials: 'same-origin',
                        headers: {
                            'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'
                        },
                        body: body.toString()
                    }).then(function (response) {
                        return response.json();
                    }).then(function (data) {
                        if (data && data.success && data.data && typeof data.data.log === 'string') {
                            logBox.value = data.data.log;
                            scrollLogToBottom();
                        }
                    }).finally(function () {
                        refreshButton.disabled = false;
                        refreshButton.textContent = originalLabel;
                    });
                });
            })();
        </script>
        <?php
    }

    private function render_toggle($key, $label, $help) {
        $checked = !empty($this->settings[$key]);
        ?>
        <label class="rsc-toggle" for="rsc-<?php echo esc_attr($key); ?>">
            <span>
                <strong><?php echo esc_html($label); ?></strong>
                <small><?php echo esc_html($help); ?></small>
            </span>
            <span class="rsc-switch">
                <input id="rsc-<?php echo esc_attr($key); ?>" type="checkbox" name="rsc_settings[<?php echo esc_attr($key); ?>]" value="1" <?php checked($checked); ?> />
                <span class="rsc-slider"></span>
            </span>
        </label>
        <?php
    }

    public function create_dirs() {
        $paths = [
            $this->cache_dir,
            $this->cache_dir . 'pages/',
            $this->cache_dir . 'css/',
            $this->cache_dir . 'js/',
            $this->cache_dir . 'rucss/',
            $this->cache_dir . 'avatars/',
            $this->cache_dir . 'fonts/',
            $this->cache_dir . 'fonts/css/',
            $this->cache_dir . 'fonts/files/',
        ];

        $created = 0;
        foreach ($paths as $path) {
            if (!file_exists($path)) {
                if (wp_mkdir_p($path)) {
                    $created++;
                }
            }
        }

        if ($created > 0) {
            $this->log('Created cache directories', ['count' => $created]);
        }
    }

    private function cache_ttl() {
        return max(60, $this->setting_int('cache_ttl', 3600));
    }

    private function asset_ttl() {
        return max(3600, $this->setting_int('asset_ttl', 604800));
    }

    private function get_exclusion_patterns($setting_key) {
        $value = isset($this->settings[$setting_key]) ? (string) $this->settings[$setting_key] : '';
        if ($value === '') {
            return [];
        }

        $lines = preg_split('/\r\n|\r|\n/', $value);
        if (!is_array($lines)) {
            return [];
        }

        $patterns = [];
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            $patterns[] = $line;
        }

        return $patterns;
    }

    private function wildcard_match($pattern, $subject) {
        $regex = preg_quote($pattern, '/');
        $regex = str_replace(['\*', '\?'], ['.*', '.'], $regex);
        return preg_match('/^' . $regex . '$/i', (string) $subject) === 1;
    }

    private function matches_any_pattern($subject, $patterns) {
        foreach ((array) $patterns as $pattern) {
            if ($this->wildcard_match($pattern, $subject)) {
                return true;
            }
        }

        return false;
    }

    private function is_current_page_excluded() {
        $patterns = $this->get_exclusion_patterns('excluded_pages');
        if (empty($patterns)) {
            return false;
        }

        $uri = isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '/';
        $path = wp_parse_url($uri, PHP_URL_PATH);
        $path = is_string($path) && $path !== '' ? $path : $uri;

        return $this->matches_any_pattern($uri, $patterns) || $this->matches_any_pattern($path, $patterns);
    }

    private function is_asset_excluded($url, $type) {
        $setting = ($type === 'css') ? 'excluded_css' : 'excluded_js';
        $patterns = $this->get_exclusion_patterns($setting);
        if (empty($patterns)) {
            return false;
        }

        $url = (string) $url;
        $parsed_path = wp_parse_url($url, PHP_URL_PATH);
        $path = is_string($parsed_path) ? $parsed_path : $url;

        return $this->matches_any_pattern($url, $patterns) || $this->matches_any_pattern($path, $patterns);
    }

    private function is_delay_script_excluded($url) {
        if ($this->is_asset_excluded($url, 'js')) {
            return true;
        }

        $patterns = $this->get_exclusion_patterns('excluded_delay_js');
        if (empty($patterns)) {
            return false;
        }

        $url = (string) $url;
        $parsed_path = wp_parse_url($url, PHP_URL_PATH);
        $path = is_string($parsed_path) ? $parsed_path : $url;

        return $this->matches_any_pattern($url, $patterns) || $this->matches_any_pattern($path, $patterns);
    }

    private function is_cacheable_request(&$reason = null) {
        if (!$this->setting_enabled('enable_page_cache')) {
            $reason = 'page-cache-disabled';
            return false;
        }

        if ($this->is_current_page_excluded()) {
            $reason = 'page-excluded';
            return false;
        }

        if (is_user_logged_in()) {
            $reason = 'logged-in-user';
            return false;
        }

        if (is_preview() || is_customize_preview()) {
            $reason = 'preview-request';
            return false;
        }

        if (php_sapi_name() === 'cli') {
            $reason = 'cli-context';
            return false;
        }

        if (!isset($_SERVER['REQUEST_METHOD']) || $_SERVER['REQUEST_METHOD'] !== 'GET') {
            $reason = 'non-get-request';
            return false;
        }

        if (defined('DOING_AJAX') && DOING_AJAX) {
            $reason = 'ajax-request';
            return false;
        }

        if (defined('REST_REQUEST') && REST_REQUEST) {
            $reason = 'rest-request';
            return false;
        }

        if (isset($_GET['preview']) || isset($_GET['customize_changeset_uuid'])) {
            $reason = 'preview-query-flag';
            return false;
        }

        $bypass_cookies = (array) apply_filters('rsc_bypass_cookies', [
            'wordpress_logged_in_',
            'wordpress_sec_',
            'comment_author_',
            'wp-postpass_',
            'woocommerce_',
            'wp_woocommerce_session_',
            'edd_items_in_cart',
            'edd_cart_hash',
        ]);

        if (!empty($_COOKIE)) {
            foreach (array_keys($_COOKIE) as $cookie_name) {
                foreach ($bypass_cookies as $prefix) {
                    if ($prefix !== '' && strpos($cookie_name, $prefix) === 0) {
                        $reason = 'bypass-cookie:' . $prefix;
                        return false;
                    }
                }
            }
        }

        if (is_feed() || is_trackback() || is_robots() || is_search()) {
            $reason = 'non-html-endpoint';
            return false;
        }

        $reason = 'ok';
        return true;
    }

    /**
     * Requests eligible for output optimization, even when page caching is bypassed.
     */
    private function can_optimize_output_request(&$reason = null) {
        if (is_admin()) {
            $reason = 'admin-request';
            return false;
        }

        if ($this->is_current_page_excluded()) {
            $reason = 'page-excluded';
            return false;
        }

        // Disable all output optimizations for logged-in users.
        if (is_user_logged_in()) {
            $reason = 'logged-in-user';
            return false;
        }

        if (php_sapi_name() === 'cli') {
            $reason = 'cli-context';
            return false;
        }

        if (!isset($_SERVER['REQUEST_METHOD']) || $_SERVER['REQUEST_METHOD'] !== 'GET') {
            $reason = 'non-get-request';
            return false;
        }

        if (defined('DOING_AJAX') && DOING_AJAX) {
            $reason = 'ajax-request';
            return false;
        }

        if (defined('REST_REQUEST') && REST_REQUEST) {
            $reason = 'rest-request';
            return false;
        }

        if (is_feed() || is_trackback() || is_robots()) {
            $reason = 'non-html-endpoint';
            return false;
        }

        $reason = 'ok';
        return true;
    }

    private function get_cache_file() {
        $uri = isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '/';
        $host = isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : wp_parse_url(home_url('/'), PHP_URL_HOST);
        $scheme = is_ssl() ? 'https' : 'http';
        $key = rsc_get_cache_key($uri, $host, $scheme);

        return $this->cache_dir . 'pages/' . $key . '.html';
    }

    private function get_cache_header_file() {
        $uri = isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '/';
        $host = isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : wp_parse_url(home_url('/'), PHP_URL_HOST);
        $scheme = is_ssl() ? 'https' : 'http';
        $key = rsc_get_cache_key($uri, $host, $scheme);

        return $this->cache_dir . 'pages/' . $key . '.headers.json';
    }

    private function get_cache_meta_file() {
        $uri = isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '/';
        $host = isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : wp_parse_url(home_url('/'), PHP_URL_HOST);
        $scheme = is_ssl() ? 'https' : 'http';
        $key = rsc_get_cache_key($uri, $host, $scheme);

        return $this->cache_dir . 'pages/' . $key . '.meta.json';
    }

    public function serve_cache() {
        $reason = '';
        if (!$this->is_cacheable_request($reason)) {
            return;
        }

        $file = $this->get_cache_file();
        $header_file = $this->get_cache_header_file();

        if (file_exists($file) && (time() - filemtime($file)) < $this->cache_ttl()) {
            $mtime = filemtime($file);
            $ttl = $this->cache_ttl();

            $cached_header_names = [];
            foreach ($this->read_cached_headers($header_file) as $header_line) {
                $name = strtolower(trim(strtok($header_line, ':')));
                if ($name !== '') {
                    $cached_header_names[$name] = true;
                }
                $replace = ($name === 'content-type');
                header($header_line, $replace);
            }

            header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $mtime) . ' GMT');
            if (empty($cached_header_names['cache-control'])) {
                header("Cache-Control: public, max-age={$ttl}");
            }
            if (empty($cached_header_names['expires'])) {
                header('Expires: ' . gmdate('D, d M Y H:i:s', time() + $ttl) . ' GMT');
            }
            header('X-RSC-Cache: HIT');
            if (empty($cached_header_names['content-type'])) {
                header('Content-Type: text/html; charset=UTF-8');
            }
            if (empty($cached_header_names['vary'])) {
                header('Vary: Cookie');
            }

            $etag = '"' . md5_file($file) . '"';
            header("ETag: {$etag}");

            if (isset($_SERVER['HTTP_IF_NONE_MATCH']) && trim($_SERVER['HTTP_IF_NONE_MATCH']) === $etag) {
                $this->log('Cache 304 response', [
                    'file' => basename($file),
                    'reason' => $reason,
                ]);
                header('HTTP/1.1 304 Not Modified');
                exit;
            }

            $this->log('Cache HIT served', [
                'file' => basename($file),
                'ttl' => $ttl,
            ]);
            readfile($file);
            exit;
        }

        $this->log('Cache MISS from disk', [
            'file' => basename($file),
        ]);
    }

    public function start_buffer() {
        $optimize_reason = '';
        if (!$this->can_optimize_output_request($optimize_reason)) {
            return;
        }

        $cache_reason = '';
        if ($this->is_cacheable_request($cache_reason)) {
            header('X-RSC-Cache: MISS');
            $this->log('Output buffering started', [
                'cache' => 'MISS',
            ]);
        } else {
            header('X-RSC-Cache: BYPASS');
            $this->log('Output buffering started', [
                'cache' => 'BYPASS',
                'reason' => $cache_reason,
            ]);
        }
        ob_start([$this, 'process_output']);
    }

    public function end_buffer() {
        if (ob_get_level() > 0 && ob_get_length() !== false) {
            ob_end_flush();
        }
    }

    private function minify_html($html) {
        return $this->get_minifier()->minify_html((string) $html);
    }

    private function minify_css($css) {
        return $this->get_minifier()->minify_css((string) $css);
    }

    private function get_rucss_module() {
        if (!($this->rucss instanceof RSC_Remove_Unused_CSS)) {
            $this->rucss = new RSC_Remove_Unused_CSS($this->cache_dir, $this->cache_url);
        }

        return $this->rucss;
    }

    private function get_script_deferrer() {
        if (!($this->script_deferrer instanceof RSC_Script_Deferrer)) {
            $this->script_deferrer = new RSC_Script_Deferrer();
        }

        return $this->script_deferrer;
    }

    private function get_script_delayer() {
        if (!($this->script_delayer instanceof RSC_Script_Delayer)) {
            $this->script_delayer = new RSC_Script_Delayer();
        }

        return $this->script_delayer;
    }

    private function get_minifier() {
        if (!($this->minifier instanceof RSC_Minifier)) {
            $this->minifier = new RSC_Minifier(__DIR__);
        }

        return $this->minifier;
    }

    private function get_asset_combiner() {
        if (!($this->asset_combiner instanceof RSC_Asset_Combiner)) {
            $this->asset_combiner = new RSC_Asset_Combiner();
        }

        return $this->asset_combiner;
    }

    private function minify_js($js) {
        return $this->get_minifier()->minify_js((string) $js);
    }

    private function is_same_domain($url) {
        if (strpos($url, '//') === 0) {
            $url = (is_ssl() ? 'https:' : 'http:') . $url;
        }

        $home_host = wp_parse_url(home_url(), PHP_URL_HOST);
        $url_host  = wp_parse_url($url, PHP_URL_HOST);

        if (!$url_host) {
            return true;
        }

        return (strtolower($home_host) === strtolower($url_host));
    }

    private function url_to_path($url) {
        if (strpos($url, '//') === 0) {
            $url = (is_ssl() ? 'https:' : 'http:') . $url;
        }

        $parsed = wp_parse_url($url);
        if (!$parsed || empty($parsed['path'])) {
            return false;
        }

        $path = $parsed['path'];

        $home_path = wp_parse_url(home_url('/'), PHP_URL_PATH);
        if (is_string($home_path) && $home_path !== '/' && strpos($path, $home_path) === 0) {
            $path = substr($path, strlen($home_path));
            if ($path === false || $path === '') {
                return false;
            }
        }

        $relative = ltrim(rawurldecode($path), '/');
        if ($relative === '') {
            return false;
        }

        $full = wp_normalize_path(ABSPATH . $relative);
        $root = wp_normalize_path(trailingslashit(ABSPATH));

        if (strpos($full, $root) !== 0) {
            return false;
        }

        return $full;
    }

    private function write_file_atomically($file, $contents) {
        $dir = dirname($file);
        if (!is_dir($dir) && !wp_mkdir_p($dir)) {
            $this->log('Failed to create directory for write', ['dir' => $dir], 'WARN');
            return false;
        }

        $tmp = $dir . '/.' . basename($file) . '.tmp-' . wp_rand(1000, 999999);
        if (file_put_contents($tmp, $contents, LOCK_EX) === false) {
            if (file_exists($tmp)) {
                unlink($tmp);
            }
            $this->log('Failed writing temporary file', ['tmp_file' => $tmp], 'WARN');
            return false;
        }

        if (!rename($tmp, $file)) {
            unlink($tmp);
            $this->log('Failed moving temporary file into place', ['file' => $file], 'WARN');
            return false;
        }

        return true;
    }

    private function parse_attr($tag, $attr) {
        if (!preg_match('/\b' . preg_quote($attr, '/') . '\s*=\s*(["\'])(.*?)\1/i', $tag, $m)) {
            return null;
        }

        return $m[2];
    }

    private function parse_rel_tokens($tag) {
        $rel = strtolower((string) $this->parse_attr($tag, 'rel'));
        if ($rel === '') {
            return [];
        }
        $tokens = preg_split('/\s+/', trim($rel));
        return is_array($tokens) ? $tokens : [];
    }

    private function get_stylesheet_href_from_tag($tag) {
        $rel_tokens = $this->parse_rel_tokens($tag);
        if (!in_array('stylesheet', $rel_tokens, true)) {
            return null;
        }

        $href = $this->parse_attr($tag, 'href');
        if (!is_string($href) || $href === '') {
            return null;
        }

        return html_entity_decode($href);
    }

    private function has_attr($tag, $attr) {
        return preg_match('/\b' . preg_quote($attr, '/') . '\b/i', $tag) === 1;
    }

    private function is_module_script_tag($tag) {
        return preg_match('/\btype\s*=\s*(["\']?)module\1/i', $tag) === 1;
    }

    private function build_absolute_url($base_url, $maybe_relative_url) {
        $url = trim((string) $maybe_relative_url);
        if ($url === '') {
            return '';
        }

        if (strpos($url, 'data:') === 0 || strpos($url, '#') === 0 || strpos($url, '%23') === 0) {
            return $url;
        }

        if (preg_match('#^https?://#i', $url)) {
            return $url;
        }

        if (strpos($url, '//') === 0) {
            return (is_ssl() ? 'https:' : 'http:') . $url;
        }

        $base = wp_parse_url($base_url);
        if (!$base || empty($base['host'])) {
            return $url;
        }

        $scheme = !empty($base['scheme']) ? $base['scheme'] : (is_ssl() ? 'https' : 'http');

        if (strpos($url, '/') === 0) {
            return $scheme . '://' . $base['host'] . $url;
        }

        $base_path = isset($base['path']) ? $base['path'] : '/';
        $dir = trailingslashit(preg_replace('#/[^/]*$#', '/', $base_path));

        return $scheme . '://' . $base['host'] . $dir . $url;
    }

    private function rewrite_css_urls_for_file($css, $file_url) {
        return preg_replace_callback(
            '/url\(([^)]+)\)/i',
            function ($m) use ($file_url) {
                $raw = trim($m[1], " \t\n\r\0\x0B\"'");
                $resolved = $this->build_absolute_url($file_url, $raw);

                if ($resolved === $raw) {
                    return $m[0];
                }

                return 'url("' . esc_url_raw($resolved) . '")';
            },
            $css
        );
    }

    private function get_remote_last_modified_age($url, $timeout = 5) {
        $res = wp_remote_head($url, [
            'timeout' => max(1, (int) $timeout),
            'redirection' => 3,
            'user-agent' => 'Really Simple Cache',
        ]);

        if (is_wp_error($res)) {
            return null;
        }

        $code = wp_remote_retrieve_response_code($res);
        if ($code < 200 || $code >= 300) {
            return null;
        }

        $last_modified = (string) wp_remote_retrieve_header($res, 'last-modified');
        if ($last_modified === '') {
            return null;
        }

        $ts = strtotime($last_modified);
        if ($ts === false || $ts <= 0) {
            return null;
        }

        return max(0, time() - $ts);
    }

    private function download_remote_asset($url, $target_dir, $prefix = 'asset', $timeout = 5) {
        $url = $this->build_absolute_url(home_url('/'), $url);
        if (!preg_match('#^https?://#i', $url)) {
            $this->log('Skipped remote asset download (invalid URL)', ['url' => $url], 'WARN');
            return false;
        }

        $hash = md5($url);
        $dir = trailingslashit($this->cache_dir . trim($target_dir, '/'));

        if (!is_dir($dir) && !wp_mkdir_p($dir)) {
            $this->log('Failed to create asset directory', ['dir' => $dir], 'WARN');
            return false;
        }

        $existing_files = glob($dir . $prefix . '-' . $hash . '.*') ?: [];
        $stale_existing = null;
        $stale_existing_mtime = 0;

        foreach ($existing_files as $existing) {
            if (!is_file($existing)) {
                continue;
            }

            if ((time() - filemtime($existing)) < $this->asset_ttl()) {
                return trailingslashit($this->cache_url . trim($target_dir, '/')) . basename($existing);
            }

            $mtime = (int) filemtime($existing);
            if ($mtime > $stale_existing_mtime) {
                $stale_existing = $existing;
                $stale_existing_mtime = $mtime;
            }
        }

        $is_font_file = trim($target_dir, '/') === 'fonts/files';
        if ($is_font_file && $stale_existing) {
            $remote_age = $this->get_remote_last_modified_age($url, $timeout);
            if (is_int($remote_age) && $remote_age >= (7 * DAY_IN_SECONDS)) {
                if (!touch($stale_existing)) {
                    $this->log('Failed to refresh stale local font mtime', [
                        'file' => $stale_existing,
                    ], 'WARN');
                }
                $this->log('Reused stale local font file (remote is older than 7 days)', [
                    'url' => $url,
                    'target' => basename($stale_existing),
                    'remote_age_seconds' => $remote_age,
                ]);
                return trailingslashit($this->cache_url . trim($target_dir, '/')) . basename($stale_existing);
            }
        }

        $res = wp_remote_get($url, [
            'timeout' => max(1, (int) $timeout),
            'redirection' => 3,
            'user-agent' => 'Really Simple Cache',
        ]);

        if (is_wp_error($res)) {
            $this->log('Remote asset download failed (WP_Error)', [
                'url' => $url,
                'error' => $res->get_error_message(),
            ], 'WARN');
            return false;
        }

        $code = wp_remote_retrieve_response_code($res);
        if ($code < 200 || $code >= 300) {
            $this->log('Remote asset download failed (HTTP status)', [
                'url' => $url,
                'status' => $code,
            ], 'WARN');
            return false;
        }

        $body = wp_remote_retrieve_body($res);
        if ($body === '') {
            $this->log('Remote asset download returned empty body', ['url' => $url], 'WARN');
            return false;
        }

        $path = wp_parse_url($url, PHP_URL_PATH);
        $ext = strtolower(pathinfo((string) $path, PATHINFO_EXTENSION));

        if ($ext === '') {
            $content_type = (string) wp_remote_retrieve_header($res, 'content-type');
            if (strpos($content_type, 'woff2') !== false) $ext = 'woff2';
            elseif (strpos($content_type, 'woff') !== false) $ext = 'woff';
            elseif (strpos($content_type, 'truetype') !== false) $ext = 'ttf';
            elseif (strpos($content_type, 'font/otf') !== false) $ext = 'otf';
            elseif (strpos($content_type, 'svg') !== false) $ext = 'svg';
            elseif (strpos($content_type, 'png') !== false) $ext = 'png';
            elseif (strpos($content_type, 'jpeg') !== false || strpos($content_type, 'jpg') !== false) $ext = 'jpg';
            elseif (strpos($content_type, 'gif') !== false) $ext = 'gif';
            else $ext = 'bin';
        }

        $file = $dir . $prefix . '-' . $hash . '.' . preg_replace('/[^a-z0-9]/i', '', $ext);
        if (!$this->write_file_atomically($file, $body)) {
            $this->log('Failed to store downloaded remote asset', ['file' => $file], 'WARN');
            return false;
        }

        $this->log('Remote asset downloaded', [
            'url' => $url,
            'target' => basename($file),
        ]);

        return trailingslashit($this->cache_url . trim($target_dir, '/')) . basename($file);
    }

    private function localize_font_stylesheets($html) {
        return preg_replace_callback(
            '/<link\b[^>]*>/i',
            function ($m) {
                $tag = $m[0];
                $href = $this->get_stylesheet_href_from_tag($tag);
                if (!$href) {
                    return $tag;
                }

                if ($this->is_asset_excluded($href, 'css')) {
                    return $tag;
                }

                $host = wp_parse_url($this->build_absolute_url(home_url('/'), $href), PHP_URL_HOST);
                if (!$host) {
                    return $tag;
                }

                $host = strtolower($host);
                if (strpos($host, 'fonts.googleapis.com') === false && strpos($host, 'fonts.bunny.net') === false) {
                    return $tag;
                }

                $local_css = $this->get_cached_font_stylesheet_url($href);
                if (!$local_css) {
                    $this->enqueue_font_css_download($href);
                    return $tag;
                }

                return preg_replace(
                    '/href=["\'][^"\']+["\']/',
                    'href="' . esc_url($local_css) . '"',
                    $tag,
                    1
                );
            },
            $html
        );
    }

    private function is_supported_font_host($url) {
        $host = wp_parse_url($this->build_absolute_url(home_url('/'), $url), PHP_URL_HOST);
        if (!$host) {
            return false;
        }

        $host = strtolower($host);
        return (strpos($host, 'fonts.googleapis.com') !== false || strpos($host, 'fonts.bunny.net') !== false);
    }

    private function get_cached_font_stylesheet_url($href) {
        $url = $this->build_absolute_url(home_url('/'), $href);
        if (!$this->is_supported_font_host($url)) {
            return false;
        }

        $hash = md5($url);
        $file = $this->cache_dir . 'fonts/css/font-css-' . $hash . '.css';
        if (!file_exists($file)) {
            return false;
        }

        if ((time() - filemtime($file)) >= $this->asset_ttl()) {
            return false;
        }

        return $this->cache_url . 'fonts/css/' . basename($file);
    }

    private function enqueue_font_css_download($href) {
        $url = $this->build_absolute_url(home_url('/'), $href);
        if (!$this->is_supported_font_host($url)) {
            return;
        }

        $hash = md5($url);
        $lock_key = 'rsc_font_queue_' . $hash;
        if (get_transient($lock_key)) {
            return;
        }

        if (!wp_next_scheduled('rsc_download_font_css', [$url])) {
            wp_schedule_single_event(time() + 1, 'rsc_download_font_css', [$url]);
        }

        set_transient($lock_key, 1, 300);
    }

    public function handle_font_css_download($href) {
        if (!$this->setting_enabled('local_fonts')) {
            return;
        }

        $url = $this->build_absolute_url(home_url('/'), $href);
        if (!$this->is_supported_font_host($url)) {
            return;
        }

        $local_css = $this->cache_remote_font_stylesheet($url);
        if ($local_css) {
            // Cached pages may still point to remote font URLs.
            $this->purge_page_cache_files();
            $this->log('Font stylesheet cached and page cache purged', [
                'url' => $url,
            ]);
        } else {
            $this->log('Font stylesheet caching skipped/failed', [
                'url' => $url,
            ], 'WARN');
        }
        delete_transient('rsc_font_queue_' . md5($url));
    }

    private function cache_remote_font_stylesheet($href) {
        $url = $this->build_absolute_url(home_url('/'), $href);
        if (!preg_match('#^https?://#i', $url)) {
            return false;
        }

        $hash = md5($url);
        $dir = $this->cache_dir . 'fonts/css/';
        $file = $dir . 'font-css-' . $hash . '.css';
        $local_url = $this->cache_url . 'fonts/css/' . basename($file);

        if (file_exists($file) && (time() - filemtime($file)) < $this->asset_ttl()) {
            return $local_url;
        }

        $res = wp_remote_get($url, [
            'timeout' => 4,
            'redirection' => 3,
            'user-agent' => 'Really Simple Cache',
        ]);

        if (is_wp_error($res)) {
            $this->log('Font stylesheet request failed', [
                'url' => $url,
                'error' => $res->get_error_message(),
            ], 'WARN');
            return false;
        }

        $code = wp_remote_retrieve_response_code($res);
        if ($code < 200 || $code >= 300) {
            $this->log('Font stylesheet request returned non-2xx', [
                'url' => $url,
                'status' => $code,
            ], 'WARN');
            return false;
        }

        $css = wp_remote_retrieve_body($res);
        if (!is_string($css) || $css === '') {
            $this->log('Font stylesheet body was empty', ['url' => $url], 'WARN');
            return false;
        }

        $css = preg_replace_callback(
            '/url\(([^)]+)\)/i',
            function ($m) use ($url) {
                $raw = trim($m[1], " \t\n\r\0\x0B\"'");
                if ($raw === '' || strpos($raw, 'data:') === 0) {
                    return $m[0];
                }

                $font_url = $this->build_absolute_url($url, $raw);
                $local_font = $this->download_remote_asset($font_url, 'fonts/files', 'font-file', 4);
                if (!$local_font) {
                    return $m[0];
                }

                return 'url("' . esc_url_raw($local_font) . '")';
            },
            $css
        );

        if ($this->setting_enabled('minify_css')) {
            $css = $this->minify_css($css);
        }

        if (!$this->write_file_atomically($file, $css)) {
            $this->log('Failed to write cached font stylesheet', ['file' => $file], 'WARN');
            return false;
        }

        $this->log('Cached font stylesheet', [
            'url' => $url,
            'target' => basename($file),
        ]);

        return $local_url;
    }

    private function minify_external_css_files($html) {
        return $this->get_minifier()->minify_external_css_files((string) $html, [
            'get_stylesheet_href' => function ($tag) {
                return $this->get_stylesheet_href_from_tag($tag);
            },
            'is_same_domain' => function ($href) {
                return $this->is_same_domain($href);
            },
            'is_asset_excluded' => function ($url, $type) {
                return $this->is_asset_excluded($url, $type);
            },
            'url_to_path' => function ($url) {
                return $this->url_to_path($url);
            },
            'rewrite_css_urls_for_file' => function ($css, $href) {
                return $this->rewrite_css_urls_for_file($css, $href);
            },
            'write_file_atomically' => function ($file, $contents) {
                return $this->write_file_atomically($file, $contents);
            },
            'cache_dir' => $this->cache_dir,
            'cache_url' => $this->cache_url,
            'minify_css_enabled' => $this->setting_enabled('minify_css'),
        ]);
    }

    private function combine_external_css_files($html) {
        return $this->get_asset_combiner()->combine_external_css_files((string) $html, [
            'get_stylesheet_href' => function ($tag) {
                return $this->get_stylesheet_href_from_tag($tag);
            },
            'is_same_domain' => function ($href) {
                return $this->is_same_domain($href);
            },
            'is_asset_excluded' => function ($url, $type) {
                return $this->is_asset_excluded($url, $type);
            },
            'url_to_path' => function ($url) {
                return $this->url_to_path($url);
            },
            'rewrite_css_urls_for_file' => function ($css, $href) {
                return $this->rewrite_css_urls_for_file($css, $href);
            },
            'write_file_atomically' => function ($file, $contents) {
                return $this->write_file_atomically($file, $contents);
            },
            'parse_attr' => function ($tag, $attr) {
                return $this->parse_attr($tag, $attr);
            },
            'minify_css' => function ($css) {
                return $this->minify_css($css);
            },
            'cache_dir' => $this->cache_dir,
            'cache_url' => $this->cache_url,
            'minify_css_enabled' => $this->setting_enabled('minify_css'),
        ]);
    }

    private function minify_external_js_files($html) {
        return $this->get_minifier()->minify_external_js_files((string) $html, [
            'is_same_domain' => function ($src) {
                return $this->is_same_domain($src);
            },
            'is_asset_excluded' => function ($url, $type) {
                return $this->is_asset_excluded($url, $type);
            },
            'url_to_path' => function ($url) {
                return $this->url_to_path($url);
            },
            'write_file_atomically' => function ($file, $contents) {
                return $this->write_file_atomically($file, $contents);
            },
            'cache_dir' => $this->cache_dir,
            'cache_url' => $this->cache_url,
            'minify_js_enabled' => $this->setting_enabled('minify_js'),
        ]);
    }

    private function combine_external_js_files($html) {
        return $this->get_asset_combiner()->combine_external_js_files((string) $html, [
            'is_same_domain' => function ($src) {
                return $this->is_same_domain($src);
            },
            'is_asset_excluded' => function ($url, $type) {
                return $this->is_asset_excluded($url, $type);
            },
            'url_to_path' => function ($url) {
                return $this->url_to_path($url);
            },
            'write_file_atomically' => function ($file, $contents) {
                return $this->write_file_atomically($file, $contents);
            },
            'has_attr' => function ($tag, $attr) {
                return $this->has_attr($tag, $attr);
            },
            'is_module_script_tag' => function ($tag) {
                return $this->is_module_script_tag($tag);
            },
            'minify_js' => function ($js) {
                return $this->minify_js($js);
            },
            'cache_dir' => $this->cache_dir,
            'cache_url' => $this->cache_url,
            'minify_js_enabled' => $this->setting_enabled('minify_js'),
        ]);
    }

    private function minify_inline_css($html) {
        return $this->get_minifier()->minify_inline_css((string) $html, $this->setting_enabled('minify_css'));
    }

    private function minify_inline_js($html) {
        return $this->get_minifier()->minify_inline_js((string) $html, $this->setting_enabled('minify_js'));
    }

    private function defer_scripts($html) {
        if (!$this->setting_enabled('defer_scripts')) {
            return $html;
        }

        return $this->get_script_deferrer()->defer_html_scripts($html, [
            'is_excluded' => function ($url, $type) {
                return $this->is_asset_excluded($url, $type);
            },
        ]);
    }

    private function delay_scripts($html) {
        if (!$this->setting_enabled('delay_scripts')) {
            return $html;
        }

        return $this->get_script_delayer()->delay_html_scripts((string) $html, [
            'is_excluded' => function ($url, $type) {
                return $this->is_delay_script_excluded($url);
            },
            'delay_ms' => 3000,
        ]);
    }

    public function process_output($html) {
        if (!is_string($html) || $html === '') {
            return $html;
        }

        if ($this->setting_enabled('local_fonts')) {
            $html = $this->localize_font_stylesheets($html);
        }

        if ($this->setting_enabled('remove_unused_css') && $this->is_html_output($html)) {
            $uri = isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '/';
            $host = isset($_SERVER['HTTP_HOST']) ? (string) $_SERVER['HTTP_HOST'] : (string) wp_parse_url(home_url('/'), PHP_URL_HOST);
            $scheme = is_ssl() ? 'https' : 'http';

            $rucss_context = [
                'request_uri' => $uri,
                'page_key' => rsc_get_cache_key($uri, $host, $scheme),
                'excluded_page_patterns' => $this->get_exclusion_patterns('rucss_excluded_pages'),
                'keep_selector_patterns' => $this->get_exclusion_patterns('rucss_keep_selectors'),
                'max_css_bytes' => max(32768, $this->setting_int('rucss_max_css_kb', 512) * 1024),
                'get_stylesheet_href' => function ($tag) {
                    $href = $this->get_stylesheet_href_from_tag($tag);
                    return is_string($href) ? $href : '';
                },
                'is_same_domain' => function ($href) {
                    return $this->is_same_domain($href);
                },
                'url_to_path' => function ($url) {
                    return $this->url_to_path($url);
                },
                'is_asset_excluded' => function ($url, $type) {
                    return $this->is_asset_excluded($url, $type);
                },
                'write_file_atomically' => function ($file, $contents) {
                    return $this->write_file_atomically($file, $contents);
                },
            ];

            $html = $this->get_rucss_module()->prune_html_stylesheets($html, $rucss_context);
        }

        if ($this->setting_enabled('combine_css')) {
            $html = $this->combine_external_css_files($html);
        } else {
            $html = $this->minify_external_css_files($html);
        }

        if ($this->setting_enabled('combine_js')) {
            $html = $this->combine_external_js_files($html);
        } else {
            $html = $this->minify_external_js_files($html);
        }

        $html = $this->minify_inline_css($html);
        $html = $this->minify_inline_js($html);
        $html = $this->defer_scripts($html);
        $html = $this->delay_scripts($html);

        if ($this->setting_enabled('minify_html')) {
            $html = $this->minify_html($html);
        }

        if ($this->setting_enabled('debug_footer') && $this->is_html_output($html)) {
            $html .= "\n<!-- Really Simple Cache | Cached on: " . date('Y-m-d H:i:s') . " -->";
        }

        if ($this->is_cacheable_request()) {
            $cache_file = $this->get_cache_file();
            $this->write_file_atomically($cache_file, $html);
            $this->write_cache_headers($this->get_cache_header_file());
            $this->write_cache_meta($this->get_cache_meta_file());
            $this->log('Cached optimized page output', [
                'file' => basename($cache_file),
                'bytes' => strlen($html),
            ]);
        } else {
            $this->log('Processed output without page cache write');
        }

        return $html;
    }

    private function is_html_output($html) {
        if (function_exists('wp_is_json_request') && wp_is_json_request()) {
            return false;
        }

        $content_type = $this->get_response_content_type();
        if ($content_type !== null) {
            return stripos($content_type, 'text/html') !== false;
        }

        return preg_match('/<!doctype\s+html|<html\b/i', $html) === 1;
    }

    private function get_response_content_type() {
        if (!function_exists('headers_list')) {
            return null;
        }

        foreach (headers_list() as $header) {
            if (stripos($header, 'Content-Type:') === 0) {
                return trim(substr($header, strlen('Content-Type:')));
            }
        }

        return null;
    }

    private function write_cache_headers($header_file) {
        $headers = $this->get_cacheable_headers();
        if (empty($headers)) {
            return;
        }

        $this->write_file_atomically($header_file, wp_json_encode(array_values($headers)));
        $this->log('Stored cache response headers', [
            'file' => basename($header_file),
            'count' => count($headers),
        ]);
    }

    private function read_cached_headers($header_file) {
        if (!is_file($header_file) || !is_readable($header_file)) {
            return [];
        }

        $raw = file_get_contents($header_file);
        if ($raw === false) {
            return [];
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return [];
        }

        $headers = [];
        foreach ($decoded as $header) {
            if (is_string($header) && $header !== '') {
                $headers[] = $header;
            }
        }

        return $headers;
    }

    private function get_cacheable_headers() {
        if (!function_exists('headers_list')) {
            return [];
        }

        $disallowed = [
            'connection',
            'content-length',
            'etag',
            'keep-alive',
            'last-modified',
            'set-cookie',
            'transfer-encoding',
            'x-rsc-cache',
        ];

        $headers = [];
        foreach (headers_list() as $header_line) {
            $name = strtolower(trim(strtok($header_line, ':')));
            if ($name === '' || in_array($name, $disallowed, true)) {
                continue;
            }
            $headers[] = $header_line;
        }

        return $headers;
    }

    private function write_cache_meta($meta_file) {
        $uri = isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '/';
        $host = isset($_SERVER['HTTP_HOST']) ? (string) $_SERVER['HTTP_HOST'] : (string) wp_parse_url(home_url('/'), PHP_URL_HOST);
        $scheme = is_ssl() ? 'https' : 'http';
        $path = wp_parse_url($uri, PHP_URL_PATH);
        $path = is_string($path) && $path !== '' ? $path : $uri;

        $meta = [
            'uri' => $uri,
            'path' => $path,
            'host' => strtolower($host),
            'scheme' => strtolower($scheme),
        ];

        $this->write_file_atomically($meta_file, wp_json_encode($meta));
        $this->log('Stored cache metadata', [
            'file' => basename($meta_file),
            'path' => $path,
        ]);
    }

    public function purge_all_cache(...$args) {
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }

        $dirs = [
            $this->cache_dir . 'pages/',
            $this->cache_dir . 'css/',
            $this->cache_dir . 'js/',
            $this->cache_dir . 'rucss/',
            $this->cache_dir . 'avatars/',
            $this->cache_dir . 'fonts/css/',
            $this->cache_dir . 'fonts/files/',
        ];

        $deleted = 0;
        foreach ($dirs as $dir) {
            if (!is_dir($dir)) {
                continue;
            }

            $files = glob($dir . '*');
            if ($files === false) {
                continue;
            }

            foreach ($files as $file) {
                if (is_file($file) && is_writable($file)) {
                    if (unlink($file)) {
                        $deleted++;
                    }
                }
            }
        }

        $this->log('Purged all cache artifacts', [
            'deleted_files' => $deleted,
            'args_count' => count($args),
        ]);
    }

    /**
     * Purge only cached page HTML files.
     */
    private function purge_page_cache_files() {
        $dir = $this->cache_dir . 'pages/';
        if (!is_dir($dir)) {
            return;
        }

        $files = glob($dir . '*');
        if ($files === false) {
            return;
        }

        $deleted = 0;
        foreach ($files as $file) {
            if (is_file($file) && is_writable($file)) {
                if (unlink($file)) {
                    $deleted++;
                }
            }
        }

        if ($deleted > 0) {
            $this->log('Purged page cache files', [
                'deleted_files' => $deleted,
            ]);
        }
    }

    public function filter_avatar_url($url, $id_or_email, $args) {
        if (!$this->setting_enabled('local_avatars')) {
            return $url;
        }

        $host = wp_parse_url($url, PHP_URL_HOST);
        if (!$host || strpos(strtolower($host), 'gravatar.com') === false) {
            return $url;
        }

        $size = 96;
        if (is_array($args) && isset($args['size'])) {
            $size = max(16, (int) $args['size']);
        } elseif (isset($_GET['s'])) {
            $size = max(16, (int) $_GET['s']);
        }

        $normalized = add_query_arg('s', $size, remove_query_arg('ver', $url));
        $hash = md5($normalized);

        foreach (glob($this->cache_dir . 'avatars/avatar-' . $hash . '.*') ?: [] as $existing) {
            if (is_file($existing) && (time() - filemtime($existing)) < $this->asset_ttl()) {
                //$this->log('Served local avatar cache HIT', [
                //    'avatar' => basename($existing),
                //]);
                return $this->cache_url . 'avatars/' . basename($existing);
            }
        }

        $local = $this->download_remote_asset($normalized, 'avatars', 'avatar');
        if ($local) {
            $this->log('Cached remote avatar locally', [
                'source' => $normalized,
            ]);
        } else {
            $this->log('Avatar localization failed, using remote URL', [
                'source' => $normalized,
            ], 'WARN');
        }
        return $local ? $local : $url;
    }
}

$rsc_instance = new ReallySimpleCache();

/**
 * ADMIN BAR: Cache / Clear All / Clear This Page
 */
add_action('admin_bar_menu', function($admin_bar) {
    if (!current_user_can('manage_options')) {
        return;
    }

    $scheme      = is_ssl() ? 'https://' : 'http://';
    $host        = $_SERVER['HTTP_HOST'] ?? '';
    $request_uri = $_SERVER['REQUEST_URI'] ?? '';
    $current_url = $scheme . $host . $request_uri;

    $admin_bar->add_node([
        'id'    => 'rsc-cache',
        'title' => 'Cache',
        'href'  => false,
        'meta'  => ['class' => 'rsc-cache-menu'],
    ]);

    $admin_bar->add_node([
        'id'     => 'rsc-clear-all',
        'title'  => 'Clear All',
        'parent' => 'rsc-cache',
        'href'   => wp_nonce_url(
            admin_url('admin-post.php?action=rsc_clear_all'),
            'rsc_clear_all'
        ),
    ]);

    $admin_bar->add_node([
        'id'     => 'rsc-clear-page',
        'title'  => 'Clear This Page',
        'parent' => 'rsc-cache',
        'href'   => wp_nonce_url(
            admin_url('admin-post.php?action=rsc_clear_page&url=' . urlencode($current_url)),
            'rsc_clear_page'
        ),
    ]);

    $admin_bar->add_node([
        'id'     => 'rsc-settings',
        'title'  => 'Settings',
        'parent' => 'rsc-cache',
        'href'   => admin_url('options-general.php?page=rsc-settings'),
    ]);
}, 100);

add_action('admin_post_rsc_clear_all', function() {
    if (!current_user_can('manage_options')) {
        wp_die('No permission.');
    }

    check_admin_referer('rsc_clear_all');

    $upload = wp_upload_dir();
    $base   = trailingslashit($upload['basedir']) . 'really-simple-cache/';

    $dirs = [
        $base . 'pages/',
        $base . 'css/',
        $base . 'js/',
        $base . 'rucss/',
        $base . 'avatars/',
        $base . 'fonts/css/',
        $base . 'fonts/files/',
    ];

    $deleted = 0;

    foreach ($dirs as $dir) {
        if (!is_dir($dir)) continue;

        $files = glob($dir . '*');
        if ($files === false) continue;

        foreach ($files as $file) {
            if (is_file($file) && unlink($file)) {
                $deleted++;
            }
        }
    }

    rsc_write_debug_log('Admin cleared all cache artifacts', [
        'user_id' => get_current_user_id(),
        'deleted_files' => $deleted,
    ]);

    wp_safe_redirect(admin_url('index.php?cache_all_cleared=' . $deleted));
    exit;
});

add_action('admin_post_rsc_clear_page', function() {
    if (!current_user_can('manage_options')) {
        wp_die('No permission.');
    }

    check_admin_referer('rsc_clear_page');

    if (empty($_GET['url'])) {
        wp_die('Missing URL.');
    }

    $url   = esc_url_raw($_GET['url']);
    $parts = wp_parse_url($url);
    $home_host = wp_parse_url(home_url('/'), PHP_URL_HOST);

    if (!$parts || empty($parts['host']) || strtolower($parts['host']) !== strtolower($home_host)) {
        wp_die('Invalid URL host.');
    }

    $path = isset($parts['path']) ? $parts['path'] : '/';
    $uri = $path;
    if (!empty($parts['query'])) {
        $uri .= '?' . $parts['query'];
    }

    $upload    = wp_upload_dir();
    $cache_dir = trailingslashit($upload['basedir']) . 'really-simple-cache/pages/';
    $cache_key = rsc_get_cache_key(
        $uri,
        $parts['host'],
        isset($parts['scheme']) ? $parts['scheme'] : null
    );

    $deleted = 0;
    foreach (['.html', '.headers.json', '.meta.json'] as $suffix) {
        $file = $cache_dir . $cache_key . $suffix;
        if (file_exists($file)) {
            if (unlink($file)) {
                $deleted++;
            }
        }
    }

    $scheme = isset($parts['scheme']) ? strtolower((string) $parts['scheme']) : (is_ssl() ? 'https' : 'http');
    $host = strtolower((string) $parts['host']);
    $meta_files = glob($cache_dir . '*.meta.json') ?: [];
    foreach ($meta_files as $meta_file) {
        $raw = file_get_contents($meta_file);
        if ($raw === false) {
            continue;
        }
        $meta = json_decode($raw, true);
        if (!is_array($meta)) {
            continue;
        }
        if (strtolower((string) ($meta['host'] ?? '')) !== $host) {
            continue;
        }
        if (strtolower((string) ($meta['scheme'] ?? '')) !== $scheme) {
            continue;
        }
        if ((string) ($meta['path'] ?? '') !== $path) {
            continue;
        }

        $base = substr($meta_file, 0, -strlen('.meta.json'));
        foreach (['.html', '.headers.json', '.meta.json'] as $suffix) {
            $file = $base . $suffix;
            if (file_exists($file)) {
                if (unlink($file)) {
                    $deleted++;
                }
            }
        }
    }

    rsc_write_debug_log('Admin cleared page cache', [
        'user_id' => get_current_user_id(),
        'url' => $url,
        'deleted_files' => $deleted,
    ]);

    $redirect_to = add_query_arg('cache_cleared', '1', $url);
    wp_safe_redirect($redirect_to);
    exit;
});

add_action('admin_notices', function() {
    if (isset($_GET['cache_all_cleared'])) {
        $n = intval($_GET['cache_all_cleared']);
        echo '<div class="notice notice-success"><p><strong>Really Simple Cache:</strong> Cleared ' . esc_html($n) . ' cached files.</p></div>';
    }

    if (isset($_GET['cache_cleared'])) {
        echo '<div class="notice notice-success"><p><strong>Really Simple Cache:</strong> Page cache cleared.</p></div>';
    }

    if (isset($_GET['debug_log_cleared'])) {
        echo '<div class="notice notice-success"><p><strong>Really Simple Cache:</strong> Debug log cleared.</p></div>';
    }
});
