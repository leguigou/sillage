<?php
declare(strict_types=1);
namespace App\Models;
use App\Core\DB;

/** Personal inbox entries are committed atomically with the corresponding ticket event. */
final class TicketNotification
{
    public static function emit(array $ticket,array $events,?int $actor,array $mentions=[],?int $comment=null,?int $previousAssignee=null): void
    {
        if (!DB::connection()->inTransaction()) throw new \LogicException('Une notification doit appartenir à une transaction métier.');
        $ids=array_unique(array_filter([$ticket['reporter_id'],$ticket['assignee_id'],$previousAssignee,...array_column(DB::all('SELECT user_id FROM ticket_followers WHERE ticket_id=?',[$ticket['id']]),'user_id'),...$mentions]));
        $source=$comment?'comment:'.$comment:implode('+',$events).':'.$ticket['version'];
        foreach($ids as $id) {
            if ((int)$id===$actor || !DB::one('SELECT id FROM users WHERE id=? AND active=1 AND verified_at IS NOT NULL',[$id])) continue;
            DB::run('INSERT IGNORE INTO ticket_notifications(user_id,ticket_id,actor_id,comment_id,source_key,events_json,mentioned) VALUES(?,?,?,?,?,?,?)',[(int)$id,$ticket['id'],$actor,$comment,$source,json_encode($events,JSON_THROW_ON_ERROR),(int)in_array((int)$id,$mentions,true)]);
        }
    }
    public static function unread(int $user): int { return (int)DB::one('SELECT COUNT(*) n FROM ticket_notifications WHERE user_id=? AND read_at IS NULL',[$user])['n']; }
    public static function page(int $user,bool $onlyUnread=false,int $page=1): array
    {
        $where='n.user_id=?'.($onlyUnread?' AND n.read_at IS NULL':'');$size=30;
        $total=(int)DB::one('SELECT COUNT(*) total FROM ticket_notifications n WHERE '.$where,[$user])['total'];
        $page=max(1,min($page,max(1,(int)ceil($total/$size))));
        $rows=DB::all('SELECT n.*,t.title,t.project_id,t.number,p.project_key,p.name project_name,u.name actor_name FROM ticket_notifications n JOIN tickets t ON t.id=n.ticket_id JOIN projects p ON p.id=t.project_id LEFT JOIN users u ON u.id=n.actor_id WHERE '.$where.' ORDER BY n.id DESC LIMIT '.$size.' OFFSET '.(($page-1)*$size),[$user]);
        foreach ($rows as &$row) $row['events']=json_decode($row['events_json'],true,32,JSON_THROW_ON_ERROR);
        return ['rows'=>$rows,'total'=>$total,'page'=>$page,'pages'=>max(1,(int)ceil($total/$size)),'unread'=>self::unread($user)];
    }
    public static function mark(int $id,int $user,bool $read): array
    {
        return DB::transaction(function() use($id,$user,$read) {
            $row=DB::one('SELECT id,ticket_id,comment_id FROM ticket_notifications WHERE id=? AND user_id=? FOR UPDATE',[$id,$user]);
            if (!$row) throw new \InvalidArgumentException('Notification introuvable.');
            DB::run('UPDATE ticket_notifications SET read_at=? WHERE id=? AND user_id=?',[$read?gmdate('Y-m-d H:i:s'):null,$id,$user]);
            return $row+['read'=>$read,'unread'=>self::unread($user)];
        });
    }
    public static function readAll(int $user): void { DB::run('UPDATE ticket_notifications SET read_at=UTC_TIMESTAMP() WHERE user_id=? AND read_at IS NULL',[$user]); }
}
