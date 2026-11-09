<?php
declare(strict_types=1);
namespace App\Services;

require_once ROOT . '/app/Libraries/Parsedown/Parsedown.php';

/**
 * Markdown → HTML for wiki pages, with Parsedown 1.8.0 in safe mode (raw HTML escaped, unsafe link
 * schemes neutralized), then a DOM pass that makes the output compatible with Sillage's CSP and
 * adds what Parsedown lacks: heading anchors and table of contents, alignment classes instead of
 * style attributes, read-only task-list checkboxes, other images shown as links, external links
 * opened in a new tab, "> [!NOTE]" callouts, images of the wiki ("_assets/<hash>") served by
 * /wiki/assets, "<br>" line breaks inside table cells (cellBreaks). Any remaining style or on*
 * attribute is removed defensively.
 *
 * The result is cached in wiki_pages.html; bump VERSION whenever the output changes so cached
 * pages are rendered again.
 */
final class WikiRenderer
{
    public const VERSION = 3;

    /** @return array{html: string, toc: list<array{level:int, id:string, text:string}>, text: string, title: ?string} */
    public static function render(string $markdown): array
    {
        $parser = new \Parsedown();
        $parser->setSafeMode(true);
        $parser->setBreaksEnabled(false);
        $raw = $parser->text($markdown);
        if ($raw === '') return ['html' => '', 'toc' => [], 'text' => '', 'title' => null];

        $document = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $document->loadHTML('<!DOCTYPE html><html><head><meta charset="utf-8"></head><body><div id="wiki-root">' . $raw . '</div></body></html>', LIBXML_NONET | LIBXML_NOBLANKS);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        $root = $document->getElementById('wiki-root');
        if (!$root) return ['html' => '', 'toc' => [], 'text' => '', 'title' => null];

        $toc = [];$used = [];$title = null;
        foreach (self::elements($root) as $element) {
            $tag = strtolower($element->nodeName);
            foreach (iterator_to_array($element->attributes) as $attribute) {
                $name = strtolower($attribute->name);
                if (str_starts_with($name, 'on')) $element->removeAttribute($attribute->name);
            }
            if ($tag === 'th' || $tag === 'td') {
                if (preg_match('/text-align:\s*(left|center|right)/', $element->getAttribute('style'), $match)) {
                    $element->setAttribute('class', 'align-' . $match[1]);
                }
                self::cellBreaks($document, $element);
            }
            $element->removeAttribute('style');
            if (preg_match('/^h([1-6])$/', $tag, $match)) {
                $text = trim(preg_replace('/\s+/u', ' ', $element->textContent) ?? '');
                $id = self::anchor($text, $used);
                $element->setAttribute('id', $id);
                if ($match[1] === '1' && $title === null) $title = $text;
                if ($match[1] === '2' || $match[1] === '3') $toc[] = ['level' => (int) $match[1], 'id' => $id, 'text' => $text];
            } elseif ($tag === 'a') {
                $href = $element->getAttribute('href');
                // Parsedown's safe mode turns "javascript:" into a harmless "javascript%3A" relative link; drop it.
                if (preg_match('/^\s*[a-z][a-z0-9+.\-]*%3a/i', $href)) $element->removeAttribute('href');
                elseif (preg_match('#^https?://#i', $href)) {
                    $element->setAttribute('target', '_blank');
                    $element->setAttribute('rel', 'noopener noreferrer');
                }
            } elseif ($tag === 'li') {
                self::taskItem($document, $element);
            }
        }
        foreach (iterator_to_array($root->getElementsByTagName('blockquote')) as $quote) self::callout($document, $quote);
        // Wiki images ("_assets/<hash>.<ext>") are served by Sillage; any other image (external
        // address, data URI) would be blocked by the CSP and is shown as a link to its address.
        foreach (iterator_to_array($root->getElementsByTagName('img')) as $image) {
            if (preg_match('#^_assets/([a-f0-9]{64}\.(?:png|jpg|webp|gif))$#D', $image->getAttribute('src'), $asset)) {
                self::image($document, $image, $asset[1]);
                continue;
            }
            $link = $document->createElement('a');
            $link->setAttribute('href', $image->getAttribute('src'));
            $link->setAttribute('class', 'wiki-image-link');
            if (preg_match('#^https?://#i', $image->getAttribute('src'))) { $link->setAttribute('target', '_blank');$link->setAttribute('rel', 'noopener noreferrer'); }
            $link->textContent = 'Image : ' . ($image->getAttribute('alt') !== '' ? $image->getAttribute('alt') : $image->getAttribute('src'));
            $image->parentNode->replaceChild($link, $image);
        }
        foreach (iterator_to_array($root->getElementsByTagName('table')) as $table) {
            $wrapper = $document->createElement('div');
            $wrapper->setAttribute('class', 'wiki-table');
            $table->parentNode->replaceChild($wrapper, $table);
            $wrapper->appendChild($table);
        }

        $html = '';
        foreach ($root->childNodes as $child) $html .= $document->saveHTML($child);
        return ['html' => $html, 'toc' => $toc, 'text' => self::plainText($root), 'title' => $title];
    }

    /** Title of a page: first level-1 heading outside code blocks, else null. */
    public static function title(string $markdown): ?string
    {
        $fence = false;
        foreach (explode("\n", $markdown) as $line) {
            if (preg_match('/^\s{0,3}(```|~~~)/', $line)) { $fence = !$fence; continue; }
            if (!$fence && preg_match('/^\s{0,3}#\s+(.+?)\s*#*\s*$/u', $line, $match)) {
                $title = trim(strip_tags(preg_replace('/[*_`~]+/', '', $match[1]) ?? ''));
                if ($title !== '') return mb_substr($title, 0, 255, 'UTF-8');
            }
        }
        return null;
    }

    /** @return list<\DOMElement> */
    private static function elements(\DOMElement $root): array
    {
        $list = [];
        foreach ($root->getElementsByTagName('*') as $element) $list[] = $element;
        return $list;
    }

    private static function anchor(string $text, array &$used): string
    {
        $slug = trim(preg_replace('/[^a-z0-9]+/', '-', self::fold($text)) ?? '', '-');
        if ($slug === '') $slug = 'section';
        $slug = substr($slug, 0, 60);
        $candidate = $slug;$n = 2;
        while (isset($used[$candidate])) $candidate = $slug . '-' . $n++;
        $used[$candidate] = true;
        return $candidate;
    }

    /** Lower-case ASCII folding of French text, one output character per input character where possible. */
    public static function fold(string $text): string
    {
        static $map = null;
        $map ??= [
            'à' => 'a', 'â' => 'a', 'ä' => 'a', 'á' => 'a', 'ã' => 'a', 'å' => 'a', 'ç' => 'c', 'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
            'î' => 'i', 'ï' => 'i', 'í' => 'i', 'ì' => 'i', 'ô' => 'o', 'ö' => 'o', 'ó' => 'o', 'ò' => 'o', 'õ' => 'o', 'ù' => 'u', 'û' => 'u',
            'ü' => 'u', 'ú' => 'u', 'ÿ' => 'y', 'ñ' => 'n', 'œ' => 'o', 'æ' => 'a', '’' => "'",
        ];
        return strtr(mb_strtolower($text, 'UTF-8'), $map);
    }

    public const CALLOUTS = ['NOTE' => ['note', 'Remarque'], 'INFO' => ['note', 'Information'], 'TIP' => ['tip', 'Astuce'], 'IMPORTANT' => ['important', 'Important'], 'WARNING' => ['warning', 'Attention'], 'CAUTION' => ['caution', 'Danger']];

    /** "> [!NOTE]" … quotes (GitHub syntax) become coloured callouts with a French title. */
    private static function callout(\DOMDocument $document, \DOMElement $quote): void
    {
        $paragraph = null;
        foreach ($quote->childNodes as $child) { if ($child instanceof \DOMElement) { $paragraph = $child;break; } }
        if (!$paragraph || strtolower($paragraph->nodeName) !== 'p' || !$paragraph->firstChild instanceof \DOMText) return;
        $text = $paragraph->firstChild;
        if (!preg_match('/^\s*\[!(NOTE|INFO|TIP|IMPORTANT|WARNING|CAUTION)\][ \t]*\n?/i', $text->data, $match)) return;
        [$kind, $label] = self::CALLOUTS[strtoupper($match[1])];
        $text->data = substr($text->data, strlen($match[0]));
        if (trim($paragraph->textContent) === '' && $paragraph->getElementsByTagName('*')->length === 0) $quote->removeChild($paragraph);
        $quote->setAttribute('class', 'wiki-callout wiki-callout-' . $kind);
        $quote->setAttribute('role', 'note');
        $title = $document->createElement('p');
        $title->setAttribute('class', 'wiki-callout-title');
        $title->textContent = $label;
        $quote->insertBefore($title, $quote->firstChild);
    }

    /** Wiki image: served by /wiki/assets; alone in its paragraph it becomes a figure with its title as caption. */
    private static function image(\DOMDocument $document, \DOMElement $image, string $name): void
    {
        $alt = $image->getAttribute('alt');
        $title = trim($image->getAttribute('title'));
        $clean = $document->createElement('img');
        $clean->setAttribute('class', 'wiki-image');
        $clean->setAttribute('src', '/wiki/assets/' . $name);
        // The editor stores the display ratio in the alternative text (e.g. "1.00"): not a description.
        $clean->setAttribute('alt', preg_match('/^\d+(\.\d+)?$/', $alt) ? '' : $alt);
        $clean->setAttribute('loading', 'lazy');
        $parent = $image->parentNode;
        $alone = $parent instanceof \DOMElement && strtolower($parent->nodeName) === 'p' && trim($parent->textContent) === '' && $parent->getElementsByTagName('*')->length === 1;
        if (!$alone) { $parent->replaceChild($clean, $image);return; }
        $figure = $document->createElement('figure');
        $figure->setAttribute('class', 'wiki-figure');
        $figure->appendChild($clean);
        if ($title !== '') {
            $caption = $document->createElement('figcaption');
            $caption->textContent = $title;
            $figure->appendChild($caption);
        }
        $parent->parentNode->replaceChild($figure, $parent);
    }

    /**
     * Markdown cells cannot hold several lines: "<br>" written in a cell (escaped as text by safe
     * mode) becomes a real line break. Only this exact tag, only in table cells, never inside code.
     */
    private static function cellBreaks(\DOMDocument $document, \DOMElement $cell): void
    {
        $xpath = new \DOMXPath($document);
        foreach (iterator_to_array($xpath->query('.//text()[not(ancestor::code)]', $cell)) as $text) {
            $parts = preg_split('#<br\s*/?>#i', $text->data);
            if (count($parts) < 2) continue;
            $parent = $text->parentNode;
            foreach ($parts as $index => $part) {
                if ($index > 0) $parent->insertBefore($document->createElement('br'), $text);
                if ($part !== '') $parent->insertBefore($document->createTextNode($part), $text);
            }
            $parent->removeChild($text);
        }
    }

    /** "[ ] text" / "[x] text" list items become read-only checkboxes. */
    private static function taskItem(\DOMDocument $document, \DOMElement $item): void
    {
        $first = $item->firstChild;
        if ($first instanceof \DOMElement && strtolower($first->nodeName) === 'p') $first = $first->firstChild;
        if (!$first instanceof \DOMText || !preg_match('/^\[( |x|X)\]\s+/', $first->data, $match)) return;
        $first->data = substr($first->data, strlen($match[0]));
        $box = $document->createElement('input');
        $box->setAttribute('type', 'checkbox');
        $box->setAttribute('disabled', 'disabled');
        $box->setAttribute('aria-label', $match[1] === ' ' ? 'À faire' : 'Fait');
        if ($match[1] !== ' ') $box->setAttribute('checked', 'checked');
        $first->parentNode->insertBefore($box, $first);
        $first->parentNode->insertBefore($document->createTextNode(' '), $first);
        $item->setAttribute('class', 'wiki-task');
    }

    private static function plainText(\DOMElement $root): string
    {
        $parts = [];
        foreach ($root->childNodes as $child) $parts[] = $child->textContent;
        return trim(preg_replace('/\s+/u', ' ', implode(' ', $parts)) ?? '');
    }
}
