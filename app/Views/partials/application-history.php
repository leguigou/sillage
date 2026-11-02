<?php $applicationHistory=App\Services\ApplicationHistory::catalog();$historyEntries=$applicationHistory['entries'];$oldestHistory=$historyEntries[array_key_last($historyEntries)]; ?>
<section class="panel" id="application-history">
 <div class="panel-header"><div><h2>Versions et améliorations</h2><p>Les évolutions du logiciel Sillage et les fonctionnalités apportées à votre espace.</p></div><?= icon('clock') ?></div>
 <div class="settings-history-summary">
  <div><small>Version du code installé</small><strong><?= e($applicationHistory['current_version']) ?></strong></div>
  <div><small>Mises à jour documentées</small><strong><?= count($historyEntries) ?></strong></div>
  <div><small>Historique depuis le</small><strong><?= e($oldestHistory['date_label']) ?></strong></div>
 </div>
 <p class="help-text">Les évolutions antérieures à la numérotation sont repérées par leur date et leur révision Git. Ce journal concerne Sillage ; les versions de vos projets restent dans « Versions et livraisons ».</p>
 <div class="settings-history-filters">
  <label>Rechercher une amélioration<input type="search" placeholder="Fonctionnalité, version, mot-clé…" data-history-search aria-controls="application-history-list"></label>
  <label>Type de changement<select data-history-category aria-controls="application-history-list"><option value="">Toutes les évolutions</option><?php foreach(App\Services\ApplicationHistory::CATEGORIES as $value=>$label): ?><option value="<?= e($value) ?>"><?= e($label) ?></option><?php endforeach; ?></select></label>
  <button class="button subtle" type="button" data-history-reset hidden>Réinitialiser</button>
 </div>
 <p class="help-text" role="status" aria-live="polite" data-history-count><?= count($historyEntries) ?> mises à jour</p>
 <div class="settings-history-list" id="application-history-list">
 <?php foreach($historyEntries as $index=>$entry): ?><details class="settings-card settings-history-entry" data-history-entry data-history-kind="<?= e($entry['category']) ?>" <?= $index===0?'open':'' ?>>
  <summary><span class="pill <?= $index===0?'soft':'neutral' ?>"><?= e($entry['label']) ?></span><strong><?= e($entry['title']) ?></strong><time datetime="<?= e($entry['day']) ?>"><?= e($entry['date_label']) ?></time><?= icon('down') ?></summary>
  <div class="settings-history-content"><p><?= e($entry['summary']) ?></p><span class="pill neutral"><?= e(App\Services\ApplicationHistory::CATEGORIES[$entry['category']]) ?></span><?php if($index===0): ?><span class="pill soft">Version installée</span><?php endif; ?>
   <ul><?php foreach($entry['changes'] as $change): ?><li><?= e($change) ?></li><?php endforeach; ?></ul>
   <?php if(isset($entry['revision'])): ?><small>Révision source : <code><?= e($entry['revision']) ?></code></small><?php endif; ?>
  </div>
 </details><?php endforeach; ?>
 </div>
 <p class="settings-empty" data-history-empty hidden>Aucune amélioration ne correspond à cette recherche.</p>
</section>
