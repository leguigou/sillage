<dialog id="project-settings-dialog" class="dialog compact"><form action="/projects/<?= (int)$project['id'] ?>/update" method="post" class="stack">
 <?= csrf() ?><input type="hidden" name="_return" value="/?project=<?= (int)$project['id'] ?>">
 <header class="dialog-header"><h2>Paramètres du projet</h2><button type="button" class="icon-button" data-close aria-label="Fermer"><?= icon('close') ?></button></header>
 <label>Nom du projet<input name="name" value="<?= e($project['name']) ?>" required maxlength="120"></label>
 <label>Clé des tickets<input value="<?= e($project['project_key']) ?>" readonly><small>La clé reste fixe pour conserver les références dans le code.</small></label>
 <label>Description<textarea name="description" rows="3" maxlength="2000"><?= e($project['description']??'') ?></textarea></label>
 <label>Couleur<input type="color" name="color" value="<?= e($project['color']??'#7166eb') ?>"></label>
 <label>Dépôt Git principal<select name="repository_connection_id"><option value="">Aucun dépôt principal</option><?php foreach($repositoryCatalog as $repository): ?><option value="<?= (int)$repository['id'] ?>" <?= (int)($project['repository_connection_id']??0)===(int)$repository['id']?'selected':'' ?>><?= e(ucfirst($repository['provider']).' · '.$repository['repository']) ?></option><?php endforeach; ?></select></label>
 <p class="help-text">Choisir un dépôt existant le lie au projet et réutilise sa connexion partagée. Un dépôt peut servir à plusieurs projets. Les autres dépôts déjà liés au projet sont conservés.</p>
 <a href="/development?project=<?= (int)$project['id'] ?>" class="text-button">Gérer les dépôts liés ou connecter un nouveau dépôt</a>
 <?php require ROOT.'/app/Views/partials/project-teams.php'; ?>
 <button class="button primary">Enregistrer le projet</button>
</form></dialog>
