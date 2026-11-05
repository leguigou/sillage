<?php
use App\Core\{DB,Security};
use App\Models\{Project,Ticket,RepositoryConnection,DevelopmentEvent};
use App\Services\{TicketDevelopment,DevelopmentPayload,DevelopmentRunPayload,Query};
$dbProject=Project::save(['name'=>'Badges Git','key'=>'DG'.strtoupper(bin2hex(random_bytes(3)))]);
$dbConnection=DB::insert('INSERT INTO connections(project_id,provider,repository,secret_cipher,webhook_cipher) VALUES(?,?,?,?,?)',[$dbProject,'github','demo/badges',Security::encrypt('synthetic-token'),Security::encrypt('synthetic-hook')]);RepositoryConnection::attach($dbProject,$dbConnection);
$dbC=DB::one('SELECT * FROM connections WHERE id=?',[$dbConnection]);$dbNumber=8000;
function badgeEvent(array $connection,int $ticket,string $kind,string $state,string $target,string $date,int $number): int {
    $t=Ticket::find($ticket);$sha=str_repeat('a',40);
    if($kind==='deployment') $event=DevelopmentRunPayload::object('github','deployment',['id'=>$number,'environment'=>$target,'sha'=>$sha,'ref'=>'feature/'.$t['key'],'created_at'=>$date],$connection['repository'],['state'=>$state,'updated_at'=>$date]);
    else $event=DevelopmentPayload::object('github','pull_request',['number'=>$number,'title'=>$t['key'].' Correction','state'=>$state==='merged'?'closed':$state,'merged_at'=>$state==='merged'?$date:null,'updated_at'=>$date,'head'=>['ref'=>'feature/'.$t['key'],'sha'=>$sha,'repo'=>['full_name'=>$connection['repository']]],'base'=>['ref'=>$target,'sha'=>str_repeat('b',40)]],$connection['repository']);
    return DevelopmentEvent::save($connection,$event,false);
}
$dbCases=[['deployment','success','production','En production','success'],['deployment','success','staging','À tester','testing'],['pull_request','merged','production','Fusionné sur production','info'],['pull_request','open','staging','PR ouverte','review'],['deployment','failure','staging','Déploiement échoué','failure'],['deployment','pending','production','Déploiement en attente','info'],['deployment','success','sandbox','Déployé','info']];
$dbTickets=[];
foreach($dbCases as [$kind,$state,$target,$label,$tone]) {
    $id=Ticket::create($dbProject,['title'=>$label],$uid);$dbTickets[]=$id;$before=Ticket::find($id)['version'];badgeEvent($dbC,$id,$kind,$state,$target,'2026-11-05T10:00:00Z',++$dbNumber);
    $badge=TicketDevelopment::badges([Ticket::find($id)])[$id];check($badge['label']===$label && $badge['tone']===$tone,'badge Git fondé sur événement '.$label);check(Ticket::find($id)['version']===$before,'calcul de badge sans modification métier');
}
$dbFirst=$dbTickets[0];badgeEvent($dbC,$dbFirst,'pull_request','open','staging','2026-11-05T11:00:00Z',++$dbNumber);
check(TicketDevelopment::badges([Ticket::find($dbFirst)])[$dbFirst]['label']==='PR ouverte','nouvelle PR après livraison remplace ancien badge vert');
$dbEmpty=Ticket::create($dbProject,['title'=>'Sans observation'],$uid);check(TicketDevelopment::badges([Ticket::find($dbEmpty)])[$dbEmpty]===null,'dépôt connecté seul ne crée pas de badge de livraison');
$dbRows=Query::tickets($dbProject,[],50);$dbBadges=TicketDevelopment::badges($dbRows);check(count($dbBadges)===count($dbRows) && $dbBadges[$dbTickets[1]]['label']==='À tester','lecture groupée conserve un résultat par ticket');
$dbUnknown=Ticket::create($dbProject,['title'=>'Date inconnue'],$uid);$dbUnknownEvent=badgeEvent($dbC,$dbUnknown,'deployment','success','production','2026-11-05T10:00:00Z',++$dbNumber);$dbUnknownMeta=json_decode(DB::one('SELECT metadata_json FROM development_events WHERE id=?',[$dbUnknownEvent])['metadata_json'],true);$dbUnknownMeta['date_known']=false;DB::run('UPDATE development_events SET metadata_json=? WHERE id=?',[json_encode($dbUnknownMeta),$dbUnknownEvent]);
check(TicketDevelopment::badges([Ticket::find($dbUnknown)])[$dbUnknown]['label']==='Suivi à confirmer','date inconnue ne présente pas un état vert comme dernier jalon fiable');
$dbConflict=Ticket::find($dbFirst);$dbConflict['has_merge_conflict']=true;check(TicketDevelopment::badges([$dbConflict])[$dbFirst]['label']==='Conflit de fusion','conflit déjà confirmé par propriétaire du suivi prioritaire');
$dbOther=Project::save(['name'=>'Dépôt non lié','key'=>'DX'.strtoupper(bin2hex(random_bytes(3)))]);$dbForeign=Ticket::create($dbOther,['title'=>'Sans accès au dépôt'],$uid);$dbKnown=DB::one('SELECT id FROM development_events WHERE connection_id=? LIMIT 1',[$dbConnection]);DB::run('INSERT INTO development_event_tickets(event_id,ticket_id) VALUES(?,?)',[$dbKnown['id'],$dbForeign]);check(TicketDevelopment::badges([Ticket::find($dbForeign)])[$dbForeign]===null,'observation de dépôt hors projet exclue du badge');
