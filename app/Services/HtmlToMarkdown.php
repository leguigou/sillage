<?php
declare(strict_types=1);
namespace App\Services;

/**
 * HTML → wiki Markdown, tuned for Confluence exports but usable for ordinary HTML.
 *
 * - Headings, paragraphs, emphasis, inline code, links (http, https, mailto only), lists and task
 *   lists, quotes, rules, code blocks with their language.
 * - Simple tables become Markdown tables. Tables whose cells hold lists, several paragraphs or other
 *   tables (Markdown cannot nest them) become nested lists, so no text is lost or duplicated.
 * - Confluence: emoticons become Unicode emojis, "Info/Note/Warning/Tip" macros and panels become
 *   "> [!…]" callouts, the table of contents macro is dropped, embedded images are handed to the
 *   resolver, which stores them and returns their wiki name ("_assets/<name>").
 * Scripts, styles, forms and unknown embeds are dropped; nesting deeper than MAX_DEPTH is flattened
 * to text. The output is only ever rendered by WikiRenderer (safe mode).
 */
final class HtmlToMarkdown
{
    public const MAX_DEPTH = 120;
    private const BLOCKS = ['address', 'article', 'aside', 'blockquote', 'center', 'dd', 'details', 'div', 'dl', 'dt', 'fieldset', 'figcaption', 'figure', 'footer', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'header', 'hr', 'li', 'main', 'nav', 'ol', 'p', 'pre', 'section', 'summary', 'table', 'tbody', 'td', 'tfoot', 'th', 'thead', 'tr', 'ul'];
    private const DROP = ['script', 'style', 'head', 'title', 'meta', 'link', 'noscript', 'template', 'iframe', 'object', 'embed', 'form', 'input', 'button', 'select', 'textarea', 'svg', 'canvas', 'video', 'audio', 'map', 'xml'];
    private const CALLOUTS = ['information' => 'NOTE', 'info' => 'NOTE', 'note' => 'WARNING', 'warning' => 'CAUTION', 'tip' => 'TIP', 'success' => 'TIP', 'error' => 'CAUTION'];
    private const EMOTICONS = [
        'smile' => '🙂', 'sad' => '🙁', 'cheeky' => '😛', 'laugh' => '😁', 'wink' => '😉', 'thumbs-up' => '👍', 'thumbs-down' => '👎',
        'information' => 'ℹ️', 'tick' => '✅', 'cross' => '❌', 'warning' => '⚠️', 'plus' => '➕', 'minus' => '➖', 'question' => '❓',
        'light-on' => '💡', 'light-off' => '💡', 'yellow-star' => '⭐', 'red-star' => '⭐', 'green-star' => '⭐', 'blue-star' => '⭐',
        'heart' => '❤️', 'broken-heart' => '💔', 'flag' => '🚩', 'flag-off' => '🏳️', 'cog' => '⚙️', 'label' => '🏷️',
    ];

    /** @var callable(string $src, string $label): ?string */
    private $resolveImage;
    private array $warnings = [];
    private bool $inTable = false;

    public function __construct(callable $resolveImage)
    {
        $this->resolveImage = $resolveImage;
    }

    /** @return list<string> */
    public function warnings(): array
    {
        return array_values(array_unique($this->warnings));
    }

    /** @return array{markdown: string, title: ?string} */
    public function convert(string $html): array
    {
        // The input is already UTF-8: a declared charset would make libxml decode it a second time.
        $html = preg_replace('/<meta\b[^>]*charset[^>]*>/i', '', $html) ?? $html;
        $document = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $document->loadHTML('<?xml encoding="UTF-8"?>' . $html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_COMPACT);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        $title = null;
        foreach ($document->getElementsByTagName('title') as $node) { $title = trim(preg_replace('/\s+/u', ' ', $node->textContent) ?? '') ?: null;break; }
        $body = $document->getElementsByTagName('body')->item(0) ?? $document->documentElement;
        $markdown = $body ? $this->joinBlocks($this->blocks($body, 0)) : '';
        $markdown = preg_replace("/\n{3,}/", "\n\n", trim($markdown)) ?? '';
        return ['markdown' => $markdown === '' ? '' : $markdown . "\n", 'title' => $title !== null ? mb_substr($title, 0, 200, 'UTF-8') : null];
    }

    // ---- Blocks -------------------------------------------------------------------------------

    /** @return list<string> Markdown blocks of the children of $node */
    private function blocks(\DOMNode $node, int $depth, bool $notes = true): array
    {
        $blocks = [];$inline = '';
        $flush = function () use (&$blocks, &$inline): void {
            $text = $this->cleanParagraph($inline);
            if ($text !== '') $blocks[] = $text;
            $inline = '';
        };
        foreach ($node->childNodes as $child) {
            if ($child instanceof \DOMText) { $inline .= $this->text($child->data);continue; }
            if (!$child instanceof \DOMElement) continue;
            $tag = strtolower($child->nodeName);
            if (in_array($tag, self::DROP, true) || $this->hasClass($child, 'toc-macro') || $this->hasClass($child, 'hidden')) continue;
            if ($depth >= self::MAX_DEPTH) { $inline .= ' ' . $this->text($child->textContent);continue; }
            if ($tag === 'br') { $flush();continue; }
            if ($tag === 'img') { $inline .= $this->image($child);continue; }
            if (!in_array($tag, self::BLOCKS, true)) { $inline .= $this->inline($child, $depth + 1);continue; }
            $flush();
            foreach ($this->block($child, $tag, $depth + 1) as $block) if (trim($block) !== '') $blocks[] = $block;
        }
        $flush();
        return $notes ? $this->labelledNotes($blocks) : $blocks;
    }

    private const NOTE_LABELS = '/^(note|notes|remarque|remarques|info|information|n\.?b\.?|attention|avertissement|warning|important|astuce|conseil|tip)\s*:?\s*$/iu';
    private const NOTE_TYPES = ['attention' => 'WARNING', 'avertissement' => 'WARNING', 'warning' => 'WARNING', 'important' => 'IMPORTANT', 'astuce' => 'TIP', 'conseil' => 'TIP', 'tip' => 'TIP'];

    /**
     * A paragraph made only of "Note :" (or Remarque, Attention, Astuce…) followed by a paragraph is
     * how Confluence Cloud exports its note panels: both become one callout.
     */
    private function labelledNotes(array $blocks): array
    {
        $out = [];
        for ($i = 0, $n = count($blocks); $i < $n; $i++) {
            $next = $blocks[$i + 1] ?? null;
            if ($next !== null && preg_match(self::NOTE_LABELS, $blocks[$i], $m) && !preg_match('/^(#|>|\||- |\d+\. |```|!\[)/', $next)) {
                $type = self::NOTE_TYPES[mb_strtolower($m[1], 'UTF-8')] ?? 'NOTE';
                $out[] = "> [!$type]\n" . $this->quote($next);
                $i++;
                continue;
            }
            $out[] = $blocks[$i];
        }
        return $out;
    }

    /** @return list<string> */
    private function block(\DOMElement $element, string $tag, int $depth): array
    {
        if (preg_match('/^h([1-6])$/', $tag, $match)) {
            $text = $this->cleanInline($this->inlineChildren($element, $depth));
            return $text === '' ? [] : [str_repeat('#', (int) $match[1]) . ' ' . $text];
        }
        if ($tag === 'ul' || $tag === 'ol') return [$this->listBlock($element, $tag === 'ol', $depth)];
        if ($tag === 'pre') return [$this->code($element)];
        if ($tag === 'hr') return ['---'];
        if ($tag === 'table') return [$this->table($element, $depth)];
        if ($tag === 'blockquote') return [$this->quote($this->joinBlocks($this->blocks($element, $depth)))];
        if ($tag === 'div' || $tag === 'section' || $tag === 'aside') {
            $callout = $this->callout($element, $depth);
            if ($callout !== null) return [$callout];
            if ($this->hasClass($element, 'code') && ($pre = $element->getElementsByTagName('pre')->item(0))) return [$this->code($pre)];
        }
        return $this->blocks($element, $depth);
    }

    private function joinBlocks(array $blocks): string
    {
        return implode("\n\n", array_filter($blocks, fn($b) => trim($b) !== ''));
    }

    /** Confluence information macros and panels → "> [!TYPE]" callouts. */
    private function callout(\DOMElement $element, int $depth): ?string
    {
        $type = $this->calloutType($element);
        if ($type === null) return null;
        $title = '';
        foreach ($element->getElementsByTagName('*') as $candidate) {
            if ($this->hasClass($candidate, 'title') || $this->hasClass($candidate, 'panelHeader')) { $title = $this->cleanInline($this->inlineChildren($candidate, $depth));$candidate->parentNode->removeChild($candidate);break; }
        }
        $bodyNode = $element;
        foreach ($element->getElementsByTagName('*') as $candidate) {
            if ($this->hasClass($candidate, 'confluence-information-macro-body') || $this->hasClass($candidate, 'panelContent')) { $bodyNode = $candidate;break; }
        }
        $blocks = $this->blocks($bodyNode, $depth, false);
        // "Note :" written as the first line of a note macro repeats the callout title.
        if (count($blocks) > 1 && preg_match(self::NOTE_LABELS, $blocks[0])) array_shift($blocks);
        $body = $this->joinBlocks($blocks);
        if ($title !== '') $body = '**' . $title . '**' . ($body !== '' ? "\n\n" . $body : '');
        if (trim($body) === '') return null;
        return "> [!$type]\n" . $this->quote($body);
    }

    /** Callout type of a Confluence macro or panel element, or null. */
    private function calloutType(\DOMElement $element): ?string
    {
        $classes = preg_split('/\s+/', $element->getAttribute('class')) ?: [];
        foreach ($classes as $class) {
            if (preg_match('/^confluence-information-macro-(information|note|warning|tip)$/', $class, $m)) return self::CALLOUTS[$m[1]];
        }
        if (in_array('aui-message', $classes, true) || in_array('alert', $classes, true)) {
            foreach (['info', 'warning', 'error', 'success'] as $kind) {
                if (array_intersect([$kind, 'aui-message-' . $kind, 'alert-' . $kind], $classes)) return self::CALLOUTS[$kind];
            }
            return 'NOTE';
        }
        if (in_array('panel', $classes, true) && !in_array('code', $classes, true)) return 'NOTE';
        return null;
    }

    private function quote(string $markdown): string
    {
        return implode("\n", array_map(fn($line) => rtrim('> ' . $line), explode("\n", $markdown)));
    }

    private function code(\DOMElement $pre): string
    {
        $text = rtrim(str_replace(["\r\n", "\r"], "\n", $pre->textContent), "\n");
        $language = '';
        $params = $pre->getAttribute('data-syntaxhighlighter-params') . ' ' . $pre->getAttribute('class');
        if (preg_match('/brush:\s*([a-z0-9+#-]+)/i', $params, $m) || preg_match('/language-([a-z0-9+#-]+)/i', $params, $m)) $language = strtolower($m[1]);
        preg_match_all('/`{3,}/', $text, $runs);
        $fence = str_repeat('`', max(3, ...array_map('strlen', $runs[0] ?: ['']) ) + (empty($runs[0]) ? 0 : 1));
        return $fence . $language . "\n" . $text . "\n" . $fence;
    }

    private function listBlock(\DOMElement $list, bool $ordered, int $depth): string
    {
        $lines = [];$number = max(1, (int) ($list->getAttribute('start') ?: 1));
        $task = $this->hasClass($list, 'inline-task-list');
        foreach ($list->childNodes as $item) {
            if (!$item instanceof \DOMElement || strtolower($item->nodeName) !== 'li') continue;
            $marker = $ordered ? ($number++) . '. ' : '- ';
            if ($task || $item->hasAttribute('data-inline-task-id')) $marker .= $this->hasClass($item, 'checked') ? '[x] ' : '[ ] ';
            $content = $this->itemContent($item, $depth);
            $lines[] = $this->indentItem($marker, $content === '' ? ' ' : $content);
        }
        return implode("\n", $lines);
    }

    /** Content of a list item: blocks joined tightly (nested lists follow their line). */
    private function itemContent(\DOMElement $item, int $depth): string
    {
        $blocks = $this->blocks($item, $depth);
        $out = '';
        foreach ($blocks as $index => $block) {
            if ($index === 0) { $out = $block;continue; }
            $isList = (bool) preg_match('/^(\d+\.|-) /', $block);
            $out .= ($isList ? "\n" : "\n\n") . $block;
        }
        return $out;
    }

    private function indentItem(string $marker, string $content): string
    {
        $pad = str_repeat(' ', strlen($marker) > 4 && str_contains($marker, '[') ? (strpos($marker, '[') ?: 2) : strlen($marker));
        $lines = explode("\n", $content);
        $first = array_shift($lines);
        return rtrim($marker . $first) . ($lines ? "\n" . implode("\n", array_map(fn($l) => $l === '' ? '' : $pad . $l, $lines)) : '');
    }

    // ---- Tables -------------------------------------------------------------------------------

    private function table(\DOMElement $table, int $depth): string
    {
        $rows = [];
        foreach ($this->rowsOf($table) as $tr) {
            $cells = [];
            foreach ($tr->childNodes as $cell) {
                if ($cell instanceof \DOMElement && in_array(strtolower($cell->nodeName), ['td', 'th'], true)) $cells[] = $cell;
            }
            if ($cells) $rows[] = $cells;
        }
        if (!$rows) return '';
        $complex = false;
        foreach ($rows as $cells) foreach ($cells as $cell) {
            if ((int) $cell->getAttribute('rowspan') > 1 || $this->isComplexCell($cell)) $complex = true;
        }
        $header = array_reduce($rows[0], fn($all, $cell) => $all && strtolower($cell->nodeName) === 'th', true) || ($rows[0][0]->parentNode && strtolower($rows[0][0]->parentNode->parentNode->nodeName ?? '') === 'thead');
        return $complex ? $this->tableAsList($rows, $header, $depth) : $this->tableAsGrid($rows, $header, $depth);
    }

    /** @return list<\DOMElement> rows of this table only (not of nested tables) */
    private function rowsOf(\DOMElement $table): array
    {
        $rows = [];
        foreach ($table->childNodes as $child) {
            if (!$child instanceof \DOMElement) continue;
            $tag = strtolower($child->nodeName);
            if ($tag === 'tr') $rows[] = $child;
            elseif (in_array($tag, ['thead', 'tbody', 'tfoot'], true)) foreach ($child->childNodes as $tr) if ($tr instanceof \DOMElement && strtolower($tr->nodeName) === 'tr') $rows[] = $tr;
        }
        return $rows;
    }

    private function isComplexCell(\DOMElement $cell): bool
    {
        foreach (['table', 'ul', 'ol', 'pre', 'blockquote', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6'] as $tag) if ($cell->getElementsByTagName($tag)->length) return true;
        $paragraphs = 0;
        foreach ($cell->getElementsByTagName('p') as $p) if (trim($p->textContent) !== '') $paragraphs++;
        foreach ($cell->getElementsByTagName('div') as $div) if ($this->calloutType($div) !== null) return true;
        return $paragraphs > 1;
    }

    private function tableAsGrid(array $rows, bool $header, int $depth): string
    {
        $was = $this->inTable;$this->inTable = true;
        $matrix = [];$width = 0;
        foreach ($rows as $cells) {
            $line = [];
            foreach ($cells as $cell) {
                $text = $this->cleanInline($this->inlineChildren($cell, $depth));
                $line[] = str_replace('|', '\|', $text === '' ? ' ' : $text);
                for ($span = (int) $cell->getAttribute('colspan'); $span > 1; $span--) $line[] = ' ';
            }
            $width = max($width, count($line));
            $matrix[] = $line;
        }
        $this->inTable = $was;
        foreach ($matrix as &$line) $line = array_pad($line, $width, ' ');
        unset($line);
        if (!$header) array_unshift($matrix, array_fill(0, $width, ' '));
        $out = ['| ' . implode(' | ', $matrix[0]) . ' |', '|' . str_repeat(' --- |', $width)];
        foreach (array_slice($matrix, 1) as $line) $out[] = '| ' . implode(' | ', $line) . ' |';
        return implode("\n", $out);
    }

    /** Rows become list items: first cell in bold, the other cells (labelled by their header) below. */
    private function tableAsList(array $rows, bool $header, int $depth): string
    {
        $labels = [];
        if ($header) { foreach (array_shift($rows) as $cell) $labels[] = trim(str_replace('**', '', $this->cleanInline($this->inlineChildren($cell, $depth))), " *"); }
        $items = [];
        foreach ($rows as $cells) {
            $first = $this->joinBlocks($this->blocks($cells[0], $depth));
            $lines = [];
            foreach (array_slice($cells, 1) as $index => $cell) {
                $content = $this->joinBlocks($this->blocks($cell, $depth));
                if (trim($content) === '') continue;
                $label = $labels[$index + 1] ?? '';
                $lines[] = $this->indentItem('- ', ($label !== '' ? '*' . $label . '* : ' : '') . $content);
            }
            if (trim($first) === '' && !$lines) continue;
            $head = trim($first) === '' ? '' : (str_contains($first, "\n") || preg_match('/^\*\*.*\*\*$/s', $first) ? $first : '**' . $first . '**');
            $items[] = $this->indentItem('- ', $head . ($lines ? "\n" . implode("\n", $lines) : ''));
        }
        return implode("\n", $items);
    }

    // ---- Inline -------------------------------------------------------------------------------

    private function inlineChildren(\DOMNode $node, int $depth): string
    {
        $out = '';
        foreach ($node->childNodes as $child) {
            if ($child instanceof \DOMText) $out .= $this->text($child->data);
            elseif ($child instanceof \DOMElement) $out .= $this->inline($child, $depth + 1);
        }
        return $out;
    }

    private function inline(\DOMElement $element, int $depth): string
    {
        $tag = strtolower($element->nodeName);
        if (in_array($tag, self::DROP, true) || $this->hasClass($element, 'hidden')) return '';
        if ($depth >= self::MAX_DEPTH) return $this->text($element->textContent);
        switch ($tag) {
            case 'br': return $this->inTable ? ' ' : "  \n";
            case 'img': return $this->image($element);
            case 'strong': case 'b': return $this->wrap('**', $this->inlineChildren($element, $depth));
            case 'em': case 'i': case 'cite': return $this->wrap('*', $this->inlineChildren($element, $depth));
            case 's': case 'del': case 'strike': return $this->wrap('~~', $this->inlineChildren($element, $depth));
            case 'code': case 'tt': case 'kbd': case 'samp': return $this->inlineCode($element->textContent);
            case 'a': return $this->link($element, $depth);
        }
        if (in_array($tag, self::BLOCKS, true)) return ' ' . $this->inlineChildren($element, $depth) . ' ';
        if ($this->hasClass($element, 'status-macro') || $this->hasClass($element, 'aui-lozenge')) return $this->wrap('**', $this->inlineChildren($element, $depth));
        return $this->inlineChildren($element, $depth);
    }

    private function wrap(string $marker, string $text): string
    {
        if (trim($text) === '') return $text;
        preg_match('/^(\s*)(.*?)(\s*)$/su', $text, $m);
        return $m[1] . $marker . $m[2] . $marker . $m[3];
    }

    private function inlineCode(string $text): string
    {
        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;
        if (trim($text) === '') return $text;
        preg_match_all('/`+/', $text, $runs);
        $fence = str_repeat('`', ($runs[0] ? max(array_map('strlen', $runs[0])) : 0) + 1);
        $pad = str_starts_with($text, '`') || str_ends_with($text, '`') ? ' ' : '';
        return $fence . $pad . $text . $pad . $fence;
    }

    private function link(\DOMElement $element, int $depth): string
    {
        $text = $this->inlineChildren($element, $depth);
        $href = trim($element->getAttribute('href'));
        if (!preg_match('#^(https?://|mailto:)#i', $href)) return $text;
        $href = str_replace([' ', '(', ')', '<', '>'], ['%20', '%28', '%29', '%3C', '%3E'], $href);
        if (trim($text) === '') return '<' . $href . '>';
        return '[' . trim($text) . '](' . $href . ')';
    }

    private function image(\DOMElement $image): string
    {
        $class = ' ' . $image->getAttribute('class') . ' ';
        if (str_contains($class, ' emoticon ') || $image->hasAttribute('data-emoji-id') || $image->hasAttribute('data-emoticon-name')) return $this->emoji($image);
        $src = $image->getAttribute('src');
        $label = trim($image->getAttribute('alt')) ?: trim(basename(parse_url($image->getAttribute('data-image-src') ?: $src, PHP_URL_PATH) ?: '')) ?: 'image';
        $name = $src !== '' ? ($this->resolveImage)($src, $label) : null;
        if ($name === null) return $label !== '' ? '*(image non importée : ' . $this->text($label) . ')*' : '';
        $alt = str_replace(['[', ']'], '', preg_replace('/\.(png|jpe?g|gif|webp)$/i', '', $label) ?? $label);
        return '![' . $alt . '](_assets/' . $name . ')';
    }

    private function emoji(\DOMElement $image): string
    {
        $fallback = trim($image->getAttribute('data-emoji-fallback'));
        if ($fallback !== '' && !str_starts_with($fallback, ':') && mb_strlen($fallback, 'UTF-8') <= 8) return $fallback;
        $name = $image->getAttribute('data-emoticon-name') ?: trim($image->getAttribute('data-emoji-shortname'), ':');
        $name = str_replace('_', '-', strtolower($name));
        if (isset(self::EMOTICONS[$name])) return self::EMOTICONS[$name];
        $id = $image->getAttribute('data-emoji-id');
        if (preg_match('/^[0-9a-f]{4,6}(-[0-9a-f]{4,6})*$/i', $id)) return implode('', array_map(fn($cp) => mb_chr((int) hexdec($cp), 'UTF-8'), explode('-', $id)));
        return '';
    }

    /** HTML whitespace collapsing + Markdown escaping of a text node. */
    private function text(string $text): string
    {
        $text = preg_replace('/[\s\x{00A0}]+/u', ' ', $text) ?? $text;
        $text = str_replace('\\', '\\\\', $text);
        $text = preg_replace('/([*`\[\]])/', '\\\\$1', $text) ?? $text;
        // Underscores only matter at word boundaries.
        return preg_replace('/(?<![\p{L}\p{N}])_|_(?![\p{L}\p{N}])/u', '\\_', $text) ?? $text;
    }

    private function cleanInline(string $text): string
    {
        $text = preg_replace('/[ \t]+/u', ' ', $text) ?? $text;
        $text = preg_replace("/ *\n */", "\n", $text) ?? $text;
        return trim(str_replace("  \n", ' ', $text));
    }

    /** Trims a paragraph and escapes what would otherwise start a heading, quote or list. */
    private function cleanParagraph(string $text): string
    {
        $text = preg_replace('/[ \t]+/u', ' ', $text) ?? $text;
        $text = preg_replace("/ ?  \n ?/", "  \n", $text) ?? $text;
        $text = trim($text, " \t\n");
        if ($text === '') return '';
        $lines = explode("\n", $text);
        foreach ($lines as &$line) {
            $line = preg_replace('/^(#{1,6}\s|>|[-+]\s|\d+[.)]\s)/', '\\\\$1', ltrim($line)) ?? $line;
        }
        return implode("\n", $lines);
    }

    private function hasClass(\DOMElement $element, string $class): bool
    {
        return in_array($class, preg_split('/\s+/', $element->getAttribute('class')) ?: [], true);
    }
}
