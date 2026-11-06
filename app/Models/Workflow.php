<?php
declare(strict_types=1);
namespace App\Models;
use App\Core\DB;

/** Project-owned vocabulary, categories and transition policy. Legacy keys remain stable. */
final class Workflow
{
    public const CATEGORIES=['todo'=>'À faire','progress'=>'En cours','review'=>'En revue','done'=>'Terminé'];
    private static array $cache=[];

    public static function reset(): void { self::$cache=[]; }
    public static function decorate(array $ticket): array
    {
        $project=(int)$ticket['project_id'];
        $ticket['status_label']=self::label('status',$project,$ticket['status']);
        $ticket['type_label']=self::label('type',$project,$ticket['type']);
        $ticket['status_category']=self::category('status',$project,$ticket['status']);
        $ticket['type_category']=self::category('type',$project,$ticket['type']);
        $ticket['status_color']=self::mark('status',$project,$ticket['status'])['color'];
        $ticket['status_options']=self::destinations($project,$ticket['status'],$ticket['paused_from_status']??null);
        return $ticket;
    }
    public static function get(int $project): array
    {
        if (isset(self::$cache[$project])) return self::$cache[$project];
        $items=['status'=>[],'type'=>[]];
        $colors=['todo'=>'#64748b','progress'=>'#7166eb','review'=>'#b45309','done'=>'#15803d','paused'=>'#64748b'];
        $icons=['todo'=>'task','progress'=>'clock','review'=>'target','done'=>'check','paused'=>'clock'];
        foreach (Ticket::STATUSES as $code=>$label) $items['status'][$code]=['code'=>$code,'label'=>$label,'category'=>$code,'icon'=>$icons[$code],'color'=>$colors[$code],'position'=>count($items['status'])*10,'enabled'=>true];
        foreach (Ticket::TYPES as $code=>$label) $items['type'][$code]=['code'=>$code,'label'=>$label,'category'=>$code,'icon'=>null,'color'=>null,'position'=>count($items['type'])*10,'enabled'=>true];
        $settings=$project?DB::one('SELECT version,restricted,transitions_json FROM project_workflows WHERE project_id=?',[$project]):null;
        $rows=$project?DB::all('SELECT *
            FROM workflow_items
            WHERE project_id=?',[$project]):DB::all('SELECT w.*,p.project_key
            FROM workflow_items w
            JOIN projects p ON p.id=w.project_id');
        foreach ($rows as $row) {
            if (!$project && isset($items[$row['kind']][$row['code']])) continue;
            if (!$project) $row['label']=$row['project_key'].' · '.$row['label'];
            $items[$row['kind']][$row['code']]=$row;
        }
        foreach ($items as &$group) uasort($group,fn($a,$b)=>[$a['position'],$a['code']]<=>[$b['position'],$b['code']]);
        return self::$cache[$project]=['version'=>(int)($settings['version']??0),'restricted'=>(bool)($settings['restricted']??false),'transitions'=>$settings?json_decode($settings['transitions_json'],true,32,JSON_THROW_ON_ERROR):[],'items'=>$items];
    }
    public static function labels(string $kind,int $project=0,bool $active=false): array
    {
        $rows=self::get($project)['items'][$kind]??[];
        if ($active) $rows=array_filter($rows,fn($row)=>(bool)$row['enabled']);
        return array_column($rows,'label','code');
    }
    public static function item(string $kind,int $project,string $code): array
    {
        $item=self::get($project)['items'][$kind][$code]??null;
        if (!$item) throw new \InvalidArgumentException('Type ou état inconnu dans ce projet.');
        return $item;
    }
    public static function label(string $kind,int $project,string $code): string { return self::get($project)['items'][$kind][$code]['label']??$code; }
    public static function categoryLabels(string $kind,int $project,string $category): array { return array_column(array_filter(self::get($project)['items'][$kind],fn($row)=>$row['category']===$category),'label','code'); }
    public static function category(string $kind,int $project,string $code): string { return self::item($kind,$project,$code)['category']; }
    public static function mark(string $kind,int $project,string $code): array
    {
        $row=self::item($kind,$project,$code);
        if ($row['icon']===null || $row['color']===null) return Appearance::mark('type',$row['category']);
        return ['icon'=>$row['icon'],'color'=>$row['color']];
    }
    public static function categorySql(string $kind='status',string $alias='t'): string
    {
        if (!in_array($kind,['status','type'],true) || !preg_match('/^[a-z][a-z0-9_]*$/D',$alias)) throw new \LogicException('Expression de catégorie invalide.');
        $fallback=$kind==='status'?"CASE $alias.status WHEN 'done' THEN 'done' WHEN 'paused' THEN 'paused' WHEN 'review' THEN 'review' WHEN 'progress' THEN 'progress' ELSE 'todo' END":"CASE $alias.type WHEN 'epic' THEN 'epic' WHEN 'subtask' THEN 'subtask' WHEN 'bug' THEN 'bug' WHEN 'story' THEN 'story' ELSE 'task' END";
        return "COALESCE((SELECT wi.category FROM workflow_items wi WHERE wi.project_id=$alias.project_id AND wi.kind='$kind' AND wi.code=$alias.$kind),$fallback)";
    }
    public static function destinations(int $project,string $from,?string $pausedFrom=null): array
    {
        $config=self::get($project);$labels=self::labels('status',$project,true);
        if (!$config['restricted']) return $labels;
        $effective=$from==='paused'?($pausedFrom??'todo'):$from;
        if ($effective==='paused' || !isset($labels[$effective])) $effective='todo';
        return array_intersect_key($labels,array_flip([$from,$effective,'paused',...($config['transitions'][$effective]??[])]));
    }
    public static function assertTransition(int $project,string $from,string $to,?string $pausedFrom=null): void
    {
        if (!isset(self::destinations($project,$from,$pausedFrom)[$to])) throw new \InvalidArgumentException('Ce passage de « '.self::label('status',$project,$from).' » à « '.self::label('status',$project,$to).' » n’est pas autorisé par le circuit du projet.');
    }
    public static function save(int $project,array $input): array
    {
        return DB::transaction(function() use($project,$input) {
            if (!DB::one('SELECT id FROM projects WHERE id=? FOR UPDATE',[$project])) throw new \InvalidArgumentException('Projet introuvable.');
            self::reset();$old=self::get($project);
            $version=filter_var($input['version']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>0]]);
            if ($version===false || $version!==$old['version']) throw new \InvalidArgumentException('Le circuit a changé. Rechargez cette section avant de réessayer.');
            $action=$input['action']??'';
            if ($action==='transitions') {
                $edges=$input['transitions']??[];
                if (!is_array($edges) || array_diff(array_keys($edges),array_keys($old['items']['status']))) throw new \InvalidArgumentException('Transitions invalides.');
                $normalized=[];
                foreach ($edges as $from=>$to) {
                    if (!is_array($to) || array_filter($to,fn($code)=>!is_string($code) || empty($old['items']['status'][$code]['enabled']))) throw new \InvalidArgumentException('Destination de transition invalide.');
                    $normalized[$from]=array_values(array_unique($to));
                }
                $restricted=!empty($input['restricted']);
            } elseif (in_array($action,['add_item','save_item'],true)) {
                $kind=$input['kind']??'';
                if (!is_string($kind) || !isset($old['items'][$kind])) throw new \InvalidArgumentException('Famille de circuit invalide.');
                $code=$action==='add_item'?($kind==='status'?'s_':'t_').bin2hex(random_bytes(8)):($input['code']??'');
                if (!is_string($code) || !preg_match('/^[a-z][a-z0-9_]{0,19}$/D',$code)) throw new \InvalidArgumentException('Clé d’élément invalide.');
                $previous=$old['items'][$kind][$code]??null;
                if ($action==='save_item' && !$previous) throw new \InvalidArgumentException('Élément du circuit introuvable.');
                if (!$previous && count($old['items'][$kind])>=30) throw new \InvalidArgumentException('30 éléments maximum par famille pour conserver des tableaux et une matrice de transitions lisibles.');
                $rawLabel=$input['label']??($previous['label']??'');
                $label=is_string($rawLabel)?trim($rawLabel):'';
                $category=$input['category']??($previous['category']??'');
                $allowed=$kind==='status'?self::CATEGORIES:Ticket::TYPES;
                if ($code==='paused') $allowed['paused']='En pause';
                if (!$label || mb_strlen($label)>80 || preg_match('/[\x00-\x1f\x7f]/u',$label) || !is_string($category) || !isset($allowed[$category])) throw new \InvalidArgumentException('Libellé ou catégorie invalide.');
                foreach ($old['items'][$kind] as $key=>$row) if ($key!==$code && mb_strtolower($row['label'])===mb_strtolower($label)) throw new \InvalidArgumentException('Ce libellé existe déjà dans le projet.');
                $previousMark=$previous?self::mark($kind,$project,$code):[];
                $mark=Appearance::validate($input['icon']??($previousMark['icon']??null),$input['color']??($previousMark['color']??null));
                $position=filter_var($input['position']??($previous['position']??0),FILTER_VALIDATE_INT,['options'=>['min_range'=>0,'max_range'=>999]]);
                if ($position===false) throw new \InvalidArgumentException('Ordre compris entre 0 et 999 requis.');
                $enabled=$action==='add_item' || (array_key_exists('enabled',$input)?!empty($input['enabled']):(bool)$previous['enabled']);
                $used=(int)DB::one('SELECT COUNT(*) n
                    FROM tickets
                    WHERE project_id=? AND '.($kind==='status'?'(status=? OR paused_from_status=?)':'type=?'),$kind==='status'?[$project,$code,$code]:[$project,$code])['n'];
                if ($previous && $category!==$previous['category'] && ($used || isset(($kind==='status'?Ticket::STATUSES:Ticket::TYPES)[$code]))) throw new \InvalidArgumentException('La catégorie d’un élément standard ou utilisé est immuable ; créez un nouvel élément.');
                if (!$enabled) {
                    if (in_array($code,['todo','paused','task'],true) || $used) throw new \InvalidArgumentException('Cet élément est standard ou utilisé par des tickets, y compris archivés. Il doit rester disponible.');
                    if ($kind==='type' && DB::one('SELECT id
                        FROM public_forms
                        WHERE project_id=? AND ticket_type=?
                        LIMIT 1',[$project,$code])) throw new \InvalidArgumentException('Un formulaire utilise encore ce type.');
                    if (DB::one("SELECT id
                        FROM formatting_rules
                        WHERE project_id=? AND field=? AND match_value=?
                        LIMIT 1",[$project,$kind,$code]) || DB::one("SELECT id
                        FROM automation_rules
                        WHERE project_id=? AND (JSON_UNQUOTE(JSON_EXTRACT(conditions_json,?))=? OR JSON_UNQUOTE(JSON_EXTRACT(actions_json,?))=?)
                        LIMIT 1",[$project,'$.'.$kind,$code,'$.'.$kind,$code])) throw new \InvalidArgumentException('Une règle utilise encore cet élément.');
                }
                DB::run('INSERT INTO workflow_items(project_id,kind,code,label,category,icon,color,position,enabled)
                    VALUES(?,?,?,?,?,?,?,?,?)
                    ON DUPLICATE KEY UPDATE label=VALUES(label),category=VALUES(category),icon=VALUES(icon),color=VALUES(color),position=VALUES(position),enabled=VALUES(enabled)',[$project,$kind,$code,$label,$category,$mark['icon'],$mark['color'],$position,(int)$enabled]);
                $normalized=$old['transitions'];$restricted=$old['restricted'];
            } else throw new \InvalidArgumentException('Action de circuit inconnue.');
            DB::run('INSERT INTO project_workflows(project_id,version,restricted,transitions_json)
                VALUES(?,1,?,?)
                ON DUPLICATE KEY UPDATE version=version+1,restricted=VALUES(restricted),transitions_json=VALUES(transitions_json)',[$project,(int)$restricted,json_encode((object)$normalized,JSON_THROW_ON_ERROR)]);
            self::reset();return self::get($project);
        });
    }
}
