<?php
/**
 * Plugin Name: Really Simple Cache
 * Description: Super lightweight output cache with HTML/CSS/JS minification and auto-defer scripts.
 * Version: 2.0
 * Author: UnicornPanel.net
 */

if (!defined('ABSPATH')) exit;

class ReallySimpleCache {

    private $cache_dir;
    private $cache_url;
    private $cache_time = 3600; // 1 hour default

    public function __construct() {

        $upload = wp_upload_dir();

        $this->cache_dir = trailingslashit($upload['basedir']) . 'really-simple-cache/';
        $this->cache_url = trailingslashit($upload['baseurl']) . 'really-simple-cache/';

        // Ensure directories exist
        add_action('init', [$this, 'create_dirs']);

        // Serve cache before WP renders template
        add_action('template_redirect', [$this, 'serve_cache'], 0);

        // Start output buffering to capture HTML
        add_action('template_redirect', [$this, 'start_buffer'], 1);

        // Flush/finish buffer on shutdown
        add_action('shutdown', [$this, 'end_buffer'], 9999);
    }

    /**
     * Create cache directories:
     *  - pages/
     *  - css/
     *  - js/
     */
    public function create_dirs() {
        $paths = [
            $this->cache_dir,
            $this->cache_dir . 'pages/',
            $this->cache_dir . 'css/',
            $this->cache_dir . 'js/',
        ];
        foreach ($paths as $path) {
            if (!file_exists($path)) {
                wp_mkdir_p($path);
            }
        }
    }

    /**
     * Whether this request is safe to cache.
     */
    private function is_cacheable_request() {

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

        if (is_feed() || is_trackback() || is_robots() || is_search()) {
            return false;
        }

        return true;
    }

    /**
     * Get the cache file path for the current full URI (path + query).
     */
    private function get_cache_file() {
        $uri = isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '/';
        $key = md5($uri);
        return $this->cache_dir . 'pages/' . $key . '.html';
    }

    /**
     * Serve cached HTML if present and still fresh.
     */
    public function serve_cache() {

        if (!$this->is_cacheable_request()) {
            return;
        }

        $file = $this->get_cache_file();

        if (file_exists($file) && (time() - filemtime($file)) < $this->cache_time) {

            $mtime = filemtime($file);

            // Cache-related headers
            header("Last-Modified: " . gmdate("D, d M Y H:i:s", $mtime) . " GMT");
            header("Cache-Control: public, max-age={$this->cache_time}");
            header("Expires: " . gmdate("D, d M Y H:i:s", time() + $this->cache_time) . " GMT");
            header("X-RSC-Cache: HIT");
            header("Content-Type: text/html; charset=UTF-8");

            // Optional ETag
            $etag = '"' . md5_file($file) . '"';
            header("ETag: $etag");

            // Handle conditional GET
            if (isset($_SERVER['HTTP_IF_NONE_MATCH']) && trim($_SERVER['HTTP_IF_NONE_MATCH']) === $etag) {
                header("HTTP/1.1 304 Not Modified");
                exit;
            }

            readfile($file);
            exit;
        }
    }

    /**
     * Start output buffering with our callback.
     */
    public function start_buffer() {

        if (!$this->is_cacheable_request()) {
            return;
        }

        // Tell tools "this page was not served from cache *yet*"
        header("X-RSC-Cache: MISS");

        ob_start([$this, 'process_output']);
    }

    /**
     * End buffer on shutdown.
     */
    public function end_buffer() {
        if (ob_get_level() > 0 && ob_get_length() !== false) {
            @ob_end_flush();
        }
    }

    /**
     * Minify HTML (simple, but effective).
     */
    private function minify_html($html) {
        // Remove extra whitespace between tags
        $html = preg_replace('/>\s+</', '><', $html);
        // Collapse multiple whitespace runs
        $html = preg_replace('/\s+/', ' ', $html);
        return trim($html);
    }

    /**
     * Minify CSS content.
     */
    private function minify_css($css) {
        // Remove comments
        $css = preg_replace('!/\*.*?\*/!s', '', $css);
        // Remove whitespace
        $css = preg_replace('/\s+/', ' ', $css);
        $css = preg_replace('/\s*([{};:,])\s*/', '$1', $css);
        return trim($css);
    }

    /**
     * Minify JS content (very simple).
     */
    private function minify_js($js) {
        require_once __DIR__ . '/JSMin.php';
        try {
            return \JSMin\JSMin::minify($js);
        } catch (Exception $e) {
            // If JSMin throws an exception, return original JS to avoid breakage
            return $js;
        }
    }

    /**
     * Check if a URL is same-domain (or relative).
     */
    private function is_same_domain($url) {

        // Protocol-relative URLs: //example.com/...
        if (strpos($url, '//') === 0) {
            $url = (is_ssl() ? 'https:' : 'http:') . $url;
        }

        $home_host = wp_parse_url(home_url(), PHP_URL_HOST);
        $url_host  = wp_parse_url($url, PHP_URL_HOST);

        // Relative URL => same domain
        if (!$url_host) {
            return true;
        }

        return (strtolower($home_host) === strtolower($url_host));
    }

    /**
     * Convert a site URL or relative URL to a local filesystem path (if possible).
     */
    private function url_to_path($url) {

        // Protocol-relative
        if (strpos($url, '//') === 0) {
            $url = (is_ssl() ? 'https:' : 'http:') . $url;
        }

        $parsed = wp_parse_url($url);
        if (!$parsed || empty($parsed['path'])) {
            return false;
        }

        $path = $parsed['path'];

        // Strip leading slash and map onto ABSPATH
        $relative = ltrim($path, '/');
        return ABSPATH . $relative;
    }

    /**
     * Insert "defer" into all non-JSON <script> tags that don't already have it.
     */
    private function defer_scripts($html) {
        return preg_replace_callback(
            '/<script([^>]*)>/i',
            function ($matches) {
                $attr = $matches[1];

                // Skip JSON/JSON-LD scripts
                if (stripos($attr, 'application/ld+json') !== false ||
                    stripos($attr, 'application/json') !== false) {
                    return "<script{$attr}>";
                }

                // Already has defer
                if (stripos($attr, 'defer') !== false) {
                    return "<script{$attr}>";
                }

                return "<script defer{$attr}>";
            },
            $html
        );
    }

    /**
     * Main output processor:
     *  - Minifies & caches external CSS/JS (same-domain only)
     *  - Minifies inline CSS/JS (skipping JSON/JSON-LD)
     *  - Defers scripts
     *  - Minifies final HTML
     *  - Saves HTML page cache
     */
    public function process_output($html) {

        if (!is_string($html) || $html === '') {
            return $html;
        }

        $cache_file = $this->get_cache_file();

        /**
         * 1) External CSS: minify and save under /css/, only if same-domain.
         */
        $html = preg_replace_callback(
            '/<link[^>]+rel=["\']stylesheet["\'][^>]+href=["\']([^"\']+)["\'][^>]*>/i',
            function ($m) {

                $tag  = $m[0];
                $href = $m[1];

                // Only process same-domain or relative URLs
                if (!$this->is_same_domain($href)) {
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

                $min = $this->minify_css($css);
                if ($min === '') {
                    return $tag;
                }

                // Hash based on original URL + contents
                $hash = md5($href . '|' . $min);
                $rel  = 'css/' . $hash . '.css';
                $file = $this->cache_dir . $rel;
                $url  = $this->cache_url . $rel;

                if (!file_exists($file)) {
                    @file_put_contents($file, $min);
                }

                // Replace only the href value, preserve other attributes
                $new_tag = preg_replace(
                    '/href=["\'][^"\']+["\']/',
                    'href="' . esc_url($url) . '"',
                    $tag,
                    1
                );

                return $new_tag;
            },
            $html
        );

        /**
         * 2) External JS: minify and save under /js/, only if same-domain.
         */
        $html = preg_replace_callback(
            '/<script[^>]*src=["\']([^"\']+)["\'][^>]*>\s*<\/script>/i',
            function ($m) {

                $tag = $m[0];
                $src = $m[1];

                // Only process same-domain or relative URLs
                if (!$this->is_same_domain($src)) {
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

                $min = $this->minify_js($js);
                if ($min === '') {
                    return $tag;
                }

                $hash = md5($src . '|' . $min);
                $rel  = 'js/' . $hash . '.js';
                $file = $this->cache_dir . $rel;
                $url  = $this->cache_url . $rel;

                if (!file_exists($file)) {
                    @file_put_contents($file, $min);
                }

                // Replace only the src value, preserve other attributes
                $new_tag = preg_replace(
                    '/src=["\'][^"\']+["\']/',
                    'src="' . esc_url($url) . '"',
                    $tag,
                    1
                );

                return $new_tag;
            },
            $html
        );

        /**
         * 3) Minify inline <style> blocks (keep them inline, don't cache).
         */
        $html = preg_replace_callback(
            '/<style\b[^>]*>(.*?)<\/style>/is',
            function ($m) {
                $full   = $m[0];
                $inside = $m[1];
                $min    = $this->minify_css($inside);
                return str_replace($inside, $min, $full);
            },
            $html
        );

        /**
         * 4) Minify inline JS (skip JSON/JSON-LD and <script src>).
         */
        $html = preg_replace_callback(
            '/<script([^>]*)>(.*?)<\/script>/is',
            function ($m) {

                $attr    = $m[1];
                $content = $m[2];

                // Skip JSON/JSON-LD scripts
                if (stripos($attr, 'application/ld+json') !== false ||
                    stripos($attr, 'application/json') !== false) {
                    return "<script{$attr}>{$content}</script>";
                }

                // Skip external scripts (handled earlier)
                if (stripos($attr, 'src=') !== false) {
                    return "<script{$attr}>{$content}</script>";
                }

                $min = $this->minify_js($content);

                return "<script{$attr}>{$min}</script>";
            },
            $html
        );

        /**
         * 5) Defer non-JSON scripts.
         */
        $html = $this->defer_scripts($html);

        /**
         * 6) Minify HTML last.
         */
        $html = $this->minify_html($html);

        /**
         * 7) Debug footer + save page cache.
         */
        $debug = "\n<!-- Really Simple Cache | Cached on: " . date('Y-m-d H:i:s') . " -->";
        $html .= $debug;

        @file_put_contents($cache_file, $html);

        return $html;
    }
}

new ReallySimpleCache();

/**
 * ADMIN BAR: Cache / Clear All / Clear This Page
 */

add_action('admin_bar_menu', function($admin_bar) {

    if (!current_user_can('manage_options')) {
        return;
    }

    // Current full URL
    $scheme      = is_ssl() ? 'https://' : 'http://';
    $host        = $_SERVER['HTTP_HOST'] ?? '';
    $request_uri = $_SERVER['REQUEST_URI'] ?? '';
    $current_url = $scheme . $host . $request_uri;

    // Main "Cache" node
    $admin_bar->add_node([
        'id'    => 'rsc-cache',
        'title' => 'Cache',
        'href'  => false,
        'meta'  => ['class' => 'rsc-cache-menu'],
    ]);

    // "Clear All" node
    $admin_bar->add_node([
        'id'     => 'rsc-clear-all',
        'title'  => 'Clear All',
        'parent' => 'rsc-cache',
        'href'   => wp_nonce_url(
            admin_url('admin-post.php?action=rsc_clear_all'),
            'rsc_clear_all'
        ),
    ]);

    // "Clear This Page" node
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

/**
 * Handle "Clear All" action – clears pages + css + js cache.
 */
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
    ];

    $deleted = 0;

    foreach ($dirs as $dir) {
        if (!is_dir($dir)) continue;

        foreach (glob($dir . '*') as $file) {
            if (is_file($file) && @unlink($file)) {
                $deleted++;
            }
        }
    }

    wp_redirect(admin_url('index.php?cache_all_cleared=' . $deleted));
    exit;
});

/**
 * Handle "Clear This Page" action – only clears cached HTML for that URL.
 */
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

    // Rebuild URI exactly like get_cache_file() does: path + query
    $uri = isset($parts['path']) ? $parts['path'] : '/';
    if (!empty($parts['query'])) {
        $uri .= '?' . $parts['query'];
    }

    $upload    = wp_upload_dir();
    $cache_dir = trailingslashit($upload['basedir']) . 'really-simple-cache/pages/';
    $file      = $cache_dir . md5($uri) . '.html';

    if (file_exists($file)) {
        @unlink($file);
    }

    // Redirect back to the original page with a small flag
    $sep = (strpos($url, '?') === false) ? '?' : '&';
    wp_redirect($url . $sep . 'cache_cleared=1');
    exit;
});

/**
 * Admin notices for cache clear actions.
 */
add_action('admin_notices', function() {

    if (isset($_GET['cache_all_cleared'])) {
        $n = intval($_GET['cache_all_cleared']);
        echo '<div class="notice notice-success"><p><strong>Really Simple Cache:</strong> Cleared ' . $n . ' cached files.</p></div>';
    }

    if (isset($_GET['cache_cleared'])) {
        echo '<div class="notice notice-success"><p><strong>Really Simple Cache:</strong> Page cache cleared.</p></div>';
    }

});
