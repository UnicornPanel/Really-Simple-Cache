<?php
/**
 * Plugin Name: Really Simple Cache
 * Description: Super lightweight output cache with HTML/CSS/JS minification and auto-defer scripts.
 * Version: 2.2
 * Author: UnicornPanel.net
 */

if (!defined('ABSPATH')) exit;

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

class ReallySimpleCache {

    private $cache_dir;
    private $cache_url;
    private $settings;

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
        }
    }

    private function defaults() {
        return [
            'enable_page_cache' => 1,
            'cache_ttl' => 3600,
            'minify_html' => 1,
            'minify_css' => 1,
            'minify_js' => 1,
            'defer_scripts' => 1,
            'debug_footer' => 1,
            'combine_css' => 0,
            'combine_js' => 0,
            'local_avatars' => 0,
            'local_fonts' => 0,
            'asset_ttl' => 604800,
            'excluded_pages' => '',
            'excluded_css' => '',
            'excluded_js' => '',
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

    public function sanitize_settings($input) {
        $defaults = $this->defaults();
        $input = is_array($input) ? $input : [];

        $settings = [
            'enable_page_cache' => empty($input['enable_page_cache']) ? 0 : 1,
            'minify_html' => empty($input['minify_html']) ? 0 : 1,
            'minify_css' => empty($input['minify_css']) ? 0 : 1,
            'minify_js' => empty($input['minify_js']) ? 0 : 1,
            'defer_scripts' => empty($input['defer_scripts']) ? 0 : 1,
            'debug_footer' => empty($input['debug_footer']) ? 0 : 1,
            'combine_css' => empty($input['combine_css']) ? 0 : 1,
            'combine_js' => empty($input['combine_js']) ? 0 : 1,
            'local_avatars' => empty($input['local_avatars']) ? 0 : 1,
            'local_fonts' => empty($input['local_fonts']) ? 0 : 1,
            'cache_ttl' => isset($input['cache_ttl']) ? max(60, (int) $input['cache_ttl']) : $defaults['cache_ttl'],
            'asset_ttl' => isset($input['asset_ttl']) ? max(3600, (int) $input['asset_ttl']) : $defaults['asset_ttl'],
            'excluded_pages' => isset($input['excluded_pages']) ? sanitize_textarea_field($input['excluded_pages']) : '',
            'excluded_css' => isset($input['excluded_css']) ? sanitize_textarea_field($input['excluded_css']) : '',
            'excluded_js' => isset($input['excluded_js']) ? sanitize_textarea_field($input['excluded_js']) : '',
        ];

        $this->settings = wp_parse_args($settings, $defaults);
        $this->purge_all_cache();

        return $this->settings;
    }

    public function render_settings_page() {
        if (!current_user_can('manage_options')) {
            return;
        }

        $s = $this->settings;
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
                    </div>

                    <div class="rsc-card">
                        <h2>Additional Optimizations</h2>
                        <?php $this->render_toggle('combine_css', 'Combine CSS Files', 'Bundles same-domain stylesheet files into combined output.'); ?>
                        <?php $this->render_toggle('combine_js', 'Combine JS Files', 'Bundles same-domain head scripts into a combined file.'); ?>
                        <?php $this->render_toggle('local_avatars', 'Store Gravatar Avatars Locally', 'Caches Gravatar image responses in local uploads.'); ?>
                        <?php $this->render_toggle('local_fonts', 'Store Bunny and Google Fonts Locally', 'Downloads and rewrites Google/Bunny font stylesheets and font files.'); ?>

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
                    </div>
                </div>

                <?php submit_button('Save RS Cache Settings'); ?>
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
        </style>
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
            $this->cache_dir . 'avatars/',
            $this->cache_dir . 'fonts/',
            $this->cache_dir . 'fonts/css/',
            $this->cache_dir . 'fonts/files/',
        ];

        foreach ($paths as $path) {
            if (!file_exists($path)) {
                wp_mkdir_p($path);
            }
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

    private function is_cacheable_request() {
        if (!$this->setting_enabled('enable_page_cache')) {
            return false;
        }

        if ($this->is_current_page_excluded()) {
            return false;
        }

        if (is_user_logged_in()) {
            return false;
        }

        if (is_preview() || is_customize_preview()) {
            return false;
        }

        if (php_sapi_name() === 'cli') {
            return false;
        }

        if (!isset($_SERVER['REQUEST_METHOD']) || $_SERVER['REQUEST_METHOD'] !== 'GET') {
            return false;
        }

        if (defined('DOING_AJAX') && DOING_AJAX) {
            return false;
        }

        if (defined('REST_REQUEST') && REST_REQUEST) {
            return false;
        }

        if (isset($_GET['preview']) || isset($_GET['customize_changeset_uuid'])) {
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
                        return false;
                    }
                }
            }
        }

        if (is_feed() || is_trackback() || is_robots() || is_search()) {
            return false;
        }

        return true;
    }

    /**
     * Requests eligible for output optimization, even when page caching is bypassed.
     */
    private function can_optimize_output_request() {
        if (is_admin()) {
            return false;
        }

        if ($this->is_current_page_excluded()) {
            return false;
        }

        // Disable all output optimizations for logged-in users.
        if (is_user_logged_in()) {
            return false;
        }

        if (php_sapi_name() === 'cli') {
            return false;
        }

        if (!isset($_SERVER['REQUEST_METHOD']) || $_SERVER['REQUEST_METHOD'] !== 'GET') {
            return false;
        }

        if (defined('DOING_AJAX') && DOING_AJAX) {
            return false;
        }

        if (defined('REST_REQUEST') && REST_REQUEST) {
            return false;
        }

        if (is_feed() || is_trackback() || is_robots()) {
            return false;
        }

        return true;
    }

    private function get_cache_file() {
        $uri = isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '/';
        $host = isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : wp_parse_url(home_url('/'), PHP_URL_HOST);
        $scheme = is_ssl() ? 'https' : 'http';
        $key = rsc_get_cache_key($uri, $host, $scheme);

        return $this->cache_dir . 'pages/' . $key . '.html';
    }

    public function serve_cache() {
        if (!$this->is_cacheable_request()) {
            return;
        }

        $file = $this->get_cache_file();

        if (file_exists($file) && (time() - filemtime($file)) < $this->cache_ttl()) {
            $mtime = filemtime($file);
            $ttl = $this->cache_ttl();

            header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $mtime) . ' GMT');
            header("Cache-Control: public, max-age={$ttl}");
            header('Expires: ' . gmdate('D, d M Y H:i:s', time() + $ttl) . ' GMT');
            header('X-RSC-Cache: HIT');
            header('Content-Type: text/html; charset=UTF-8');
            header('Vary: Cookie');

            $etag = '"' . md5_file($file) . '"';
            header("ETag: {$etag}");

            if (isset($_SERVER['HTTP_IF_NONE_MATCH']) && trim($_SERVER['HTTP_IF_NONE_MATCH']) === $etag) {
                header('HTTP/1.1 304 Not Modified');
                exit;
            }

            readfile($file);
            exit;
        }
    }

    public function start_buffer() {
        if (!$this->can_optimize_output_request()) {
            return;
        }

        if ($this->is_cacheable_request()) {
            header('X-RSC-Cache: MISS');
        } else {
            header('X-RSC-Cache: BYPASS');
        }
        ob_start([$this, 'process_output']);
    }

    public function end_buffer() {
        if (ob_get_level() > 0 && ob_get_length() !== false) {
            ob_end_flush();
        }
    }

    private function minify_html($html) {
        $html = preg_replace('/>\s+</', '><', $html);
        return trim($html);
    }

    private function minify_css($css) {
        $css = preg_replace('!/\*.*?\*/!s', '', $css);
        $css = preg_replace('/\s+/', ' ', $css);
        $css = preg_replace('/\s*([{};:,])\s*/', '$1', $css);
        return trim($css);
    }

    private function minify_js($js) {
        require_once __DIR__ . '/JSMin.php';
        try {
            return \JSMin\JSMin::minify($js);
        } catch (\Exception $e) {
            return $js;
        }
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
            return false;
        }

        $tmp = $dir . '/.' . basename($file) . '.tmp-' . wp_rand(1000, 999999);
        if (file_put_contents($tmp, $contents, LOCK_EX) === false) {
            if (file_exists($tmp)) {
                unlink($tmp);
            }
            return false;
        }

        if (!rename($tmp, $file)) {
            unlink($tmp);
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

        if (strpos($url, 'data:') === 0 || strpos($url, '#') === 0) {
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

    private function download_remote_asset($url, $target_dir, $prefix = 'asset', $timeout = 5) {
        $url = $this->build_absolute_url(home_url('/'), $url);
        if (!preg_match('#^https?://#i', $url)) {
            return false;
        }

        $hash = md5($url);
        $dir = trailingslashit($this->cache_dir . trim($target_dir, '/'));

        if (!is_dir($dir) && !wp_mkdir_p($dir)) {
            return false;
        }

        foreach (glob($dir . $prefix . '-' . $hash . '.*') ?: [] as $existing) {
            if (is_file($existing) && (time() - filemtime($existing)) < $this->asset_ttl()) {
                return trailingslashit($this->cache_url . trim($target_dir, '/')) . basename($existing);
            }
        }

        $res = wp_remote_get($url, [
            'timeout' => max(1, (int) $timeout),
            'redirection' => 3,
            'user-agent' => 'Really Simple Cache',
        ]);

        if (is_wp_error($res)) {
            return false;
        }

        $code = wp_remote_retrieve_response_code($res);
        if ($code < 200 || $code >= 300) {
            return false;
        }

        $body = wp_remote_retrieve_body($res);
        if ($body === '') {
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
            return false;
        }

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
            return false;
        }

        $code = wp_remote_retrieve_response_code($res);
        if ($code < 200 || $code >= 300) {
            return false;
        }

        $css = wp_remote_retrieve_body($res);
        if (!is_string($css) || $css === '') {
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
            return false;
        }

        return $local_url;
    }

    private function minify_external_css_files($html) {
        return preg_replace_callback(
            '/<link\b[^>]*>/i',
            function ($m) {
                $tag = $m[0];
                $href = $this->get_stylesheet_href_from_tag($tag);
                if (!$href) {
                    return $tag;
                }

                if (!$this->is_same_domain($href)) {
                    return $tag;
                }

                if ($this->is_asset_excluded($href, 'css')) {
                    return $tag;
                }

                $path = $this->url_to_path($href);
                if (!$path || !file_exists($path) || !is_readable($path)) {
                    return $tag;
                }

                $css = file_get_contents($path);
                if ($css === false || $css === '') {
                    return $tag;
                }

                $css = $this->rewrite_css_urls_for_file($css, $href);
                $min = $this->setting_enabled('minify_css') ? $this->minify_css($css) : $css;
                if ($min === '') {
                    return $tag;
                }

                $hash = md5($href . '|' . $min);
                $rel  = 'css/' . $hash . '.css';
                $file = $this->cache_dir . $rel;
                $url  = $this->cache_url . $rel;

                if (!file_exists($file)) {
                    $this->write_file_atomically($file, $min);
                }

                return preg_replace(
                    '/href=["\'][^"\']+["\']/',
                    'href="' . esc_url($url) . '"',
                    $tag,
                    1
                );
            },
            $html
        );
    }

    private function combine_external_css_files($html) {
        $groups = [];

        $html = preg_replace_callback(
            '/<link\b[^>]*>/i',
            function ($m) use (&$groups) {
                $tag = $m[0];
                $href = $this->get_stylesheet_href_from_tag($tag);
                if (!$href) {
                    return $tag;
                }

                if (!$this->is_same_domain($href)) {
                    return $tag;
                }

                if ($this->is_asset_excluded($href, 'css')) {
                    return $tag;
                }

                $path = $this->url_to_path($href);
                if (!$path || !file_exists($path) || !is_readable($path)) {
                    return $tag;
                }

                $css = file_get_contents($path);
                if ($css === false || $css === '') {
                    return $tag;
                }

                $media = $this->parse_attr($tag, 'media');
                $media = $media ? strtolower(trim($media)) : 'all';

                $group_id = md5('css-' . $media);
                if (!isset($groups[$group_id])) {
                    $groups[$group_id] = [
                        'media' => $media,
                        'items' => [],
                        'original_tags' => [],
                    ];
                }

                $groups[$group_id]['items'][] = [
                    'href' => $href,
                    'css' => $this->rewrite_css_urls_for_file($css, $href),
                ];
                $groups[$group_id]['original_tags'][] = $tag;

                return '<!--RSC_CSS_COMBINE:' . $group_id . '-->';
            },
            $html
        );

        if (empty($groups)) {
            return $html;
        }

        $tags = [];
        foreach ($groups as $group_id => $group) {
            if (count($group['items']) < 2) {
                $tags[$group_id] = $group['original_tags'][0];
                continue;
            }

            $combined = '';
            $fingerprint = '';
            foreach ($group['items'] as $item) {
                $chunk = $item['css'];
                if ($this->setting_enabled('minify_css')) {
                    $chunk = $this->minify_css($chunk);
                }
                $combined .= "\n" . $chunk;
                $fingerprint .= $item['href'] . '|' . $chunk . ';';
            }

            $hash = md5($fingerprint);
            $rel = 'css/combined-' . $hash . '.css';
            $file = $this->cache_dir . $rel;
            $url = $this->cache_url . $rel;

            if (!file_exists($file)) {
                $this->write_file_atomically($file, trim($combined));
            }

            $media_attr = ($group['media'] !== 'all' && $group['media'] !== '') ? ' media="' . esc_attr($group['media']) . '"' : '';
            $tags[$group_id] = '<link rel="stylesheet" href="' . esc_url($url) . '"' . $media_attr . ' />';
        }

        $seen = [];
        return preg_replace_callback(
            '/<!--RSC_CSS_COMBINE:([a-f0-9]{32})-->/',
            function ($m) use (&$seen, $tags) {
                $id = $m[1];
                if (!isset($tags[$id])) {
                    return '';
                }
                if (isset($seen[$id])) {
                    return '';
                }
                $seen[$id] = true;
                return $tags[$id];
            },
            $html
        );
    }

    private function minify_external_js_files($html) {
        return preg_replace_callback(
            '/<script[^>]*src=["\']([^"\']+)["\'][^>]*>\s*<\/script>/i',
            function ($m) {
                $tag = $m[0];
                $src = html_entity_decode($m[1]);

                if (!$this->is_same_domain($src)) {
                    return $tag;
                }

                if ($this->is_asset_excluded($src, 'js')) {
                    return $tag;
                }

                $path = $this->url_to_path($src);
                if (!$path || !file_exists($path) || !is_readable($path)) {
                    return $tag;
                }

                $js = file_get_contents($path);
                if ($js === false || $js === '') {
                    return $tag;
                }

                $min = $this->setting_enabled('minify_js') ? $this->minify_js($js) : $js;
                if ($min === '') {
                    return $tag;
                }

                $hash = md5($src . '|' . $min);
                $rel  = 'js/' . $hash . '.js';
                $file = $this->cache_dir . $rel;
                $url  = $this->cache_url . $rel;

                if (!file_exists($file)) {
                    $this->write_file_atomically($file, $min);
                }

                return preg_replace(
                    '/src=["\'][^"\']+["\']/',
                    'src="' . esc_url($url) . '"',
                    $tag,
                    1
                );
            },
            $html
        );
    }

    private function combine_script_region($region_html) {
        $groups = [];
        $group_index = 0;

        $processed = preg_replace_callback(
            '/<script[^>]*src=["\']([^"\']+)["\'][^>]*>\s*<\/script>/i',
            function ($m) use (&$groups, &$group_index) {
                $tag = $m[0];
                $src = html_entity_decode($m[1]);

                if (!$this->is_same_domain($src)) {
                    return $tag;
                }

                if ($this->is_asset_excluded($src, 'js')) {
                    return $tag;
                }

                if ($this->has_attr($tag, 'async') || $this->has_attr($tag, 'defer') || $this->has_attr($tag, 'nomodule')) {
                    return $tag;
                }

                if ($this->is_module_script_tag($tag)) {
                    return $tag;
                }

                if ($this->has_attr($tag, 'integrity') || $this->has_attr($tag, 'crossorigin')) {
                    return $tag;
                }

                $path = $this->url_to_path($src);
                if (!$path || !file_exists($path) || !is_readable($path)) {
                    return $tag;
                }

                $js = file_get_contents($path);
                if ($js === false || $js === '') {
                    return $tag;
                }

                $group_id = 'js-' . $group_index;
                $groups[$group_id][] = [
                    'src' => $src,
                    'js' => $js,
                ];
                $group_index++;

                return '<!--RSC_JS_COMBINE:' . $group_id . '-->';
            },
            $region_html
        );

        if (empty($groups) || count($groups) < 2) {
            return $region_html;
        }

        $fingerprint = '';
        $combined = '';

        foreach ($groups as $items) {
            foreach ($items as $item) {
                $chunk = $item['js'];
                if ($this->setting_enabled('minify_js')) {
                    $chunk = $this->minify_js($chunk);
                }
                $combined .= "\n" . $chunk . ';';
                $fingerprint .= $item['src'] . '|' . $chunk . ';';
            }
        }

        $hash = md5($fingerprint);
        $rel = 'js/combined-' . $hash . '.js';
        $file = $this->cache_dir . $rel;
        $url = $this->cache_url . $rel;

        if (!file_exists($file)) {
            $this->write_file_atomically($file, trim($combined));
        }

        $replacement = '<script src="' . esc_url($url) . '"></script>';
        $used = false;

        return preg_replace_callback(
            '/<!--RSC_JS_COMBINE:js-\d+-->/',
            function () use (&$used, $replacement) {
                if ($used) {
                    return '';
                }
                $used = true;
                return $replacement;
            },
            $processed
        );
    }

    private function combine_external_js_files($html) {
        $updated = $html;

        if (preg_match('/<head\b[^>]*>(.*?)<\/head>/is', $updated, $head_match, PREG_OFFSET_CAPTURE)) {
            $head_html = $head_match[1][0];
            $head_pos = $head_match[1][1];
            $new_head = $this->combine_script_region($head_html);
            $updated = substr_replace($updated, $new_head, $head_pos, strlen($head_html));
        }

        if (preg_match('/<body\b[^>]*>(.*?)<\/body>/is', $updated, $body_match, PREG_OFFSET_CAPTURE)) {
            $body_html = $body_match[1][0];
            $body_pos = $body_match[1][1];
            $new_body = $this->combine_script_region($body_html);
            $updated = substr_replace($updated, $new_body, $body_pos, strlen($body_html));
        }

        return $updated;
    }

    private function minify_inline_css($html) {
        if (!$this->setting_enabled('minify_css')) {
            return $html;
        }

        return preg_replace_callback(
            '/<style\b[^>]*>(.*?)<\/style>/is',
            function ($m) {
                $full = $m[0];
                $inside = $m[1];
                $min = $this->minify_css($inside);
                return str_replace($inside, $min, $full);
            },
            $html
        );
    }

    private function minify_inline_js($html) {
        if (!$this->setting_enabled('minify_js')) {
            return $html;
        }

        return preg_replace_callback(
            '/<script([^>]*)>(.*?)<\/script>/is',
            function ($m) {
                $attr = $m[1];
                $content = $m[2];

                if (stripos($attr, 'application/ld+json') !== false || stripos($attr, 'application/json') !== false) {
                    return "<script{$attr}>{$content}</script>";
                }

                if (stripos($attr, 'src=') !== false) {
                    return "<script{$attr}>{$content}</script>";
                }

                $min = $this->minify_js($content);

                return "<script{$attr}>{$min}</script>";
            },
            $html
        );
    }

    private function defer_scripts($html) {
        if (!$this->setting_enabled('defer_scripts')) {
            return $html;
        }

        return preg_replace_callback(
            '/<script([^>]*)>/i',
            function ($matches) {
                $attr = $matches[1];

                if (stripos($attr, 'application/ld+json') !== false || stripos($attr, 'application/json') !== false) {
                    return "<script{$attr}>";
                }

                if (stripos($attr, 'defer') !== false || stripos($attr, 'async') !== false || $this->is_module_script_tag('<script' . $attr . '>')) {
                    return "<script{$attr}>";
                }

                return "<script defer{$attr}>";
            },
            $html
        );
    }

    public function process_output($html) {
        if (!is_string($html) || $html === '') {
            return $html;
        }

        if ($this->setting_enabled('local_fonts')) {
            $html = $this->localize_font_stylesheets($html);
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

        if ($this->setting_enabled('minify_html')) {
            $html = $this->minify_html($html);
        }

        if ($this->setting_enabled('debug_footer')) {
            $html .= "\n<!-- Really Simple Cache | Cached on: " . date('Y-m-d H:i:s') . " -->";
        }

        if ($this->is_cacheable_request()) {
            $cache_file = $this->get_cache_file();
            $this->write_file_atomically($cache_file, $html);
        }

        return $html;
    }

    public function purge_all_cache(...$args) {
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }

        $dirs = [
            $this->cache_dir . 'pages/',
            $this->cache_dir . 'css/',
            $this->cache_dir . 'js/',
            $this->cache_dir . 'avatars/',
            $this->cache_dir . 'fonts/css/',
            $this->cache_dir . 'fonts/files/',
        ];

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
                    unlink($file);
                }
            }
        }
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

        foreach ($files as $file) {
            if (is_file($file) && is_writable($file)) {
                unlink($file);
            }
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
                return $this->cache_url . 'avatars/' . basename($existing);
            }
        }

        $local = $this->download_remote_asset($normalized, 'avatars', 'avatar');
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

    $uri = isset($parts['path']) ? $parts['path'] : '/';
    if (!empty($parts['query'])) {
        $uri .= '?' . $parts['query'];
    }

    $upload    = wp_upload_dir();
    $cache_dir = trailingslashit($upload['basedir']) . 'really-simple-cache/pages/';
    $file      = $cache_dir . rsc_get_cache_key(
        $uri,
        $parts['host'],
        isset($parts['scheme']) ? $parts['scheme'] : null
    ) . '.html';

    if (file_exists($file)) {
        unlink($file);
    }

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
});
