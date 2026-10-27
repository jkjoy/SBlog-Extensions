<?php

declare(strict_types=1);

if (!defined('APP_VERSION')) { http_response_code(403); exit; }

/** Apply VOID authoring shortcuts to public article DOM without interpreting HTML. */
function sbvoid_enhance_body(DOMElement $root): void
{
    $document = $root->ownerDocument;
    if (!$document) { return; }
    $xpath = new DOMXPath($document);
    $hasClass = static fn(DOMElement $node, string $class): bool => in_array($class, preg_split('/\s+/', trim($node->getAttribute('class'))) ?: [], true);
    $protected = static function (DOMNode $node) use ($root, $hasClass): bool {
        for ($parent = $node; $parent; $parent = $parent->parentNode) {
            if ($parent instanceof DOMElement) {
                if (in_array(strtolower($parent->tagName), ['code', 'pre', 'script', 'style', 'textarea', 'ruby'], true)
                    || $parent->getAttribute('id') === 'comments'
                    || $hasClass($parent, 'content-gate') || $hasClass($parent, 'comments')) { return true; }
            }
            if ($parent === $root) { break; }
        }
        return false;
    };
    $marker = static function (DOMNode $node, string $text): bool {
        if (!$node instanceof DOMElement || strtolower($node->tagName) !== 'p' || trim($node->textContent) !== $text) { return false; }
        foreach ($node->childNodes as $child) { if (!$child instanceof DOMText) { return false; } }
        return true;
    };
    $containsProtected = static function (DOMNode $node) use ($hasClass, $xpath): bool {
        // Existing code may sit inside a notice, but gates/comments keep their exact parents.
        $nodes = [$node];
        foreach ($xpath->query('.//*', $node) as $descendant) { $nodes[] = $descendant; }
        foreach ($nodes as $descendant) {
            if ($descendant instanceof DOMElement && ($descendant->getAttribute('id') === 'comments'
                || $hasClass($descendant, 'content-gate') || $hasClass($descendant, 'comments'))) { return true; }
        }
        return false;
    };
    $photoParagraph = static function (DOMNode $node) use ($xpath): bool {
        if ($node instanceof DOMText) { return trim($node->textContent) === ''; }
        if (!$node instanceof DOMElement || strtolower($node->tagName) !== 'p' || $node->getElementsByTagName('img')->length === 0 || trim($node->textContent) !== '') { return false; }
        foreach ($xpath->query('.//*', $node) as $descendant) {
            if (!in_array(strtolower($descendant->nodeName), ['img', 'a', 'br', 'picture', 'source'], true)) { return false; }
        }
        return true;
    };
    $bodies = [];
    if ($hasClass($root, 'articleBody')) { $bodies[] = $root; }
    foreach ($xpath->query('.//*[contains(concat(" ", normalize-space(@class), " "), " articleBody ")]', $root) as $body) { $bodies[] = $body; }

    foreach ($bodies as $body) {
        if ($protected($body)) { continue; }
        // Pair only standalone sibling paragraphs. Unmatched/mixed markers stay literal.
        for ($node = $body->firstChild; $node;) {
            $next = $node->nextSibling;
            $kind = $marker($node, '[notice]') ? 'notice' : ($marker($node, '[photos]') ? 'photos' : '');
            if ($kind === '') { $node = $next; continue; }
            $closing = null;
            $between = [];
            $valid = true;
            for ($sibling = $next; $sibling; $sibling = $sibling->nextSibling) {
                if ($marker($sibling, '[/' . $kind . ']')) { $closing = $sibling; break; }
                if ($marker($sibling, '[' . $kind . ']')) { $valid = false; break; }
                if ($containsProtected($sibling) || ($kind === 'photos' && !$photoParagraph($sibling))) { $valid = false; break; }
                $between[] = $sibling;
            }
            if (!$closing || !$valid || !$between) { $node = $next; continue; }
            $group = $document->createElement($kind === 'photos' ? 'div' : 'aside');
            $group->setAttribute('class', $kind === 'photos' ? 'photos' : 'void-shortcode-notice notice');
            $body->insertBefore($group, $node);
            foreach ($between as $part) {
                if ($kind === 'photos' && $part instanceof DOMElement) {
                    $figure = $document->createElement('figure');
                    while ($part->firstChild) { $figure->appendChild($part->firstChild); }
                    $group->appendChild($figure);
                    $body->removeChild($part);
                } else { $group->appendChild($part); }
            }
            $next = $closing->nextSibling;
            $body->removeChild($node);
            $body->removeChild($closing);
            $node = $next;
        }

        // An inline notice can contain existing emphasis/links; remove only text markers.
        foreach ($xpath->query('.//p', $body) as $paragraph) {
            if ($protected($paragraph)) { continue; }
            $first = $paragraph->firstChild;
            $last = $paragraph->lastChild;
            if (!$first instanceof DOMText || !$last instanceof DOMText
                || !preg_match('/^\s*\[notice\]/u', $first->data)
                || !preg_match('/\[\/notice\]\s*$/u', $last->data)) { continue; }
            if ($first === $last) {
                if (!preg_match('/^(\s*)\[notice\]([\s\S]*?)\[\/notice\](\s*)$/u', $first->data, $match)) { continue; }
                $first->data = $match[1] . $match[2] . $match[3];
            } else {
                $first->data = preg_replace('/^(\s*)\[notice\]/u', '$1', $first->data) ?? $first->data;
                $last->data = preg_replace('/\[\/notice\](\s*)$/u', '$1', $last->data) ?? $last->data;
            }
            $paragraph->setAttribute('class', trim($paragraph->getAttribute('class') . ' notice'));
        }

        // Snapshot text nodes before mutation. createTextNode keeps all supplied text literal.
        $texts = [];
        foreach ($xpath->query('.//text()', $body) as $text) { if (!$protected($text)) { $texts[] = $text; } }
        foreach ($texts as $text) {
            if (!$text->parentNode || !preg_match_all('/\{\{([^{}:\r\n]+):([^{}\r\n]+)\}\}/u', $text->data, $matches, PREG_OFFSET_CAPTURE)) { continue; }
            $replacement = $document->createDocumentFragment();
            $offset = 0;
            foreach ($matches[0] as $index => [$token, $position]) {
                $replacement->appendChild($document->createTextNode(substr($text->data, $offset, $position - $offset)));
                $ruby = $document->createElement('ruby');
                $ruby->appendChild($document->createTextNode($matches[1][$index][0]));
                $opening = $document->createElement('rp');
                $opening->appendChild($document->createTextNode('('));
                $ruby->appendChild($opening);
                $pronunciation = $document->createElement('rt');
                $pronunciation->appendChild($document->createTextNode($matches[2][$index][0]));
                $ruby->appendChild($pronunciation);
                $closing = $document->createElement('rp');
                $closing->appendChild($document->createTextNode(')'));
                $ruby->appendChild($closing);
                $replacement->appendChild($ruby);
                $offset = $position + strlen($token);
            }
            $replacement->appendChild($document->createTextNode(substr($text->data, $offset)));
            $text->parentNode->replaceChild($replacement, $text);
        }
    }
}
