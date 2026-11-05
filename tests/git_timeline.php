<?php
use App\Services\{TicketDevelopment,DevelopmentPayload,DevelopmentRunPayload};
$gtCommit=DevelopmentPayload::object('github','commit',['sha'=>str_repeat('a',40),'commit'=>['message'=>'Correction du flux','author'=>['name'=>'Auteur Git','date'=>'2026-11-04T10:00:00Z']]],'demo/timeline');$gtCommit['id']=1;
$gtPR=DevelopmentPayload::object('github','pull_request',['number'=>18,'title'=>'Corriger le flux','state'=>'closed','merged_at'=>'2026-11-05T09:00:00Z','updated_at'=>'2026-11-05T09:00:00Z','head'=>['ref'=>'feature/WEB-18','sha'=>str_repeat('a',40),'repo'=>['full_name'=>'demo/timeline']],'base'=>['ref'=>'staging','sha'=>str_repeat('b',40)],'merge_commit_sha'=>str_repeat('c',40)],'demo/timeline');$gtPR['id']=2;
$gtUnknown=DevelopmentPayload::object('github','branch',['name'=>'feature/WEB-18','commit'=>['sha'=>str_repeat('a',40)]],'demo/timeline');$gtUnknown['id']=3;
$gtDays=TicketDevelopment::timeline([$gtUnknown,$gtCommit,$gtPR]);
check(array_keys($gtDays)===['2026-11-05','2026-11-04','unknown'],'chronologie Git par jour décroissant avec dates inconnues séparées');
$gtSame=$gtPR;$gtSame['id']=4;$gtDays=TicketDevelopment::timeline([$gtPR,$gtSame]);check(array_column($gtDays['2026-11-05'],'id')===[4,2],'ordre stable des observations Git à la même date');
check(TicketDevelopment::presentation($gtPR)['icon']==='git-merge' && TicketDevelopment::presentation($gtPR)['tone']!=='success','fusion de PR distinguée du commit et sans couleur de livraison réussie');
check(TicketDevelopment::presentation($gtCommit)['icon']==='git-commit' && TicketDevelopment::presentation($gtUnknown)['icon']==='git-branch','repères distincts pour commit et branche');
$gtPipeline=DevelopmentRunPayload::object('github','workflow',['id'=>12,'head_sha'=>str_repeat('a',40),'name'=>'Tests PHP','conclusion'=>'failure','updated_at'=>'2026-11-05T10:00:00Z'],'demo/timeline');check(TicketDevelopment::presentation($gtPipeline)['tone']==='failure','échec de pipeline mis en évidence sans modifier le ticket');
