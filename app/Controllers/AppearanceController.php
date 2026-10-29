<?php
declare(strict_types=1);
namespace App\Controllers;
use App\Core\Auth;
use App\Models\Appearance;

final class AppearanceController
{
    public function save(): void
    {
        Auth::admin();
        Appearance::save($_POST);
        flash('Pictogrammes et couleurs enregistrés pour tous les projets.');
        $project=filter_var($_POST['project_id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
        redirect('/settings'.($project?'?project='.$project:'').'#appearance');
    }
}
