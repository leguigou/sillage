"""Branding workflow uses only the isolated installation and synthetic logo bytes."""
import base64
branding_previous_client=http_support.client
branding_member_csrf,branding_member_page=get_token('/settings')
check('action="/company"' not in branding_member_page,'non-admin settings hide company editor')
check(call('/company',{'_csrf':branding_member_csrf,'company_name':'Interdit'},True)[0]==403,'member cannot change company identity')
try:
    http_support.client=urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
    branding_csrf,_=get_token('/login')
    _,branding_page,_=call('/login',{'_csrf':branding_csrf,'email':'qa.admin@example.com','password':'Tests-Sillage-2026!'})
    branding_csrf=token(branding_page)
    _,branding_page,_=call('/settings')
    check('action="/company"' in branding_page,'administrator can edit company identity')
    branding_version=re.search(r'action="/company"[\s\S]*?name="version" value="(\d+)"',branding_page).group(1)
    branding_fields={'_csrf':branding_csrf,'version':branding_version,'company_name':'Studio & Équipe','company_subtitle':'Notre espace commun','company_icon':'⚓','_return':'/settings#company'}
    check(call('/company',{'company_name':'Sans CSRF'},True)[0]==403,'company changes require CSRF')
    check(call('/company',dict(branding_fields,company_name=''),True)[0]==422,'empty company name rejected over HTTP')
    branding_png=base64.b64decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jRZkAAAAASUVORK5CYII=')
    status,branding_page,_=upload('/company',branding_fields,'company_logo','logo.png',branding_png,'image/png')
    check(status==200 and 'Studio &amp; Équipe' in branding_page and 'Notre espace commun' in branding_page,'company name subtitle and logo saved over HTTP')
    branding_url=re.search(r'src="(/company/logo/[a-f0-9]{64})"',branding_page).group(1)
    with urllib.request.urlopen(BASE+branding_url) as response:
        check(response.headers['Content-Type']=='image/png' and response.read()==branding_png,'company logo serves exact image bytes before login')
    with urllib.request.urlopen(BASE+'/login') as response:
        branding_guest=response.read().decode()
        check('Studio &amp; Équipe' in branding_guest and branding_url in branding_guest,'name and logo propagate to anonymous login')
    with urllib.request.urlopen(BASE+public_path) as response:
        branding_form=response.read().decode()
        check('Studio &amp; Équipe' in branding_form and branding_url in branding_form,'name and logo propagate to public ticket form')
    check(call('/company',branding_fields,True)[0]==422,'stale company edit refused over HTTP')
    branding_new_version=re.search(r'action="/company"[\s\S]*?name="version" value="(\d+)"',branding_page).group(1)
    status,_,_=upload('/company',dict(branding_fields,version=branding_new_version),'company_logo','unsafe.svg',b'<svg xmlns="http://www.w3.org/2000/svg"><script>bad()</script></svg>','image/svg+xml',json_accept=True)
    check(status==422,'unsafe company logo refused over HTTP')
    status,branding_page,_=upload('/company',dict(branding_fields,version=branding_new_version),'company_logo','replacement.png',branding_png+b'replacement','image/png')
    branding_replacement_url=re.search(r'src="(/company/logo/[a-f0-9]{64})"',branding_page).group(1)
    check(status==200 and branding_replacement_url!=branding_url and call(branding_url)[0]==404,'replacing logo invalidates previous image URL')
    branding_url=branding_replacement_url
    branding_new_version=re.search(r'action="/company"[\s\S]*?name="version" value="(\d+)"',branding_page).group(1)
    status,branding_page,_=call('/company',dict(branding_fields,version=branding_new_version,remove_logo='1'))
    check(status==200 and branding_url not in branding_page and '⚓' in branding_page,'logo removal restores company symbol')
    check(call(branding_url)[0]==404,'removed company logo no longer served')
    # Restore defaults for the later independent installation profiles.
    _,branding_page,_=call('/settings')
    branding_reset_version=re.search(r'action="/company"[\s\S]*?name="version" value="(\d+)"',branding_page).group(1)
    call('/company',dict(branding_fields,version=branding_reset_version,company_name='Entreprise de test',company_subtitle='Espace entreprise',company_icon=''))
finally:
    http_support.client=branding_previous_client
