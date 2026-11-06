<?php /**
 * Dialog #development-branch-ticket: link a Git branch to an open ticket that has no
 * branch yet. Included by sections/development.php for every role except "viewer".
 * No PHP variables (only csrf()); the form has no action: the ticket search, the
 * options and the submission are handled by public/assets/development-branch-ticket.js.
 */ ?>
<dialog id="development-branch-ticket" class="dialog compact" aria-labelledby="branch-ticket-title">
  <header class="dialog-header">
    <div><h2 id="branch-ticket-title">Lier la branche à un ticket</h2><p class="help-text" data-branch-ticket-title></p></div>
    <button type="button" class="icon-button" data-close aria-label="Fermer"><?= icon('close') ?></button>
  </header>
  <form method="post" class="stack" data-branch-ticket-form>
    <?= csrf() ?>
    <label>Rechercher par clé ou résumé<input type="search" maxlength="120" data-branch-ticket-search data-dialog-ignore placeholder="Ex. : WEB-125 ou sitemap"></label>
    <label>Ticket ouvert sans branche<select name="ticket_id" size="6" required></select></label>
    <p class="help-text" role="status" aria-live="polite" data-branch-ticket-status></p>
    <footer class="dialog-footer">
      <button type="button" class="button subtle" data-close>Annuler</button>
      <button type="submit" class="button primary" disabled>Lier la branche</button>
    </footer>
  </form>
</dialog>
