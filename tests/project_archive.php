<?php
// Archived projects: hidden from Project::all() and "all projects" lists, data kept, reversible.
use App\Models\{Project,Ticket};
use App\Services\Query;
use App\Core\DB;

$archivePa=Project::save(['name'=>'Projet à archiver','key'=>'AR'.strtoupper(bin2hex(random_bytes(3)))]);
$archivePb=Project::save(['name'=>'Projet qui reste actif','key'=>'AS'.strtoupper(bin2hex(random_bytes(3)))]);
$archiveMarker='Archive-'.bin2hex(random_bytes(4));
$archiveTicket=Ticket::create($archivePa,['title'=>$archiveMarker],$uid);
Ticket::create($archivePb,['title'=>$archiveMarker],$uid);
check(Query::count(0,['q'=>$archiveMarker])===2,'tickets des deux projets visibles dans « Tous les projets » avant archivage');
$archived=Project::setArchived($archivePa,true);
$firstArchivedAt=$archived['archived_at'];
check($firstArchivedAt!==null,'projet archivé horodaté');
check(!in_array($archivePa,array_map('intval',array_column(Project::all(),'id')),true),'projet archivé absent des menus et sélecteurs');
check(in_array($archivePa,array_map('intval',array_column(Project::archived(),'id')),true),'projet archivé listé parmi les archives');
check(Query::count(0,['q'=>$archiveMarker])===1,'tickets du projet archivé exclus de « Tous les projets »');
check(Query::count($archivePa,['q'=>$archiveMarker])===1 && Ticket::find($archiveTicket)['title']===$archiveMarker,'données du projet archivé conservées et consultables');
check(Project::setArchived($archivePa,true)['archived_at']===$firstArchivedAt,'archivage répété idempotent');
Project::save(['name'=>'Projet archivé renommé'],$archivePa);
check(Project::find($archivePa)['archived_at']===$firstArchivedAt,'modification du projet conserve son archivage');
check(Project::setArchived($archivePa,false)['archived_at']===null,'projet réactivé');
check(in_array($archivePa,array_map('intval',array_column(Project::all(),'id')),true) && Query::count(0,['q'=>$archiveMarker])===2,'projet réactivé de nouveau proposé partout');
rejects(fn()=>Project::setArchived(PHP_INT_MAX,true),'archivage d’un projet inconnu refusé');
// Last active project: simulate every other project archived, inside a transaction rolled back afterwards.
DB::connection()->beginTransaction();
try {
    DB::run('UPDATE projects SET archived_at=UTC_TIMESTAMP() WHERE archived_at IS NULL AND id<>?',[$archivePb]);
    rejects(fn()=>Project::setArchived($archivePb,true),'dernier projet actif impossible à archiver');
} finally {
    DB::connection()->rollBack();
}
check(Project::find($archivePb)['archived_at']===null && count(Project::all())>1,'simulation du dernier projet annulée');
