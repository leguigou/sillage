<?php
/**
 * Global HTML helper functions used by every template (not a page, outputs nothing by itself).
 * Loaded with require_once by View::render(), layout.php, guest.php and by controllers that return HTML fragments
 * (e.g. WorkspaceController::projectSettings, TicketPeopleController, TicketDevelopmentController).
 * All helpers return HTML strings and escape their dynamic values with e().
 */

/** Inline SVG icon from the built-in set below; unknown names fall back to the 'grid' icon. */
function icon(string $name, string $class=''): string {
    $paths=[
        'github'=>'<path fill="currentColor" stroke="none" d="M12 .3a12 12 0 0 0-3.8 23.4c.6.1.8-.3.8-.6v-2.3c-3.3.7-4-1.4-4-1.4-.5-1.4-1.3-1.8-1.3-1.8-1.1-.7.1-.7.1-.7 1.2.1 1.9 1.3 1.9 1.3 1 1.8 2.8 1.3 3.4 1 .1-.8.4-1.3.8-1.6-2.7-.3-5.5-1.3-5.5-5.9 0-1.3.5-2.4 1.3-3.2-.1-.3-.6-1.6.1-3.3 0 0 1-.3 3.3 1.2A11.5 11.5 0 0 1 12 6c1 0 2 .1 3 .4 2.3-1.5 3.3-1.2 3.3-1.2.7 1.7.2 3 .1 3.3.8.8 1.3 1.9 1.3 3.2 0 4.6-2.8 5.6-5.5 5.9.4.4.8 1.1.8 2.2v3.3c0 .3.2.7.8.6A12 12 0 0 0 12 .3Z"/>',
        'bitbucket'=>'<path fill="currentColor" stroke="none" fill-rule="evenodd" d="M3 3a1 1 0 0 0-1 1.2l3 16a1 1 0 0 0 1 .8h12a1 1 0 0 0 1-.8l3-16A1 1 0 0 0 21 3H3Zm5 6h8l-1 6H9L8 9Z"/>',
        'folder'=>'<path d="M3 7a2 2 0 0 1 2-2h5l2 3h7a2 2 0 0 1 2 2v9H3z"/>',
        'task'=>'<rect x="4" y="3" width="16" height="18" rx="3"/><path d="m8 12 3 3 5-6"/>',
        'bug'=>'<rect x="7" y="7" width="10" height="13" rx="5"/><path d="M9 7V5a3 3 0 0 1 6 0v2M12 8v12M3 9l4 2m10 0 4-2M3 16l4-1m10 0 4 1M5 21l3-3m8 0 3 3"/>',
        'story'=>'<path d="M5 3h14v18l-7-4-7 4zM9 7h6M9 11h6"/>',
        'epic'=>'<path d="m13 2-9 12h7l-1 8 10-13h-7z"/>',
        'subtask'=>'<rect x="3" y="3" width="11" height="11" rx="2"/><rect x="10" y="10" width="11" height="11" rx="2"/><path d="m13 15 2 2 3-4"/>',
        'globe'=>'<circle cx="12" cy="12" r="9"/><ellipse cx="12" cy="12" rx="4" ry="9"/><path d="M3 12h18"/>',
        'cart'=>'<path d="M2 3h3l3 13h11l3-9H6M9 20h.01M18 20h.01"/><circle cx="9" cy="20" r="1"/><circle cx="18" cy="20" r="1"/>',
        'support'=>'<path d="M4 13v-1a8 8 0 0 1 16 0v1M20 17v1a3 3 0 0 1-3 3h-4"/><rect x="2" y="11" width="5" height="7" rx="2"/><rect x="17" y="11" width="5" height="7" rx="2"/>',
        'server'=>'<rect x="3" y="3" width="18" height="7" rx="2"/><rect x="3" y="14" width="18" height="7" rx="2"/><path d="M7 6.5h.01M7 17.5h.01M16 7h2M16 18h2"/>',
        'rocket'=>'<path d="M9 15c-2-5 2-11 12-12 0 10-7 14-12 12Zm0 0-4 4M8 9 3 10v5l5-1m7 2-1 5h-5l1-5M3 21l3-1-2-2z"/><circle cx="16" cy="8" r="2"/>',
        'target'=>'<circle cx="12" cy="12" r="9"/><circle cx="12" cy="12" r="5"/><circle cx="12" cy="12" r="1"/>',
        'up'=>'<path d="m6 15 6-6 6 6"/>',
        'up-double'=>'<path d="m6 12 6-6 6 6m-12 6 6-6 6 6"/>',
        'down-double'=>'<path d="m6 6 6 6 6-6m-12 6 6 6 6-6"/>',
        'equal'=>'<path d="M5 9h14M5 15h14"/>',
        'panel'=>'<rect x="3" y="4" width="18" height="16" rx="3"/><path d="M9 4v16m6-11-3 3 3 3"/>',
        'grid'=>'<rect x="3" y="3" width="7" height="7" rx="2"/><rect x="14" y="3" width="7" height="7" rx="2"/><rect x="3" y="14" width="7" height="7" rx="2"/><rect x="14" y="14" width="7" height="7" rx="2"/>',
        'list'=>'<path d="M8 6h13M8 12h13M8 18h13M3 6h.01M3 12h.01M3 18h.01"/>',
        'board'=>'<rect x="3" y="4" width="18" height="16" rx="3"/><path d="M9 4v16M15 4v16M5 8h2M11 8h2M17 8h2"/>',
        'timeline'=>'<path d="M4 3v18M4 6h12M8 11h12M6 16h9M4 21h17"/>',
        'form'=>'<rect x="5" y="3" width="14" height="18" rx="3"/><path d="M9 8h6M9 12h6M9 16h3"/>',
        'flask'=>'<path d="M9 3h6m-5 0v7L4 20a1 1 0 0 0 1 1h14a1 1 0 0 0 1-1l-6-10V3M8 15h8"/>',
        'git-branch'=>'<circle cx="6" cy="5" r="3"/><circle cx="18" cy="6" r="3"/><circle cx="6" cy="19" r="3"/><path d="M6 8v8m0-4h5a7 7 0 0 0 7-3"/>',
        'git-commit'=>'<circle cx="12" cy="12" r="4"/><path d="M2 12h6m8 0h6"/>',
        'git-pull'=>'<circle cx="6" cy="5" r="3"/><circle cx="6" cy="19" r="3"/><circle cx="18" cy="19" r="3"/><path d="M6 8v8m12 0V9a4 4 0 0 0-4-4h-2m3-3-3 3 3 3"/>',
        'git-merge'=>'<circle cx="6" cy="5" r="3"/><circle cx="18" cy="19" r="3"/><path d="M6 8v5a6 6 0 0 0 6 6h3m-3-7 6-6m0 0h-5m5 0v5"/>',
        'code'=>'<path d="m8 6-6 6 6 6m8-12 6 6-6 6m-3-15-2 18"/>',
        'chart'=>'<path d="M3 3v18h18M8 16v-5M13 16V6M18 16V9"/>',
        'settings'=>'<polygon points="9.56,5.12 10.26,2.15 13.74,2.15 14.44,5.12 15.14,5.41 17.74,3.81 20.19,6.26 18.59,8.86 18.88,9.56 21.85,10.26 21.85,13.74 18.88,14.44 18.59,15.14 20.19,17.74 17.74,20.19 15.14,18.59 14.44,18.88 13.74,21.85 10.26,21.85 9.56,18.88 8.86,18.59 6.26,20.19 3.81,17.74 5.41,15.14 5.12,14.44 2.15,13.74 2.15,10.26 5.12,9.56 5.41,8.86 3.81,6.26 6.26,3.81 8.86,5.41"/><circle cx="12" cy="12" r="3.2"/>',
        'search'=>'<circle cx="10.5" cy="10.5" r="7"/><path d="m16 16 5 5"/>',
        'plus'=>'<path d="M12 5v14M5 12h14"/>',
        'chevron'=>'<path d="m9 5 7 7-7 7"/>',
        'down'=>'<path d="m6 9 6 6 6-6"/>',
        'more'=>'<circle cx="5" cy="12" r="1"/><circle cx="12" cy="12" r="1"/><circle cx="19" cy="12" r="1"/>',
        'save'=>'<path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h12l4 4v12a2 2 0 0 1-2 2Z"/><path d="M7 3v6h10V3M7 21v-8h10v8"/>',
        'edit'=>'<path d="m16 3 5 5-12 12-6 1 1-6L16 3ZM13 6l5 5M4 15l5 5"/>',
        'comment'=>'<path d="M21 11.5a8.5 8.5 0 0 1-8.5 8.5H4l-2 2v-9.5A8.5 8.5 0 0 1 10.5 4H12a9 9 0 0 1 9 7.5Z"/><path d="M7 10h9M7 14h6"/>',
        'filter'=>'<path d="M4 6h16M7 12h10M10 18h4"/><circle cx="9" cy="6" r="2"/><circle cx="15" cy="12" r="2"/>',
        'check'=>'<path d="m5 12 4 4L19 6"/>',
        'copy'=>'<rect x="8" y="8" width="12" height="13" rx="2"/><path d="M16 8V5a2 2 0 0 0-2-2H5a2 2 0 0 0-2 2v9a2 2 0 0 0 2 2h3"/>',
        'close'=>'<path d="m6 6 12 12M6 18 18 6"/>',
        'sun'=>'<circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M2 12h2M20 12h2M5 5l1 1M18 18l1 1M5 19l1-1M18 6l1-1"/>',
        'moon'=>'<path d="M20 14A9 9 0 0 1 10 3a9 9 0 1 0 10 11z"/>',
        'arrow'=>'<path d="M5 12h14m-5-5 5 5-5 5"/>',
        'calendar'=>'<rect x="3" y="5" width="18" height="16" rx="2"/><path d="M3 10h18M7 3v4m10-4v4"/>',
        'clock'=>'<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
        'user-add'=>'<circle cx="10" cy="7" r="4"/><path d="M3 21v-2a7 7 0 0 1 11-5M19 13v8m-4-4h8"/>',
        'user'=>'<circle cx="12" cy="8" r="4"/><path d="M4 21v-2a8 8 0 0 1 16 0v2"/>',
        'lock'=>'<rect x="5" y="10" width="14" height="11" rx="3"/><path d="M8 10V7a4 4 0 0 1 8 0v3"/>',
        'bell'=>'<path d="M18 8a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9M10 21h4"/>',
        'download'=>'<path d="M12 3v12m-5-5 5 5 5-5M4 16v5h16v-5"/>',
        'upload'=>'<path d="M12 16V4m-5 5 5-5 5 5M4 16v5h16v-5"/>',
        'alert'=>'<path d="m12 3 10 18H2L12 3Z"/><path d="M12 9v5m0 3h.01"/>',
        'flag'=>'<path d="M5 22V3m0 1c5-4 9 4 14 0v10c-5 4-9-4-14 0"/>',
        'spark'=>'<path d="m12 2 3 7 7 3-7 3-3 7-3-7-7-3 7-3z"/>',
        'external'=>'<path d="M14 3h7v7m0-7L10 14M10 3H4v17h17v-6"/>',
        'mail'=>'<rect x="3" y="5" width="18" height="14" rx="3"/><path d="m3 6 9 7 9-7"/>',
        'link'=>'<path d="m10 13 4-4M8 16l-2 2a4 4 0 0 1-6-6l5-5a4 4 0 0 1 6 0m2 1 2-2a4 4 0 0 1 6 6l-5 5a4 4 0 0 1-6 0"/>',
    ];
    return '<svg class="icon '.e($class).'" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.65" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'.($paths[$name]??$paths['grid']).'</svg>';
}
/** User avatar: uploaded photo via UserAvatar::url() (batch-primed in View::render) or the person's initials. */
function avatar(?string $name, string $extra='', ?int $userId=null): string { $image=\App\Models\UserAvatar::url($userId); $parts=preg_split('/\s+/u',trim($name??'')); $initials=$name?mb_substr($parts[0],0,1).(count($parts)>1?mb_substr(end($parts),0,1):''):'–'; return '<span class="avatar '.e($extra).'" title="'.e($name??'Non assigné').'">'.($image?'<img class="avatar-image" src="'.e($image).'" alt="'.e($name).'">':e($initials)).'</span>'; }

/** "Bloqué" badge for flagged tickets; empty string when not blocked. */
function blocked_badge(bool $blocked): string { return $blocked?'<span class="blocked-badge" title="Un obstacle empêche d’avancer sur ce ticket">'.icon('flag').'<span>Bloqué</span></span>':''; }

/** Render the same persisted project identity in each view; no position-based palette. */
function project_mark(array $project,string $variant='compact'): string {
    $color=(string)($project['color']??'#7166eb');
    if (!preg_match('/^#[a-f0-9]{6}$/iD',$color)) $color='#7166eb';
    $tile=$variant==='tile';
    $symbol=$project['icon']??'folder';
    if (!isset(\App\Models\Appearance::ICONS[$symbol])) $symbol='folder';
    return '<span class="project-mark '.($tile?'project-tile':'project-symbol').'" data-project-id="'.(int)$project['id'].'" data-mark-icon="'.e($symbol).'" data-project-color="'.e(strtolower($color)).'" aria-hidden="true">'.icon($symbol).'</span>';
}

/** Icon badge for a ticket type ($kind='type', per-project workflow mark when $project is given) or a priority ($kind='priority'). */
function work_mark(string $kind,string $key,int $project=0, ?string $shortLabel=null): string {
    $mark=$kind==='type'&&$project?\App\Models\Workflow::mark('type',$project,$key):\App\Models\Appearance::mark($kind,$key);
    $labels=$kind==='type'?\App\Models\Workflow::labels('type',$project):\App\Models\Ticket::PRIORITIES;
    $content = icon($mark['icon']);
    if ($shortLabel !== null) $content .= '<span class="mark-short-label" aria-hidden="true">'.e($shortLabel).'</span>';
    return '<span class="work-mark '.($kind==='type'?'type-icon':'priority-mark').'" data-mark-kind="'.e($kind).'" data-mark-key="'.e($key).'" data-mark-icon="'.e($mark['icon']).'" data-mark-color="'.e($mark['color']).'" role="img" aria-label="'.e($labels[$key]).'" title="'.e($labels[$key]).'">'.$content.'</span>';
}

/** Coloured status pill using the project's workflow label and colour for status $key. */
function status_badge(string $key,int $project): string {
    $color=\App\Models\Workflow::mark('status',$project,$key)['color'];
    return '<span class="pill workflow-status" data-status-color="'.e($color).'"><span class="status-dot" aria-hidden="true"></span><span class="status-label">'.e(\App\Models\Workflow::label('status',$project,$key)).'</span></span>';
}
