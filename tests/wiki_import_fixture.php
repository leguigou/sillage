<?php
declare(strict_types=1);
// Synthetic Confluence "Export to Word" file (MHTML, quoted-printable HTML + base64 images), used by
// tests/wiki_files.php and tests/wiki.php. It reproduces the structures met in real exports:
// emoticons, embedded image, information macro, "Note :" paragraphs, nested tables, code macro.

function wiki_import_png(): string
{
    return base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==');
}

function wiki_import_fixture(): string
{
    $html = <<<'HTML'
<html xmlns:o='urn:schemas-microsoft-com:office:office'><head><meta http-equiv="Content-Type" content="text/html; charset=utf-8"><title>Procédure Punchout</title><style>body{color:red}</style><script>alert(1)</script></head>
<body>
<h1><img class="emoticon emoticon-label" data-emoji-id="1f3f7" data-emoji-shortname=":label:" data-emoji-fallback="🏷️" src="emo1" data-emoticon-name="label"> Punchout client</h1>
<p>Le module <code>Punchout_Gateway</code> gère le *panier* et les [crochets] ; voir <a href="https://exemple.fr/doc?a=1&amp;b=2">la doc</a> ou <a href="javascript:alert(1)">piège</a>.</p>
<h2><img class="emoticon emoticon-blue-star" data-emoji-id="atlassian-blue_star" data-emoji-shortname=":blue_star:" data-emoji-fallback=":blue_star:" src="emo2" data-emoticon-name="blue-star"> Schéma</h2>
<p><span class="confluence-embedded-file-wrapper"><img class="confluence-embedded-image" alt="schema.png" src="img1" data-image-src="https://exemple.atlassian.net/wiki/download/attachments/1/schema.png"></span></p>
<p><img class="confluence-embedded-image" alt="absente.png" src="https://exemple.atlassian.net/absente.png"></p>
<div class="confluence-information-macro confluence-information-macro-information"><span class="aui-icon confluence-information-macro-icon"></span><div class="confluence-information-macro-body"><p>Note :</p><p>Commande validée par le client.</p></div></div>
<p>Note :</p><p>Le lot 2 supprimera cette étape.</p>
<div class="confluence-information-macro confluence-information-macro-warning"><div class="confluence-information-macro-body"><p>Ne jamais modifier le mapping.</p></div></div>
<div class="table-wrap"><table class="confluenceTable"><tbody>
<tr><th><p><strong>Étape</strong></p></th><th><p><strong>Détails</strong></p></th></tr>
<tr><td><p>Connexion</p></td><td><p><code>PunchOut Site</code> ce qui :</p><div class="table-wrap"><table><tbody><tr><th>Comportement</th></tr><tr><td>Connecte le client existant</td></tr></tbody></table></div></td></tr>
<tr><td><p>Panier</p></td><td><p><code>Cart</code></p></td></tr>
</tbody></table></div>
<table><tbody><tr><th>Type</th><th>Description</th></tr><tr><td>"Setup request"</td><td>requête | initiale</td></tr></tbody></table>
<div class="code panel pdl"><div class="codeContent panelContent pdl"><pre class="syntaxhighlighter-pre" data-syntaxhighlighter-params="brush: xml; gutter: false">&lt;cXML&gt;
  &lt;Cart/&gt;
&lt;/cXML&gt;</pre></div></div>
<ul><li>Premier<ol><li>Sous-étape</li></ol></li><li>Second</li></ul>
<ul class="inline-task-list"><li data-inline-task-id="1" class="checked">Fait</li><li data-inline-task-id="2">À faire</li></ul>
<p>1. pas une liste</p>
<form><input value="x"></form>
</body></html>
HTML;
    $png = wiki_import_png();
    $boundary = '----=_Part_1_2.3';
    return "Date: Mon, 9 Nov 2026 07:41:41 +0000 (UTC)\r\nSubject: Exported From Confluence\r\nMIME-Version: 1.0\r\nContent-Type: multipart/related; \r\n\tboundary=\"$boundary\"\r\n\r\n"
        . "--$boundary\r\nContent-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: quoted-printable\r\nContent-Location: file:///C:/exported.html\r\n\r\n"
        . quoted_printable_encode($html) . "\r\n"
        . "--$boundary\r\nContent-Type: application/octet-stream\r\nContent-Transfer-Encoding: base64\r\nContent-Location: file:///C:/img1\r\n\r\n"
        . chunk_split(base64_encode($png)) . "\r\n"
        . "--$boundary\r\nContent-Type: application/octet-stream\r\nContent-Transfer-Encoding: base64\r\nContent-Location: file:///C:/emo1\r\n\r\n"
        . chunk_split(base64_encode($png)) . "\r\n"
        . "--$boundary--\r\n";
}
