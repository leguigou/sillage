<?php use App\Models\Ticket; ?>
<section class="panel ticket-facts"><h2>Informations</h2><dl class="ticket-meta">
  <dt>État</dt><dd><?= e(Ticket::STATUSES[$ticket['status']]) ?></dd>
  <dt>Type</dt><dd><?= e(Ticket::TYPES[$ticket['type']]) ?></dd>
  <dt>Priorité</dt><dd><?= e(Ticket::PRIORITIES[$ticket['priority']]) ?></dd>
  <dt>Personne assignée</dt><dd><?= e($ticket['assignee_name']??'Non assigné') ?></dd>
  <dt>Équipe responsable</dt><dd><?= e($ticket['team_name']??'Sans équipe') ?></dd>
  <dt>Avancement</dt><dd><?= (int)$ticket['progress_percent'] ?> %</dd>
  <dt>Temps total estimé</dt><dd><?= $ticket['estimated_hours']===null?'Non renseigné':e(number_format((float)$ticket['estimated_hours'],2,',',' ')).' h' ?></dd>
  <dt>Date de début</dt><dd><?= e($ticket['start_date']?:'Non renseignée') ?></dd>
  <dt>Échéance</dt><dd><?= e($ticket['due_date']?:'Non renseignée') ?></dd>
  <dt>Parent</dt><dd><?php if($parent): ?><a href="<?= e(ticket_url((int)$parent['id'])) ?>"><?= e($parent['project_key'].'-'.$parent['number'].' · '.$parent['title']) ?></a><?php else: ?>Aucun<?php endif; ?></dd>
  <dt>Étiquettes</dt><dd><?= e($ticket['labels_array']?implode(', ',$ticket['labels_array']):'Aucune') ?></dd>
  <dt>Bloqué</dt><dd><?= $ticket['flagged']?'Oui':'Non' ?></dd>
</dl></section>
<section class="panel"><h3>Suivi du ticket</h3><dl class="ticket-meta">
  <dt>Projet</dt><dd><?= e($project['name']) ?></dd><dt>Rapporteur</dt><dd><?= e($ticket['reporter_name']??'Externe') ?></dd><dt>Créé le</dt><dd><?= e(substr($ticket['created_at'],0,16)) ?></dd><dt>Mis à jour le</dt><dd><?= e(substr($ticket['updated_at'],0,16)) ?></dd><?php if($ticket['external_email']): ?><dt>Contact externe</dt><dd><?= e($ticket['external_email']) ?></dd><?php endif; ?>
</dl></section>
