<?php

if (!defined('ABSPATH')) {
    exit;
}

class RSC_Script_Delayer {

    public function delay_html_scripts(string $html, array $context = []): string {
        if ($html === '' || stripos($html, '<script') === false) {
            return $html;
        }

        $is_excluded = isset($context['is_excluded']) && is_callable($context['is_excluded'])
            ? $context['is_excluded']
            : null;

        $delay_ms = isset($context['delay_ms']) ? max(100, (int) $context['delay_ms']) : 3000;
        $delayed_count = 0;

        $updated = preg_replace_callback(
            '/<script\b([^>]*)>\s*<\/script>/i',
            function ($matches) use ($is_excluded, &$delayed_count) {
                $attr = (string) $matches[1];
                $tag = '<script' . $attr . '></script>';

                if ($this->is_non_executable_script($attr)) {
                    return $tag;
                }

                if ($this->is_module_script($attr) || $this->has_attr($attr, 'nomodule')) {
                    return $tag;
                }

                if ($this->has_attr($attr, 'data-rsc-no-delay') || $this->has_attr($attr, 'data-no-delay')) {
                    return $tag;
                }

                if ($this->has_attr($attr, 'data-rsc-delayed') || $this->has_attr($attr, 'data-rsc-delay-src')) {
                    return $tag;
                }

                $src = $this->extract_attr($attr, 'src');
                if (!is_string($src) || $src === '') {
                    return $tag;
                }

                $src = html_entity_decode($src);
                if ($is_excluded && call_user_func($is_excluded, $src, 'js')) {
                    return $tag;
                }

                $without_src = preg_replace('/\s+src\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)/i', '', $attr, 1);
                if (!is_string($without_src)) {
                    $without_src = $attr;
                }

                $without_type = preg_replace('/\s+type\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)/i', '', $without_src, 1);
                if (!is_string($without_type)) {
                    $without_type = $without_src;
                }

                $delayed_count++;

                return '<script type="text/plain" data-rsc-delayed="1" data-rsc-delay-src="'
                    . esc_attr($src)
                    . '"'
                    . $without_type
                    . '></script>';
            },
            $html
        );

        if (!is_string($updated) || $delayed_count < 1) {
            return is_string($updated) ? $updated : $html;
        }

        $loader = $this->build_loader_script($delay_ms);
        if (stripos($updated, '</body>') !== false) {
            return preg_replace('/<\/body>/i', $loader . '</body>', $updated, 1);
        }

        return $updated . $loader;
    }

    private function build_loader_script(int $delay_ms): string {
        $delay_ms = max(100, $delay_ms);

        $script = '(function(){'
            . 'var loaded=false;'
            . 'function patchDomReady(){'
            . 'if(document.__rscDomReadyPatched){return;}'
            . 'document.__rscDomReadyPatched=true;'
            . 'var origAdd=document.addEventListener.bind(document);'
            . 'document.addEventListener=function(type,listener,options){'
            . 'if(type==="DOMContentLoaded"&&document.readyState!=="loading"){'
            . 'if(typeof listener==="function"){setTimeout(function(){listener.call(document);},0);}'
            . 'return;'
            . '}'
            . 'return origAdd(type,listener,options);'
            . '};'
            . '}'
            . 'function loadDelayed(){'
            . 'if(loaded){return;}loaded=true;'
            . 'patchDomReady();'
            . 'var nodes=document.querySelectorAll("script[data-rsc-delayed=\"1\"][data-rsc-delay-src]");'
            . 'for(var i=0;i<nodes.length;i++){' 
            . 'var old=nodes[i];'
            . 'var src=old.getAttribute("data-rsc-delay-src");'
            . 'if(!src){continue;}'
            . 'var s=document.createElement("script");'
            . 'for(var j=0;j<old.attributes.length;j++){' 
            . 'var a=old.attributes[j];'
            . 'if(!a){continue;}'
            . 'if(a.name==="type"||a.name==="data-rsc-delayed"||a.name==="data-rsc-delay-src"){continue;}'
            . 's.setAttribute(a.name,a.value);'
            . '}'
            . 's.src=src;'
            . 'if(!s.hasAttribute("async")&&!s.hasAttribute("defer")){s.defer=true;}'
            . 'old.parentNode.replaceChild(s,old);'
            . '}'
            . '}'
            . 'var ev=["scroll","mousemove","touchstart","click","keydown"];'
            . 'for(var k=0;k<ev.length;k++){' 
            . 'window.addEventListener(ev[k],loadDelayed,{once:true,passive:true});'
            . '}'
            . 'window.addEventListener("load",function(){setTimeout(loadDelayed,' . $delay_ms . ');},{once:true});'
            . 'setTimeout(loadDelayed,' . $delay_ms . ');'
            . '})();';

        return '<script data-rsc-delay-loader="1">' . $script . '</script>';
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
