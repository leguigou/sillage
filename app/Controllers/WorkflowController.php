<?php
declare(strict_types=1);
namespace App\Controllers;
use App\Core\Auth;
use App\Models\Workflow;
final class WorkflowController
{
    public function save(string $id): void
    {
        Auth::admin();$input=$_POST;
        if (isset($input['enabled_present'])) $input['enabled']=!empty($input['enabled']);
        Workflow::save((int)$id,$input);
        flash('Circuit du projet enregistré.');
        redirect('/settings?project='.(int)$id.'#workflow');
    }
}
