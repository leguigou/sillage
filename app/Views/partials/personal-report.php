<?php
$currentReport=$activeView;
if($currentReport) {$reportCreate=false;$reportDialogId='save-view-dialog';require ROOT.'/app/Views/partials/personal-report-form.php';}
$activeView=null;$reportCreate=true;$reportDialogId=$currentReport?'create-report-dialog':'save-view-dialog';
require ROOT.'/app/Views/partials/personal-report-form.php';$activeView=$currentReport;
?>
<script src="<?= e(asset_url('personal-reports.js')) ?>" defer></script>
