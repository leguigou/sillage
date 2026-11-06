<?php
/**
 * "États à afficher" fieldset: one status[] checkbox per workflow status of a project.
 * Included by filters.php (#filters-dialog) and personal-report-form.php.
 * Expects $statusFilterProject (project id, 0 on the cross-project "all" section) and
 * $filters. Default ticks come from App\Services\Filter::visibleStatuses(), replaced by the
 * explicit selection when Filter::selectedStatuses() returns one. The empty hidden
 * status[] input makes the field present even when no box is checked.
 */
$statusOptions = App\Models\Workflow::labels('status', $statusFilterProject);
$checkedStatuses = App\Services\Filter::visibleStatuses($filters, $statusFilterProject);
$explicitStatuses = App\Services\Filter::selectedStatuses($filters);
if ($explicitStatuses !== null) $checkedStatuses = array_intersect_key($statusOptions, array_flip($explicitStatuses));
?>
<fieldset class="status-filter">
  <legend>États à afficher</legend>
  <input type="hidden" name="status[]" value="">
  <div class="status-filter-options">
    <?php foreach ($statusOptions as $code => $label): ?>
      <label class="check-label"><input type="checkbox" name="status[]" value="<?= e($code) ?>" <?= isset($checkedStatuses[$code]) ? 'checked' : '' ?>><?= e($label) ?></label>
    <?php endforeach; ?>
  </div>
</fieldset>
