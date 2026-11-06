<?php /**
 * Dialog #test-procedure-dialog: write a test procedure after a pull request is merged to
 * staging and publish it in the ticket conversation. Included by ticket.php when $canEdit
 * and the ticket is not archived. No PHP variables besides csrf(): it is opened from
 * data-test-procedure buttons and submitted by ticket-development.js
 * (POST /tickets/{id}/development/pull-requests/{event}/test-procedure).
 */ ?>
<dialog id="test-procedure-dialog" class="dialog" aria-labelledby="test-procedure-title"><header class="dialog-header"><div><h2 id="test-procedure-title">Procédure de test</h2><p class="help-text" data-procedure-context></p></div><button type="button" class="icon-button" data-close aria-label="Fermer"><?= icon('close') ?></button></header>
<p class="help-text">Après la fusion sur staging, expliquez comment vérifier la correction. Vérifiez d’abord que le serveur dispose bien de cette version.</p>
<p role="status" aria-live="polite" data-procedure-status></p>
<form class="stack" data-test-procedure-form><?= csrf() ?><input type="hidden" name="submission_key">
<label>URL du serveur à tester (facultatif)<input name="url" type="url" maxlength="1000" placeholder="https://recette.exemple.fr"></label>
<label>Préconditions (facultatif)<textarea name="preconditions" maxlength="1000" rows="2" placeholder="Compte, droits ou données nécessaires…"></textarea></label>
<label>Étapes de test<textarea name="steps" required maxlength="6000" rows="5" placeholder="1. Ouvrir…&#10;2. Cliquer sur…&#10;3. Vérifier…"></textarea></label>
<label>Résultat attendu<textarea name="expected" required maxlength="2000" rows="3" placeholder="Ce que la personne doit constater pour valider le test…"></textarea></label>
<div class="ticket-development-actions"><button type="button" class="button subtle" data-close>Annuler</button><button class="button primary" data-procedure-publish disabled>Publier dans le suivi</button></div></form></dialog>
