<dialog id="connection-dialog" class="dialog"><form action="/connections" method="post" class="stack" autocomplete="off" data-connect-repositories>
 <?= csrf() ?><input type="hidden" name="project_id" value="<?= (int)$project['id'] ?>"><input type="hidden" name="_return" value="/development">
 <header class="dialog-header"><h2>Connecter des dépôts</h2><button type="button" class="icon-button" data-close aria-label="Fermer"><?= icon('close') ?></button></header>
 <label>Fournisseur<select name="provider" data-connection-provider-select aria-describedby="provider-help-github"><option value="github">GitHub</option><option value="bitbucket">Bitbucket Cloud</option></select></label>
 <?php require ROOT . '/app/Views/partials/provider-guides.php'; ?>
 <label>Jeton d’accès<input type="password" name="token" required autocomplete="new-password" maxlength="4000" minlength="10"><small>Collez uniquement le jeton, sans préfixe « Bearer ». Il ne sera pas réaffiché après enregistrement.</small></label>
 <label data-bitbucket-workspace hidden>Workspace Bitbucket<input name="workspace" maxlength="100" autocomplete="off" placeholder="mon-workspace"><small>Requis uniquement pour charger la liste des dépôts Bitbucket.</small></label>
 <button type="button" class="button subtle" data-discover-repositories>Vérifier le jeton et lister les dépôts</button>
 <p class="connection-feedback" role="status" aria-live="polite" data-discovery-status></p>
 <section class="repository-picker" data-repository-picker hidden aria-label="Dépôts accessibles">
  <label>Filtrer les dépôts chargés<input type="search" data-repository-search placeholder="Nom du dépôt…"></label>
  <p class="help-text" data-repository-count></p><div class="repository-choices" data-repository-choices></div>
  <button type="button" class="button subtle small-button" data-load-repositories hidden>Charger les dépôts suivants</button>
  <small>Seuls les dépôts cochés seront connectés. La lecture des pull requests sera vérifiée à l’enregistrement.</small>
 </section>
 <details data-manual-repositories><summary>Saisir des dépôts manuellement</summary><label>Dépôts<textarea name="repository" rows="3" maxlength="10000" autocomplete="off" spellcheck="false" placeholder="organisation/mon-projet"></textarea><small>Un dépôt ou une URL par ligne. Vous pouvez combiner cette saisie avec la sélection ci-dessus, jusqu’à 20 dépôts par envoi.</small></label></details>
 <p class="help-text">Le jeton n’est pas enregistré lors de la recherche. À la connexion, il sera chiffré côté serveur pour chaque dépôt sélectionné.</p>
 <p class="connection-feedback" role="status" aria-live="polite" data-connection-status></p><ul class="connection-results" data-connection-results></ul>
 <a class="button subtle" data-connection-review hidden href="/development?project=<?= (int)$project['id'] ?>">Voir les connexions et configurer les webhooks</a>
 <button class="button primary" data-connection-submit>Vérifier et connecter la sélection</button>
</form></dialog>
