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
        $events=DevelopmentEvent::withTickets(DB::all("SELECT e.*,JSON_REMOVE(e.metadata_json,'$.details.files','$.details.commit_shas') AS metadata_json,c.repository,c.provider FROM development_events e JOIN connections c ON c.id=e.connection_id JOIN development_event_tickets l ON l.event_id=e.id JOIN project_connections pc ON pc.connection_id=c.id AND pc.project_id=? WHERE l.ticket_id=? ORDER BY e.occurred_at DESC,e.id DESC LIMIT 100",[$project['id'],$ticket['id']]),(int)$project['id']);
        $developmentCounts=array_column(DB::all('SELECT e.kind,COUNT(*) n FROM development_events e JOIN development_event_tickets l ON l.event_id=e.id JOIN project_connections pc ON pc.connection_id=e.connection_id WHERE pc.project_id=? AND l.ticket_id=? GROUP BY e.kind',[$project['id'],$ticket['id']]),'n','kind');
        return compact('ticketRepositories','primaryRepository','ticketBranches','events','developmentCounts');
    }
}
