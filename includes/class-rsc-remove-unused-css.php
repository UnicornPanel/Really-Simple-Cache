<?php

if (!defined('ABSPATH')) {
    exit;
}

class RSC_Remove_Unused_CSS {

    private $cache_dir;
    private $cache_url;

    public function __construct($cache_dir, $cache_url) {
        $this->cache_dir = trailingslashit((string) $cache_dir);
        $this->cache_url = trailingslashit((string) $cache_url);
    }

    public function prune_html_stylesheets(string $html, array $context): string {
        if ($html === '' || stripos($html, '<link') === false) {
            return $html;
        }

        $request_uri = isset($context['request_uri']) ? (string) $context['request_uri'] : '/';
        $page_patterns = isset($context['excluded_page_patterns']) && is_array($context['excluded_page_patterns'])
            ? $context['excluded_page_patterns']
            : [];

        if ($this->is_filtered_true('rsc_rucss_skip_page', false, [$request_uri, $context])) {
            return $html;
        }

        if (!empty($page_patterns) && $this->matches_any_pattern($request_uri, $page_patterns)) {
            return $html;
        }

        $callbacks = $this->normalize_callbacks($context);
        if (empty($callbacks['get_stylesheet_href']) || empty($callbacks['is_same_domain']) || empty($callbacks['url_to_path']) || empty($callbacks['write_file_atomically'])) {
            return $html;
        }

        $inventory = $this->build_dom_inventory($html);

        $max_css_bytes = max(1024, (int) ($context['max_css_bytes'] ?? 524288));
        $page_key = (string) ($context['page_key'] ?? md5($request_uri));

        $keep_patterns = isset($context['keep_selector_patterns']) && is_array($context['keep_selector_patterns'])
            ? array_values(array_filter(array_map('trim', $context['keep_selector_patterns']), static function ($v) {
                return $v !== '';
            }))
            : [];
        $keep_hash = md5(implode('|', $keep_patterns));

        return (string) preg_replace_callback(
            '/<link\b[^>]*>/i',
            function ($m) use ($callbacks, $context, $inventory, $max_css_bytes, $page_key, $keep_patterns, $keep_hash) {
                $tag = $m[0];
                $href = (string) call_user_func($callbacks['get_stylesheet_href'], $tag);
                if ($href === '') {
                    return $tag;
                }

                if ($this->is_filtered_true('rsc_rucss_skip_stylesheet', false, [$href, $context])) {
                    return $tag;
                }

                if (!empty($callbacks['is_asset_excluded']) && call_user_func($callbacks['is_asset_excluded'], $href, 'css')) {
                    return $tag;
                }

                if (!call_user_func($callbacks['is_same_domain'], $href)) {
                    return $tag;
                }

                $path = call_user_func($callbacks['url_to_path'], $href);
                if (!$path || !is_string($path) || !is_file($path) || !is_readable($path)) {
                    return $tag;
                }

                $size = @filesize($path);
                if (is_int($size) && $size > $max_css_bytes) {
                    return $tag;
                }

                $css = @file_get_contents($path);
                if (!is_string($css) || $css === '') {
                    return $tag;
                }

                $css_hash = md5($css);
                $artifact_hash = md5($href . '|' . $css_hash . '|' . $page_key . '|' . $keep_hash);
                $rel = 'rucss/' . $artifact_hash . '.css';
                $artifact_file = $this->cache_dir . $rel;
                $artifact_url = $this->cache_url . $rel;

                if (!is_file($artifact_file)) {
                    $pruned_css = $this->prune_css($css, $inventory, $keep_patterns, $context);
                    if (!is_string($pruned_css) || trim($pruned_css) === '') {
                        return $tag;
                    }

                    $written = call_user_func($callbacks['write_file_atomically'], $artifact_file, $pruned_css);
                    if (!$written) {
                        return $tag;
                    }
                }

                return (string) preg_replace(
                    '/href=["\'][^"\']+["\']/',
                    'href="' . esc_url($artifact_url) . '"',
                    $tag,
                    1
                );
            },
            $html
        );
    }

    private function normalize_callbacks(array $context): array {
        $keys = [
            'get_stylesheet_href',
            'is_same_domain',
            'url_to_path',
            'is_asset_excluded',
            'write_file_atomically',
        ];

        $callbacks = [];
        foreach ($keys as $key) {
            $value = $context[$key] ?? null;
            if (is_callable($value)) {
                $callbacks[$key] = $value;
            }
        }

        return $callbacks;
    }

    private function prune_css(string $css, array $inventory, array $keep_patterns, array $context): string {
        $blocks = $this->parse_css_blocks($css);
        if (empty($blocks)) {
            return $css;
        }

        $out = '';
        foreach ($blocks as $block) {
            $type = $block['type'] ?? '';
            if ($type === 'statement') {
                $out .= $block['raw'];
                continue;
            }

            if ($type !== 'block') {
                $out .= $block['raw'];
                continue;
            }

            $prelude = trim((string) $block['prelude']);
            $body = (string) $block['body'];

            if ($prelude === '') {
                $out .= $block['raw'];
                continue;
            }

            if ($prelude[0] === '@') {
                $lower = strtolower($prelude);

                if (preg_match('/^@(font-face|keyframes|-webkit-keyframes|property|counter-style|page)\b/i', $lower)) {
                    $out .= $block['raw'];
                    continue;
                }

                if (preg_match('/^@(media|supports|container|layer|document)\b/i', $lower)) {
                    $inner = $this->prune_css($body, $inventory, $keep_patterns, $context);
                    if (trim($inner) !== '') {
                        $out .= $prelude . '{' . $inner . '}';
                    }
                    continue;
                }

                // Keep unknown at-rules conservatively.
                $out .= $block['raw'];
                continue;
            }

            $selectors = $this->split_selector_list($prelude);
            if (empty($selectors)) {
                $out .= $block['raw'];
                continue;
            }

            $kept = [];
            foreach ($selectors as $selector) {
                if ($this->should_keep_selector($selector, $inventory, $keep_patterns, $context)) {
                    $kept[] = trim($selector);
                }
            }

            if (empty($kept)) {
                continue;
            }

            $out .= implode(',', $kept) . '{' . $body . '}';
        }

        return $out;
    }

    private function should_keep_selector(string $selector, array $inventory, array $keep_patterns, array $context): bool {
        $selector = trim($selector);
        if ($selector === '') {
            return false;
        }

        if ($this->is_filtered_true('rsc_rucss_keep_selector', false, [$selector, $context])) {
            return true;
        }

        if ($this->matches_any_pattern($selector, $keep_patterns)) {
            return true;
        }

        if ($this->has_dynamic_selector_features($selector)) {
            return true;
        }

        $normalized = preg_replace('/::?[a-zA-Z-]+(?:\([^)]*\))?/', '', $selector);
        $normalized = is_string($normalized) ? $normalized : $selector;

        $ids = [];
        if (preg_match_all('/#([a-zA-Z0-9_-]+)/', $normalized, $m)) {
            $ids = array_map('strtolower', $m[1]);
        }

        $classes = [];
        if (preg_match_all('/\.([a-zA-Z0-9_-]+)/', $normalized, $m)) {
            $classes = array_map('strtolower', $m[1]);
        }

        $attrs = [];
        if (preg_match_all('/\[\s*([a-zA-Z_:][-a-zA-Z0-9_:.]*)/', $normalized, $m)) {
            $attrs = array_map('strtolower', $m[1]);
        }

        foreach ($ids as $id) {
            if (!isset($inventory['ids'][$id])) {
                return false;
            }
        }

        foreach ($classes as $class) {
            if (!isset($inventory['classes'][$class])) {
                return false;
            }
        }

        foreach ($attrs as $attr) {
            if (!isset($inventory['attrs'][$attr])) {
                return false;
            }
        }

        $tag_tokens = [];
        if (preg_match_all('/(^|[\s>+~,(])([a-zA-Z][a-zA-Z0-9-]*)/', $normalized, $m, PREG_SET_ORDER)) {
            foreach ($m as $match) {
                $token = strtolower($match[2]);
                if (in_array($token, ['from', 'to'], true)) {
                    continue;
                }
                $tag_tokens[] = $token;
            }
        }

        foreach ($tag_tokens as $tag) {
            if (!isset($inventory['tags'][$tag])) {
                return false;
            }
        }

        // If we extracted no verifiable signals, keep conservatively.
        if (empty($ids) && empty($classes) && empty($attrs) && empty($tag_tokens)) {
            return true;
        }

        return true;
    }

    private function has_dynamic_selector_features(string $selector): bool {
        if (preg_match('/:(hover|focus|focus-visible|focus-within|active|target|visited|link|checked|disabled|enabled|required|optional|valid|invalid|in-range|out-of-range|placeholder-shown|autofill|open|closed|fullscreen|modal|popover-open|has|is|where|not|nth-|first-|last-|only-|empty|root|before|after)\b/i', $selector)) {
            return true;
        }

        if (strpos($selector, '*') !== false || strpos($selector, '&') !== false) {
            return true;
        }

        return false;
    }

    private function build_dom_inventory(string $html): array {
        $inventory = [
            'tags' => [],
            'ids' => [],
            'classes' => [],
            'attrs' => [],
        ];

        if (preg_match_all('/<([a-zA-Z][a-zA-Z0-9:-]*)(\s[^>]*?)?>/s', $html, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $tag = strtolower($match[1]);
                if ($tag === '' || $tag[0] === '/' || $tag === '!' || $tag === '?xml') {
                    continue;
                }

                $inventory['tags'][$tag] = true;
                $attr_str = isset($match[2]) ? (string) $match[2] : '';

                if ($attr_str !== '') {
                    if (preg_match('/\bid\s*=\s*(["\'])(.*?)\1/is', $attr_str, $id_match)) {
                        $id = strtolower(trim($id_match[2]));
                        if ($id !== '') {
                            $inventory['ids'][$id] = true;
                        }
                    }

                    if (preg_match('/\bclass\s*=\s*(["\'])(.*?)\1/is', $attr_str, $class_match)) {
                        $classes = preg_split('/\s+/', trim($class_match[2]));
                        if (is_array($classes)) {
                            foreach ($classes as $class_name) {
                                $class_name = strtolower(trim($class_name));
                                if ($class_name !== '') {
                                    $inventory['classes'][$class_name] = true;
                                }
                            }
                        }
                    }

                    if (preg_match_all('/\s([a-zA-Z_:][-a-zA-Z0-9_:.]*)\s*(?:=|(?=\s|$))/', $attr_str, $attr_matches)) {
                        foreach ($attr_matches[1] as $attr_name) {
                            $attr_name = strtolower((string) $attr_name);
                            if ($attr_name !== '') {
                                $inventory['attrs'][$attr_name] = true;
                            }
                        }
                    }
                }
            }
        }

        return $inventory;
    }

    private function parse_css_blocks(string $css): array {
        $len = strlen($css);
        $i = 0;
        $blocks = [];

        while ($i < $len) {
            $start = $i;

            while ($i < $len && ctype_space($css[$i])) {
                $i++;
            }

            if ($i >= $len) {
                break;
            }

            if ($i + 1 < $len && $css[$i] === '/' && $css[$i + 1] === '*') {
                $end = strpos($css, '*/', $i + 2);
                if ($end === false) {
                    break;
                }
                $i = $end + 2;
                $blocks[] = [
                    'type' => 'comment',
                    'raw' => substr($css, $start, $i - $start),
                ];
                continue;
            }

            $statement_end = null;
            $brace_pos = null;
            $j = $i;
            $quote = '';
            $paren_depth = 0;

            while ($j < $len) {
                $ch = $css[$j];

                if ($quote !== '') {
                    if ($ch === '\\') {
                        $j += 2;
                        continue;
                    }
                    if ($ch === $quote) {
                        $quote = '';
                    }
                    $j++;
                    continue;
                }

                if ($ch === '"' || $ch === "'") {
                    $quote = $ch;
                    $j++;
                    continue;
                }

                if ($ch === '(') {
                    $paren_depth++;
                    $j++;
                    continue;
                }

                if ($ch === ')' && $paren_depth > 0) {
                    $paren_depth--;
                    $j++;
                    continue;
                }

                if ($paren_depth === 0 && $ch === ';') {
                    $statement_end = $j;
                    break;
                }

                if ($paren_depth === 0 && $ch === '{') {
                    $brace_pos = $j;
                    break;
                }

                $j++;
            }

            if ($brace_pos === null) {
                $end_pos = ($statement_end !== null) ? $statement_end + 1 : $len;
                $raw = substr($css, $start, $end_pos - $start);
                $blocks[] = [
                    'type' => 'statement',
                    'raw' => $raw,
                ];
                $i = $end_pos;
                continue;
            }

            $prelude = trim(substr($css, $i, $brace_pos - $i));
            $depth = 1;
            $k = $brace_pos + 1;
            $quote = '';

            while ($k < $len && $depth > 0) {
                $ch = $css[$k];
                if ($quote !== '') {
                    if ($ch === '\\') {
                        $k += 2;
                        continue;
                    }
                    if ($ch === $quote) {
                        $quote = '';
                    }
                    $k++;
                    continue;
                }

                if ($ch === '"' || $ch === "'") {
                    $quote = $ch;
                    $k++;
                    continue;
                }

                if ($k + 1 < $len && $css[$k] === '/' && $css[$k + 1] === '*') {
                    $comment_end = strpos($css, '*/', $k + 2);
                    if ($comment_end === false) {
                        $k = $len;
                        break;
                    }
                    $k = $comment_end + 2;
                    continue;
                }

                if ($ch === '{') {
                    $depth++;
                } elseif ($ch === '}') {
                    $depth--;
                }

                $k++;
            }

            if ($depth !== 0) {
                $blocks[] = [
                    'type' => 'raw',
                    'raw' => substr($css, $start),
                ];
                break;
            }

            $raw = substr($css, $start, $k - $start);
            $body = substr($css, $brace_pos + 1, ($k - 1) - ($brace_pos + 1));

            $blocks[] = [
                'type' => 'block',
                'prelude' => $prelude,
                'body' => $body,
                'raw' => $raw,
            ];

            $i = $k;
        }

        return $blocks;
    }

    private function split_selector_list(string $selectors): array {
        $items = [];
        $buf = '';
        $len = strlen($selectors);
        $depth_square = 0;
        $depth_round = 0;
        $quote = '';

        for ($i = 0; $i < $len; $i++) {
            $ch = $selectors[$i];

            if ($quote !== '') {
                $buf .= $ch;
                if ($ch === '\\' && $i + 1 < $len) {
                    $i++;
                    $buf .= $selectors[$i];
                    continue;
                }
                if ($ch === $quote) {
                    $quote = '';
                }
                continue;
            }

            if ($ch === '"' || $ch === "'") {
                $quote = $ch;
                $buf .= $ch;
                continue;
            }

            if ($ch === '[') {
                $depth_square++;
                $buf .= $ch;
                continue;
            }
            if ($ch === ']' && $depth_square > 0) {
                $depth_square--;
                $buf .= $ch;
                continue;
            }
            if ($ch === '(') {
                $depth_round++;
                $buf .= $ch;
                continue;
            }
            if ($ch === ')' && $depth_round > 0) {
                $depth_round--;
                $buf .= $ch;
                continue;
            }

            if ($ch === ',' && $depth_square === 0 && $depth_round === 0) {
                $trimmed = trim($buf);
                if ($trimmed !== '') {
                    $items[] = $trimmed;
                }
                $buf = '';
                continue;
            }

            $buf .= $ch;
        }

        $trimmed = trim($buf);
        if ($trimmed !== '') {
            $items[] = $trimmed;
        }

        return $items;
    }

    private function wildcard_match(string $pattern, string $subject): bool {
        if ($pattern === '') {
            return false;
        }

        $regex = preg_quote($pattern, '/');
        $regex = str_replace(['\\*', '\\?'], ['.*', '.'], $regex);

        return preg_match('/^' . $regex . '$/i', $subject) === 1;
    }

    private function matches_any_pattern(string $subject, array $patterns): bool {
        foreach ($patterns as $pattern) {
            $pattern = trim((string) $pattern);
            if ($pattern === '') {
                continue;
            }
            if ($this->wildcard_match($pattern, $subject)) {
                return true;
            }
        }

        return false;
    }

    private function is_filtered_true(string $hook, bool $default, array $args): bool {
        if (!function_exists('apply_filters')) {
            return $default;
        }

        $value = apply_filters($hook, $default, ...$args);

        return (bool) $value;
    }
}
