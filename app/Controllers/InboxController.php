<?php
declare(strict_types=1);
namespace App\Controllers;
use App\Core\{Auth,View};
use App\Models\{Project,TicketFollow,TicketNotification};
/**
 * The signed-in user's notification inbox and ticket following.
 *
 * - GET /notifications: paginated inbox page (optional ?unread=1 filter).
 * - GET /notifications/count: unread count as JSON (e.g. for a badge).
 * - POST /notifications/{id}/state: mark read/unread, or "open" (mark read
 *   then redirect to the ticket/comment).
 * - POST /notifications/read-all, POST /tickets/{id}/follow (follow/unfollow).
 * Any signed-in user, including read-only accounts; every query is scoped to
 * the current user's own notifications (App\Models\TicketNotification).
 */
final class InboxController
{
    public function index(): void
    {
        $user=Auth::requireUser();$projects=Project::all();
        // Project context for the page layout: ?project, else last used, else first;
        // a placeholder named after the company when no project exists yet.
        $project=$projects?Project::find((int)($_GET['project']??$_SESSION['project_id']??$projects[0]['id'])):['id'=>0,'name'=>\App\Core\Company::name(),'project_key'=>''];
        $onlyUnread=($_GET['unread']??'')==='1';$inbox=TicketNotification::page((int)$user['id'],$onlyUnread,max(1,(int)($_GET['page']??1)));
        View::render('inbox',compact('user','projects','project','onlyUnread','inbox')+['title'=>'Notifications','section'=>'notifications','members'=>Project::members()]);
    }
    /** Changes the read state of one notification (action: read, unread or open). */
    public function read(string $id): void
    {
        $user=Auth::requireUser();$action=$_POST['action']??'';
        if (!in_array($action,['read','unread','open'],true)) throw new \InvalidArgumentException('Action de notification invalide.');
        $row=TicketNotification::mark((int)$id,(int)$user['id'],$action!=='unread');
        if ($action==='open') redirect(ticket_url((int)$row['ticket_id'],$row['comment_id']?'#comment-'.$row['comment_id']:''));
        if (str_contains($_SERVER['HTTP_ACCEPT']??'','application/json')) json_response($row);
        redirect('/notifications');
    }
    public function readAll(): void
    {
        $user=Auth::requireUser();TicketNotification::readAll((int)$user['id']);
        if (str_contains($_SERVER['HTTP_ACCEPT']??'','application/json')) json_response(['unread'=>0]);
        redirect('/notifications');
    }
    public function count(): void { $user=Auth::requireUser();json_response(['unread'=>TicketNotification::unread((int)$user['id'])]); }
    public function follow(string $id): void
    {
        $user=Auth::requireUser();$action=$_POST['action']??'';
        if (!in_array($action,['follow','unfollow'],true)) throw new \InvalidArgumentException('Action de suivi invalide.');
        $result=TicketFollow::set((int)$id,(int)$user['id'],$action==='follow');
        if (str_contains($_SERVER['HTTP_ACCEPT']??'','application/json')) json_response($result);
        redirect(ticket_url((int)$id));
    }
}
