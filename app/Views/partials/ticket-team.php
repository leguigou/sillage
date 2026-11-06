<?php
/**
 * "Équipe responsable" select (team_id), limited to the teams linked to the project.
 * Included by ticket-edit.php and by dialogs.php (new-ticket dialog).
 * Uses $projectTeams when the caller defines it, otherwise Team::forProject();
 * expects $project and $selectedTeam (team id or null).
 */
$ticketTeams=$projectTeams??App\Models\Team::forProject((int)$project['id']); ?>
<label>Équipe responsable<select name="team_id"><option value="">Sans équipe</option><?php foreach($ticketTeams as $candidate): ?><option value="<?= (int)$candidate['id'] ?>" <?= (int)($selectedTeam??0)===(int)$candidate['id']?'selected':'' ?>><?= e($candidate['name']) ?></option><?php endforeach; ?></select><small>L’équipe et la personne assignée sont indépendantes. Seules les équipes liées au projet sont proposées.</small></label>
