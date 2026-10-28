"""Browser-cookie lifecycle through public HTTP; cookie secrets remain in process memory."""
import time, copy
remember_jar=next(h.cookiejar for h in http_support.client.handlers if isinstance(h,urllib.request.HTTPCookieProcessor))
remember_cookie=next(c for c in remember_jar if c.name.endswith('_remember'))
check(not remember_cookie.discard and abs(remember_cookie.expires-time.time()-30*86400)<10,'persistent login cookie lasts thirty days after password login')
check('HttpOnly' in remember_cookie._rest and remember_cookie._rest.get('SameSite')=='Lax','persistent credential is HttpOnly and SameSite=Lax')
remember_expiry=remember_cookie.expires
for c in list(remember_jar):
    if c.name.startswith('sillage_') and not c.name.endswith('_remember'): remember_jar.clear(c.domain,c.path,c.name)
status,html,url=call('/list')
check(status==200 and not url.endswith('/login'),'connection restored after loss of PHP session cookie as during redeployment')
check(next(c for c in remember_jar if c.name.endswith('_remember')).expires==remember_expiry,'session restoration keeps original thirty-day expiry')
csrf=token(html)
import subprocess, shutil, pathlib, os
legacy_root=pathlib.Path(__file__).resolve().parents[1];legacy_env=os.environ.copy()
if not legacy_env.get('DB_DATABASE') and (legacy_root/'.runtime/test-env.json').is_file(): legacy_env.update(json.loads((legacy_root/'.runtime/test-env.json').read_text(encoding='utf-8-sig')))
legacy_ini=legacy_root/'.runtime/php-config/php.ini'
legacy_command=[shutil.which('php')]+(['-c',str(legacy_ini)] if legacy_ini.exists() else [])+[str(legacy_root/'tests/legacy_session.php')]
legacy_result=json.loads(subprocess.run(legacy_command,env=legacy_env,capture_output=True,text=True,encoding='utf-8',check=True,timeout=20).stdout)
check(all(legacy_result.values()),'existing session upgraded to thirty days without another password or loss on repeated authentication checks')
