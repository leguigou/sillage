<?php
use App\Models\Ticket;
use App\Services\TimelineCalendar;
$calendar=TimelineCalendar::build((string)($filters['scale']??'weeks'),$filters['from']??null);
$scale=$calendar['scale']; $start=$calendar['start']; $days=$calendar['days']; $origin=$calendar['origin'];
?>
<div class="timeline-controls">
 <a class="button subtle" href="<?= e(query_url('/timeline',$baseQuery,['from'=>$calendar['todayOrigin']])) ?>">Aujourd’hui</a>
 <div class="segmented"><?php foreach(['weeks'=>'Semaines','months'=>'Mois','quarters'=>'Trimestres'] as $value=>$label): ?><a class="<?= $scale===$value?'selected':'' ?>" href="<?= e(query_url('/timeline',$baseQuery,['scale'=>$value])) ?>" <?= $scale===$value?'aria-current="true"':'' ?>><?= e($label) ?></a><?php endforeach; ?></div>
 <div class="toolbar-spacer"></div>
 <a class="icon-button" aria-label="Période précédente" href="<?= e(query_url('/timeline',$baseQuery,['from'=>$start->modify('-'.$calendar['step'].' days')->format('Y-m-d')])) ?>">←</a>
 <span><?= e($start->format('d/m/Y')) ?> — <?= e($calendar['last']) ?></span>
 <a class="icon-button" aria-label="Période suivante" href="<?= e(query_url('/timeline',$baseQuery,['from'=>$start->modify('+'.$calendar['step'].' days')->format('Y-m-d')])) ?>">→</a>
</div>
<div class="timeline-panel" tabindex="0" role="region" aria-label="Chronologie des tickets, semaines et jours ; défilement horizontal disponible">
 <div class="timeline-grid timeline-scale-<?= e($scale) ?>" data-timeline-grid-days="<?= $days ?>">
  <div class="timeline-head"><div class="timeline-corner">Tickets <small>Semaines ISO · du lundi au dimanche</small></div><div class="timeline-ruler">
   <div class="timeline-month-band"><?php foreach($calendar['bands'] as $band): ?><span class="timeline-month" data-timeline-left="<?= $band['left'] ?>" data-timeline-width="<?= $band['width'] ?>"><?= e($band['label']) ?></span><?php endforeach; ?></div>
   <div class="timeline-week-band"><?php foreach($calendar['weeks'] as $week): ?><span class="timeline-week" data-week="<?= e($week['key']) ?>" data-timeline-left="<?= $week['left'] ?>" data-timeline-width="<?= $week['width'] ?>" title="<?= e($week['title']) ?>"><strong>S<?= e($week['number']) ?></strong><small><?= e($week['range']) ?></small></span><?php endforeach; ?></div>
   <div class="timeline-day-band"><?php foreach($calendar['dates'] as $date): ?><span class="timeline-day <?= $date['weekend']?'is-weekend':'' ?> <?= $date['today']?'is-today':'' ?>" data-date="<?= e($date['date']) ?>" data-timeline-left="<?= $date['left'] ?>" data-timeline-width="<?= $date['width'] ?>" title="<?= e(($date['today']?'Aujourd’hui · ':'').$date['weekday'].' '.(new DateTimeImmutable($date['date']))->format('d/m/Y')) ?>" <?= $date['today']?'aria-current="date"':'' ?>><small><?= e($date['weekday']) ?></small><strong><?= e($date['day']) ?></strong></span><?php endforeach; ?></div>
  </div></div>
  <?php foreach($tickets as $ticket): $bar=$ticket['start_date']?TimelineCalendar::bar($calendar,$ticket['start_date'],$ticket['due_date']):null; ?>
  <div class="timeline-row" data-ticket-id="<?= (int)$ticket['id'] ?>" data-version="<?= (int)$ticket['version'] ?>" data-start-date="<?= e($ticket['start_date']) ?>" data-due-date="<?= e($ticket['due_date']) ?>">
   <a href="<?= e(ticket_url((int)$ticket['id'])) ?>" class="timeline-task"><span class="type-icon type-<?= e($ticket['type']) ?>"><?= $ticket['type']==='epic'?'ϟ':'✓' ?></span><span><small><?= e($ticket['key']) ?><?= blocked_badge((bool)$ticket['flagged']) ?></small><?= e($ticket['title']) ?></span></a>
   <div class="timeline-track" data-timeline-origin="<?= e($origin) ?>" data-timeline-days="<?= $days ?>">
    <?php if($calendar['todayLeft']!==null): ?><span class="timeline-today-line" data-timeline-left="<?= $calendar['todayLeft'] ?>" aria-hidden="true"></span><?php endif; ?>
    <?php if($bar): ?><a class="timeline-bar status-<?= e($ticket['status']) ?>" href="<?= e(ticket_url((int)$ticket['id'])) ?>" data-timeline-left="<?= $bar['left'] ?>" data-timeline-width="<?= $bar['width'] ?>" title="<?= e($ticket['title'].' · '.$bar['range'].($bar['clippedStart']?' · Commence avant la période':'').($bar['clippedEnd']?' · Se termine après la période':'')) ?>"><?= $bar['clippedStart']?'‹ ':'' ?><?= e($ticket['title']) ?><?= $bar['clippedEnd']?' ›':'' ?></a>
    <?php elseif($ticket['start_date']): ?><a class="timeline-outside" href="<?= e(ticket_url((int)$ticket['id'])) ?>" title="<?= e($ticket['start_date'].' → '.($ticket['due_date']?:$ticket['start_date'])) ?>"><?= ($ticket['due_date']?:$ticket['start_date'])<$origin?'Avant la période':'Après la période' ?></a><?php else: ?><button class="timeline-unscheduled" data-schedule-ticket="<?= (int)$ticket['id'] ?>">+ Planifier</button><?php endif; ?>
   </div>
  </div>
  <?php endforeach; if(!$tickets): ?><div class="empty-state"><h3>Votre prochaine étape commence ici.</h3><p>Ajoutez un ticket ou ajustez les filtres.</p></div><?php endif; ?>
 </div>
</div>
<div class="timeline-legend"><?php foreach(App\Services\Filter::visibleStatuses($filters) as $value=>$label): ?><span><i class="legend-dot status-<?= e($value) ?>"></i><?= e($label) ?></span><?php endforeach; ?><span><i class="timeline-today-key"></i>Aujourd’hui</span><span><i class="timeline-weekend-key"></i>Week-end</span><small><?= (int)($days/7) ?> semaines affichées. Survolez un jour ou une barre pour ses dates exactes.</small></div>
<dialog id="schedule-dialog" class="dialog compact"><form class="stack" data-schedule-form><header class="dialog-header"><h2>Donner une date au prochain pas</h2><button type="button" class="icon-button" data-close aria-label="Fermer"><?= icon('close') ?></button></header><input type="hidden" name="ticket_id"><label>Date de début<input type="date" name="start_date" required></label><label>Date d’échéance<input type="date" name="due_date" required></label><button class="button primary">Planifier</button></form></dialog>
<?php $timelineLinks=App\Core\DB::all("SELECT l.source_id,l.target_id FROM ticket_links l JOIN tickets t ON t.id=l.source_id WHERE t.project_id=? AND l.relation='blocks'",[$project['id']]); ?><script type="application/json" id="timeline-dependencies"><?= json_encode($timelineLinks,JSON_HEX_TAG|JSON_HEX_AMP|JSON_THROW_ON_ERROR) ?></script>
