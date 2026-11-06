<?php
declare(strict_types=1);
namespace App\Controllers;
use App\Core\Auth;
use App\Models\Workflow;
/**
 * Saves a project's workflow ("circuit": workflow items such as statuses, and
 * the allowed status transitions) from the project settings screen
 * (POST /projects/{id}/workflow).
 *
 * Admin only. Validation and storage are done by App\Models\Workflow::save().
 */
final class WorkflowController
{
    public function save(string $id): void
    {
        Auth::admin();$input=$_POST;
        // An unchecked checkbox is not posted at all; the hidden "enabled_present"
        // field tells us the checkbox was on the form, so its absence means false.
        if (isset($input['enabled_present'])) $input['enabled']=!empty($input['enabled']);
        Workflow::save((int)$id,$input);
        flash('Circuit du projet enregistré.');
        redirect('/settings?project='.(int)$id.'#workflow');
    }
}
