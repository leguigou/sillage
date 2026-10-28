<?php $availableTeams=$teams??App\Core\DB::all('SELECT id,name FROM teams ORDER BY name,id'); $selectedTeams=empty($newProjectTeams)?($project['team_ids']??[]):[]; ?>
<fieldset class="project-teams"><legend>Équipes du projet</legend><input type="hidden" name="teams_present" value="1">
<?php foreach($availableTeams as $candidate): ?><label class="check-label"><input type="checkbox" name="team_ids[]" value="<?= (int)$candidate['id'] ?>" <?= in_array((int)$candidate['id'],$selectedTeams,true)?'checked':'' ?>><?= e($candidate['name']) ?></label><?php endforeach; ?>
<?php if(!$availableTeams): ?><p class="help-text">Créez une équipe dans Paramètres → Équipes pour l’associer ici.</p><?php endif; ?>
<p class="help-text">Plusieurs équipes peuvent travailler sur ce projet. Réaffectez leurs tickets avant de retirer une équipe du projet.</p></fieldset>
