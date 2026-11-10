<?php
/**
 * "Personnes et équipes" fieldset: assignee[] and team[] checkboxes (several values, "none" for
 * unassigned / without team) plus assignment_match, which joins the two lists with AND ("all") or
 * OR ("any"). Included by filters.php (#filters-dialog) and personal-report-form.php.
 * Expects $filters, $assignmentMembers (users), $assignmentTeams (teams), $assignmentPrefix (unique
 * id prefix) and $assignmentMarker (true adds the assignment_filter field so a POST with every box
 * unchecked still clears the saved selection).
 */
$selectedAssignees = App\Services\Filter::selectedIds($filters, 'assignee');
$selectedTeams = App\Services\Filter::selectedIds($filters, 'team');
$assignmentAny = ($filters['assignment_match'] ?? '') === 'any';
?>
<fieldset class="status-filter assignment-filter">
  <legend>Personnes et équipes</legend>
  <?php if (!empty($assignmentMarker)): ?><input type="hidden" name="assignment_filter" value="1"><?php endif; ?>
  <div class="assignment-filter-lists">
    <div class="assignment-filter-list" role="group" aria-labelledby="<?= e($assignmentPrefix) ?>-assignees">
      <p class="assignment-filter-title" id="<?= e($assignmentPrefix) ?>-assignees">Personne assignée</p>
      <label class="check-label"><input type="checkbox" name="assignee[]" value="none" <?= in_array('none', $selectedAssignees, true) ? 'checked' : '' ?>>Non assigné</label>
      <?php foreach ($assignmentMembers as $m): ?>
        <label class="check-label"><input type="checkbox" name="assignee[]" value="<?= (int)$m['id'] ?>" <?= in_array((string)$m['id'], $selectedAssignees, true) ? 'checked' : '' ?>><?= e($m['name']) ?></label>
      <?php endforeach; ?>
    </div>
    <div class="assignment-filter-list" role="group" aria-labelledby="<?= e($assignmentPrefix) ?>-teams">
      <p class="assignment-filter-title" id="<?= e($assignmentPrefix) ?>-teams">Équipe</p>
      <label class="check-label"><input type="checkbox" name="team[]" value="none" <?= in_array('none', $selectedTeams, true) ? 'checked' : '' ?>>Sans équipe</label>
      <?php foreach ($assignmentTeams as $team): ?>
        <label class="check-label"><input type="checkbox" name="team[]" value="<?= (int)$team['id'] ?>" <?= in_array((string)$team['id'], $selectedTeams, true) ? 'checked' : '' ?>><?= e($team['name']) ?></label>
      <?php endforeach; ?>
    </div>
  </div>
  <div class="assignment-filter-match" role="radiogroup" aria-label="Combiner personne et équipe">
    <label class="check-label"><input type="radio" name="assignment_match" value="all" <?= $assignmentAny ? '' : 'checked' ?>>Les deux conditions</label>
    <label class="check-label"><input type="radio" name="assignment_match" value="any" <?= $assignmentAny ? 'checked' : '' ?>>L’une ou l’autre</label>
  </div>
  <p class="help-text">Sans case cochée, aucune restriction. Exemple : cochez votre nom et « Sans équipe », puis « L’une ou l’autre » pour voir vos tickets et ceux sans équipe.</p>
</fieldset>
