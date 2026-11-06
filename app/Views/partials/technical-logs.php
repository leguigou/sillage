<?php /**
 * Admin-only settings panel #technical-logs ("Journaux techniques"): filter form and an
 * empty table. Rows are loaded as JSON from /administration/logs by
 * public/assets/technical-logs.js. Included by sections/settings.php for admins only;
 * needs no variable from the caller. Retention, channels and providers come from
 * App\Services\TechnicalLog.
 */ ?>
<section class="panel" id="technical-logs" data-technical-logs>
 <div class="panel-header"><div><h2>Journaux techniques</h2><p>Appels API et MCP, webhooks, échanges GitHub, Bitbucket et fournisseurs IA.</p></div><?= icon('code') ?></div>
 <p class="help-text">Conservation : <?= App\Services\TechnicalLog::retention() ?> jours. Les appels sont enregistrés à partir de cette mise à jour. Les corps, jetons, cookies et paramètres d’URL ne sont pas conservés. Les dates suivent votre fuseau horaire ; les filtres de dates utilisent UTC.</p>
 <form class="technical-log-filters" data-log-filters>
  <div class="inline-fields"><label>Sens<select name="direction"><option value="">Tous les sens</option><option value="incoming">Entrant</option><option value="outgoing">Sortant</option></select></label><label>Service<select name="channel"><option value="">Tous les services</option><?php foreach(App\Services\TechnicalLog::CHANNELS as $value=>$label): ?><option value="<?= e($value) ?>"><?= e($label) ?></option><?php endforeach; ?></select></label><label>Fournisseur<select name="provider"><option value="">Tous les fournisseurs</option><?php foreach(App\Services\TechnicalLog::PROVIDERS as $value=>$label): ?><option value="<?= e($value) ?>"><?= e($label) ?></option><?php endforeach; ?></select></label><label>Résultat<select name="outcome"><option value="">Tous les résultats</option><option value="success">Réussite</option><option value="error">Erreur</option></select></label></div>
  <div class="inline-fields"><label>Du (UTC)<input type="date" name="from"></label><label>Au (UTC)<input type="date" name="to"></label><label>Identifiant de requête<input type="text" name="request_id" pattern="[a-f0-9]{32}" maxlength="32" placeholder="Relier les appels d’une même requête"></label></div>
  <div class="settings-actions"><button class="button primary" type="submit">Appliquer les filtres</button><button class="button subtle" type="button" data-log-refresh>Actualiser</button><button class="button subtle" type="reset" data-log-reset hidden>Réinitialiser</button></div>
 </form>
 <p class="help-text" role="status" aria-live="polite" data-log-status>Ouvrez cet onglet pour charger les appels récents.</p>
 <div class="technical-log-table table-scroll"><table><thead><tr><th>Date</th><th>Sens / service</th><th>Appel</th><th>Résultat</th><th>Durée</th><th>Compte</th></tr></thead><tbody data-log-rows></tbody></table></div>
 <p class="settings-empty" data-log-empty hidden>Aucun appel ne correspond aux filtres.</p>
 <div class="settings-actions"><button class="button subtle" type="button" data-log-more hidden>Afficher les 50 suivants</button></div>
 <noscript><p class="help-text">Activez JavaScript pour consulter et filtrer les journaux sans quitter les paramètres.</p></noscript>
</section>
