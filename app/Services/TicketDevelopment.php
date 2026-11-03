<?php
declare(strict_types=1);
namespace App\Services;
use App\Core\DB;
use App\Models\{DevelopmentEvent,RepositoryConnection,TicketBranch};

/** Read model shared by the ticket page and its in-place development refresh. */
final class TicketDevelopment
{
    public static function data(array $ticket,array $project): array
    {
        $ticketRepositories=RepositoryConnection::forProject((int)$project['id']);$primaryRepository=null;
        foreach($ticketRepositories as $repository) if($repository['id']===$project['repository_connection_id']) $primaryRepository=$repository;
        $ticketBranches=TicketBranch::all((int)$ticket['id']);
        $events=DevelopmentEvent::withTickets(DB::all('SELECT '.DevelopmentEvent::summaryColumns('e').',c.repository,c.provider FROM (SELECT d.*,ROW_NUMBER() OVER(PARTITION BY d.kind ORDER BY d.occurred_at DESC,d.id DESC) AS kind_rank FROM development_events d JOIN development_event_tickets l ON l.event_id=d.id JOIN project_connections pc ON pc.connection_id=d.connection_id AND pc.project_id=? WHERE l.ticket_id=?) e JOIN connections c ON c.id=e.connection_id WHERE e.kind_rank<=20 ORDER BY e.occurred_at DESC,e.id DESC',[$project['id'],$ticket['id']]),(int)$project['id']);
        $developmentCounts=array_column(DB::all('SELECT e.kind,COUNT(*) n FROM development_events e JOIN development_event_tickets l ON l.event_id=e.id JOIN project_connections pc ON pc.connection_id=e.connection_id WHERE pc.project_id=? AND l.ticket_id=? GROUP BY e.kind',[$project['id'],$ticket['id']]),'n','kind');
        return compact('ticketRepositories','primaryRepository','ticketBranches','events','developmentCounts');
    }
}
