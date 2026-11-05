<?php
declare(strict_types=1);
namespace App\Services;
use App\Core\DB;
use App\Models\{DevelopmentEvent,RepositoryConnection,TicketBranch};

/** Read model shared by the ticket page and its in-place development refresh. */
final class TicketDevelopment
{
    /** Latest observed Git milestone per ticket; no provider call and no inferred deployment. */
    public static function badges(array $tickets): array
    {
        if(!$tickets) return [];
        $ids=array_column($tickets,'id');$events=[];$result=[];
        $rows=DB::all('SELECT '.DevelopmentEvent::summaryColumns('e').',r.linked_ticket,c.repository,c.provider FROM (SELECT e.id,l.ticket_id linked_ticket,ROW_NUMBER() OVER(PARTITION BY l.ticket_id,e.kind ORDER BY e.occurred_at DESC,e.id DESC) position_in_kind FROM development_events e JOIN development_event_tickets l ON l.event_id=e.id JOIN tickets t ON t.id=l.ticket_id JOIN project_connections pc ON pc.project_id=t.project_id AND pc.connection_id=e.connection_id WHERE l.ticket_id IN ('.implode(',',array_fill(0,count($ids),'?')).') AND e.kind IN (\'pull_request\',\'deployment\')) r JOIN development_events e ON e.id=r.id JOIN connections c ON c.id=e.connection_id WHERE r.position_in_kind=1 ORDER BY e.occurred_at DESC,e.id DESC',$ids);
        foreach($rows as $row) $events[$row['linked_ticket']][]=DevelopmentEvent::hydrate($row);
        foreach($tickets as $ticket) {
            if(!empty($ticket['has_merge_conflict'])) $badge=['tone'=>'warning','label'=>'Conflit de fusion','detail'=>'Intervention requise','icon'=>'alert','title'=>'Conflit confirmé dans une pull request liée.'];
            elseif(!empty($events[$ticket['id']])) $badge=self::eventBadge($events[$ticket['id']][0]);
            elseif(!empty($ticket['branch_providers'])) $badge=['tone'=>'neutral','label'=>'Branche liée','detail'=>'Aucune PR reçue','icon'=>'code','title'=>'Une branche est liée au ticket ; aucune PR ni livraison reçue.'];
            else $badge=null;
            $result[$ticket['id']]=$badge;
        }
        return $result;
    }
    private static function eventBadge(array $event): array
    {
        $meta=$event['metadata'];$state=$event['state'];$environment=$meta['environment']??null;
        $badge=['tone'=>'neutral','label'=>'Suivi Git','detail'=>'État inconnu','icon'=>'code'];
        if($event['kind']==='deployment') {
            $env=$environment?:'Environnement non fourni';
            $badge['detail']=$env;
            if($state==='success') {
                $key=mb_strtolower(trim($env));
                $production=in_array($key,['prod','production'],true);
                $staging=in_array($key,['staging','stage','test','testing','recette','preprod','préproduction','preproduction'],true);
                $badge['tone']=$production?'success':($staging?'testing':'info');
                $badge['label']=$production?'En production':($staging?'À tester':'Déployé');$badge['icon']=$staging?'flask':'check';$badge['detail'].=' · déploiement réussi';
            } elseif($state==='failure') {$badge['tone']='failure';$badge['label']='Déploiement échoué';$badge['icon']='close';}
            elseif(in_array($state,['running','pending','waiting'],true)) {$badge['tone']='info';$badge['label']=$state==='running'?'Déploiement en cours':'Déploiement en attente';$badge['icon']='clock';}
            elseif($state==='cancelled') $badge['label']='Déploiement annulé';
            else $badge['label']='Déploiement à confirmer';
        } else {
            $target=$meta['target_branch']??null;$number=str_starts_with($event['external_key'],'pr:')?substr($event['external_key'],3):'';
            $badge['detail']=($number?'PR #'.$number:'Pull request').($target?' · vers '.$target:'');
            if($state==='open') {$badge['tone']='review';$badge['label']='PR ouverte';}
            elseif($state==='merged') {$badge['tone']='info';$badge['label']=$target?'Fusionné sur '.$target:'PR fusionnée';$badge['detail']='Déploiement non confirmé';}
            else $badge['label']='PR fermée';
        }
        if(($meta['date_known']??true)===false) {$badge['tone']='neutral';$badge['label']='Suivi à confirmer';$badge['detail']='Date de l’événement non fournie';}
        $badge['title']=$event['repository'].' · '.$badge['label'].' · '.$badge['detail'].(($meta['date_known']??true)?' · '.$event['occurred_at'].' UTC':'');
        return $badge;
    }
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
