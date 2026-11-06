<?php
declare(strict_types=1);
namespace App\Controllers;
use App\Core\{Auth,Security};
use App\Models\{Ticket,TicketBranch,Project};
use App\Services\{Development,DevelopmentPayload};

/**
 * The "development" panel of a ticket: Git branches and pull requests linked
 * to it (all URLs under /tickets/{id}/development).
 *
 * - GET  (panel), GET .../pull-requests/{event}/files: any signed-in user.
 * - POST .../branches (browse branches), .../link, .../links/{link}/delete,
 *   .../links/{link}/sync, .../pull-requests/{event}/sync: editors only,
 *   rate-limited per user. The repository must be linked to the ticket's project.
 * - GET/POST .../pull-requests/{event}/test-procedure: editors; open the form
 *   and publish a test procedure comment for a PR (App\Models\GitConversation).
 * Answers are JSON, except the panel which returns an HTML fragment.
 */
final class TicketDevelopmentController
{
    /** Renders the development panel HTML fragment (loaded asynchronously by the ticket page). */
    public function panel(string $id): void
    {
        $user=Auth::requireUser();$ticket=Ticket::find((int)$id);$project=Project::find((int)$ticket['project_id']);
        extract(\App\Services\TicketDevelopment::data($ticket,$project));
        require_once ROOT.'/app/Views/icons.php';
        header('Content-Type: text/html; charset=utf-8');header('Cache-Control: no-store');
        require ROOT.'/app/Views/partials/ticket-development.php';
    }
    /**
     * Shared guard for branch actions: editor role, existing ticket, rate limit,
     * and a posted connection_id that belongs to the ticket's project.
     * Returns [user, connection].
     */
    private function connection(string $ticket): array
    {
        $user=Auth::editor();Ticket::find((int)$ticket);
        Security::limit('ticket-git:'.$user['id'],60,60);
        $id=filter_var($_POST['connection_id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
        if ($id===false || $id===null) throw new \InvalidArgumentException('Choisissez un dépôt du projet.');
        return [$user,TicketBranch::connection((int)$ticket,$id)];
    }
    /** Paginated, searchable list of the repository's branches (refresh=1 reloads from the provider). */
    public function branches(string $ticket): void
    {
        [, $connection]=$this->connection($ticket);
        $page=filter_var($_POST['page']??1,FILTER_VALIDATE_INT);
        if ($page===false) throw new \InvalidArgumentException('Page de branches invalide.');
        $search=$_POST['search']??'';if(!is_string($search)) throw new \InvalidArgumentException('Recherche de branches invalide.');
        json_response(\App\Services\GitBranchCatalog::page($connection,$page,$search,($_POST['refresh']??'')==='1'));
    }
    /** Links an existing branch (checked with the provider) to the ticket, then queues a repository sync. */
    public function link(string $ticket): void
    {
        [$user,$connection]=$this->connection($ticket);
        $name=DevelopmentPayload::branchName($_POST['branch_name']??null);
        $event=Development::branch($connection,$name);
        $id=TicketBranch::link((int)$ticket,$connection,$event,(int)$user['id']);
        \App\Services\GitSync::request((int)$connection['id']);
        json_response(['ok'=>true,'id'=>$id]);
    }
    public function unlink(string $ticket,string $link): void
    {
        Auth::editor();Ticket::find((int)$ticket);
        TicketBranch::unlink((int)$ticket,(int)$link);
        json_response(['ok'=>true]);
    }
    /** Guard for history sync actions: editor role, existing ticket, per-user rate limit. */
    private function historyEditor(string $ticket): void
    {
        $user=Auth::editor();Ticket::find((int)$ticket);Security::limit('ticket-git:'.$user['id'],60,60);
    }
    /** Fetches one page of pull requests for the linked branch {link}. */
    public function syncBranch(string $ticket,string $link): void
    {
        $this->historyEditor($ticket);
        $page=filter_var($_POST['page']??1,FILTER_VALIDATE_INT);
        if($page===false) throw new \InvalidArgumentException('Page de pull requests invalide.');
        json_response(\App\Services\GitPullRequest::branch((int)$ticket,(int)$link,$page));
    }
    /** Refreshes the details of pull request {event} linked to the ticket. */
    public function syncPullRequest(string $ticket,string $event): void
    {
        $this->historyEditor($ticket);
        json_response(\App\Services\GitPullRequest::sync((int)$ticket,(int)$event));
    }
    /**
     * Pages (40 per page) through the changed files of a PR, from the details
     * stored by the last sync; "stale" is true if the PR changed since then.
     */
    public function pullRequestFiles(string $ticket,string $event): void
    {
        Auth::requireUser();Ticket::find((int)$ticket);
        $row=\App\Services\GitPullRequest::eventForTicket((int)$ticket,(int)$event);$details=$row['metadata']['details']??null;
        if(!$details) throw new \InvalidArgumentException('Synchronisez les détails de cette pull request pour consulter ses fichiers.');
        $page=filter_var($_GET['page']??1,FILTER_VALIDATE_INT);
        if($page===false || $page<1 || $page>10000) throw new \InvalidArgumentException('Page de fichiers invalide.');
        $files=$details['files'];unset($details['files'],$details['commit_shas']);
        json_response(['title'=>$row['title'],'details'=>$details,'stale'=>$details['revision']!==\App\Services\GitPullRequest::revision($row),'files'=>array_slice($files,($page-1)*40,40),'page'=>$page,'has_more'=>$page*40<count($files)]);
    }
    /**
     * Opens the test procedure form for a PR: returns its title and a fresh
     * submission_key that makes a resubmitted form idempotent.
     */
    public function testProcedure(string $ticket,string $event): void
    {
        Auth::editor();
        $pr=\App\Models\GitConversation::context((int)$ticket,(int)$event);
        header('Cache-Control: no-store');
        json_response(['title'=>$pr['title'],'submission_key'=>bin2hex(random_bytes(16))]);
    }
    /** Publishes the test procedure as a ticket comment and returns the rendered comment HTML. */
    public function publishTestProcedure(string $id,string $event): void
    {
        $user=Auth::editor();Security::limit('ticket-procedure:'.$user['id'],30,60);
        $comment=\App\Models\GitConversation::publish((int)$id,(int)$event,$_POST,(int)$user['id']);
        $ticket=Ticket::find((int)$id);$canEdit=true;
        [$c]=\App\Models\GitConversation::comments((int)$id,$comment);
        $count=(int)\App\Core\DB::one('SELECT COUNT(*) n FROM comments WHERE ticket_id=?',[(int)$id])['n'];
        require_once ROOT.'/app/Views/icons.php';ob_start();require ROOT.'/app/Views/partials/ticket-conversation-comment.php';$html=ob_get_clean();
        json_response(['ok'=>true,'id'=>$comment,'count'=>$count,'html'=>$html]);
    }

}
