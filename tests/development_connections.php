<?php
use App\Services\Development;
check(Development::repository('github','organisation/projet') === 'organisation/projet','dépôt GitHub sous forme propriétaire/nom');
check(Development::repository('github',' https://github.com/organisation/projet.git/ ') === 'organisation/projet','URL GitHub normalisée avant vérification');
check(Development::repository('github','git@github.com:organisation/projet.git') === 'organisation/projet','URL SSH GitHub acceptée sans lancer Git');
check(Development::repository('bitbucket','https://bitbucket.org/espace/projet/') === 'espace/projet','URL Bitbucket normalisée');
foreach (['personne@example.com','https://github.com.evil.example/organisation/projet','https://bitbucket.org/espace/projet','https://github.com/organisation/projet/tree/main','../projet','organisation/..','https://github.com/organisation/projet?token=secret'] as $badRepo) {
    rejects(fn()=>Development::repository('github',$badRepo),'dépôt invalide ou fournisseur incorrect refusé');
}
$repositoryPage = Development::repositoryPage('github',[['full_name'=>'organisation/projet','private'=>true],['full_name'=>'organisation/autre']],1);
check(count($repositoryPage['repositories'])===2 && $repositoryPage['repositories'][0]['private']===true && $repositoryPage['repositories'][1]['private']===null && !$repositoryPage['has_more'],'liste GitHub préserve identité et visibilité inconnue');
$repositoryPage = Development::repositoryPage('github',array_fill(0,100,['full_name'=>'organisation/projet','private'=>false]),1);
check($repositoryPage['has_more'],'page GitHub complète propose la suite');
$repositoryPage = Development::repositoryPage('bitbucket',['values'=>[['full_name'=>'espace/projet','is_private'=>true]],'next'=>'https://untrusted.example/next'],2);
check($repositoryPage['has_more'] && $repositoryPage['page']===2 && !isset($repositoryPage['next']),'pagination conserve le numéro sans exposer ni suivre une URL distante');
rejects(fn()=>Development::repositoryPage('github',['message'=>'invalid'],1),'réponse liste inattendue refusée');
rejects(fn()=>Development::repositoryPage('github',[['full_name'=>'personne@example.com']],1),'identifiant fournisseur invalide refusé');
rejects(fn()=>Development::repositoryPage('github',[['full_name'=>'organisation/projet','private'=>'yes']],1),'visibilité fournisseur ambiguë refusée');
rejects(fn()=>Development::discover('bitbucket','synthetic-token',1,''),'workspace requis avant appel Bitbucket');
rejects(fn()=>Development::discover('github','synthetic-token',0),'page invalide refusée avant appel externe');
