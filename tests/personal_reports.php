<?php
use App\Core\{View,DB};
use App\Models\{SavedView,Project,Ticket};
use App\Services\{ListLayout,Query};
check(View::hours(null)==='—' && View::hours(0)==='0 min','absence de durée distincte de zéro');
check(View::hours('0.70')==='42 min' && View::hours('1.7')==='1 h 42 min','durées décimales converties en heures et minutes');
check(View::hours('1.999')==='2 h' && View::hours('0.001')==='< 1 min','arrondi des minutes sans soixante minutes affichées');
$rpProject=Project::save(['name'=>'Rapports personnels','key'=>'RP'.strtoupper(bin2hex(random_bytes(3)))]);
$rpId=SavedView::save($uid,$rpProject,'Mes priorités',['q'=>'important','sort'=>'created','direction'=>'desc','columns'=>['created','title','priority'],'show_progress'=>'1']);
$rpRow=DB::one('SELECT * FROM saved_views WHERE id=?',[$rpId]);$rpSettings=json_decode($rpRow['settings'],true,64,JSON_THROW_ON_ERROR);
check($rpSettings['columns']===['created','title','priority'] && $rpSettings['direction']==='desc','rapport personnel conserve ordre des colonnes et tri de création');
$rpRevision=SavedView::revision($rpRow);
SavedView::save($uid,$rpProject,'Mes urgences',['priority'=>'high','sort'=>'created','direction'=>'asc','columns'=>['title','created']],$rpId,$rpRevision);
check(DB::one('SELECT name FROM saved_views WHERE id=?',[$rpId])['name']==='Mes urgences','rapport existant modifiable par son propriétaire');
rejects(fn()=>SavedView::save($uid,$rpProject,'Écrasement',[], $rpId,$rpRevision),'modification depuis un brouillon de rapport périmé refusée');
rejects(fn()=>SavedView::save($uid+1000000,$rpProject,'Autre compte',[],$rpId,$rpRevision),'rapport non modifiable depuis un autre compte');
foreach([['columns'=>['title','unknown']],['columns'=>['title','title']],['columns'=>['created']],['sort'=>'created;DROP TABLE tickets'],['direction'=>'invalid'],['group'=>'unknown'],['q'=>['nested']],['status'=>['nested'=>'todo']]] as $rpInvalid) rejects(fn()=>SavedView::save($uid,$rpProject,'Invalide',$rpInvalid),'configuration de rapport invalide refusée');
$rpOld=Ticket::create($rpProject,['title'=>'Ancien'],$uid);$rpNew=Ticket::create($rpProject,['title'=>'Nouveau'],$uid);
DB::run("UPDATE tickets SET created_at='2026-01-01 10:00:00' WHERE id=?",[$rpOld]);DB::run("UPDATE tickets SET created_at='2026-02-01 10:00:00' WHERE id=?",[$rpNew]);
check(array_column(Query::tickets($rpProject,['sort'=>'created','direction'=>'desc']),'id')===[$rpNew,$rpOld],'tri de création décroissant du plus récent au plus ancien');
check(array_column(Query::tickets($rpProject,['sort'=>'created','direction'=>'asc']),'id')===[$rpOld,$rpNew],'tri de création croissant du plus ancien au plus récent');
SavedView::delete($rpId,$uid);check(DB::one('SELECT id FROM saved_views WHERE id=?',[$rpId])===null,'suppression personnelle sans supprimer les tickets');

check(ListLayout::settings($rpProject,['show_progress'=>false,'hierarchy'=>true])===['show_progress'=>'0','hierarchy'=>'1'],'options de rapport booléennes normalisées une seule fois');
