<?php

namespace Avife\common;

if (!defined('ABSPATH')) exit;

use  Avife\interface\Lazyload;
use Masterminds\HTML5;
use Avife\traits\DomHelperTrait;

class Lazyloadhtml implements Lazyload
{
    use DomHelperTrait;
    public function handle($content)
    {
        $html = (string) $content;

        // Protect non-JavaScript <script> elements (e.g. type="text/template")
        // from HTML parsers that mishandle their raw-text content.
        $protected = [];
        $jsTypes = ['', 'text/javascript', 'application/javascript',
                    'text/ecmascript', 'application/ecmascript',
                    'module', 'text/jscript'];
        $html = preg_replace_callback(
            '/<script\b([^>]*)>([\s\S]*?)<\/script>/i',
            function ($matches) use (&$protected, $jsTypes) {
                $attrs = $matches[1];
                if (preg_match('/\btype\s*=\s*[\"\']([^\"\']*)[\"\']/', $attrs, $typeMatch)) {
                    $type = strtolower(trim($typeMatch[1]));
                    if (!in_array($type, $jsTypes, true)) {
                        $placeholder = '<!--AVIF_SCRIPT_PLACEHOLDER_' . count($protected) . '-->';
                        $protected[] = $matches[0];
                        return $placeholder;
                    }
                }
                return $matches[0];
            },
            $html
        );
        if ($html === null) {
            $html = (string) $content;
            $protected = [];
        }

        $parser = new HTML5(['encode_entities' => false]);
        $dom = $parser->loadHTML($html);

        // Add loading="lazy" where missing
        foreach (['img', 'iframe'] as $tagName) {
            foreach ($dom->getElementsByTagName($tagName) as $tag) {
                if ($this->isInsideNoscript($tag)) {
                    continue; // skip noscript content
                }
                if (!$tag->hasAttribute('loading')) {
                    $tag->setAttribute('loading', 'lazy');
                }
            }
        }

        // Save HTML using the same parser
        $updatedHtml = $parser->saveHTML($dom);

        // Restore protected non-JavaScript script elements.
        foreach ($protected as $index => $original) {
            $updatedHtml = str_replace(
                '<!--AVIF_SCRIPT_PLACEHOLDER_' . $index . '-->',
                $original,
                $updatedHtml
            );
        }

        return $updatedHtml;
    }

}
