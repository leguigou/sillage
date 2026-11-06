<?php
/**
 * Outputs the personal report dialogs (personal-report-form.php) and loads
 * personal-reports.js. Included by filters.php; expects $activeView plus what the form needs.
 * With an active report: an edit dialog #save-view-dialog and a create dialog
 * #create-report-dialog. Without one: a single create dialog using the id #save-view-dialog.
 * $activeView is temporarily set to null for the create form and restored afterwards.
 */
$currentReport=$activeView;
if($currentReport) {$reportCreate=false;$reportDialogId='save-view-dialog';require ROOT.'/app/Views/partials/personal-report-form.php';}
$activeView=null;$reportCreate=true;$reportDialogId=$currentReport?'create-report-dialog':'save-view-dialog';
require ROOT.'/app/Views/partials/personal-report-form.php';$activeView=$currentReport;
?>
<script src="<?= e(asset_url('personal-reports.js')) ?>" defer></script>
