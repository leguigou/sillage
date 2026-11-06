<?php
declare(strict_types=1);
namespace App\Services;

/**
 * Owns the allowed return destinations and ticket links carrying that context.
 * Powers the "Revenir à ..." link on the ticket page: ticket links carry a ?return= value naming the view
 * the user came from. To prevent open redirects, a destination is accepted only if it is a local path
 * (no scheme, host or "//") whose path is one of the VIEWS keys; anything else is ignored.
 * The source is, in order: an explicit _ticket_return/return parameter, the current URL, then a
 * same-origin Referer header. Used by the bootstrap helpers and the ticket view.
 */
final class TicketNavigation
{
    private const VIEWS = [
        '/notifications'=>'Revenir aux notifications',
        '/releases'=>'Revenir aux versions et livraisons',
        '/'=>'Revenir à la vue d’ensemble', '/list'=>'Revenir à la liste', '/all'=>'Revenir à tous les tickets',
        '/board'=>'Revenir au tableau', '/timeline'=>'Revenir à la chronologie', '/charts'=>'Revenir aux rapports',
        '/development'=>'Revenir au développement', '/forms'=>'Revenir aux formulaires',
        '/automation'=>'Revenir aux automatisations', '/settings'=>'Revenir aux paramètres',
    ];

    /** Returns $value unchanged if it is an allowed return destination, otherwise null. */
    public static function destination(mixed $value): ?string
    {
        // Percent encoding into a ticket URL can triple this length; stay below Apache's request line.
        if (!is_string($value) || $value==='' || strlen($value)>2048 || !str_starts_with($value,'/') || str_starts_with($value,'//') || preg_match('/[\\\\\x00-\x20\x7f]/',$value)) return null;
        $parts=parse_url($value);
        if (!$parts || isset($parts['scheme']) || isset($parts['host']) || !isset(self::VIEWS[$parts['path']??''])) return null;
        return $value;
    }
    /** Destination from the Referer header, only for the same origin as the application URL; a ticket page referrer passes on its own return value. */
    private static function referrer(): ?string
    {
        $source=parse_url($_SERVER['HTTP_REFERER']??'');$base=parse_url(url());
        if (!$source || !$base || isset($source['user']) || isset($source['pass'])) return null;
        $origin=fn($p)=>strtolower($p['scheme']??'').'://'.strtolower($p['host']??'').':'.($p['port']??(strtolower($p['scheme']??'')==='https'?443:80));
        if ($origin($source)!==$origin($base)) return null;
        $path=$source['path']??'/';
        if (preg_match('#^/tickets/[1-9][0-9]*$#D',$path)) {
            parse_str($source['query']??'',$query);
            return self::destination($query['return']??null);
        }
        return self::destination($path.(isset($source['query'])?'?'.$source['query']:'').(isset($source['fragment'])?'#'.$source['fragment']:''));
    }
    /** The return destination for links built during this request, or null if none is allowed. */
    public static function current(): ?string
    {
        $explicit=self::destination($_POST['_ticket_return']??null)??self::destination($_GET['return']??null);
        if ($explicit!==null) return $explicit;
        $source=self::destination($_SERVER['REQUEST_URI']??null);
        if ($source!==null) {
            // Make an implicit project explicit so another browser tab cannot change the return project.
            if (!isset($_GET['project']) && parse_url($source,PHP_URL_PATH)!=='/all' && str_starts_with($_SERVER['REQUEST_METHOD']??'GET','GET') && !empty($_SESSION['project_id'])) {
                $parts=explode('#',$source,2);
                $source=$parts[0].(str_contains($parts[0],'?')?'&':'?').'project='.(int)$_SESSION['project_id'].(isset($parts[1])?'#'.$parts[1]:'');
            }
            return self::destination($source);
        }
        return self::referrer();
    }
    /** URL of a ticket page carrying the current return destination. */
    public static function ticket(int $id,string $fragment='',array $query=[]): string
    {
        $source=self::current();
        $query=($source!==null?['return'=>$source]:[])+$query;
        return '/tickets/'.$id.($query?'?'.http_build_query($query,'','&',PHP_QUERY_RFC3986):'').$fragment;
    }
    /** Path and label of the ticket page's back link; defaults to the project's list view. */
    public static function back(int $project): array
    {
        $path=self::current()??'/list?project='.$project;
        return ['path'=>$path,'label'=>self::VIEWS[parse_url($path,PHP_URL_PATH)]];
    }
}
