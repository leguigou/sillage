<?php use App\Models\Workflow; $workflow=Workflow::get((int)$project['id']); ?>
<section class="panel workflow-settings" id="workflow">
 <div class="panel-header"><div><h2>Circuit du projet</h2><p><?= e($project['name']) ?> · Statuts, types de tickets et passages autorisés.</p></div><?= icon('board') ?></div>
 <p class="help-text">Les clés existantes sont conservées. L’ordre définit les colonnes du tableau. Une catégorie conserve le sens métier pour les rapports, les formulaires et les notifications. Un élément utilisé ne peut pas être désactivé.</p>
 <?php foreach(['status'=>'Statuts et colonnes','type'=>'Types de tickets'] as $kind=>$heading): ?>
 <h3><?= e($heading) ?></h3><div class="workflow-items">
 <?php foreach($workflow['items'][$kind] as $code=>$definition): ?>
 <details class="workflow-item" data-settings-key="<?= e($kind.'-'.$code) ?>"><summary><span class="work-mark" data-mark-color="<?= e(Workflow::mark($kind,(int)$project['id'],$code)['color']) ?>"><?= icon(Workflow::mark($kind,(int)$project['id'],$code)['icon']) ?></span><strong><?= e($definition['label']) ?></strong><span class="workflow-item-meta"><?= e($kind==='status'?(Workflow::CATEGORIES[$definition['category']]??'En pause'):App\Models\Ticket::TYPES[$definition['category']]) ?><?= !$definition['enabled']?' · Désactivé':'' ?></span><?= icon('down') ?></summary>
 <form action="/projects/<?= (int)$project['id'] ?>/workflow" method="post" class="stack workflow-item-form">
 <?= csrf() ?><input type="hidden" name="version" value="<?= $workflow['version'] ?>"><input type="hidden" name="action" value="save_item"><input type="hidden" name="kind" value="<?= e($kind) ?>"><input type="hidden" name="code" value="<?= e($code) ?>"><input type="hidden" name="_return" value="/settings?project=<?= (int)$project['id'] ?>#workflow">
 <label>Libellé<input name="label" value="<?= e($definition['label']) ?>" maxlength="80" required></label>
 <div class="inline-fields"><label>Catégorie<select name="category"><?php foreach(($kind==='status'?Workflow::CATEGORIES+['paused'=>'En pause (réservé)']:App\Models\Ticket::TYPES) as $value=>$text): ?><option value="<?= e($value) ?>" <?= $value===$definition['category']?'selected':'' ?> <?= $value==='paused'&&$code!=='paused'?'disabled':'' ?>><?= e($text) ?></option><?php endforeach; ?></select></label><label>Ordre<input type="number" name="position" min="0" max="999" value="<?= (int)$definition['position'] ?>" required></label></div>
 <?php $pickerName='';$pickerMark=Workflow::mark($kind,(int)$project['id'],$code);$pickerLabel='Pictogramme et couleur';require ROOT.'/app/Views/partials/appearance-picker.php'; ?>
 <input type="hidden" name="enabled_present" value="1"><label class="check-label"><input type="checkbox" name="enabled" value="1" <?= $definition['enabled']?'checked':'' ?>>Disponible dans le projet</label>
 <small class="help-text">Clé API immuable : <code><?= e($code) ?></code>. Les catégories standard et celles déjà utilisées sont protégées.</small>
 <button class="button subtle">Enregistrer <?= e($definition['label']) ?></button>
 </form></details>
 <?php endforeach; ?></div>
 <details class="workflow-add" data-settings-key="add-<?= e($kind) ?>"><summary class="text-button">+ Ajouter <?= $kind==='status'?'un statut':'un type de ticket' ?></summary>
 <form action="/projects/<?= (int)$project['id'] ?>/workflow" method="post" class="stack details-content">
 <?= csrf() ?><input type="hidden" name="version" value="<?= $workflow['version'] ?>"><input type="hidden" name="action" value="add_item"><input type="hidden" name="kind" value="<?= e($kind) ?>"><input type="hidden" name="_return" value="/settings?project=<?= (int)$project['id'] ?>#workflow">
 <label>Libellé<input name="label" required maxlength="80" placeholder="<?= $kind==='status'?'En attente du client':'Incident' ?>"></label>
 <div class="inline-fields"><label>Catégorie<select name="category"><?php foreach(($kind==='status'?Workflow::CATEGORIES:App\Models\Ticket::TYPES) as $value=>$text): ?><option value="<?= e($value) ?>"><?= e($text) ?></option><?php endforeach; ?></select></label><label>Ordre<input type="number" name="position" min="0" max="999" value="25" required></label></div>
 <?php $pickerName='';$pickerMark=['icon'=>$kind==='status'?'clock':'task','color'=>'#7166eb'];$pickerLabel='Pictogramme et couleur';require ROOT.'/app/Views/partials/appearance-picker.php'; ?>
 <button class="button primary">Ajouter <?= $kind==='status'?'le statut':'le type' ?></button>
 </form></details>
 <?php endforeach; ?>
 <h3>Passages autorisés</h3>
 <form action="/projects/<?= (int)$project['id'] ?>/workflow" method="post" class="stack">
 <?= csrf() ?><input type="hidden" name="version" value="<?= $workflow['version'] ?>"><input type="hidden" name="action" value="transitions"><input type="hidden" name="_return" value="/settings?project=<?= (int)$project['id'] ?>#workflow">
 <label class="check-label"><input type="checkbox" name="restricted" value="1" <?= $workflow['restricted']?'checked':'' ?>>Autoriser uniquement les passages cochés ci-dessous</label>
 <p class="help-text">Sans restriction, tous les passages sont autorisés. Avec restriction, cochez les destinations de chaque ligne. Rester dans le même état et mettre en pause/reprendre restent possibles. Les automatisations et le MCP suivent les mêmes règles.</p>
 <div class="table-scroll workflow-transitions"><table><thead><tr><th>Depuis ↓ / Vers →</th><?php $workflowStates=Workflow::labels('status',(int)$project['id'],true);unset($workflowStates['paused']); foreach($workflowStates as $text): ?><th><?= e($text) ?></th><?php endforeach; ?></tr></thead><tbody>
 <?php foreach($workflowStates as $from=>$fromText): ?><tr><th><?= e($fromText) ?></th><?php foreach($workflowStates as $to=>$toText): ?><td><?php if($from===$to): ?><?= icon('check') ?><?php else: ?><input type="checkbox" name="transitions[<?= e($from) ?>][]" value="<?= e($to) ?>" aria-label="<?= e($fromText.' vers '.$toText) ?>" <?= in_array($to,$workflow['transitions'][$from]??[],true)?'checked':'' ?>><?php endif; ?></td><?php endforeach; ?></tr><?php endforeach; ?>
 </tbody></table></div><button class="button primary">Enregistrer les passages autorisés</button>
 </form>
 <script type="application/json" id="workflow-data"><?= json_encode(['status'=>Workflow::labels('status',(int)$project['id'],true),'type'=>Workflow::labels('type',(int)$project['id'],true)],JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_AMP|JSON_HEX_QUOT|JSON_THROW_ON_ERROR) ?></script>
</section>
