<?php
declare(strict_types=1);
namespace App\Models;
use App\Core\DB;
final class TicketFollow
{
    public static function get(int $ticket,int $user): array
    {
        return ['following'=>(bool)DB::one('SELECT user_id FROM ticket_followers WHERE ticket_id=? AND user_id=?',[$ticket,$user]),'count'=>(int)DB::one('SELECT COUNT(*) n FROM ticket_followers f JOIN users u ON u.id=f.user_id WHERE f.ticket_id=? AND u.active=1 AND u.verified_at IS NOT NULL',[$ticket])['n']];
    }
    public static function set(int $ticket,int $user,bool $follow): array
    {
        return DB::transaction(function() use($ticket,$user,$follow) {
            Ticket::find($ticket);
            if (!DB::one('SELECT id FROM users WHERE id=? AND active=1 AND verified_at IS NOT NULL',[$user])) throw new \InvalidArgumentException('Compte indisponible.');
            if ($follow) DB::run('INSERT IGNORE INTO ticket_followers(ticket_id,user_id) VALUES(?,?)',[$ticket,$user]);
            else DB::run('DELETE FROM ticket_followers WHERE ticket_id=? AND user_id=?',[$ticket,$user]);
            return self::get($ticket,$user);
        });
    }
}
