<?php use App\Models\McpToken;
require_once ROOT.'/app/Views/copy-field.php';
$newKey=$_SESSION['new_mcp_key']??null; unset($_SESSION['new_mcp_key']); ?>
<section class="panel team-section" id="mcp">
  <div class="panel-header"><div><span class="eyebrow">SILLAGE, CONNECTÉ À VOS OUTILS</span><h2>MCP et clés d’accès</h2><p>Autorisez un assistant à agir avec les droits de votre compte.</p></div><span class="pill soft"><?= count($mcpTokens) ?> clés</span></div>
  <?= copy_field('Adresse du serveur MCP',url('/mcp')) ?>
  <p class="help-text">Transport Streamable HTTP, authentification par en-tête <code>Authorization: Bearer votre_clé</code>. HTTPS requis en production. Chaque clé appartient à votre compte et peut recevoir moins de droits que vous.</p>
  <?php if ($newKey): ?><div class="oauth-created" role="status"><strong>Votre nouvelle clé — à copier maintenant</strong><p>Elle ne sera plus affichée après cette page.</p><?= copy_field('Clé MCP générée',$newKey['key'],true) ?></div><?php endif; ?>
  <form method="post" action="/mcp/tokens" class="stack mcp-create"><?= csrf() ?><input type="hidden" name="_return" value="/settings#mcp">
    <label>Nom de la clé<input name="name" required maxlength="120" placeholder="Ex. : Assistant de mon équipe"></label>
    <div class="inline-fields"><label>Validité<select name="lifetime" data-mcp-lifetime><option value="limited">Pendant une durée</option><option value="unlimited">Sans expiration</option></select></label><label>Durée<input name="duration" type="number" min="1" max="36500" value="30" required data-mcp-duration></label><label>Unité<select name="unit" data-mcp-duration><option value="minutes">Minutes</option><option value="hours">Heures</option><option value="days" selected>Jours</option></select></label></div>
    <fieldset><legend>Droits de cette clé</legend><?php foreach(McpToken::availableScopes($user['role']) as $scope): ?><label class="check-label"><input type="checkbox" name="scopes[]" value="<?= e($scope) ?>" <?= $scope!=='admin'?'checked':'' ?>><?= e(McpToken::SCOPES[$scope]) ?></label><?php endforeach; ?></fieldset>
    <button class="button primary">Générer une clé</button>
  </form>
  <p class="help-text">La base ne conserve qu’une empreinte de la clé. Sa suppression coupe son accès. Un changement de mot de passe ou de droits invalide également les clés existantes du compte.</p>
  <div class="table-panel"><table class="ticket-table"><thead><tr><th>Clé</th><th>Droits</th><th>Expiration (UTC)</th><th>Dernier accès (UTC)</th><th>État</th><th></th></tr></thead><tbody>
  <?php foreach($mcpTokens as $key): $invalid=(int)$key['auth_version']!==(int)$user['auth_version']; $expired=$key['expires_at']!==null&&$key['expires_at']<=gmdate('Y-m-d H:i:s'); ?>
  <tr data-mcp-token-id="<?= (int)$key['id'] ?>"><td><strong><?= e($key['name']) ?></strong><small class="muted"> #<?= e($key['fingerprint']) ?></small></td><td><?= e(implode(', ',json_decode($key['scopes'],true))) ?></td><td><?= e($key['expires_at']??'Sans expiration') ?></td><td><?= e($key['last_used_at']??'Jamais') ?></td><td><span class="pill <?= $invalid||$expired?'neutral':'soft' ?>"><?= $invalid?'Accès modifiés':($expired?'Expirée':'Active') ?></span></td><td><form method="post" action="/mcp/tokens/<?= (int)$key['id'] ?>/delete"><?= csrf() ?><input type="hidden" name="_return" value="/settings#mcp"><button class="text-button danger" data-confirm="Supprimer cette clé et couper son accès au MCP ?">Supprimer</button></form></td></tr>
  <?php endforeach; if(!$mcpTokens): ?><tr><td colspan="6">Aucune clé pour le moment.</td></tr><?php endif; ?>
  </tbody></table></div>
  <?php if($mcpAudit): ?><details class="details-content"><summary>Dernières actions MCP de mon compte</summary><?php foreach($mcpAudit as $entry): ?><div class="member-row"><div><strong><?= e($entry['tool_name']) ?></strong><small><?= e(($entry['token_name']??'Clé supprimée').' · '.$entry['created_at'].' UTC') ?></small></div><span class="pill neutral"><?= $entry['succeeded']?'Réussie':'Refusée' ?></span></div><?php endforeach; ?></details><?php endif; ?>
</section>
