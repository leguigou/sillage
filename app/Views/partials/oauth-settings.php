<?php
require_once ROOT.'/app/Views/copy-field.php';
$oauthClients=$user['role']==='admin'?App\Core\DB::all('SELECT id,name,redirect_uri,auth_method FROM oauth_clients ORDER BY created_at DESC,id'):[];
$oauthGrants=App\Core\DB::all('SELECT g.id,g.scopes,g.expires_at,g.last_used_at,g.auth_version,c.name FROM oauth_grants g JOIN oauth_clients c ON c.id=g.client_id WHERE g.user_id=? ORDER BY g.created_at DESC,g.id',[$user['id']]);
$newOAuth=$_SESSION['new_oauth_client']??null; unset($_SESSION['new_oauth_client']);
?>
<section class="panel team-section oauth-settings" id="oauth">
  <div class="panel-header"><div><span class="eyebrow">VOTRE ESPACE, CONNECTÉ</span><h2>Connexions OAuth</h2><p>Reliez ChatGPT à Sillage et gardez le contrôle des accès.</p></div><span class="pill soft"><?= icon('lock') ?>OAuth · PKCE</span></div>
  <?php if($user['role']==='admin'): ?>
  <?php if($newOAuth): ?>
  <div class="oauth-created" role="status"><div><h3><?= icon('check') ?>Client créé</h3><p>Copiez ces identifiants dans les paramètres OAuth de ChatGPT.</p></div>
    <?= copy_field('ID client OAuth',$newOAuth['id']) ?>
    <?php if($newOAuth['secret']!==null): ?><?= copy_field('Secret du client OAuth — affiché une seule fois',$newOAuth['secret'],true) ?><?php else: ?><p class="help-text">Client public : méthode <code>none</code>, sans secret. PKCE est obligatoire.</p><?php endif; ?>
  </div>
  <?php endif; ?>
  <div class="oauth-layout">
    <div class="oauth-card"><h3>Créer un client</h3><p class="help-text">Dans ChatGPT, choisissez « Client OAuth défini par l’utilisateur », puis copiez son URL de rappel ici.</p>
      <form method="post" action="/oauth/clients" class="stack"><?= csrf() ?><input type="hidden" name="_return" value="/settings#oauth">
        <label>Nom du client<input name="name" required maxlength="120" placeholder="Ex. : ChatGPT de l’équipe"></label>
        <label>URL de rappel<input type="url" name="redirect_uri" required maxlength="1000" placeholder="https://…"><small>L’URL exacte affichée par ChatGPT.</small></label>
        <label>Authentification du client<select name="auth_method"><option value="none">none — sans secret, avec PKCE</option><option value="client_secret_post">client_secret_post — secret dans la requête</option><option value="client_secret_basic">client_secret_basic — secret dans l’en-tête Basic</option></select></label>
        <div class="oauth-actions"><button class="button primary"><?= icon('plus') ?>Créer le client OAuth</button></div>
      </form>
    </div>
    <aside class="oauth-card oauth-guide"><h3>À renseigner dans ChatGPT</h3><p class="help-text">Les URL sont découvertes automatiquement. Ouvrez les paramètres manuels si nécessaire.</p>
      <?= copy_field('URL du serveur MCP',url('/mcp')) ?>
      <div class="oauth-scope-grid"><?= copy_field('Portées par défaut',"read\nwrite") ?><?= copy_field('Portées de base',"read\noffline_access") ?></div>
      <p class="help-text">Ajoutez <code>admin</code> aux portées par défaut pour gérer les projets, équipes et règles, puis autorisez ce droit à la connexion.</p>
      <details class="oauth-manual"><summary>URL et paramètres avancés</summary><div class="stack">
        <?php foreach(['URL d’autorisation'=>url('/oauth/authorize'),'URL du token'=>url('/oauth/token'),'Serveur d’autorisation'=>App\Models\OAuth::issuer(),'Ressource'=>url('/mcp')] as $label=>$value): ?><?= copy_field($label,$value) ?><?php endforeach; ?>
        <p class="help-text">PKCE : <strong>S256</strong> · OIDC : <strong>désactivé</strong>.<br>Laissez l’URL d’enregistrement vide. DCR et CIMD ne sont pas proposés.</p>
      </div></details>
    </aside>
  </div>
  <div class="oauth-section-heading"><h3>Clients enregistrés</h3><span class="pill neutral"><?= count($oauthClients) ?></span></div>
  <div class="oauth-clients"><?php foreach($oauthClients as $client): ?>
    <article class="oauth-card" data-oauth-client="<?= e($client['id']) ?>"><header><h4><?= e($client['name']) ?></h4><span class="pill neutral"><?= e($client['auth_method']) ?></span></header>
      <?= copy_field('ID client OAuth',$client['id']) ?><?= copy_field('URL de rappel',$client['redirect_uri']) ?>
      <form action="/oauth/clients/<?= e($client['id']) ?>/delete" method="post" class="oauth-actions"><?= csrf() ?><input type="hidden" name="_return" value="/settings#oauth"><button class="button oauth-danger" data-confirm="Supprimer ce client OAuth et révoquer toutes ses connexions ?"><?= icon('close') ?>Supprimer le client</button></form>
    </article>
  <?php endforeach; ?></div>
  <?php if(!$oauthClients): ?><p class="oauth-empty">Aucun client enregistré. Créez le premier avec l’URL de rappel de ChatGPT.</p><?php endif; ?>
  <?php endif; ?>
  <div class="oauth-section-heading"><h3>Mes connexions autorisées</h3></div>
  <div class="oauth-clients"><?php foreach($oauthGrants as $grant): ?><article class="oauth-card"><header><h4><?= e($grant['name']) ?></h4><span class="pill neutral"><?= e(implode(', ',json_decode($grant['scopes'],true))) ?></span></header><p class="help-text">Expiration : <?= e($grant['expires_at']) ?> UTC<br>Dernier accès : <?= e($grant['last_used_at']??'Aucun') ?><?php if((int)$grant['auth_version']!==(int)$user['auth_version']): ?><br>Invalidée par un changement d’accès<?php endif; ?></p><form method="post" action="/oauth/grants/<?= e($grant['id']) ?>/revoke"><?= csrf() ?><input type="hidden" name="_return" value="/settings#oauth"><button class="button subtle">Révoquer la connexion</button></form></article><?php endforeach; ?></div>
  <?php if(!$oauthGrants): ?><p class="oauth-empty">Aucune connexion OAuth autorisée pour votre compte.</p><?php endif; ?>
</section>
