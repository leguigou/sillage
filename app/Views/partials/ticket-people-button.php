<?php
/**
 * Compact button showing a ticket's assignee and collaborators (3 avatars + overflow
 * count) and its team; clicking it opens the people dialog (data-ticket-people,
 * ticket-people.js). Included by sections/board.php for each card, and rendered by
 * TicketPeopleController (the 'button' field of its JSON reply).
 * Expects $t (ticket with assignee_id, assignee_name, team_name, key) and $people
 * (collaborators of that ticket).
 */
$linked=[];if($t['assignee_id']) $linked[]=['id'=>$t['assignee_id'],'name'=>$t['assignee_name']];foreach($people as $person) if((int)$person['id']!==(int)$t['assignee_id']) $linked[]=$person; ?>
<button type="button" class="ticket-people-button" data-ticket-people="<?= (int)$t['id'] ?>" aria-label="<?= e('Personnes et équipe de '.$t['key']) ?>" title="<?= e(implode(', ',array_column($linked,'name')).($t['team_name']?' · '.$t['team_name']:'')?:'Ajouter des personnes ou une équipe') ?>"><?php foreach(array_slice($linked,0,3) as $person) echo avatar($person['name'],'small',(int)$person['id']); ?><?php if(count($linked)>3): ?><span class="people-overflow">+<?= count($linked)-3 ?></span><?php endif; ?><?php if(!$linked): ?><?= icon('user-add') ?><?php endif; ?><?php if($t['team_name']): ?><span class="card-people-team"><?= e($t['team_name']) ?></span><?php endif; ?></button>
