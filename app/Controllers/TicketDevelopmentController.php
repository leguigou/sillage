<?php
declare(strict_types=1);
namespace App\Controllers;
use App\Core\{Auth,Security};
use App\Models\{Ticket,TicketBranch,Project};
use App\Services\{Development,DevelopmentPayload};

final class TicketDevelopmentController
{
    public function panel(string $id): void
    {
        $user=Auth::requireUser();$ticket=Ticket::find((int)$id);$project=Project::find((int)$ticket['project_id']);
        extract(\App\Services\TicketDevelopment::data($ticket,$project));
        require_once ROOT.'/app/Views/icons.php';
        header('Content-Type: text/html; charset=utf-8');header('Cache-Control: no-store');
        require ROOT.'/app/Views/partials/ticket-development.php';
    }
    private function connection(string $ticket): array
    {
        $user=Auth::editor();Ticket::find((int)$ticket);
        Security::limit('ticket-git:'.$user['id'],60,60);
        $id=filter_var($_POST['connection_id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
        if ($id===false || $id===null) throw new \InvalidArgumentException('Choisissez un dépôt du projet.');
        return [$user,TicketBranch::connection((int)$ticket,$id)];
    }
    public function branches(string $ticket): void
    {
        [, $connection]=$this->connection($ticket);
        $page=filter_var($_POST['page']??1,FILTER_VALIDATE_INT);
        if ($page===false) throw new \InvalidArgumentException('Page de branches invalide.');
        $search=$_POST['search']??'';if(!is_string($search)) throw new \InvalidArgumentException('Recherche de branches invalide.');
        json_response(\App\Services\GitBranchCatalog::page($connection,$page,$search,($_POST['refresh']??'')==='1'));
    }
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
    private function historyEditor(string $ticket): void
    {
        $user=Auth::editor();Ticket::find((int)$ticket);Security::limit('ticket-git:'.$user['id'],60,60);
    }
    public function syncBranch(string $ticket,string $link): void
    {
        $this->historyEditor($ticket);
        $page=filter_var($_POST['page']??1,FILTER_VALIDATE_INT);
        if($page===false) throw new \InvalidArgumentException('Page de pull requests invalide.');
        json_response(\App\Services\GitPullRequest::branch((int)$ticket,(int)$link,$page));
    }
    public function syncPullRequest(string $ticket,string $event): void
    {
        $this->historyEditor($ticket);
        json_response(\App\Services\GitPullRequest::sync((int)$ticket,(int)$event));
    }
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
}
