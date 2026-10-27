"""Actual HTTP checks for a separate installation's branding and registration policy."""
import sys
from http_workflows_support import call, check, get_token, token, mail_for, passed_count
import re, uuid

open_signup = '--open' in sys.argv
company = 'Atelier Horizon & Associés'
status, html, _ = call('/login')
check(status == 200 and 'Atelier Horizon &amp; Associés' in html, 'custom company shown and escaped on login')
check('Cabesto' not in html, 'login has no fixed company name')
if open_signup:
    check('toute adresse e-mail valide' in html, 'open registration policy shown')
else:
    check('@atelier-horizon.example' in html and '@horizon-services.example' in html, 'both configured domains shown')
    check('prenom.nom@atelier-horizon.example' in html, 'email example follows configured domain')

marker = uuid.uuid4().hex[:10]
csrf = token(html)
if not open_signup:
    for domain in ['outside.example', 'atelier-horizon.example.evil.test', 'sub.atelier-horizon.example']:
        status, body, _ = call('/register', {'_csrf': csrf, 'email': marker+'@'+domain, 'password': 'Inscription-Sillage-2026!'}, True)
        check(status == 422, 'registration rejected for '+domain)
domains = ['outside.example'] if open_signup else ['atelier-horizon.example', 'horizon-services.example']
for domain in domains:
    email = 'qa.company.'+marker+'@'+domain
    status, body, _ = call('/register', {'_csrf': csrf, 'email': email, 'password': 'Inscription-Sillage-2026!'})
    check(status == 200 and 'Votre compte est créé' in body, 'registration accepted for '+domain)
    check(company in mail_for(email), 'verification mail carries configured company')
    csrf = token(body)

# These accounts were created before the company's domain changed.
status, body, _ = call('/forgot', {'_csrf': csrf, 'email': 'qa.admin@cabesto.com'})
check(status == 200 and company in mail_for('qa.admin@cabesto.com'), 'existing account can recover access after domain change')
csrf = token(body)
status, body, url = call('/login', {'_csrf': csrf, 'email': 'qa.admin@cabesto.com', 'password': 'Tests-Sillage-2026!'})
check(status == 200 and not url.endswith('/login'), 'existing administrator signs in after domain change')
csrf = token(body)
check('Atelier Horizon &amp; Associés' in body and 'Cabesto' not in body, 'sidebar and footer use configured company')
status, settings, _ = call('/settings')
check(status == 200 and 'Configuration de la société' in settings and 'Cabesto' not in settings, 'settings and team instructions follow company configuration')
if open_signup:
    check('Désactivée' in settings, 'administrator sees disabled domain restriction')
else:
    check('@horizon-services.example' in settings and 'Activée' in settings, 'administrator sees effective domain restriction')
pid = re.search(r'name="project_id" value="(\d+)"', body).group(1)
status, body, _ = call('/forms', {'_csrf': csrf, 'project_id': pid, 'title': 'Company '+marker, 'description': 'Formulaire de test', 'ticket_type': 'task', 'fields[]': ['title']})
path = re.search(r'http://127\.0\.0\.1:8089(/f/[a-f0-9]{64})', body).group(1)
status, public_html, _ = call(path)
check(status == 200 and 'Atelier Horizon &amp; Associés' in public_html and 'Cabesto' not in public_html, 'external form branding follows configured company')
print('\n', passed_count(), 'company HTTP checks passed ('+('open' if open_signup else 'restricted')+').')
