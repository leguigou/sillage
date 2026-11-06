<?php
/**
 * Admin-only settings panel #appearance ("Pictogrammes et couleurs"): workspace-wide
 * icon/colour of every ticket type and priority. Display only; ticket data is unchanged.
 * Included by sections/settings.php for admins only. Loads App\Models\Appearance::get()
 * itself; expects $project (link to project settings and return URL).
 * Renders one appearance-picker.php per entry with $pickerName = "marks[kind][key]" and
 * posts everything to /appearance with the "version" field for optimistic locking.
 */
$appearance=App\Models\Appearance::get(); ?>
<section class="panel appearance-settings" id="appearance">
 <div class="panel-header"><div><h2>Pictogrammes et couleurs</h2><p>Les mêmes repères dans les listes, tableaux et chronologies de tous les projets.</p></div><?= icon('grid') ?></div>
 <p class="help-text">L’identité de chaque projet se règle dans <button type="button" class="text-button" data-edit-project="<?= (int)$project['id'] ?>">les paramètres du projet</button>. Les réglages ci-dessous sont communs à tout l’espace.</p>
 <form action="/appearance" method="post" class="stack settings-form settings-form-wide">
 <?= csrf() ?><input type="hidden" name="version" value="<?= (int)$appearance['version'] ?>"><input type="hidden" name="project_id" value="<?= (int)$project['id'] ?>"><input type="hidden" name="_return" value="/settings?project=<?= (int)$project['id'] ?>#appearance">
 <?php foreach(['type'=>['Types de tickets',App\Models\Ticket::TYPES],'priority'=>['Priorités',App\Models\Ticket::PRIORITIES]] as $kind=>[$heading,$labels]): ?>
 <div class="appearance-category"><h3><?= e($heading) ?></h3><div class="appearance-rows">
 <?php foreach($labels as $key=>$label): $pickerName='marks['.$kind.']['.$key.']'; $pickerMark=$appearance['marks'][$kind][$key]; $pickerLabel=$label; ?>
 <details class="appearance-row" data-appearance-row><summary><?= work_mark($kind,$key) ?><strong><?= e($label) ?></strong><span>Personnaliser</span><?= icon('down') ?></summary><?php require ROOT.'/app/Views/partials/appearance-picker.php'; ?></details>
 <?php endforeach; ?></div></div>
 <?php endforeach; ?>
 <p class="help-text">Modifie uniquement l’affichage. Les types et priorités des tickets restent identiques.</p><div class="settings-actions"><button class="button primary">Enregistrer les pictogrammes</button></div>
 </form>
</section>
