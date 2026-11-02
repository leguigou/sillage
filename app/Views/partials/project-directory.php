<section class="panel" id="project-directory">
 <div class="panel-header"><div><h2>Les projets</h2><p>Un cap pour chaque initiative.</p></div></div>
 <?php if($user['role']==='admin'): ?><div class="settings-toolbar"><button class="button primary" data-open="project-dialog"><?= icon('plus') ?>Créer un projet</button></div><?php endif; ?>
 <?php foreach($projects as $p): ?><a class="settings-project" href="/?project=<?= (int)$p['id'] ?>" <?= (int)$project['id']===(int)$p['id']?'aria-current="true"':'' ?>><?= project_mark($p,'tile') ?><div><strong><?= e($p['name']) ?></strong><small><?= e($p['description']) ?></small></div><?php if((int)$project['id']===(int)$p['id']): ?><span class="project-current">Sélectionné</span><?php endif; ?><?= icon('arrow') ?></a><?php endforeach; ?>
</section>
