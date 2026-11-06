<?php
declare(strict_types=1);
namespace App\Services;
use App\Core\DB;

/** Explicit identities: rich-text spans or the plain-text API token @[user:123]. */
final class Mentions
{
    public static function ids(string $body,string $format): array
    {
        $ids=[];
        if ($format==='html') {
            $document=self::document($body);
            foreach ($document->getElementsByTagName('span') as $span) {
                $raw=$span->getAttribute('data-mention-id');
                if ($raw!=='') $ids[]=self::id($raw);
            }
        } else {
            preg_match_all('/@\[user:([1-9][0-9]{0,18})\]/',$body,$matches);
            foreach($matches[1] as $raw) $ids[]=self::id($raw);
        }
        $ids=array_values(array_unique($ids));
        if (count($ids)>20) throw new \InvalidArgumentException('20 personnes mentionnées maximum par commentaire.');
        return $ids;
    }
    public static function id(string $raw): int
    {
        if (!preg_match('/^[1-9][0-9]{0,18}$/D',$raw) || filter_var($raw,FILTER_VALIDATE_INT)===false) throw new \InvalidArgumentException('Identifiant de mention invalide.');
        return (int)$raw;
    }
    public static function document(string $body): \DOMDocument
    {
        $document=new \DOMDocument('1.0','UTF-8');$previous=libxml_use_internal_errors(true);
        try { $document->loadHTML('<?xml encoding="UTF-8"><html><body>'.$body.'</body></html>',LIBXML_NONET); }
        finally { libxml_clear_errors();libxml_use_internal_errors($previous); }
        return $document;
    }
    public static function people(array $ids,bool $active=true): array
    {
        if (!$ids) return [];
        $rows=DB::all('SELECT id,name
            FROM users
            WHERE id IN ('.implode(',',array_fill(0,count($ids),'?')).')'.($active?' AND active=1 AND verified_at IS NOT NULL':''),$ids);
        $people=array_column($rows,'name','id');
        if ($active && count($people)!==count($ids)) throw new \InvalidArgumentException('Une personne mentionnée n’est plus disponible. Choisissez un compte actif et vérifié.');
        return $people;
    }
    public static function span(int $id,string $name): string { return '<span class="mention" data-mention-id="'.$id.'">@'.e($name).'</span>'; }
    public static function renderText(string $body): string
    {
        $people=self::people(self::ids($body,'text'),false);
        return nl2br(preg_replace_callback('/@\[user:([1-9][0-9]{0,18})\]/',fn($match)=>isset($people[(int)$match[1]])?self::span((int)$match[1],$people[(int)$match[1]]):$match[0],e($body)));
    }
}
