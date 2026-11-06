<?php
declare(strict_types=1);
namespace App\Models;
use App\Core\{DB,Security};

/** A successful production delivery is explicit; a merged PR never implies deployment. */
final class Release
{
    public const ENVIRONMENTS=['staging'=>'Préproduction','production'=>'Production'];
    public const OUTCOMES=['success'=>'Réussie','failed'=>'Échouée','rolled_back'=>'Retour arrière'];
    public static function find(int $id): array
    {
        $row=DB::one('SELECT * FROM releases WHERE id=?',[$id]);
        if (!$row) throw new \InvalidArgumentException('Version introuvable.');
        $row['delivery_state']=self::deliveryState($id);
        return $row;
    }
    public static function deliveryState(int $id): array
    {
        return self::states([$id])[$id];
    }
    private static function states(array $ids): array
    {
        $states=[];foreach($ids as $id) $states[$id]=array_fill_keys(array_keys(self::ENVIRONMENTS),null);
        if (!$ids) return $states;
        $placeholders=implode(',',array_fill(0,count($ids),'?'));
        foreach(DB::all("SELECT d.*,u.name actor_name FROM release_deliveries d JOIN users u ON u.id=d.actor_id JOIN (SELECT release_id,environment,MAX(id) id FROM release_deliveries WHERE release_id IN ($placeholders) GROUP BY release_id,environment) latest ON latest.id=d.id",$ids) as $row) $states[$row['release_id']][$row['environment']]=$row;
        return $states;
    }
    public static function page(int $project,int $offset=0,int $limit=50,bool $archived=false): array
    {
        Project::find($project);$offset=max(0,$offset);$limit=max(1,min(100,$limit));
        $rows=DB::all("SELECT r.*,(SELECT COUNT(*) FROM tickets t WHERE t.release_id=r.id AND t.archived=0) ticket_count FROM releases r WHERE r.project_id=? AND r.archived=? ORDER BY r.id DESC LIMIT $limit OFFSET $offset",[$project,(int)$archived]);
        $states=self::states(array_column($rows,'id'));
        foreach($rows as &$row) $row['delivery_state']=$states[$row['id']];
        return ['total'=>(int)DB::one('SELECT COUNT(*) n
            FROM releases
            WHERE project_id=? AND archived=?',[$project,(int)$archived])['n'],'offset'=>$offset,'limit'=>$limit,'releases'=>$rows];
    }
    public static function save(int $project,array $input,int $actor,?int $id=null): array
    {
        return DB::transaction(function() use($project,$input,$actor,$id) {
            if (!DB::one('SELECT id FROM projects WHERE id=? FOR UPDATE',[$project])) throw new \InvalidArgumentException('Projet introuvable.');
            $old=$id?self::find($id):null;
            if ($old && ((int)$old['project_id']!==$project || (int)$old['version']!==(int)($input['version']??0))) throw new \InvalidArgumentException('Projet ou version de livraison obsolète.');
            $name=trim((string)($input['name']??($old['name']??'')));$description=trim((string)($input['description']??($old['description']??'')));
            $date=Security::date(array_key_exists('target_date',$input)?$input['target_date']:($old['target_date']??null));
            $archived=(int)(bool)($input['archived']??($old['archived']??false));
            if ($name==='' || mb_strlen($name)>120 || mb_strlen($description)>10000) throw new \InvalidArgumentException('Nom de version requis (120 caractères), description limitée à 10 000 caractères.');
            if (DB::one('SELECT id
                FROM releases
                WHERE project_id=? AND name=? AND id<>?',[$project,$name,$id??0])) throw new \InvalidArgumentException('Ce nom de version existe déjà dans le projet.');
            if ($id) DB::run('UPDATE releases
                SET name=?,description=?,target_date=?,archived=?,version=version+1
                WHERE id=?',[$name,$description,$date,$archived,$id]);
            else $id=DB::insert('INSERT INTO releases(project_id,name,description,target_date,archived)
                VALUES(?,?,?,?,?)',[$project,$name,$description,$date,$archived]);
            Ticket::activity($project,null,$actor,($old?'a modifié la version ':'a créé la version ').$name);
            return self::find($id);
        });
    }
    public static function deliver(int $id,array $input,int $actor,int $version): array
    {
        return DB::transaction(function() use($id,$input,$actor,$version) {
            $old=self::find($id);DB::one('SELECT id FROM projects WHERE id=? FOR UPDATE',[$old['project_id']]);$old=self::find($id);
            if ((int)$old['version']!==$version) throw new \InvalidArgumentException('Cette version a changé. Rechargez-la avant de déclarer une livraison.');
            if ($old['archived']) throw new \InvalidArgumentException('Restaurez la version avant de déclarer une livraison.');
            $environment=(string)($input['environment']??'');$outcome=(string)($input['outcome']??'');
            $reference=trim((string)($input['reference']??''));$note=trim((string)($input['note']??''));
            if (!isset(self::ENVIRONMENTS[$environment],self::OUTCOMES[$outcome]) || $reference==='' || mb_strlen($reference)>240 || mb_strlen($note)>2000) throw new \InvalidArgumentException('Choisissez un environnement, un résultat et une référence Git/build (240 caractères maximum).');
            DB::run('INSERT INTO release_deliveries(release_id,actor_id,environment,outcome,reference,note)
                VALUES(?,?,?,?,?,?)',[$id,$actor,$environment,$outcome,$reference,$note]);
            DB::run('UPDATE releases SET version=version+1 WHERE id=?',[$id]);
            Ticket::activity((int)$old['project_id'],null,$actor,'a déclaré une livraison '.self::OUTCOMES[$outcome].' · '.$old['name'].' · '.self::ENVIRONMENTS[$environment]);
            return self::find($id);
        });
    }
    public static function deliveries(int $id,int $offset=0,int $limit=30): array
    {
        self::find($id);$offset=max(0,$offset);$limit=max(1,min(100,$limit));
        return ['total'=>(int)DB::one('SELECT COUNT(*) n
            FROM release_deliveries
            WHERE release_id=?',[$id])['n'],'offset'=>$offset,'limit'=>$limit,'entries'=>DB::all("SELECT d.*,u.name actor_name FROM release_deliveries d JOIN users u ON u.id=d.actor_id WHERE release_id=? ORDER BY id DESC LIMIT $limit OFFSET $offset",[$id])];
    }
    public static function validateTarget(mixed $value,int $project,?int $self): ?int
    {
        if ($value===null || $value==='') return null;
        if ((!is_int($value) && !is_string($value)) || !ctype_digit((string)$value) || (int)$value<1) throw new \InvalidArgumentException('Version cible invalide.');
        $release=DB::one('SELECT id,project_id,archived FROM releases WHERE id=?',[(int)$value]);
        if (!$release) throw new \InvalidArgumentException('Version cible introuvable.');
        $previous=$self?DB::one('SELECT release_id FROM tickets WHERE id=?',[$self])['release_id']??null:null;
        if ((int)$release['project_id']!==$project || ($release['archived'] && (int)$previous!==(int)$value)) throw new \InvalidArgumentException('La version cible doit appartenir au projet et être active.');
        return (int)$value;
    }
}
