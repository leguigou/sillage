<?php
use App\Services\Conversation;
use App\Models\{Project,Ticket};
use App\Core\DB;

$progressTicket = Ticket::create($p, ['title' => 'Avancement manuel', 'progress_percent' => 45], $uid);
check(Ticket::find($progressTicket)['progress_percent'] === 45, 'avancement enregistré à la création');
foreach ([-1,101,'1.5','1e2',true,[]] as $badProgress) {
    rejects(fn() => Ticket::validate(['title'=>'Test','progress_percent'=>$badProgress], $p), 'avancement hors limites ou non entier refusé');
}
$current = Ticket::find($progressTicket);
Ticket::patch($progressTicket, ['priority'=>'high'], $uid, $current['version']);
check(Ticket::find($progressTicket)['progress_percent'] === 45, 'modification partielle conserve l’avancement');
$current = Ticket::find($progressTicket);
Ticket::patch($progressTicket, ['progress_percent'=>100], $uid, $current['version']);
check(Ticket::find($progressTicket)['progress_percent'] === 100, 'avancement accepte 100 pour cent');
$safe = Conversation::clean('<p onclick="evil()"><b>Avancée</b> <i>confirmée</i></p><script>alert(1)</script><a href="javascript:evil()">lien</a><img src="https://example.com/track.png">', $progressTicket);
check(str_contains($safe,'<strong>Avancée</strong>') && str_contains($safe,'<em>confirmée</em>') && !str_contains($safe,'script') && !str_contains($safe,'onclick') && !str_contains($safe,'<img'), 'mise en forme reconstruite sans HTML actif ni image distante');
rejects(fn() => Conversation::clean('<p><br></p>', $progressTicket), 'commentaire enrichi vide refusé');
rejects(fn() => Conversation::clean(str_repeat('x',10001), $progressTicket), 'limite de texte enrichi vérifiée');
$cid = Ticket::comment($progressTicket,'<ul><li>Livré</li></ul>', $uid, 'html');
$comment = DB::one('SELECT * FROM comments WHERE id=?',[$cid]);
check($comment['body_format'] === 'html' && str_contains(Conversation::render($comment), '<li>Livré</li>'), 'commentaire enrichi persiste et se rend');
check(Conversation::render(['body'=>'<b>texte ancien</b>','body_format'=>'text']) === '&lt;b&gt;texte ancien&lt;/b&gt;', 'anciens commentaires restent du texte échappé');
$replyRoot = Ticket::comment($progressTicket, 'Question initiale', $uid);
$reply = Ticket::comment($progressTicket, 'Réponse directe', $uid, 'text', $replyRoot);
check((int) DB::one('SELECT parent_id FROM comments WHERE id=?', [$reply])['parent_id'] === $replyRoot, 'une réponse est rattachée à son message');
$otherTicket = Ticket::create($p, ['title' => 'Autre fil'], $uid);
rejects(fn() => Ticket::comment($otherTicket, 'Réponse égarée', $uid, 'text', $replyRoot), 'réponse à un commentaire d’un autre ticket refusée');
$deep = $reply;
for ($level = 2; $level <= Ticket::COMMENT_REPLY_DEPTH + 2; $level++) {
    $deep = Ticket::comment($progressTicket, 'Niveau ' . $level, $uid, 'text', $deep);
}
$depth = 0;
for ($current = $deep; $current; $depth++) {
    $current = (int) (DB::one('SELECT parent_id FROM comments WHERE id=?', [$current])['parent_id'] ?? 0);
}
check($depth - 1 === Ticket::COMMENT_REPLY_DEPTH, 'la profondeur des réponses est plafonnée');
$threadRows = \App\Models\GitConversation::comments($progressTicket);
[$ascRoots, $threadReplies] = Ticket::commentThreads($threadRows, 'asc');
[$descRoots] = Ticket::commentThreads($threadRows, 'desc');
check(array_column($ascRoots, 'id') === array_reverse(array_column($descRoots, 'id')), 'l’ordre décroissant inverse les messages principaux');
check(!in_array($reply, array_map('intval', array_column($ascRoots, 'id')), true) && (int) $threadReplies[$replyRoot][0]['id'] === $reply, 'les réponses sont regroupées sous leur message');
$orderProject = Project::save(['name' => 'Ordre des commentaires', 'key' => 'ORD' . strtoupper(bin2hex(random_bytes(2))), 'comment_order' => 'desc']);
check(Project::find($orderProject)['comment_order'] === 'desc', 'ordre des commentaires enregistré à la création du projet');
Project::save(['name' => 'Ordre des commentaires', 'comment_order' => 'asc'], $orderProject);
check(Project::find($orderProject)['comment_order'] === 'asc', 'ordre des commentaires modifiable');
rejects(fn() => Project::save(['name' => 'Ordre des commentaires', 'comment_order' => 'random'], $orderProject), 'ordre des commentaires inconnu refusé');
