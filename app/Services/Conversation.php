<?php
declare(strict_types=1);
namespace App\Services;
use App\Core\DB;

/**
 * The only boundary that turns conversation HTML into trusted markup.
 * HTML sanitizer for rich-text ticket comments, used when a comment is saved (clean()) and again every
 * time it is displayed (render()), so stored HTML is never echoed as-is.
 * Allow-list approach: the HTML is parsed with DOMDocument (network access disabled) and rebuilt from
 * scratch; only a few formatting tags survive, with no attributes except: http(s) links (opened with
 * noopener), images pointing to this ticket's own image attachments, and mention spans for known users.
 * All text is escaped. Size and nesting limits protect against oversized or deeply nested input.
 */
final class Conversation
{
    /**
     * Returns sanitized HTML or throws InvalidArgumentException. $publishing=true (on save) requires every
     * mentioned user to be active and verified; on display, mentions of any existing user are kept.
     */
    public static function clean(string $html, int $ticket,bool $publishing=true): string
    {
        if (strlen($html) > 100000) {
            throw new \InvalidArgumentException('Le commentaire est trop long.');
        }
        $document = new \DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        try {
            // The XML encoding prefix makes libxml read the fragment as UTF-8 instead of ISO-8859-1.
            $document->loadHTML('<?xml encoding="UTF-8"><html><body>' . $html . '</body></html>', LIBXML_NONET);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        $images = [];
        $mentions=Mentions::people(Mentions::ids($html,'html'),$publishing);
        foreach (DB::all("SELECT id FROM attachments WHERE ticket_id=? AND mime IN ('image/png','image/jpeg','image/webp')", [$ticket]) as $file) {
            $images[(int) $file['id']] = true;
        }
        $escape = fn(string $s): string => htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $nodes = 0;
        // Recursive rebuild: returns the safe HTML for one node and its children.
        // Unknown tags are dropped but their (escaped) content is kept; dangerous containers are dropped entirely.
        $walk = function (\DOMNode $node, int $depth = 0) use (&$walk, &$nodes, $images, $escape,$mentions): string {
            if (++$nodes > 4000 || $depth > 30) {
                throw new \InvalidArgumentException('La mise en forme du commentaire est trop complexe.');
            }
            if ($node instanceof \DOMText) {
                return $escape($node->textContent);
            }
            if (!$node instanceof \DOMElement) {
                return '';
            }
            $tag = strtolower($node->tagName);
            if ($tag==='span' && $node->hasAttribute('data-mention-id')) {
                $id=Mentions::id($node->getAttribute('data-mention-id'));
                return isset($mentions[$id])?Mentions::span($id,$mentions[$id]):$escape($node->textContent);
            }
            if (in_array($tag, ['script','style','iframe','object','svg','math','template','head'], true)) {
                return '';
            }
            if ($tag === 'img') {
                if (preg_match('#^/attachments/([1-9][0-9]*)/preview$#D', $node->getAttribute('src'), $match) && isset($images[(int) $match[1]])) {
                    return '<img src="/attachments/' . (int) $match[1] . '/preview" alt="' . $escape(mb_substr($node->getAttribute('alt'), 0, 240)) . '" loading="lazy">';
                }
                return '';
            }
            $content = '';
            foreach ($node->childNodes as $child) {
                $content .= $walk($child, $depth + 1);
            }
            $tag = ['b'=>'strong','i'=>'em','strike'=>'s','div'=>'p'][$tag] ?? $tag;
            if ($tag === 'a') {
                $href = $node->getAttribute('href');
                return preg_match('#^https?://#i', $href) && filter_var($href, FILTER_VALIDATE_URL)
                    ? '<a href="' . $escape($href) . '" rel="noopener noreferrer" target="_blank">' . $content . '</a>' : $content;
            }
            if ($tag === 'br') {
                return '<br>';
            }
            return in_array($tag, ['p','strong','em','u','s','ul','ol','li','blockquote','pre','code'], true)
                ? '<' . $tag . '>' . $content . '</' . $tag . '>' : $content;
        };
        $body = $document->getElementsByTagName('body')->item(0);
        $result = $body ? $walk($body) : '';
        $text = trim(html_entity_decode(strip_tags($result), ENT_QUOTES | ENT_HTML5, 'UTF-8'), " \n\r\t\xC2\xA0");
        if (($text === '' && !str_contains($result, '<img ')) || mb_strlen($text) > 10000 || strlen($result) > 60000) {
            throw new \InvalidArgumentException('Le commentaire doit contenir du texte (10 000 caractères maximum) ou une image jointe.');
        }
        return $result;
    }

    /**
     * Readable plain text of a stored comment, one line per paragraph, list item or line break, used to
     * compare two versions of a message in the ticket history. Mentions keep their "@Name" text and images
     * become "[image : nom]"; no markup survives.
     */
    public static function plainText(array $comment): string
    {
        $body = (string) $comment['body'];
        if (($comment['body_format'] ?? 'text') !== 'html') {
            $people = Mentions::people(Mentions::ids($body, 'text'), false);
            $text = preg_replace_callback('/@\[user:([1-9][0-9]{0,18})\]/', fn($m) => isset($people[(int) $m[1]]) ? '@' . $people[(int) $m[1]] : $m[0], $body) ?? $body;
        } else {
            $html = preg_replace_callback('/<img\b[^>]*\balt="([^"]*)"[^>]*>/i', fn($m) => '[image : ' . $m[1] . ']', $body) ?? $body;
            $html = preg_replace('/<li\b[^>]*>/i', '- ', $html) ?? $html;
            $html = preg_replace('#<br\s*/?>|</(p|li|blockquote|pre|ul|ol)>#i', "\n", $html) ?? $html;
            $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }
        $text = str_replace(["\r\n", "\r", "\xC2\xA0"], ["\n", "\n", ' '], $text);
        $text = preg_replace("/[ \t]+\n/", "\n", $text) ?? $text;
        return trim(preg_replace("/\n{3,}/", "\n\n", $text) ?? $text);
    }

    /** Display-safe HTML for a stored comment (plain-text or HTML format); unrenderable HTML shows a placeholder. */
    public static function render(array $comment): string
    {
        if (($comment['body_format'] ?? 'text') !== 'html') {
            return Mentions::renderText($comment['body']);
        }
        try {
            return self::clean($comment['body'], (int) $comment['ticket_id'],false);
        } catch (\InvalidArgumentException) {
            return '<span class="muted">Contenu indisponible ou pièce jointe supprimée.</span>';
        }
    }
}
