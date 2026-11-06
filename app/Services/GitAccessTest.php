<?php
declare(strict_types=1);
namespace App\Services;
/**
 * Diagnoses the permissions of a stored Git connection (called from DevelopmentController).
 * Makes one small read request per area (repository, pull requests, CI pipelines, deployments) and
 * reports, per area, whether it succeeded plus the token permission the user should grant.
 * Only controlled InvalidArgumentException messages are shown; any other error becomes a generic
 * message pointing to the technical logs.
 */
final class GitAccessTest
{
    /** Returns one result row per check: key, label, ok, http_status, message, permission. */
    public static function run(array $connection): array
    {
        $github=$connection['provider']==='github';
        $checks=[
            'repository'=>['Dépôt','',$github?'Metadata en lecture.':'Dépôts en lecture : read:repository:bitbucket ou repository.'],
            'pull_requests'=>['Pull requests',$github?'pulls?state=all&per_page=1':'pullrequests?state=OPEN&pagelen=1',$github?'Pull requests en lecture.':'Pull requests en lecture : read:pullrequest:bitbucket ou pullrequest.'],
            'pipelines'=>[$github?'GitHub Actions':'Pipelines',$github?'actions/runs?per_page=1':'pipelines/?pagelen=1',$github?'Actions en lecture.':'Pipelines en lecture : read:pipeline:bitbucket ou pipeline.'],
            'deployments'=>['Déploiements','deployments?'.($github?'per_page':'pagelen').'=1',$github?'Deployments en lecture.':'Pipelines en lecture : read:pipeline:bitbucket ou pipeline.'],
        ];$result=[];
        foreach($checks as $key=>[$label,$resource,$permission]) {
            try {Development::request($connection,$resource,8);$result[]=['key'=>$key,'label'=>$label,'ok'=>true,'http_status'=>null,'message'=>'Accès confirmé.','permission'=>$permission];}
            catch(\Throwable $error) {$result[]=['key'=>$key,'label'=>$label,'ok'=>false,'http_status'=>$error instanceof GitAccessException?$error->httpStatus:null,'message'=>$error instanceof \InvalidArgumentException?$error->getMessage():'Test indisponible. Consultez les journaux techniques.','permission'=>$permission];}
        }
        return $result;
    }
}
