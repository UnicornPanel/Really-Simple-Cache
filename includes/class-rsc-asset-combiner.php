<?php

if (!defined('ABSPATH')) {
    exit;
}

class RSC_Asset_Combiner {

    public function combine_external_css_files(string $html, array $context): string {
        if ($html === '' || stripos($html, '<link') === false) {
            return $html;
        }

        $required = [
            'get_stylesheet_href',
            'is_same_domain',
            'is_asset_excluded',
            'url_to_path',
            'rewrite_css_urls_for_file',
            'write_file_atomically',
            'parse_attr',
            'minify_css',
        ];

        foreach ($required as $key) {
            if (empty($context[$key]) || !is_callable($context[$key])) {
                return $html;
            }
        }

        $cache_dir = isset($context['cache_dir']) ? (string) $context['cache_dir'] : '';
        $cache_url = isset($context['cache_url']) ? (string) $context['cache_url'] : '';
        if ($cache_dir === '' || $cache_url === '') {
            return $html;
        }

        $minify_css_enabled = !empty($context['minify_css_enabled']);
        $groups = [];

        $updated = preg_replace_callback(
            '/<link\b[^>]*>/i',
            function ($m) use (&$groups, $context) {
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

                $media = call_user_func($context['parse_attr'], $tag, 'media');
                $media = is_string($media) && $media !== '' ? strtolower(trim($media)) : 'all';

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
                    'css' => call_user_func($context['rewrite_css_urls_for_file'], $css, $href),
                ];
                $groups[$group_id]['original_tags'][] = $tag;

                return '<!--RSC_CSS_COMBINE:' . $group_id . '-->';
            },
            $html
        );

        if (!is_string($updated) || empty($groups)) {
            return is_string($updated) ? $updated : $html;
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
                if ($minify_css_enabled) {
                    $chunk = call_user_func($context['minify_css'], $chunk);
                }
                $combined .= "\n" . $chunk;
                $fingerprint .= $item['href'] . '|' . $chunk . ';';
            }

            $hash = md5($fingerprint);
            $rel = 'css/combined-' . $hash . '.css';
            $file = $cache_dir . $rel;
            $url = $cache_url . $rel;

            if (!file_exists($file)) {
                call_user_func($context['write_file_atomically'], $file, trim($combined));
            }

            $media_attr = ($group['media'] !== 'all' && $group['media'] !== '') ? ' media="' . esc_attr($group['media']) . '"' : '';
            $tags[$group_id] = '<link rel="stylesheet" href="' . esc_url($url) . '"' . $media_attr . ' />';
        }

        $seen = [];
        $final = preg_replace_callback(
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
            $updated
        );

        return is_string($final) ? $final : $updated;
    }

    public function combine_external_js_files(string $html, array $context): string {
        if ($html === '' || stripos($html, '<script') === false) {
            return $html;
        }

        $required = [
            'is_same_domain',
            'is_asset_excluded',
            'url_to_path',
            'write_file_atomically',
            'has_attr',
            'is_module_script_tag',
            'minify_js',
        ];

        foreach ($required as $key) {
            if (empty($context[$key]) || !is_callable($context[$key])) {
                return $html;
            }
        }

        $updated = $html;

        if (preg_match('/<head\b[^>]*>(.*?)<\/head>/is', $updated, $head_match, PREG_OFFSET_CAPTURE)) {
            $head_html = $head_match[1][0];
            $head_pos = $head_match[1][1];
            $new_head = $this->combine_script_region($head_html, $context);
            $updated = substr_replace($updated, $new_head, $head_pos, strlen($head_html));
        }

        if (preg_match('/<body\b[^>]*>(.*?)<\/body>/is', $updated, $body_match, PREG_OFFSET_CAPTURE)) {
            $body_html = $body_match[1][0];
            $body_pos = $body_match[1][1];
            $new_body = $this->combine_script_region($body_html, $context);
            $updated = substr_replace($updated, $new_body, $body_pos, strlen($body_html));
        }

        return $updated;
    }

    private function combine_script_region(string $region_html, array $context): string {
        $cache_dir = isset($context['cache_dir']) ? (string) $context['cache_dir'] : '';
        $cache_url = isset($context['cache_url']) ? (string) $context['cache_url'] : '';
        if ($cache_dir === '' || $cache_url === '') {
            return $region_html;
        }

        $minify_js_enabled = !empty($context['minify_js_enabled']);
        $groups = [];
        $group_index = 0;

        $processed = preg_replace_callback(
            '/<script[^>]*src=["\']([^"\']+)["\'][^>]*>\s*<\/script>/i',
            function ($m) use (&$groups, &$group_index, $context) {
                $tag = $m[0];
                $src = html_entity_decode($m[1]);

                if (!call_user_func($context['is_same_domain'], $src)) {
                    return $tag;
                }

                if (call_user_func($context['is_asset_excluded'], $src, 'js')) {
                    return $tag;
                }

                if (call_user_func($context['has_attr'], $tag, 'async') || call_user_func($context['has_attr'], $tag, 'defer') || call_user_func($context['has_attr'], $tag, 'nomodule')) {
                    return $tag;
                }

                if (call_user_func($context['is_module_script_tag'], $tag)) {
                    return $tag;
                }

                if (call_user_func($context['has_attr'], $tag, 'integrity') || call_user_func($context['has_attr'], $tag, 'crossorigin')) {
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

        if (!is_string($processed) || empty($groups) || count($groups) < 2) {
            return $region_html;
        }

        $fingerprint = '';
        $combined = '';

        foreach ($groups as $items) {
            foreach ($items as $item) {
                $chunk = $item['js'];
                if ($minify_js_enabled) {
                    $chunk = call_user_func($context['minify_js'], $chunk);
                }
                $combined .= "\n" . $chunk . ';';
                $fingerprint .= $item['src'] . '|' . $chunk . ';';
            }
        }

        $hash = md5($fingerprint);
        $rel = 'js/combined-' . $hash . '.js';
        $file = $cache_dir . $rel;
        $url = $cache_url . $rel;

        if (!file_exists($file)) {
            call_user_func($context['write_file_atomically'], $file, trim($combined));
        }

        $replacement = '<script src="' . esc_url($url) . '"></script>';
        $used = false;

        $final = preg_replace_callback(
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

        return is_string($final) ? $final : $region_html;
    }
}
