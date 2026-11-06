<?php
/**
 * Ticket table section for /list and /all (both map to this file in workspace.php).
 * Expects $tickets (one page of 50), $count, $page, $limit, $filters, $section, $baseQuery (from workspace.php),
 * $project, $members, $user, $activeView and $rules.
 * Visible columns and their order come from ListLayout::columns()/order(); each row's cells and actions are rendered
 * by partials/list-ticket-cells.php and partials/list-ticket-actions.php. $deploymentBadges (TicketDevelopment::badges())
 * is prepared here for those partials.
 * Before output, rows may be reordered as a parent/child tree ($filters['hierarchy']) and then grouped ($filters['group']).
 * The hidden #bulk-form (POST /bulk) is shown by JS when rows are ticked; its value choices are in #bulk-value-options.
 */
use App\Models\Ticket;
use App\Services\ListLayout;
$deploymentBadges=App\Services\TicketDevelopment::badges($tickets);
$listColumns=ListLayout::columns($filters);$listOrder=ListLayout::order($filters);
?><?php require ROOT.'/app/Views/partials/list-assignee-avatars.php'; ?><template id="priority-mark-templates"><?php foreach(Ticket::PRIORITIES as $priority=>$label) echo work_mark('priority',$priority,shortLabel:Ticket::PRIORITY_CODES[$priority]); ?></template><?php
if(!empty($filters['hierarchy'])) {
    $index=array_column($tickets,null,'id'); $childrenByParent=[]; $roots=[];
    foreach($tickets as $row) { if($row['parent_id']&&isset($index[$row['parent_id']])) $childrenByParent[$row['parent_id']][]=$row; else $roots[]=$row; }
    $ordered=[]; $visited=[];
    $visit=function(array $row,int $depth) use(&$visit,&$ordered,&$visited,$childrenByParent): void { if(isset($visited[$row['id']])) return; $visited[$row['id']]=true; $row['_depth']=$depth; $ordered[]=$row; foreach($childrenByParent[$row['id']]??[] as $child) $visit($child,$depth+1); };
    foreach($roots as $rootRow) $visit($rootRow,0);
    foreach($tickets as $remaining) if(!isset($visited[$remaining['id']])) $visit($remaining,0);
    $tickets=$ordered;
}
$group=$filters['group']??''; $grouped=[];
foreach($tickets as $ticket) { $g=match($group){'team'=>$ticket['team_name']??'Sans équipe','assignee'=>$ticket['assignee_name']??'Non assigné','priority'=>Ticket::PRIORITIES[$ticket['priority']],'type'=>\App\Models\Workflow::label('type',(int)$ticket['project_id'],$ticket['type']),'parent'=>$ticket['parent_id']?'Parent #'.$ticket['parent_id']:'Sans parent','labels'=>implode(', ',$ticket['labels_array'])?:'Sans étiquette',default=>''}; $grouped[$g][]=$ticket; }
?>
<form method="post" action="/bulk" id="bulk-form" class="bulk-bar" hidden><?= csrf() ?><input type="hidden" name="_return" value="<?= e(query_url('/'.$section,$baseQuery)) ?>"><span><strong data-selected-count>0</strong> sélectionné(s)</span><select name="field" aria-label="Champ à modifier"><option value="status">État</option><option value="priority">Priorité</option><option value="assignee_id">Personne assignée</option><option value="flagged">Blocage</option></select><select name="value" required aria-label="Nouvelle valeur"></select><button class="button primary small-button" data-confirm="Appliquer cette modification à tous les tickets sélectionnés ?">Appliquer</button></form>
<script type="application/json" id="bulk-value-options"><?= json_encode(['status'=>App\Models\Workflow::labels('status',(int)$project['id'],true),'priority'=>Ticket::PRIORITIES,'assignee_id'=>['none'=>'Non assigné']+array_column($members,'name','id'),'flagged'=>['0'=>'Non bloqué','1'=>'Bloqué']],JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_THROW_ON_ERROR) ?></script>
<div class="table-panel">
<table class="ticket-table"
  data-column-layout-key="<?= e($user['id'].':'.$project['id'].':'.($activeView?($activeView['id'].':'.App\Models\SavedView::revision($activeView)):'default')) ?>"
  data-report-layout="<?= e(json_encode(['columns'=>isset($filters['columns']) || isset($filters['show_progress']) ? $listColumns : null,'savedOrder'=>$activeView?ListLayout::columns(json_decode($activeView['settings'],true,64,JSON_THROW_ON_ERROR)):null],JSON_THROW_ON_ERROR)) ?>">
<thead><tr>
  <th class="checkbox-cell"><input type="checkbox" data-select-all aria-label="Sélectionner tous les tickets affichés"></th>
  <?php foreach ($listOrder as $key):
      $label = ListLayout::COLUMNS[$key];
      $sorted = ($filters['sort'] ?? 'rank') === $key;
      $ascending = ($filters['direction'] ?? 'asc') === 'asc';
  ?>
    <th data-column="<?= e($key) ?>" <?= in_array($key,$listColumns,true)?'':'hidden' ?><?= $sorted?' aria-sort="'.($ascending?'ascending':'descending').'"':'' ?>>
      <div class="list-column-heading">
      <?php if (isset(ListLayout::SORT_LABELS[$key])): ?>
        <a draggable="true" data-list-column-drag title="Cliquer pour trier, glisser pour déplacer" <?= $sorted?'aria-current="true"':'' ?> href="<?= e(query_url('/'.$section,$baseQuery,['sort'=>$key,'direction'=>$sorted && $ascending?'desc':'asc','page'=>1])) ?>"><?= e($label) ?> <?= icon($sorted && $ascending?'up':'down') ?></a>
      <?php else: ?>
        <span draggable="true" data-list-column-drag title="Glisser pour déplacer la colonne"><?= e($label) ?></span>
      <?php endif; ?>
      </div>
    </th>
  <?php endforeach; ?>
  <th></th>
</tr></thead><tbody>
<?php foreach($grouped as $groupLabel=>$rows): if($groupLabel): ?><tr class="table-group"><td colspan="<?= count($listColumns)+2 ?>"><?= e($groupLabel) ?><span><?= count($rows) ?></span></td></tr><?php endif; foreach($rows as $t): ?>
<tr data-ticket-id="<?= (int)$t['id'] ?>" data-version="<?= (int)$t['version'] ?>" data-parent="<?= (int)($t['parent_id']??0) ?>" data-depth="<?= (int)($t['_depth']??0) ?>" data-priority="<?= e($t['priority']) ?>" data-status="<?= e($t['status']) ?>" data-type="<?= e($t['type']) ?>" data-flagged="<?= (int)$t['flagged'] ?>" data-bulk-statuses="<?= e(json_encode($t['status_options'],JSON_THROW_ON_ERROR)) ?>">
<td class="checkbox-cell"><input type="checkbox" name="ids[]" value="<?= (int)$t['id'] ?>" form="bulk-form" data-select-ticket aria-label="Sélectionner <?= e($t['key']) ?>"></td><?php require ROOT.'/app/Views/partials/list-ticket-cells.php'; ?><td><?php require ROOT.'/app/Views/partials/list-ticket-actions.php'; ?></td></tr>
<?php endforeach; endforeach; ?></tbody></table><?php if(!$tickets): ?><div class="empty-state"><?= icon('search') ?><h3>Aucun ticket sur cet horizon.</h3><p>Essayez d’autres filtres, ou créez le premier ticket.</p></div><?php endif; ?><div class="table-footer"><button class="text-button" data-open="ticket-dialog"><?= icon('plus') ?>Créer un ticket</button><span><?= min($count,($page-1)*$limit+1) ?>–<?= min($count,$page*$limit) ?> sur <?= $count ?></span><div><?php if($page>1): ?><a class="button subtle small-button" href="<?= e(query_url('/'.$section,$baseQuery,['page'=>$page-1])) ?>">Précédent</a><?php endif; if($page*$limit<$count): ?><a class="button subtle small-button" href="<?= e(query_url('/'.$section,$baseQuery,['page'=>$page+1])) ?>">Suivant</a><?php endif; ?></div></div></div>
<script type="application/json" id="formatting-rules"><?= json_encode($rules,JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_THROW_ON_ERROR) ?></script>
