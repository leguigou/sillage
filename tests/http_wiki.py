"""Wiki through actual HTTP: pages, editor saves and conflicts, history, search, rights, CSRF, escaping."""
import re, json, uuid, urllib.parse, urllib.request, http.cookiejar
import http_workflows_support as support
from http_workflows_support import call, get_token, token, check, passed_count

csrf, _ = get_token('/login')
_, html, _ = call('/login', {'_csrf': csrf, 'email': 'qa.admin@example.com', 'password': 'Tests-Sillage-2026!'})
csrf = token(html)
admin = support.client
suffix = uuid.uuid4().hex[:6]
folder = 'Procédures ' + suffix

status, html, _ = call('/wiki')
check(status == 200 and 'Accueil du wiki' in html and 'Nouvelle page' in html, 'wiki home with tree and actions')
check('href="/wiki' in html and '>Wiki<' in html, 'wiki entry in the main menu')
check(call('/wiki/folders', {'parent': '', 'name': folder}, True)[0] == 403, 'folder creation requires CSRF')
status, _, url = call('/wiki/folders', {'_csrf': csrf, 'parent': '', 'name': folder, '_return': '/wiki'})
check(status == 200 and urllib.parse.unquote(url).endswith('folder=' + folder), 'folder created')
for parent, name in [('..', 'x'), ('', '../x'), ('', 'a/b'), ('', 'CON'), ('/etc', 'x')]:
    check(call('/wiki/folders', {'_csrf': csrf, 'parent': parent, 'name': name}, True)[0] == 422, 'invalid folder refused: ' + parent + '|' + name)

status, html, url = call('/wiki/pages', {'_csrf': csrf, 'folder': folder, 'title': 'Clôture de caisse', '_return': '/wiki'})
match = re.search(r'/wiki/pages/(\d+)/edit$', url)
check(status == 200 and match and 'data-wiki-editor' in html, 'page created and editor opened')
page = match.group(1)
check('wiki-editor.js' in html and 'name="content"' in html and 'name="base_revision" value="1"' in html, 'editor loads Milkdown with Markdown fallback')
check(call('/wiki/pages', {'_csrf': csrf, 'folder': folder, 'title': 'clôture de caisse'}, True)[0] == 422, 'duplicate page name refused')

content = '# Clôture de caisse\n\n## Étapes\n\n1. Compter le fond\n2. Saisir le montant\n\n<script>alert(1)</script> [x](javascript:alert(1))\n\n| Magasin | Montant |\n|:--|--:|\n| Toulon | 12 |\n'
save = '/wiki/pages/' + page
check(call(save, {'content': content, 'base_revision': '1'}, True)[0] == 403, 'save requires CSRF')
status, body, _ = call(save, {'_csrf': csrf, 'content': content, 'base_revision': '1', 'message': 'premières étapes'}, True)
data = json.loads(body)
check(status == 200 and data['ok'] and data['revision'] == 2 and data['changed'], 'editor save creates version 2')
status, body, _ = call(save, {'_csrf': csrf, 'content': content + '3. Fermer\n', 'base_revision': '1'}, True)
data = json.loads(body)
check(status == 409 and data['conflict'] and data['current'] == 2 and '/diff?from=1&to=2' in data['diff_url'], 'stale save returns a conflict with a diff link')
status, body, _ = call(save, {'_csrf': csrf, 'content': content + '3. Fermer\n', 'base_revision': '1', 'force': '1'}, True)
check(status == 200 and json.loads(body)['revision'] == 3, 'forced save keeps both versions')

status, html, _ = call('/wiki/pages/' + page)
check(status == 200 and '<h2 id="etapes">' in html and '<h1 id="cloture-de-caisse">' in html, 'page rendered with heading anchors')
check('<script>alert(1)</script>' not in html and '&lt;script&gt;alert(1)&lt;/script&gt;' in html, 'raw HTML escaped')
check('href="javascript' not in html.lower(), 'javascript links neutralized')
check('class="align-right"' in html and 'style="text-align' not in html, 'table alignment rendered without style attributes')
check('Historique (3 versions)' in html, 'history link shows the version count')

status, html, _ = call('/wiki/pages/' + page + '/history')
check(status == 200 and 'premières étapes' in html and html.count('name="to"') == 3, 'history lists versions, authors and messages')
status, html, _ = call('/wiki/pages/' + page + '/diff?from=2&to=3')
check(status == 200 and 'wiki-diff-row add' in html and 'Fermer' in html, 'diff shows the added line')
status, html, _ = call('/wiki/pages/' + page + '/revisions/2')
check(status == 200 and 'Restaurer cette version' in html, 'old version viewable with restore action')
status, _, url = call('/wiki/pages/' + page + '/revisions/2/restore', {'_csrf': csrf, '_return': '/wiki/pages/' + page})
check(status == 200 and url.endswith('/wiki/pages/' + page), 'restore redirects to the page')
check('Historique (4 versions)' in call('/wiki/pages/' + page)[1], 'restore added a fourth version')

request = urllib.request.Request(support.BASE + '/wiki/pages/' + page + '/raw')
with support.client.open(request, timeout=20) as response:
    raw = response.read().decode('utf-8')
    check(response.headers['Content-Type'].startswith('text/markdown') and 'attachment' in response.headers['Content-Disposition'], 'Markdown download headers')
    check(raw == content, 'downloaded file equals the saved Markdown')

status, html, _ = call('/wiki/search?q=' + urllib.parse.quote('cloture'))
check(status == 200 and '/wiki/pages/' + page in html, 'search ignores accents')
status, html, _ = call('/wiki/search?q=' + urllib.parse.quote('montan') + '&folder=' + urllib.parse.quote(folder))
check('/wiki/pages/' + page in html and '<mark>' in html, 'prefix search in a folder with highlighted snippet')
status, html, _ = call('/wiki/search?q=' + urllib.parse.quote('introuvablexyz'))
check(status == 200 and 'Aucune page trouvée' in html, 'search without result')

status, _, url = call('/wiki/pages/' + page + '/move', {'_csrf': csrf, 'folder': '', 'name': 'Clôture ' + suffix, '_return': '/wiki/pages/' + page})
check(status == 200 and 'Clôture ' + suffix in call('/wiki/pages/' + page)[1], 'page renamed and moved to the root')
check(call('/wiki/pages/' + page + '/move', {'_csrf': csrf, 'folder': '', 'name': '../evasion'}, True)[0] == 422, 'path traversal in rename refused')

viewer = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
support.client = viewer
vcsrf, _ = get_token('/login')
_, html, _ = call('/login', {'_csrf': vcsrf, 'email': 'qa.viewer@example.com', 'password': 'Tests-Sillage-2026!'})
vcsrf = token(html)
status, html, _ = call('/wiki/pages/' + page)
check(status == 200 and 'Modifier</a>' not in html and 'Nouvelle page' not in html, 'viewer reads without edit actions')
check(call('/wiki/pages/' + page + '/edit')[0] == 403, 'viewer cannot open the editor')
check(call(save, {'_csrf': vcsrf, 'content': 'x', 'base_revision': '4'}, True)[0] == 403, 'viewer cannot save')
check(call('/wiki/pages/' + page + '/delete', {'_csrf': vcsrf})[0] == 403, 'viewer cannot delete')
check(call('/wiki/deleted')[0] == 403, 'deleted pages are admin only')
support.client = admin

status, _, url = call('/wiki/pages/' + page + '/delete', {'_csrf': csrf, '_return': '/wiki'})
check(status == 200 and call('/wiki/pages/' + page)[0] == 422, 'deleted page no longer readable')
status, html, _ = call('/wiki/deleted')
check(status == 200 and '/wiki/pages/' + page + '/history' in html, 'deleted page listed for administrators')
status, _, url = call('/wiki/folders/delete', {'_csrf': csrf, 'folder': folder, '_return': '/wiki'})
check(status == 200 and 'folder=' not in url, 'empty folder deleted by an administrator')

print('Wiki page ' + page)
print(str(passed_count()) + ' wiki HTTP checks passed')
