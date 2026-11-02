<div class="settings-page" data-settings-page>
<nav class="settings-shortcuts" aria-label="Paramètres de l’espace" data-settings-tabs>
 <a class="button subtle" href="#profile">Mon profil</a>
 <?php if ($user['role']==='admin'): ?>
 <a class="button subtle" href="#company" data-settings-extra="company-summary">Identité de l’entreprise</a>
 <?php if(!empty($project['id'])): ?><a class="button subtle" href="#workflow">Circuit du projet</a><?php endif; ?>
 <a class="button subtle" href="#appearance">Pictogrammes et couleurs</a>
 <a class="button subtle" href="#llm">Intelligence artificielle</a>
 <a class="button subtle" href="#notifications">Notifications e-mail</a>
 <a class="button subtle" href="#application-history">Versions et améliorations</a>
 <?php else: ?>
 <a class="button subtle" href="#company-summary">L’espace entreprise</a>
 <a class="button subtle" href="#directory">Annuaire</a>
 <?php endif; ?>
 <a class="button subtle" href="#email-preferences">Mes notifications</a>
 <a class="button subtle" href="#mcp">MCP et clés d’accès</a>
 <a class="button subtle" href="#oauth">Connexions OAuth</a>
 <a class="button subtle" href="#teams">Équipes</a>
 <?php if ($user['role']==='admin'): ?><a class="button subtle" href="#users" data-settings-extra="directory">Utilisateurs et accès</a><?php endif; ?>
 <a class="button subtle" href="#project-directory">Projets</a>
</nav>
<div data-settings-panels></div>
<div class="settings-grid"><?php require ROOT.'/app/Views/partials/profile-settings.php'; ?><section class="panel" id="company-summary"><h2>Un espace <?= e(App\Core\Company::name()) ?></h2><p><?= e(App\Core\Company::signupHint()) ?> Chaque adresse est vérifiée avant de donner accès à l’espace.</p><div class="settings-status"><?= icon('lock') ?><span>Comptes vérifiés · Sessions protégées</span></div><?php if ($user['role']==='admin'): ?><details class="company-configuration"><summary>Configuration de la société</summary><div class="stack details-content"><p>Cette installation possède sa propre base et son stockage.</p><dl><dt>Société</dt><dd><?= e(App\Core\Company::name()) ?></dd><dt>Domaines configurés</dt><dd><?= e(App\Core\Company::domainsLabel() ?: 'Aucun') ?></dd><dt>Restriction des nouvelles inscriptions</dt><dd><?= App\Core\Company::settings()['restricted']?'Activée':'Désactivée' ?></dd></dl><p class="help-text">Le nom et le logo se modifient dans « Identité de l’entreprise ». Le fichier <code>.env</code> fournit le nom initial avec <code>COMPANY_NAME</code> et les règles d’inscription avec <code>ALLOWED_DOMAINS</code> et <code>EMAIL_DOMAIN_RESTRICTION</code>. Ces règles concernent les nouvelles inscriptions ; gérez les comptes existants dans « Utilisateurs et accès ».</p></div></details><?php endif; ?><h3>Clair ou sombre ?</h3><p>Changez d’ambiance avec la gélule en bas du menu.</p><div class="theme-control"><button data-set-theme="light"><?= icon('sun') ?>Clair</button><button data-set-theme="dark"><?= icon('moon') ?>Sombre</button></div></section><section class="panel" id="directory"><div class="panel-header"><div><h2>Annuaire <?= e(App\Core\Company::name()) ?></h2><p>Les personnes qui font avancer les projets.</p></div><span class="pill soft"><?= count($members) ?> membres</span></div><?php foreach($members as $m): ?><div class="member-row"><?= avatar($m['name'],'small',(int)$m['id']) ?><div><strong><?= e($m['name']) ?></strong><small><?= e($m['email']) ?></small></div><span class="pill neutral"><?= e(['admin'=>'Administrateur','member'=>'Membre','viewer'=>'Lecture seule'][$m['role']]) ?></span></div><?php endforeach; ?></section><?php require ROOT.'/app/Views/partials/project-directory.php'; ?></div>
<?php if ($user['role']==='admin' && !empty($project['id'])) require ROOT.'/app/Views/partials/workflow-settings.php'; ?>
<?php if ($user['role']==='admin') require ROOT.'/app/Views/partials/appearance-settings.php'; ?>
<?php if ($user['role']==='admin') require ROOT . '/app/Views/partials/company-settings.php'; ?>
<?php if ($user['role']==='admin') require ROOT.'/app/Views/partials/llm-settings.php'; ?>
<?php if ($user['role']==='admin') require ROOT.'/app/Views/partials/application-history.php'; ?>
<?php require ROOT.'/app/Views/partials/email-settings.php'; ?>
<?php require ROOT . '/app/Views/partials/mcp-tokens.php'; ?>
<?php require ROOT.'/app/Views/partials/oauth-settings.php'; ?>
<?php require ROOT . '/app/Views/partials/teams.php'; ?>

<dialog id="password-dialog" class="dialog compact"><form method="post" action="/account/password" class="stack settings-form"><?= csrf() ?><input type="hidden" name="_return" value="/settings"><header class="dialog-header"><h2>Changer de mot de passe</h2><button type="button" data-close class="icon-button" aria-label="Fermer"><?= icon('close') ?></button></header><label>Mot de passe actuel<input type="password" name="current_password" autocomplete="current-password" required></label><label>Nouveau mot de passe<input type="password" name="new_password" autocomplete="new-password" minlength="12" maxlength="72" required></label><p class="help-text">Le changement fermera toutes les sessions de votre compte.</p><div class="settings-actions"><button class="button primary">Enregistrer et me reconnecter</button></div></form></dialog>

</div>
