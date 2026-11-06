<?php
declare(strict_types=1);
namespace App\Services;
/**
 * Validates list/report display settings: visible columns and their order, grouping, and up to three
 * sort criteria (sort/direction, sort2/direction2, sort3/direction3). The keys of SORT_LABELS are the
 * only sort names Query::tickets() accepts.
 * settings() whitelists and normalises a complete saved configuration (filters included, checked by
 * running Filter::where()) before it is stored in a saved view or in FilterMemory.
 */
final class ListLayout
{
    public const COLUMNS=['type'=>'Type','key'=>'Clé','title'=>'Résumé','progress_percent'=>'Avancement','git'=>'Git','development'=>'Déploiement','assignee'=>'Personne assignée','team'=>'Équipe','priority'=>'Priorité','status'=>'État','start'=>'Date de début','due'=>'Échéance','estimated_hours'=>'Temps estimé','labels'=>'Étiquettes','created'=>'Création','updated'=>'Modification'];
    public const GROUPS=[''=>'Aucun','assignee'=>'Personne assignée','team'=>'Équipe','priority'=>'Priorité','type'=>'Type de ticket','parent'=>'Parent / Epic','labels'=>'Étiquettes'];
    public const SORT_LABELS=['rank'=>'Ordre manuel','progress_percent'=>'Avancement','title'=>'Résumé','status'=>'État','priority'=>'Priorité','due'=>'Échéance','estimated_hours'=>'Temps estimé','start'=>'Date de début','created'=>'Création','updated'=>'Dernière modification','assignee'=>'Personne assignée','team'=>'Équipe','type'=>'Type'];
    public static function columns(array $settings): array
    {
        $columns=$settings['columns']??array_keys(array_diff_key(self::COLUMNS,['estimated_hours'=>true]));
        if(!is_array($columns) || !array_is_list($columns) || count($columns)>count(self::COLUMNS) || !in_array('title',$columns,true)) throw new \InvalidArgumentException('Choisissez les colonnes du rapport en conservant le résumé.');
        foreach($columns as $column) if(!is_string($column) || !isset(self::COLUMNS[$column])) throw new \InvalidArgumentException('Colonne de rapport invalide.');
        if(count(array_unique($columns))!==count($columns)) throw new \InvalidArgumentException('Une colonne ne peut apparaître qu’une fois.');
        // Old reports stored the progress display separately from their columns.
        if (isset($settings['show_progress'])) {
            $columns = array_values(array_diff($columns, ['progress_percent']));
            if (!empty($settings['show_progress'])) {
                array_splice($columns, array_search('title', $columns, true)+1, 0, ['progress_percent']);
            }
        }
        return $columns;
    }
    /** All column keys: the selected ones first, in their chosen order, then the hidden ones. */
    public static function order(array $settings): array {$columns=self::columns($settings);return [...$columns,...array_diff(array_keys(self::COLUMNS),$columns)];}
    /** Ordered [sort key => 'asc'|'desc'] map; defaults to the manual rank when no sort is given. */
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
        foreach(['hierarchy','show_progress','hide_done','flagged'] as $key) if(isset($settings[$key])) {
            if(!in_array($settings[$key],[0,1,'0','1',false,true,''],true)) throw new \InvalidArgumentException('Option de rapport invalide.');
            $settings[$key]=empty($settings[$key])?'0':'1';
        }
        if (isset($settings['columns'])) {
            $settings['columns'] = self::columns($settings);
            unset($settings['show_progress']);
        }
        if(strlen(json_encode($settings,JSON_THROW_ON_ERROR))>16000) throw new \InvalidArgumentException('Configuration de rapport trop volumineuse.');
        // Building the WHERE clause (result discarded) validates filter values and the JQL text.
        Filter::where($project,$settings);return $settings;
    }
}
