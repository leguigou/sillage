<?php
/**
 * Helper file, not a page: defines copy_field(), which returns the HTML of a read-only field plus a "Copier" button.
 * Loaded with require_once by partials/mcp-tokens.php, partials/oauth-settings.php and partials/provider-settings.php.
 * Label and value are escaped with e(); $secret=true renders a password-type input, multi-line values a textarea.
 * The button only carries data-copy-target="<field id>"; the actual copy is done by delegated front-end JavaScript.
 * Relies on icon() from icons.php being loaded first.
 */

/** Read-only value with an accessible, delegated copy action. */
function copy_field(string $label, string $value, bool $secret=false): string {
    $id='copy-value-'.bin2hex(random_bytes(8));
    $field=str_contains($value,"\n")
        ? '<textarea id="'.$id.'" readonly rows="2" spellcheck="false">'.e($value).'</textarea>'
        : '<input id="'.$id.'" readonly type="'.($secret?'password':'text').'" value="'.e($value).'" autocomplete="off" spellcheck="false">';
    return '<div class="copy-field"><label for="'.$id.'">'.e($label).'</label><div class="copy-value">'.$field.'<button type="button" class="button subtle copy-button" data-copy-target="'.$id.'" aria-label="Copier '.e($label).'">'.icon('copy').'<span>Copier</span></button></div></div>';
}
