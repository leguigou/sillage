<?php
declare(strict_types=1);
namespace App\Services;
final class ListLayout
{
    public const COLUMNS=['type'=>'Type','key'=>'Clé','title'=>'Résumé','assignee'=>'Personne assignée','team'=>'Équipe','priority'=>'Priorité','status'=>'État','due'=>'Échéance','estimated_hours'=>'Temps estimé','labels'=>'Étiquettes','created'=>'Création'];
    public const GROUPS=[''=>'Aucun','assignee'=>'Personne assignée','team'=>'Équipe','priority'=>'Priorité','type'=>'Type de ticket','parent'=>'Parent / Epic','labels'=>'Étiquettes'];
    public const SORT_LABELS=['rank'=>'Ordre manuel','progress_percent'=>'Avancement','title'=>'Résumé','status'=>'État','priority'=>'Priorité','due'=>'Échéance','estimated_hours'=>'Temps estimé','start'=>'Début','created'=>'Création','updated'=>'Dernière modification','assignee'=>'Personne assignée','team'=>'Équipe','type'=>'Type'];
    public static function columns(array $settings): array
    {
        $columns=$settings['columns']??array_keys(self::COLUMNS);
        if(!is_array($columns) || !array_is_list($columns) || count($columns)>count(self::COLUMNS) || !in_array('title',$columns,true)) throw new \InvalidArgumentException('Choisissez les colonnes du rapport en conservant le résumé.');
        foreach($columns as $column) if(!is_string($column) || !isset(self::COLUMNS[$column])) throw new \InvalidArgumentException('Colonne de rapport invalide.');
        if(count(array_unique($columns))!==count($columns)) throw new \InvalidArgumentException('Une colonne ne peut apparaître qu’une fois.');
        return $columns;
    }
    public static function order(array $settings): array {$columns=self::columns($settings);return [...$columns,...array_diff(array_keys(self::COLUMNS),$columns)];}
    public static function sorts(array $settings): array
    {
        if(isset($settings['sort4'])) throw new \InvalidArgumentException('Trois critères de tri maximum.');
        $result=[];
        foreach(['','2','3'] as $suffix) {
            $field=$settings['sort'.$suffix]??($suffix===''?'rank':'');$direction=$settings['direction'.$suffix]??'asc';
            if($field==='') continue;
            if(!is_string($field) || !isset(self::SORT_LABELS[$field]) || !in_array($direction,['asc','desc'],true)) throw new \InvalidArgumentException('Critère ou sens du tri invalide.');
            if(isset($result[$field])) throw new \InvalidArgumentException('Choisissez des critères de tri différents.');
            $result[$field]=$direction;
        }
        return $result;
    }
    public static function settings(int $project,array $settings): array
    {
        self::sorts($settings);
        $allowed=['q','status','type','priority','assignee','team','hide_done','flagged','label','due_before','jql','pause','release','resolution','validation','sort','direction','group','hierarchy','columns','show_progress'];
        $allowed=[...$allowed,'sort2','direction2','sort3','direction3'];
        $settings=array_intersect_key($settings,array_flip($allowed));
        foreach($settings as $key=>$value) {
            if(in_array($key,['status','type','priority','columns'],true) && is_array($value)) {foreach($value as $item) if(!is_string($item)) throw new \InvalidArgumentException('Liste de critères invalide.');}
            elseif(!is_scalar($value) && $value!==null) throw new \InvalidArgumentException('Critère de rapport invalide.');
        }
        if(isset($settings['sort']) && !isset(self::SORT_LABELS[$settings['sort']])) throw new \InvalidArgumentException('Tri du rapport invalide.');
        if(isset($settings['direction']) && !in_array($settings['direction'],['asc','desc'],true)) throw new \InvalidArgumentException('Sens du tri invalide.');
        if(isset($settings['group']) && !isset(self::GROUPS[$settings['group']])) throw new \InvalidArgumentException('Regroupement du rapport invalide.');
        if(isset($settings['columns'])) $settings['columns']=self::columns($settings);
        foreach(['hierarchy','show_progress','hide_done','flagged'] as $key) if(isset($settings[$key])) {
            if(!in_array($settings[$key],[0,1,'0','1',false,true,''],true)) throw new \InvalidArgumentException('Option de rapport invalide.');
            $settings[$key]=empty($settings[$key])?'0':'1';
        }
        if(strlen(json_encode($settings,JSON_THROW_ON_ERROR))>16000) throw new \InvalidArgumentException('Configuration de rapport trop volumineuse.');
        Filter::where($project,$settings);return $settings;
    }
}
