<?php /**
 * Hidden <template> of pre-rendered small avatars: data-avatar-user="0" for "unassigned"
 * plus one per member. app.js copies the right one into a list row after the assignee is
 * changed inline. Included by sections/list.php. Expects $members.
 */ ?>
<template id="list-assignee-avatars"><span data-avatar-user="0"><?= avatar(null,'small') ?></span><?php foreach($members as $person): ?><span data-avatar-user="<?= (int)$person['id'] ?>"><?= avatar($person['name'],'small',(int)$person['id']) ?></span><?php endforeach; ?></template>
