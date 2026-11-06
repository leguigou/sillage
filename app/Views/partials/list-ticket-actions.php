<?php
/**
 * Per-row "more" menu (HTML popover) of the ticket list: open, edit and comment (not for
 * viewers), copy link, history, development. Included by sections/list.php for each row.
 * Expects $t (ticket row) and $user.
 */
$rowMenuId='ticket-actions-'.(int)$t['id']; ?>
<button type="button" class="icon-button" data-ticket-menu-button popovertarget="<?= e($rowMenuId) ?>" aria-haspopup="menu" aria-expanded="false" aria-controls="<?= e($rowMenuId) ?>" aria-label="Actions pour <?= e($t['key']) ?>"><?= icon('more') ?></button>
<div id="<?= e($rowMenuId) ?>" class="dropdown-menu ticket-row-menu" popover="auto" role="menu" aria-label="Actions pour <?= e($t['key']) ?>">
 <a role="menuitem" href="<?= e(ticket_url((int)$t['id'])) ?>"><?= icon('arrow') ?>Ouvrir le ticket</a>
 <?php if($user['role']!=='viewer'): ?><a role="menuitem" href="<?= e(ticket_url((int)$t['id'],'#ticket-edit-dialog')) ?>"><?= icon('edit') ?>Modifier le ticket</a><a role="menuitem" href="<?= e(ticket_url((int)$t['id'],'#ticket-comment-dialog')) ?>"><?= icon('comment') ?>Ajouter un commentaire</a><?php endif; ?>
 <button type="button" role="menuitem" data-copy="<?= e(url('/tickets/'.$t['id'])) ?>"><?= icon('link') ?>Copier le lien</button>
 <div class="ticket-menu-separator" role="separator"></div><a role="menuitem" href="<?= e(ticket_url((int)$t['id'],'#history')) ?>"><?= icon('clock') ?>Voir l’historique</a><a role="menuitem" href="<?= e(ticket_url((int)$t['id'],'#development')) ?>"><?= icon('code') ?>Développement</a>
</div>
