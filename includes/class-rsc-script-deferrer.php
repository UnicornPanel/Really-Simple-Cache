<?php

if (!defined('ABSPATH')) {
    exit;
}

class RSC_Script_Deferrer {

    public function defer_html_scripts(string $html, array $context = []): string {
        if ($html === '' || stripos($html, '<script') === false) {
            return $html;
        }

        $is_excluded = isset($context['is_excluded']) && is_callable($context['is_excluded'])
            ? $context['is_excluded']
            : null;

        $updated = preg_replace_callback(
            '/<script\b([^>]*)>/i',
            function ($matches) use ($is_excluded) {
                $attr = (string) $matches[1];
                $tag = '<script' . $attr . '>';

                if ($this->is_non_executable_script($attr)) {
                    return $tag;
                }

                if ($this->has_attr($attr, 'defer') || $this->has_attr($attr, 'async') || $this->has_attr($attr, 'nomodule')) {
                    return $tag;
                }

                if ($this->is_module_script($attr)) {
                    return $tag;
                }

                if ($this->has_attr($attr, 'data-rsc-no-defer') || $this->has_attr($attr, 'data-no-defer')) {
                    return $tag;
                }

                $src = $this->extract_attr($attr, 'src');
                if (!is_string($src) || $src === '') {
                    // Defer applies to external scripts only.
                    return $tag;
                }

                $src = html_entity_decode($src);
                if ($is_excluded && call_user_func($is_excluded, $src, 'js')) {
                    return $tag;
                }

                return '<script defer' . $attr . '>';
            },
            $html
        );

        return is_string($updated) ? $updated : $html;
    }

    private function has_attr(string $attr, string $name): bool {
        return preg_match('/\b' . preg_quote($name, '/') . '\b/i', $attr) === 1;
    }

    private function extract_attr(string $attr, string $name) {
        if (preg_match('/\b' . preg_quote($name, '/') . '\s*=\s*(["\'])(.*?)\1/i', $attr, $m)) {
            return $m[2];
        }

        if (preg_match('/\b' . preg_quote($name, '/') . '\s*=\s*([^\s>]+)/i', $attr, $m)) {
            return trim($m[1], "\"'");
        }

        return null;
    }

    private function is_module_script(string $attr): bool {
        return preg_match('/\btype\s*=\s*(["\']?)module\1/i', $attr) === 1;
    }

    private function is_non_executable_script(string $attr): bool {
        if (!preg_match('/\btype\s*=\s*(["\']?)([^"\'>\s]+)\1/i', $attr, $m)) {
            return false;
        }

        $type = strtolower(trim((string) $m[2]));
        if ($type === '' || $type === 'text/javascript' || $type === 'application/javascript') {
            return false;
        }

        return (strpos($type, 'json') !== false || strpos($type, 'template') !== false || strpos($type, 'importmap') !== false);
    }
}
