<?php

function legal_sanitize_html(string $html): string
{
    if (!class_exists('DOMDocument')) {
        throw new RuntimeException('HTML sanitizer requires the DOM extension.');
    }
    $allowedTags = ['p', 'br', 'strong', 'b', 'em', 'i', 'u', 'h2', 'h3', 'h4', 'ul', 'ol', 'li', 'blockquote', 'a'];
    $allowedAttributes = ['a' => ['href', 'title', 'target', 'rel']];
    $document = new DOMDocument('1.0', 'UTF-8');
    $previous = libxml_use_internal_errors(true);
    $loaded = $document->loadHTML(
        '<?xml encoding="UTF-8"><div id="legal-root">' . $html . '</div>',
        LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NONET
    );
    libxml_clear_errors();
    libxml_use_internal_errors($previous);
    if (!$loaded) throw new RuntimeException('Geçersiz HTML içeriği.');

    $root = $document->getElementById('legal-root');
    if (!$root) throw new RuntimeException('HTML içeriği ayrıştırılamadı.');
    $walk = function (DOMNode $node) use (&$walk, $allowedTags, $allowedAttributes): void {
        foreach (iterator_to_array($node->childNodes) as $child) {
            if ($child instanceof DOMComment) {
                $node->removeChild($child);
                continue;
            }
            if (!$child instanceof DOMElement) continue;
            $tag = strtolower($child->tagName);
            if (!in_array($tag, $allowedTags, true)) {
                while ($child->firstChild) $node->insertBefore($child->firstChild, $child);
                $node->removeChild($child);
                continue;
            }
            foreach (iterator_to_array($child->attributes) as $attribute) {
                $name = strtolower($attribute->name);
                if (!in_array($name, $allowedAttributes[$tag] ?? [], true)) $child->removeAttribute($attribute->name);
            }
            if ($tag === 'a') {
                $href = trim($child->getAttribute('href'));
                if ($href !== '' && !preg_match('#^(https://|mailto:)#i', $href)) $child->removeAttribute('href');
                if ($child->getAttribute('target') === '_blank') $child->setAttribute('rel', 'noopener noreferrer');
                else $child->removeAttribute('target');
            }
            $walk($child);
        }
    };
    $walk($root);

    $result = '';
    foreach ($root->childNodes as $child) $result .= $document->saveHTML($child);
    return $result;
}

