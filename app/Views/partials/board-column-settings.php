<?php /**
 * Dialog #board-column-settings: personal look of one board column (background colour
 * and opacity, width, card density). Included by sections/board.php.
 * Static markup with no PHP variables: the form has no action and nothing is sent to the
 * server; public/assets/board-columns.js fills it and saves the values in localStorage.
 */ ?>
<dialog id="board-column-settings" class="dialog compact">
 <header class="dialog-header"><h2 data-column-settings-title>Personnaliser la colonne</h2><button type="button" class="icon-button" data-close aria-label="Fermer"><?= icon('close') ?></button></header>
 <form class="stack" data-column-settings-form>
  <p class="help-text">Réglages personnels pour ce projet, conservés dans ce navigateur. L’aperçu s’applique à la colonne derrière cette fenêtre.</p>
  <label class="check-label"><input type="checkbox" name="custom_background">Personnaliser le fond</label>
  <label>Couleur de fond<input type="color" name="color" value="#8b7cf8"></label>
  <label>Opacité du fond <output data-column-opacity>12 %</output><input type="range" name="opacity" min="0" max="100" step="1" value="12"><small>0 % : transparent · 100 % : opaque.</small></label>
  <label>Largeur<select name="width"><option value="">Selon les options du tableau</option><option value="250">250 px</option><option value="270">270 px</option><option value="320">320 px</option><option value="360">360 px</option><option value="420">420 px</option></select></label>
  <label>Espacement des cartes<select name="density"><option value="normal">Confortable</option><option value="compact">Compact</option></select></label>
  <p class="help-text" role="status" data-column-settings-status></p>
  <div class="board-column-dialog-actions"><button type="button" class="button subtle" data-column-defaults>Rétablir les valeurs par défaut</button><button type="submit" class="button primary">Enregistrer</button></div>
 </form>
</dialog>
