<?php
declare(strict_types=1);
namespace App\Models;
use App\Core\DB;

/** Owns the allowed visual vocabulary and installation-wide ticket appearance. */
final class Appearance
{
    public const ICONS = [
        'folder'=>'Dossier','check'=>'Coche','task'=>'Tâche','bug'=>'Bug',
        'story'=>'Récit','epic'=>'Éclair','subtask'=>'Sous-tâche','flag'=>'Drapeau',
        'code'=>'Code','globe'=>'Site web','cart'=>'Commerce','support'=>'Support',
        'server'=>'Serveur','rocket'=>'Fusée','target'=>'Objectif','spark'=>'Étoile',
        'up-double'=>'Double flèche haute','up'=>'Flèche haute','equal'=>'Égal',
        'down'=>'Flèche basse','down-double'=>'Double flèche basse','clock'=>'Horloge',
        'chart'=>'Graphique','grid'=>'Grille',
    ];
    public const PALETTE = ['#7166eb','#7c3aed','#2563eb','#0891b2','#0f766e','#15803d','#b45309','#c2410c','#dc2626','#be185d','#64748b','#334155'];
    private const DEFAULTS = [
        'type'=>[
            'task'=>['icon'=>'task','color'=>'#2563eb'],
            'story'=>['icon'=>'story','color'=>'#15803d'],
            'bug'=>['icon'=>'bug','color'=>'#dc2626'],
            'subtask'=>['icon'=>'subtask','color'=>'#0891b2'],
            'epic'=>['icon'=>'epic','color'=>'#7c3aed'],
        ],
        'priority'=>[
            'highest'=>['icon'=>'up-double','color'=>'#dc2626'],
            'high'=>['icon'=>'up','color'=>'#c2410c'],
            'medium'=>['icon'=>'equal','color'=>'#b45309'],
            'low'=>['icon'=>'down','color'=>'#2563eb'],
            'lowest'=>['icon'=>'down-double','color'=>'#64748b'],
        ],
    ];
    private static ?array $cached = null;

    public static function validate(mixed $icon, mixed $color): array
    {
        if (!is_string($icon) || !isset(self::ICONS[$icon])) throw new \InvalidArgumentException('Choisissez un pictogramme dans la bibliothèque.');
        if (!is_string($color) || !preg_match('/^#[a-f0-9]{6}$/iD',$color)) throw new \InvalidArgumentException('Choisissez une couleur au format #RRGGBB.');
        return ['icon'=>$icon,'color'=>strtolower($color)];
    }

    public static function get(): array
    {
        if (self::$cached !== null) return self::$cached;
        $marks=self::DEFAULTS;
        // One statement reads the revision and its marks from the same MySQL snapshot.
        $rows=DB::all('SELECT s.version,m.kind,m.mark_key,m.icon,m.color FROM appearance_settings s LEFT JOIN appearance_marks m ON 1=1 WHERE s.id=1');
        foreach ($rows as $row) {
            if ($row['kind']!==null && isset($marks[$row['kind']][$row['mark_key']])) $marks[$row['kind']][$row['mark_key']]=self::validate($row['icon'],$row['color']);
        }
        return self::$cached=['version'=>(int)$rows[0]['version'],'marks'=>$marks];
    }

    public static function mark(string $kind,string $key): array
    {
        $settings=self::get();
        if (!isset($settings['marks'][$kind][$key])) throw new \InvalidArgumentException('Repère visuel inconnu.');
        return $settings['marks'][$kind][$key];
    }

    public static function save(array $input): void
    {
        $submitted=$input['marks']??null;
        if (!is_array($submitted) || array_diff(array_keys($submitted),array_keys(self::DEFAULTS))) throw new \InvalidArgumentException('Configuration de pictogrammes invalide.');
        $marks=[];
        foreach (self::DEFAULTS as $kind=>$defaults) {
            $rows=$submitted[$kind]??null;
            if (!is_array($rows) || array_diff(array_keys($rows),array_keys($defaults))) throw new \InvalidArgumentException('Liste de repères invalide.');
            foreach ($defaults as $key=>$default) {
                $row=$rows[$key]??null;
                if (!is_array($row)) throw new \InvalidArgumentException('La configuration des repères est incomplète.');
                $marks[$kind][$key]=self::validate($row['icon']??null,$row['color']??null);
            }
        }
        $version=filter_var($input['version']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>0]]);
        if ($version===false) throw new \InvalidArgumentException('Version de configuration invalide.');
        DB::transaction(function () use ($marks,$version) {
            $old=DB::one('SELECT version FROM appearance_settings WHERE id=1 FOR UPDATE');
            if ((int)$old['version']!==$version) throw new \InvalidArgumentException('Les pictogrammes ont changé. Rechargez cette section avant de réessayer.');
            foreach ($marks as $kind=>$rows) foreach ($rows as $key=>$mark) {
                DB::run('INSERT INTO appearance_marks(kind,mark_key,icon,color) VALUES(?,?,?,?) ON DUPLICATE KEY UPDATE icon=VALUES(icon),color=VALUES(color)',[$kind,$key,$mark['icon'],$mark['color']]);
            }
            DB::run('UPDATE appearance_settings SET version=version+1 WHERE id=1');
        });
        self::$cached=null;
    }
}
