<?php use App\Models\Ticket; ?>
<section class="panel ticket-facts" id="ticket-facts" data-ticket-live><div class="ticket-facts-heading"><h2>Informations</h2><?= blocked_badge((bool)$ticket['flagged']) ?></div><dl class="ticket-meta">
  <dt>État</dt><dd><?= status_badge($ticket['status'],(int)$project['id']) ?></dd>
  <dt>Type</dt><dd class="ticket-mark-label"><?= work_mark('type',$ticket['type'],(int)$project['id']) ?><?= e(\App\Models\Workflow::label('type',(int)$project['id'],$ticket['type'])) ?></dd>
  <dt>Priorité</dt><dd class="ticket-mark-label"><?= work_mark('priority',$ticket['priority']) ?><?= e(Ticket::PRIORITIES[$ticket['priority']]) ?></dd>
  <dt>Personne assignée</dt><dd><?= e($ticket['assignee_name']??'Non assigné') ?></dd>
  <dt>Équipe responsable</dt><dd><?= e($ticket['team_name']??'Sans équipe') ?></dd>
  <dt>Avancement</dt><dd><?= (int)$ticket['progress_percent'] ?> %</dd>
  <dt>Temps total estimé</dt><dd><?= $ticket['estimated_hours']===null?'Non renseigné':e(number_format((float)$ticket['estimated_hours'],2,',',' ')).' h' ?></dd>
  <dt>Temps passé</dt><dd><?= e(App\Core\View::hours($ticket['spent_hours'])) ?></dd>
  <dt>Reste à faire</dt><dd><?= $ticket['remaining_hours']===null?'Sans estimation':e(App\Core\View::hours($ticket['remaining_hours'])) ?></dd>
  <dt>Date de début</dt><dd><?= e($ticket['start_date']?:'Non renseignée') ?></dd>
  <dt>Échéance</dt><dd><?= e($ticket['due_date']?:'Non renseignée') ?></dd>
  <dt>Parent</dt><dd><?php if($parent): ?><a href="<?= e(ticket_url((int)$parent['id'])) ?>"><?= e($parent['project_key'].'-'.$parent['number'].' · '.$parent['title']) ?></a><?php else: ?>Aucun<?php endif; ?></dd>
  <dt>Étiquettes</dt><dd><?= e($ticket['labels_array']?implode(', ',$ticket['labels_array']):'Aucune') ?></dd>
</dl>
<?php if($ticket['status']==='paused'): ?><p class="help-text ticket-pause-note">Mis de côté pour plus tard. Ce ticket reste accessible avec le filtre « En pause ».</p><?php endif; ?>
<?php if($canEdit && !$ticket['archived']): ?><form method="post" action="/tickets/<?= (int)$ticket['id'] ?>/pause" class="ticket-pause-action"><?= csrf() ?><?= ticket_context() ?><input type="hidden" name="_return" value="<?= e(ticket_url((int)$ticket['id'])) ?>"><input type="hidden" name="version" value="<?= (int)$ticket['version'] ?>"><input type="hidden" name="action" value="<?= $ticket['status']==='paused'?'resume':'pause' ?>"><button class="button subtle"><?= icon($ticket['status']==='paused'?'arrow':'clock') ?><?= $ticket['status']==='paused'?'Reprendre le ticket':'Mettre en pause' ?></button></form><?php endif; ?>
</section>
<?php $following=App\Models\TicketFollow::get((int)$ticket['id'],(int)$user['id']); ?><section class="panel" id="ticket-following" data-ticket-live><h3>Suivi du ticket</h3><form action="/tickets/<?= (int)$ticket['id'] ?>/follow" method="post" class="stack" data-ticket-follow><?= csrf() ?><?= ticket_context() ?><input type="hidden" name="action" value="<?= $following['following']?'unfollow':'follow' ?>"><button class="button subtle" aria-pressed="<?= $following['following']?'true':'false' ?>"><?= icon('bell') ?><span data-follow-label><?= $following['following']?'Ne plus suivre':'Suivre ce ticket' ?></span></button><small data-follow-count><?= $following['count'] ?> personne(s) suivent ce ticket</small></form><dl class="ticket-meta">
  <dt>Projet</dt><dd><?= e($project['name']) ?></dd><dt>Rapporteur</dt><dd><?= e($ticket['reporter_name']??'Externe') ?></dd><dt>Créé le</dt><dd><?= e(substr($ticket['created_at'],0,16)) ?></dd><dt>Mis à jour le</dt><dd><?= e(substr($ticket['updated_at'],0,16)) ?></dd><?php if($ticket['external_email']): ?><dt>Contact externe</dt><dd><?= e($ticket['external_email']) ?></dd><?php endif; ?>
</dl></section>
