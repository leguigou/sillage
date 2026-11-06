<?php
/**
 * Column options of the ticket list: show/hide checkboxes and, on the list/all sections
 * only, up/down reorder buttons and the "Avancé" display modes. Included by filters.php
 * inside #view-options-dialog. Expects $section and $filters; column keys, labels and
 * order come from App\Services\ListLayout. The controls only carry data-* hooks (no
 * name attribute) and are applied by front-end JS rather than submitted.
 */
$reorderColumns=in_array($section,['list','all'],true); $visibleListColumns=App\Services\ListLayout::columns($filters); ?>
<div class="column-picker <?= $reorderColumns?'column-order-picker':'' ?>" data-list-column-options><?php foreach(App\Services\ListLayout::order($filters) as $key): $label=App\Services\ListLayout::COLUMNS[$key]; ?><div class="column-option-row" data-list-column="<?= e($key) ?>"><label class="check-label"><input type="checkbox" data-column-toggle="<?= e($key) ?>" <?= in_array($key,$visibleListColumns,true)?'checked':'' ?> <?= $key==='title'?'disabled':'' ?>><?= e($label) ?></label><?php if($reorderColumns): ?><button type="button" class="icon-button" data-column-move="up" aria-label="<?= e('Monter '.$label) ?>" title="Monter"><?= icon('up') ?></button><button type="button" class="icon-button" data-column-move="down" aria-label="<?= e('Descendre '.$label) ?>" title="Descendre"><?= icon('down') ?></button><?php endif; ?></div><?php endforeach; ?></div>
<?php if($reorderColumns): ?><p class="help-text">Glissez le texte des en-têtes ou utilisez les flèches ici. Le résumé reste visible.</p><?php endif; ?>

<?php if ($reorderColumns): ?>
<details class="list-advanced-options">
  <summary>Avancé</summary>
  <div class="stack">
    <label class="check-label"><input type="checkbox" data-list-label-visible>Afficher le libellé du type à côté de l’icône</label>
    <label>Priorité
      <select data-list-priority-mode>
        <option value="label">Icône et libellé</option>
        <option value="icon">Icône seule</option>
        <option value="code">Code P1 à P6</option>
      </select>
    </label>
    <label>Personne assignée
      <select data-list-assignee-mode>
        <option value="name">Nom de la personne</option>
        <option value="avatar">Avatar</option>
      </select>
    </label>
    <label>Avancement
      <select data-list-progress-mode>
        <option value="both">Barre et temps estimé</option>
        <option value="bar">Barre seule</option>
        <option value="estimate">Temps estimé seul</option>
        <option value="bar-remaining">Barre et temps restant</option>
        <option value="remaining">Temps restant seul</option>
      </select>
    </label>
  </div>
</details>
<?php endif; ?>
