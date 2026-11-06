<?php
/**
 * "Équipes du projet" fieldset: one team_ids[] checkbox per team. Included by
 * project-settings.php (boxes pre-checked from $project['team_ids']) and by dialogs.php in
 * the new-project dialog, where $newProjectTeams=true leaves every box unchecked.
 * Uses $teams when the caller defines it, otherwise App\Models\Team::options().
 * The hidden "teams_present" field lets the server treat "no box checked" as an empty list.
 */
$availableTeams=$teams??App\Models\Team::options(); $selectedTeams=empty($newProjectTeams)?($project['team_ids']??[]):[]; ?>
<fieldset class="project-teams"><legend>Équipes du projet</legend><input type="hidden" name="teams_present" value="1">
<?php foreach($availableTeams as $candidate): ?><label class="check-label"><input type="checkbox" name="team_ids[]" value="<?= (int)$candidate['id'] ?>" <?= in_array((int)$candidate['id'],$selectedTeams,true)?'checked':'' ?>><?= e($candidate['name']) ?></label><?php endforeach; ?>
<?php if(!$availableTeams): ?><p class="help-text">Créez une équipe dans Paramètres → Équipes pour l’associer ici.</p><?php endif; ?>
<p class="help-text">Plusieurs équipes peuvent travailler sur ce projet. Réaffectez leurs tickets avant de retirer une équipe du projet.</p></fieldset>
