<?php
declare(strict_types=1);
namespace App\Services;
use App\Core\DB;

/** The only boundary that turns conversation HTML into trusted markup. */
final class Conversation
{
    public static function clean(string $html, int $ticket): string
    {
        if (strlen($html) > 100000) {
            throw new \InvalidArgumentException('Le commentaire est trop long.');
        }
        $document = new \DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        try {
            $document->loadHTML('<?xml encoding="UTF-8"><html><body>' . $html . '</body></html>', LIBXML_NONET);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        $images = [];
        foreach (DB::all("SELECT id FROM attachments WHERE ticket_id=? AND mime IN ('image/png','image/jpeg','image/webp')", [$ticket]) as $file) {
            $images[(int) $file['id']] = true;
        }
        $escape = fn(string $s): string => htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $nodes = 0;
        $walk = function (\DOMNode $node, int $depth = 0) use (&$walk, &$nodes, $images, $escape): string {
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

    public static function render(array $comment): string
    {
        if (($comment['body_format'] ?? 'text') !== 'html') {
            return nl2br(e($comment['body']));
        }
        try {
            return self::clean($comment['body'], (int) $comment['ticket_id']);
        } catch (\InvalidArgumentException) {
            return '<span class="muted">Contenu indisponible ou pièce jointe supprimée.</span>';
        }
    }
}
