<?php

if (!defined('ABSPATH')) {
    exit;
}

class RSC_Minifier {

    private $plugin_dir;

    public function __construct(string $plugin_dir) {
        $this->plugin_dir = rtrim($plugin_dir, '/');
    }

    public function minify_html(string $html): string {
        $html = preg_replace('/>\s+</', '><', $html);
        return trim((string) $html);
    }

    public function minify_css(string $css): string {
        $css = preg_replace('!/\*.*?\*/!s', '', $css);
        $css = preg_replace('/\s+/', ' ', (string) $css);
        $css = preg_replace('/\s*([{};:,])\s*/', '$1', (string) $css);
        return trim((string) $css);
    }

    public function minify_js(string $js): string {
        require_once $this->plugin_dir . '/includes/JSMin.php';

        try {
            return \JSMin\JSMin::minify($js);
        } catch (\Exception $e) {
            return $js;
        }
    }

    public function minify_external_css_files(string $html, array $context): string {
        if ($html === '' || stripos($html, '<link') === false) {
            return $html;
        }

        if (empty($context['get_stylesheet_href']) || !is_callable($context['get_stylesheet_href'])) {
            return $html;
        }

        if (empty($context['is_same_domain']) || !is_callable($context['is_same_domain'])) {
            return $html;
        }

        if (empty($context['is_asset_excluded']) || !is_callable($context['is_asset_excluded'])) {
            return $html;
        }

        if (empty($context['url_to_path']) || !is_callable($context['url_to_path'])) {
            return $html;
        }

        if (empty($context['rewrite_css_urls_for_file']) || !is_callable($context['rewrite_css_urls_for_file'])) {
            return $html;
        }

        if (empty($context['write_file_atomically']) || !is_callable($context['write_file_atomically'])) {
            return $html;
        }

        $cache_dir = isset($context['cache_dir']) ? (string) $context['cache_dir'] : '';
        $cache_url = isset($context['cache_url']) ? (string) $context['cache_url'] : '';
        if ($cache_dir === '' || $cache_url === '') {
            return $html;
        }

        $minify_css_enabled = !empty($context['minify_css_enabled']);

        $updated = preg_replace_callback(
            '/<link\b[^>]*>/i',
            function ($m) use ($context, $cache_dir, $cache_url, $minify_css_enabled) {
                $tag = $m[0];
                $href = call_user_func($context['get_stylesheet_href'], $tag);
                if (!is_string($href) || $href === '') {
                    return $tag;
                }

                if (!call_user_func($context['is_same_domain'], $href)) {
                    return $tag;
                }

                if (call_user_func($context['is_asset_excluded'], $href, 'css')) {
                    return $tag;
                }

                $path = call_user_func($context['url_to_path'], $href);
                if (!$path || !is_string($path) || !file_exists($path) || !is_readable($path)) {
                    return $tag;
                }

                $css = file_get_contents($path);
                if (!is_string($css) || $css === '') {
                    return $tag;
                }

                $css = call_user_func($context['rewrite_css_urls_for_file'], $css, $href);
                $min = $minify_css_enabled ? $this->minify_css($css) : $css;
                if ($min === '') {
                    return $tag;
                }

                $hash = md5($href . '|' . $min);
                $rel  = 'css/' . $hash . '.css';
                $file = $cache_dir . $rel;
                $url  = $cache_url . $rel;

                if (!file_exists($file)) {
                    call_user_func($context['write_file_atomically'], $file, $min);
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

        return is_string($updated) ? $updated : $html;
    }

    public function minify_external_js_files(string $html, array $context): string {
        if ($html === '' || stripos($html, '<script') === false) {
            return $html;
        }

        if (empty($context['is_same_domain']) || !is_callable($context['is_same_domain'])) {
            return $html;
        }

        if (empty($context['is_asset_excluded']) || !is_callable($context['is_asset_excluded'])) {
            return $html;
        }

        if (empty($context['url_to_path']) || !is_callable($context['url_to_path'])) {
            return $html;
        }

        if (empty($context['write_file_atomically']) || !is_callable($context['write_file_atomically'])) {
            return $html;
        }

        $cache_dir = isset($context['cache_dir']) ? (string) $context['cache_dir'] : '';
        $cache_url = isset($context['cache_url']) ? (string) $context['cache_url'] : '';
        if ($cache_dir === '' || $cache_url === '') {
            return $html;
        }

        $minify_js_enabled = !empty($context['minify_js_enabled']);

        $updated = preg_replace_callback(
            '/<script[^>]*src=["\']([^"\']+)["\'][^>]*>\s*<\/script>/i',
            function ($m) use ($context, $cache_dir, $cache_url, $minify_js_enabled) {
                $tag = $m[0];
                $src = html_entity_decode($m[1]);

                if (!call_user_func($context['is_same_domain'], $src)) {
                    return $tag;
                }

                if (call_user_func($context['is_asset_excluded'], $src, 'js')) {
                    return $tag;
                }

                $path = call_user_func($context['url_to_path'], $src);
                if (!$path || !is_string($path) || !file_exists($path) || !is_readable($path)) {
                    return $tag;
                }

                $js = file_get_contents($path);
                if (!is_string($js) || $js === '') {
                    return $tag;
                }

                $min = $minify_js_enabled ? $this->minify_js($js) : $js;
                if ($min === '') {
                    return $tag;
                }

                $hash = md5($src . '|' . $min);
                $rel  = 'js/' . $hash . '.js';
                $file = $cache_dir . $rel;
                $url  = $cache_url . $rel;

                if (!file_exists($file)) {
                    call_user_func($context['write_file_atomically'], $file, $min);
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

        return is_string($updated) ? $updated : $html;
    }

    public function minify_inline_css(string $html, bool $enabled): string {
        if (!$enabled) {
            return $html;
        }

        $updated = preg_replace_callback(
            '/<style\b[^>]*>(.*?)<\/style>/is',
            function ($m) {
                $full = $m[0];
                $inside = $m[1];
                $min = $this->minify_css($inside);
                return str_replace($inside, $min, $full);
            },
            $html
        );

        return is_string($updated) ? $updated : $html;
    }

    public function minify_inline_js(string $html, bool $enabled): string {
        if (!$enabled) {
            return $html;
        }

        $updated = preg_replace_callback(
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

        return is_string($updated) ? $updated : $html;
    }
}
