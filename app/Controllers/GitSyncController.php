<?php
declare(strict_types=1);
namespace App\Controllers;
use App\Core\{Auth,DB};
use App\Models\GitJob;
use App\Services\GitSync;
final class GitSyncController
{
    private function project(string $id): int
    {
        $project=filter_var($_POST['project_id']??$_GET['project']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
        if(!$project || !DB::one('SELECT 1
            FROM project_connections
            WHERE connection_id=? AND project_id=?',[(int)$id,$project])) throw new \InvalidArgumentException('Ce dépôt n’est pas lié au projet.');
        return $project;
    }
    public function status(string $id): void {Auth::requireUser();$this->project($id);json_response(GitJob::status((int)$id));}
    public function recover(string $id): void
    {
        Auth::editor();$this->project($id);
        $state=DB::one('SELECT enabled FROM git_sync_state WHERE connection_id=?',[(int)$id]);
        if($state && !$state['enabled']) throw new \InvalidArgumentException('La synchronisation automatique est désactivée pour ce dépôt. Un administrateur peut l’activer dans Développement.');
        GitSync::request((int)$id);json_response(['ok'=>true,'status'=>GitJob::status((int)$id)],202);
    }
    public function configure(string $id): void
    {
        Auth::admin();$this->project($id);$minutes=filter_var($_POST['interval']??5,FILTER_VALIDATE_INT);
        if(!in_array($minutes,[1,5,15,30,60],true) || !in_array($_POST['enabled']??'',['0','1'],true)) throw new \InvalidArgumentException('Configuration de synchronisation invalide.');
        DB::run('INSERT INTO git_sync_state(connection_id,enabled,interval_minutes)
            VALUES(?,?,?)
            ON DUPLICATE KEY UPDATE enabled=VALUES(enabled),interval_minutes=VALUES(interval_minutes)',[(int)$id,(int)$_POST['enabled'],$minutes]);
        if($_POST['enabled']==='1') GitSync::request((int)$id);json_response(['ok'=>true]);
    }
}
