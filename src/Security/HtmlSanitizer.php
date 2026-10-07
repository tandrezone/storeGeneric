<?php

declare(strict_types=1);

namespace App\Security;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMText;

/**
 * Reduces a product description down to a small allowlist of formatting
 * tags — used both when the admin WYSIWYG editor (admin/products.php)
 * saves long_description, and again wherever it's rendered. Sanitizing on
 * read too (not just on write) means a legacy plain-text description
 * saved before this existed — which might contain a stray "<" or "&" —
 * gets parsed and re-escaped safely instead of leaking raw markup, and
 * it's a no-op on content that's already clean.
 */
final class HtmlSanitizer
{
    private const ALLOWED_TAGS = ['p', 'br', 'b', 'strong', 'i', 'em', 'u', 'ul', 'ol', 'li', 'a', 'blockquote'];

    public function clean(string $html): string
    {
        if (trim($html) === '') {
            return '';
        }

        $dom = new DOMDocument();
        $wrapped = '<?xml encoding="utf-8"?><root>' . $html . '</root>';

        $previousSetting = libxml_use_internal_errors(true);
        $dom->loadHTML($wrapped, LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_use_internal_errors($previousSetting);

        $root = $dom->getElementsByTagName('root')->item(0);
        if ($root === null) {
            return htmlspecialchars(strip_tags($html));
        }

        $this->cleanChildren($root);

        $result = '';
        foreach (iterator_to_array($root->childNodes) as $child) {
            $result .= $dom->saveHTML($child);
        }

        return trim($result);
    }

    /**
     * Recursively strips disallowed elements — unwrapping their already-
     * cleaned children rather than discarding them — except script/style,
     * which are removed outright, content included. Runs bottom-up so a
     * disallowed element's children are already sanitized by the time we
     * decide whether to keep or unwrap the element itself.
     */
    private function cleanChildren(DOMNode $node): void
    {
        foreach (iterator_to_array($node->childNodes) as $child) {
            if ($child instanceof DOMText) {
                continue;
            }

            if (!$child instanceof DOMElement) {
                $node->removeChild($child);
                continue;
            }

            $tag = strtolower($child->tagName);

            if (in_array($tag, ['script', 'style'], true)) {
                $node->removeChild($child);
                continue;
            }

            // contenteditable regions (Chrome in particular) wrap each
            // paragraph in a <div> on Enter rather than <p> — treat it as
            // one instead of unwrapping it, or every paragraph break would
            // silently collapse into one run-on block of text.
            if ($tag === 'div') {
                $replacement = $child->ownerDocument->createElement('p');
                while ($child->firstChild) {
                    $replacement->appendChild($child->firstChild);
                }
                $node->replaceChild($replacement, $child);
                $child = $replacement;
                $tag = 'p';
            }

            $this->cleanChildren($child);

            if (!in_array($tag, self::ALLOWED_TAGS, true)) {
                while ($child->firstChild) {
                    $node->insertBefore($child->firstChild, $child);
                }
                $node->removeChild($child);
                continue;
            }

            $this->cleanAttributes($child, $tag);
        }
    }

    private function cleanAttributes(DOMElement $element, string $tag): void
    {
        $href = $tag === 'a' ? $element->getAttribute('href') : '';

        foreach (iterator_to_array($element->attributes ?? []) as $attr) {
            $element->removeAttribute($attr->name);
        }

        if ($tag === 'a' && $this->isSafeUrl($href)) {
            $element->setAttribute('href', $href);
            $element->setAttribute('rel', 'noopener noreferrer');
            $element->setAttribute('target', '_blank');
        }
    }

    private function isSafeUrl(string $url): bool
    {
        $url = trim($url);
        return $url !== '' && preg_match('#^(https?://|mailto:)#i', $url) === 1;
    }
}
