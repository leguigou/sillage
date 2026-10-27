<?php use App\Models\User; $isAdmin = $user['role'] === 'admin'; ?>
<section class="panel team-section" id="teams">
  <div class="panel-header"><div><span class="eyebrow">AVANCER ENSEMBLE</span><h2>Équipes</h2><p>Organisez les personnes par métier ou par initiative.</p></div><span class="pill soft"><?= count($teams) ?> équipes</span></div>
  <p class="help-text">Un utilisateur peut appartenir à plusieurs équipes. Tous les comptes connectés conservent l’accès aux projets ; les équipes servent à organiser les personnes.</p>
  <?php if ($isAdmin): ?>
  <form method="post" action="/teams" class="team-create"><?= csrf() ?><input type="hidden" name="_return" value="/settings#teams"><label>Nouvelle équipe<input name="name" maxlength="120" required placeholder="Ex. : Support magasins"></label><button class="button primary">Créer l’équipe</button></form>
  <p class="help-text">Pour ajouter une personne : elle crée son compte et valide l’e-mail reçu, puis vous la sélectionnez ci-dessous. <?= e(App\Core\Company::signupHint()) ?></p>
  <?php endif; ?>
  <div class="team-grid">
    <?php foreach ($teams as $team): $people=$teamMembers[$team['id']]??[]; $selected=array_column($people,'id'); ?>
    <article class="team-card" data-team-id="<?= (int)$team['id'] ?>">
      <header class="panel-header"><h3><?= e($team['name']) ?></h3><span class="pill neutral"><?= count($people) ?> membres</span></header>
      <?php foreach ($people as $person): ?>
      <div class="member-row"><?= avatar($person['name'],'small',(int)$person['id']) ?><div><strong><?= e($person['name']) ?></strong><small><?= e($person['email']) ?><?= !$person['active']?' · Désactivé':'' ?></small></div>
        <?php if ($isAdmin): ?><form method="post" action="/teams/<?= (int)$team['id'] ?>/members"><?= csrf() ?><input type="hidden" name="_return" value="/settings#teams"><input type="hidden" name="user_id" value="<?= (int)$person['id'] ?>"><input type="hidden" name="action" value="remove"><button class="text-button" aria-label="Retirer <?= e($person['name']) ?> de <?= e($team['name']) ?>">Retirer</button></form><?php endif; ?>
      </div>
      <?php endforeach; if (!$people): ?><p class="muted">Aucun membre pour le moment.</p><?php endif; ?>
      <?php if ($isAdmin): $available=array_filter($members,fn($m)=>!in_array($m['id'],$selected,true)); ?>
      <?php if ($available): ?><form method="post" action="/teams/<?= (int)$team['id'] ?>/members" class="stack"><?= csrf() ?><input type="hidden" name="_return" value="/settings#teams"><input type="hidden" name="action" value="add"><label>Ajouter à <?= e($team['name']) ?><select name="user_id" required><option value="">Choisir une personne</option><?php foreach ($available as $candidate): ?><option value="<?= (int)$candidate['id'] ?>"><?= e($candidate['name'].' · '.$candidate['email']) ?></option><?php endforeach; ?></select></label><button class="button subtle">Ajouter à l’équipe</button></form><?php endif; ?>
      <details><summary>Gérer l’équipe</summary><div class="stack details-content"><form method="post" action="/teams" class="stack"><?= csrf() ?><input type="hidden" name="_return" value="/settings#teams"><input type="hidden" name="team_id" value="<?= (int)$team['id'] ?>"><label>Nom de l’équipe<input name="name" value="<?= e($team['name']) ?>" required maxlength="120"></label><button class="button subtle">Renommer</button></form><form method="post" action="/teams/<?= (int)$team['id'] ?>/delete"><?= csrf() ?><input type="hidden" name="_return" value="/settings#teams"><button class="text-button" data-confirm="Supprimer cette équipe ? Les comptes et les tickets seront conservés.">Supprimer l’équipe</button></form></div></details>
      <?php endif; ?>
    </article>
    <?php endforeach; ?>
  </div>
  <?php if (!$teams): ?><p class="muted">Les équipes apparaîtront ici dès leur création par un administrateur.</p><?php endif; ?>
</section>
<?php if ($isAdmin): ?>
<section class="panel team-section" id="users">
  <div class="panel-header"><div><h2>Utilisateurs et accès</h2><p>Les rôles s’appliquent à tout l’espace <?= e(App\Core\Company::name()) ?>.</p></div><span class="pill soft"><?= count($accounts) ?> comptes</span></div>
  <p class="help-text">Administrateur : configuration et gestion des accès. Membre : création et modification des tickets. Lecture seule : consultation et export. Désactiver un compte ferme ses sessions et conserve son historique. La validation d’adresse reste obligatoire.</p>
  <div class="access-list">
  <?php foreach ($accounts as $account): ?>
  <form method="post" action="/users/<?= (int)$account['id'] ?>" class="access-row" data-account-id="<?= (int)$account['id'] ?>">
    <?= csrf() ?><input type="hidden" name="_return" value="/settings#users">
    <div><strong><?= e($account['name']) ?></strong><small><?= e($account['email']) ?> · <?= $account['verified_at']?'Adresse vérifiée':'Validation en attente' ?></small></div>
    <?php if ((int)$account['id']===(int)$user['id']): ?><span class="pill soft">Vous · Administrateur</span><?php else: ?>
    <label>Rôle<select name="role"><?php foreach (User::ROLES as $key=>$label): ?><option value="<?= e($key) ?>" <?= $account['role']===$key?'selected':'' ?>><?= e($label) ?></option><?php endforeach; ?></select></label>
    <label>Accès<select name="active"><option value="1" <?= $account['active']?'selected':'' ?>>Actif</option><option value="0" <?= !$account['active']?'selected':'' ?>>Désactivé</option></select></label>
    <button class="button subtle small-button">Enregistrer</button>
    <?php endif; ?>
  </form>
  <?php endforeach; ?>
  </div>
</section>
<?php endif; ?>
