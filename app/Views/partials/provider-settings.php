<dialog id="provider-settings-<?= e($provider) ?>" class="dialog">
 <header class="dialog-header"><div><h2>Configuration <?= e($providerName) ?></h2><p class="help-text">Vos dépôts et leurs informations enregistrées.</p></div><button type="button" class="icon-button" data-close aria-label="Fermer"><?= icon('close') ?></button></header>
 <div class="provider-connections">
 <?php foreach($configured as $connection): ?>
  <form action="/connections/<?= (int)$connection['id'] ?>/update" method="post" class="stack provider-connection" data-connection-update autocomplete="off">
   <?= csrf() ?><input type="hidden" name="project_id" value="<?= (int)$project['id'] ?>"><input type="hidden" name="_return" value="/development?project=<?= (int)$project['id'] ?>">
   <label>Dépôt<input value="<?= e($connection['repository']) ?>" readonly></label>
   <p class="help-text">Dernière synchronisation : <?= e($connection['last_sync_at']??'jamais') ?></p>
   <label>Nouveau jeton d’accès<input type="password" name="token" autocomplete="new-password" maxlength="4000" placeholder="Jeton enregistré — laisser vide pour le conserver"><small>Cette connexion et son jeton sont partagés entre les projets qui utilisent ce dépôt. Le jeton existant est chiffré et ne sera pas réaffiché. Pour le remplacer, collez le nouveau jeton : son accès à ce dépôt sera vérifié avant l’enregistrement.</small></label>
   <p class="connection-feedback" role="status" aria-live="polite" data-update-status></p>
   <button class="button subtle">Enregistrer cette connexion</button>
  </form>
 <?php endforeach; ?>
 </div>
 <button type="button" class="button primary" data-open="connection-dialog" data-close data-connection-provider="<?= e($provider) ?>">Connecter d’autres dépôts</button>
</dialog>
