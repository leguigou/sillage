"""Small HTTP client for additional isolated installation workflows."""
import http.cookiejar, urllib.request, urllib.parse, urllib.error, re, os, pathlib, uuid, json, hashlib, hmac

BASE = os.environ.get('SILLAGE_TEST_URL', 'http://127.0.0.1:8089')
if not BASE.endswith(':8089') and os.environ.get('CI') != 'true':
    raise RuntimeError('Use isolated test server on 8089')
client = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
passed = 0

def call(path, data=None, json_accept=False):
    encoded = urllib.parse.urlencode(data, doseq=True).encode() if data is not None else None
    try:
        with client.open(urllib.request.Request(BASE+path, data=encoded, headers={'Accept': 'application/json' if json_accept else 'text/html'}), timeout=20) as response:
            return response.status, response.read().decode('utf-8'), response.url
    except urllib.error.HTTPError as error:
        return error.code, error.read().decode('utf-8'), error.url

def check(ok, name):
    global passed
    if not ok:
        raise AssertionError(name)
    passed += 1
    print('OK', name)

def token(html):
    found = re.search(r'name="_csrf" value="([a-f0-9]+)"', html)
    if not found:
        raise AssertionError('CSRF token missing')
    return found.group(1)

def get_token(path='/'):
    status, html, _ = call(path)
    check(status == 200, 'GET '+path.split('?')[0])
    return token(html), html

def mail_for(email):
    mail_dir = pathlib.Path(__file__).resolve().parents[1]/'storage'/'mail'
    for path in sorted(mail_dir.glob('*.txt'), key=lambda p: p.stat().st_mtime_ns, reverse=True):
        text = path.read_text(encoding='utf-8')
        if 'To: '+email+'\n' in text:
            return text
    raise AssertionError('Local test mail missing')

def passed_count():
    return passed

def upload(path,fields,field,filename,content,mime):
    boundary='sillage-'+uuid.uuid4().hex; parts=[]
    for key,value in fields.items(): parts.append(('--'+boundary+'\r\nContent-Disposition: form-data; name="'+key+'"\r\n\r\n'+str(value)+'\r\n').encode())
    parts.append(('--'+boundary+'\r\nContent-Disposition: form-data; name="'+field+'"; filename="'+filename+'"\r\nContent-Type: '+mime+'\r\n\r\n').encode()+content+b'\r\n')
    parts.append(('--'+boundary+'--\r\n').encode())
    request=urllib.request.Request(BASE+path,data=b''.join(parts),headers={'Content-Type':'multipart/form-data; boundary='+boundary})
    try:
        with client.open(request,timeout=20) as response: return response.status,response.read().decode(),response.url
    except urllib.error.HTTPError as error: return error.code,error.read().decode(),error.url
def signed_webhook(path,data,valid=True):
    body=json.dumps(data).encode(); signature='sha256='+hmac.new(b'test-webhook-secret' if valid else b'wrong-secret',body,hashlib.sha256).hexdigest()
    request=urllib.request.Request(BASE+path,data=body,headers={'Content-Type':'application/json','Accept':'application/json','X-Hub-Signature-256':signature})
    try:
        with client.open(request,timeout=20) as response: return response.status,response.read().decode()
    except urllib.error.HTTPError as error: return error.code,error.read().decode()
