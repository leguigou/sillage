<dialog id="project-settings-dialog" class="dialog compact"><form action="/projects/<?= (int)$project['id'] ?>/update" method="post" class="stack">
 <?= csrf() ?><input type="hidden" name="_return" value="/?project=<?= (int)$project['id'] ?>">
 <header class="dialog-header"><h2>Paramètres du projet</h2><button type="button" class="icon-button" data-close aria-label="Fermer"><?= icon('close') ?></button></header>
 <a class="button subtle" href="/settings?project=<?= (int)$project['id'] ?>#workflow">Configurer les statuts, types et transitions</a>
 <input type="hidden" name="validation_present" value="1"><label class="check-label"><input type="checkbox" name="require_ticket_validation" value="1" <?= !empty($project['require_ticket_validation'])?'checked':'' ?>>Exiger une validation avant la clôture des tickets</label><small>Un collaborateur valide explicitement le contenu. Toute modification du contenu annule cette validation. Les tickets déjà terminés sont conservés.</small>
 <label>Nom du projet<input name="name" value="<?= e($project['name']) ?>" required maxlength="120"></label>
 <label>Clé des tickets<input value="<?= e($project['project_key']) ?>" readonly><small>La clé reste fixe pour conserver les références dans le code.</small></label>
 <label>Description<textarea name="description" rows="3" maxlength="2000"><?= e($project['description']??'') ?></textarea></label>
 <?php $pickerName=''; $pickerMark=['icon'=>$project['icon']??'folder','color'=>$project['color']??'#7166eb']; $pickerLabel='Identité du projet'; require ROOT.'/app/Views/partials/appearance-picker.php'; ?>
 <label>Dépôt Git principal<select name="repository_connection_id"><option value="">Aucun dépôt principal</option><?php foreach($repositoryCatalog as $repository): ?><option value="<?= (int)$repository['id'] ?>" <?= (int)($project['repository_connection_id']??0)===(int)$repository['id']?'selected':'' ?>><?= e(ucfirst($repository['provider']).' · '.$repository['repository']) ?></option><?php endforeach; ?></select></label>
 <p class="help-text">Choisir un dépôt existant le lie au projet et réutilise sa connexion partagée. Un dépôt peut servir à plusieurs projets. Les autres dépôts déjà liés au projet sont conservés.</p>
 <a href="/development?project=<?= (int)$project['id'] ?>" class="text-button">Gérer les dépôts liés ou connecter un nouveau dépôt</a>
 <a class="text-button" href="/settings?project=<?= (int)$project['id'] ?>#notifications">Configurer les notifications e-mail du projet</a>
 <?php require ROOT.'/app/Views/partials/project-teams.php'; ?>
 <div class="settings-actions"><button class="button primary">Enregistrer le projet</button></div>
</form></dialog>
