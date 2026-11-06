<?php
declare(strict_types=1);
namespace App\Services;

require_once ROOT . '/app/Libraries/Parsedown/Parsedown.php';

/**
 * Markdown → HTML for wiki pages, with Parsedown 1.8.0 in safe mode (raw HTML escaped, unsafe link
 * schemes neutralized), then a DOM pass that makes the output compatible with Sillage's CSP and
 * adds what Parsedown lacks: heading anchors and table of contents, alignment classes instead of
 * style attributes, read-only task-list checkboxes, images shown as links (lot 1), external links
 * opened in a new tab. Any remaining style or on* attribute is removed defensively.
 *
 * The result is cached in wiki_pages.html; bump VERSION whenever the output changes so cached
 * pages are rendered again.
 */
final class WikiRenderer
{
    public const VERSION = 1;

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
                if (preg_match('#^https?://#i', $href)) {
                    $element->setAttribute('target', '_blank');
                    $element->setAttribute('rel', 'noopener noreferrer');
                }
            } elseif ($tag === 'li') {
                self::taskItem($document, $element);
            }
        }
        // Images are not stored in lot 1: replace them by a link to their address.
        foreach (iterator_to_array($root->getElementsByTagName('img')) as $image) {
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
