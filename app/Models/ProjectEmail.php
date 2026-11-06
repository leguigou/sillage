<?php
declare(strict_types=1);
namespace App\Models;
use App\Core\DB;

final class ProjectEmail
{
    public const EVENTS=[
        'created'=>'Création du ticket',
        'updated'=>'Modification du contenu, des dates ou de la priorité',
        'assigned'=>'Changement de responsable ou d’équipe',
        'status'=>'Changement d’état (hors clôture)',
        'completed'=>'Ticket terminé',
        'commented'=>'Nouveau commentaire',
        'archived'=>'Archivage ou restauration',
    ];
    public const RECIPIENTS=[
        'reporter'=>'Demandeur interne',
        'assignee'=>'Responsable individuel',
        'team'=>'Équipe responsable',
        'project_teams'=>'Toutes les équipes du projet',
        'admins'=>'Administrateurs',
        'selected'=>'Personnes choisies',
        'followers'=>'Personnes qui suivent le ticket',
        'mentioned'=>'Personnes mentionnées (commentaires)',
    ];
    public static function defaults(): array
    {
        return ['created'=>['assignee','team'],'updated'=>['followers'],'assigned'=>['assignee','team','followers'],'status'=>['reporter','assignee','followers'],'completed'=>['reporter','assignee','team','followers'],'commented'=>['reporter','assignee','followers','mentioned'],'archived'=>['followers']];
    }
    public static function get(int $project): array
    {
        $row=DB::one('SELECT * FROM project_email_settings WHERE project_id=?',[$project]);
        if (!$row) return ['project_id'=>$project,'enabled'=>false,'notify_actor'=>false,'rules'=>self::defaults(),'recipients'=>[],'version'=>0];
        $row['rules']=json_decode($row['rules_json'],true,32,JSON_THROW_ON_ERROR);
        $row['recipients']=json_decode($row['recipients_json'],true,32,JSON_THROW_ON_ERROR);
        unset($row['rules_json'],$row['recipients_json']);return $row;
    }
    public static function save(int $project,array $input): void
    {
        DB::transaction(function() use($project,$input) {
            if (!DB::one('SELECT id FROM projects WHERE id=? FOR UPDATE',[$project])) throw new \InvalidArgumentException('Projet introuvable.');
            $old=self::get($project);
            if ((int)($input['version']??-1)!==(int)$old['version']) throw new \InvalidArgumentException('Les notifications du projet ont changé. Rechargez la page.');
            $rules=$input['rules']??[];$selected=$input['recipients']??[];
            if (!is_array($rules) || array_diff(array_keys($rules),array_keys(self::EVENTS)) || !is_array($selected) || count($selected)>200) throw new \InvalidArgumentException('Paramètres de notifications invalides.');
            $normalized=[];
            foreach(self::EVENTS as $event=>$label) {
                $roles=$rules[$event]??[];
                if (!is_array($roles) || array_filter($roles,fn($v)=>!is_string($v) || !isset(self::RECIPIENTS[$v]))) throw new \InvalidArgumentException('Destinataire ou événement de notification invalide.');
                $normalized[$event]=array_values(array_unique($roles));
            }
            $ids=[];
            foreach($selected as $id) {
                if ((!is_int($id) && !is_string($id)) || !ctype_digit((string)$id) || (int)$id<1 || !DB::one('SELECT id
                    FROM users
                    WHERE id=? AND active=1 AND verified_at IS NOT NULL',[(int)$id])) throw new \InvalidArgumentException('Choisissez des comptes actifs et vérifiés pour les notifications.');
                $ids[]=(int)$id;
            }
            $ids=array_values(array_unique($ids));sort($ids);
            DB::run('INSERT INTO project_email_settings(project_id,enabled,notify_actor,rules_json,recipients_json)
                VALUES(?,?,?,?,?)
                ON DUPLICATE KEY UPDATE enabled=VALUES(enabled),notify_actor=VALUES(notify_actor),rules_json=VALUES(rules_json),recipients_json=VALUES(recipients_json),version=version+1',[$project,(int)!empty($input['enabled']),(int)!empty($input['notify_actor']),json_encode($normalized,JSON_THROW_ON_ERROR),json_encode($ids,JSON_THROW_ON_ERROR)]);
            if (empty($input['enabled'])) DB::run("UPDATE notification_outbox
                SET state='cancelled',lease_token=NULL
                WHERE project_id=? AND state IN ('pending','failed')",[$project]);
        });
    }

    /** Number of queued e-mails per delivery state for a project. */
    public static function queueCounts(int $project): array
    {
        return DB::all(
            'SELECT state,COUNT(*) n
                FROM notification_outbox
                WHERE project_id=?
                GROUP BY state',
            [$project],
        );
    }

    /** The 20 latest e-mails queued for a project, with recipient and ticket number. */
    public static function recentDeliveries(int $project): array
    {
        return DB::all(
            'SELECT n.state,n.attempts,n.created_at,n.sent_at,n.last_error,u.name,t.number
                FROM notification_outbox n
                JOIN users u ON u.id=n.recipient_id
                JOIN tickets t ON t.id=n.ticket_id
                WHERE n.project_id=?
                ORDER BY n.id DESC
                LIMIT 20',
            [$project],
        );
    }
}
