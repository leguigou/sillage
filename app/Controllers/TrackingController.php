<?php
declare(strict_types=1);
namespace App\Controllers;
use App\Core\Auth;
use App\Models\{TicketTime,TicketClosure,Release};

/**
 * Delivery tracking actions: time spent, closure validation and releases.
 *
 * - POST /tickets/{id}/time: add, edit or delete a time entry (App\Models\TicketTime).
 * - POST /tickets/{id}/validation: approve or reject a ticket awaiting
 *   validation (App\Models\TicketClosure).
 * - POST /releases: create/update a release (version); POST
 *   /releases/{id}/deliveries: record its deployment to an environment with
 *   an outcome and a Git/build reference (App\Models\Release).
 * Editors only (read-only accounts are refused). The models handle validation
 * and optimistic locking through the posted "version".
 */
final class TrackingController
{
    /** JSON answer for AJAX callers (Accept: application/json), otherwise flash + redirect. */
    private function reply(string $path,array $result): void
    {
        if (str_contains($_SERVER['HTTP_ACCEPT']??'','application/json')) json_response(['ok'=>true]+$result);
        flash('Enregistré.');redirect($path);
    }
    public function time(string $id): void
    {
        $user=Auth::editor();$entry=empty($_POST['entry_id'])?null:(int)$_POST['entry_id'];
        if (!in_array($_POST['action']??'save',['save','delete'],true)) throw new \InvalidArgumentException('Action de saisie de temps invalide.');
        $result=TicketTime::save((int)$id,$_POST,(int)$user['id'],(int)($_POST['version']??0),$entry,($_POST['action']??'save')==='delete');
        $this->reply(ticket_url((int)$id).'#time',$result);
    }
    public function review(string $id): void
    {
        $user=Auth::editor();$action=$_POST['decision']??'';
        if (!in_array($action,['approved','rejected'],true)) throw new \InvalidArgumentException('Décision de validation invalide.');
        $ticket=TicketClosure::review((int)$id,$action==='approved',(string)($_POST['note']??''),(int)$user['id'],(int)($_POST['version']??0));
        $this->reply(ticket_url((int)$id),['ticket'=>$ticket]);
    }
    public function release(): void
    {
        $user=Auth::editor();$id=empty($_POST['id'])?null:(int)$_POST['id'];
        $release=Release::save((int)($_POST['project_id']??0),$_POST,(int)$user['id'],$id);
        $this->reply('/releases?project='.$release['project_id'].'&release='.$release['id'],['release'=>$release]);
    }
    public function delivery(string $id): void
    {
        $user=Auth::editor();$release=Release::deliver((int)$id,$_POST,(int)$user['id'],(int)($_POST['version']??0));
        $this->reply('/releases?project='.$release['project_id'].'&release='.$release['id'],['release'=>$release]);
    }
}
