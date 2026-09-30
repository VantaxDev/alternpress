"""Standard-library HTTP regressions on an isolated copy; no live site is touched."""
from pathlib import Path
import base64, html, http.cookiejar, json, re, shutil, socket, subprocess, tempfile, time
import urllib.error, urllib.parse, urllib.request

source = Path(__file__).resolve().parents[1]
checks = 0
def expect(value, label):
    global checks
    assert value, label
    checks += 1
    print('PASS ' + label, flush=True)

with tempfile.TemporaryDirectory(prefix='alternpress-http-security-') as directory:
    root = Path(directory) / 'site'
    shutil.copytree(source, root, ignore=shutil.ignore_patterns('.git', '__pycache__'))
    # The development router models private-path protection; Apache rules are
    # separately checked on a deployed test installation.
    router = Path(directory) / 'router.php'
    router.write_text('''<?php
$path=rawurldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));
if(preg_match('#^/(?:storage|content|cache|lib|bin|tests)(?:/|$)|^/(?:config(?:\\.local)?\\.php|alt-cli|\\.)#',$path)){http_response_code(403);exit;}
if($path==='/admin/'||$path==='/admin/index.php'){$_SERVER['SCRIPT_NAME']='/admin/index.php';require __DIR__.'/site/admin/index.php';return;}
if($path!=='/'&&$path!=='/index.php'&&is_file(__DIR__.'/site'.$path))return false;
$_SERVER['SCRIPT_NAME']='/index.php';require __DIR__.'/site/index.php';
''')
    password = 'Regression-Only-2026!'
    subprocess.run(['php', str(root/'alt-cli'), 'password', '--stdin'], input=(password+'\n').encode(), check=True, stdout=subprocess.DEVNULL)
    draft = root/'content/it/post/private-regression/index.md'
    draft.parent.mkdir(parents=True)
    draft.write_text('---\ntitle: Never publicly visible\nstatus: draft\n---\nSecret regression content\n')
    with socket.socket() as port_socket:
        port_socket.bind(('127.0.0.1', 0)); port=port_socket.getsockname()[1]
    base='http://127.0.0.1:'+str(port)
    jar=http.cookiejar.CookieJar()
    client=urllib.request.build_opener(urllib.request.HTTPCookieProcessor(jar))
    log=open(Path(directory)/'http.log','w')
    server=subprocess.Popen(['php','-d','display_errors=0','-S','127.0.0.1:'+str(port),'-t',str(root),str(router)],stdout=log,stderr=log)
    def request(path, fields=None, auth=True, raw=None, headers=None):
        data=raw if raw is not None else (urllib.parse.urlencode(fields).encode() if fields is not None else None)
        try:
            with (client if auth else urllib.request.build_opener()).open(urllib.request.Request(base+path,data=data,headers=headers or {}),timeout=30) as response:
                return response.status,response.read(),response.headers
        except urllib.error.HTTPError as error:return error.code,error.read(),error.headers
    def get_text(*args,**kwargs):
        status,body,headers=request(*args,**kwargs);return status,body.decode(),headers
    def field(body,name):return html.unescape(re.search('name="'+re.escape(name)+'" value="([^"]*)"',body).group(1))
    def upload_archive(data, csrf):
        boundary='----AlternPressTestArchive123'
        payload=b''
        for key,value in [('action','restore_prepare'),('csrf',csrf)]:
            payload+=f'--{boundary}\r\nContent-Disposition: form-data; name="{key}"\r\n\r\n{value}\r\n'.encode()
        payload+=f'--{boundary}\r\nContent-Disposition: form-data; name="archive"; filename="backup.tar.gz"\r\nContent-Type: application/gzip\r\n\r\n'.encode()+data+f'\r\n--{boundary}--\r\n'.encode()
        return get_text('/admin/?page=settings',raw=payload,headers={'Content-Type':'multipart/form-data; boundary='+boundary})
    def upload_photo(data, csrf):
        boundary='----AlternPressTestPhoto123'
        payload=b''
        for key,value in [('action','upload'),('csrf',csrf)]:
            payload+=f'--{boundary}\r\nContent-Disposition: form-data; name="{key}"\r\n\r\n{value}\r\n'.encode()
        payload+=f'--{boundary}\r\nContent-Disposition: form-data; name="photo"; filename="image.png"\r\nContent-Type: image/png\r\n\r\n'.encode()+data+f'\r\n--{boundary}--\r\n'.encode()
        return get_text('/admin/',raw=payload,headers={'Content-Type':'multipart/form-data; boundary='+boundary})
    try:
        for attempt in range(50):
            try:get_text('/it');break
            except OSError:time.sleep(.1)
        status,public,headers=get_text('/it',auth=False)
        expect(status==200 and 'AlternPress' in public,'public demo renders')
        csp=headers.get('Content-Security-Policy','')
        nonce=re.search(r"script-src 'nonce-([^']+)'",csp).group(1)
        expect(all('nonce="'+nonce+'"' in tag for tag in re.findall(r'<script[^>]*>',public)),'public inline scripts use matching CSP nonce')
        expect('unsafe-inline' not in csp and "object-src 'none'" in csp,'public CSP blocks arbitrary inline scripts and objects')
        expect('Never publicly visible' not in get_text('/it/archive',auth=False)[1],'draft absent from archive')
        expect(request('/it/post/private-regression',auth=False)[0]==404,'draft URL returns 404')
        status,body,headers=get_text('/admin/')
        cookie=headers.get('Set-Cookie','').lower()
        expect('httponly' in cookie and 'samesite=strict' in cookie and 'path=/admin/' in cookie,'admin cookie scoped and protected')
        expect('no-store' in headers.get('Cache-Control',''),'admin responses are not cached')
        csrf=field(body,'csrf');before_cookie=[c.value for c in jar]
        expect(request('/admin/',{'action':'login','csrf':'wrong','password':password})[0]==403,'login requires CSRF')
        expect(request('/admin/',{'action':'login','csrf':csrf,'password[]':password})[0]==422,'array password rejected cleanly')
        _,body,_=get_text('/admin/',{'action':'login','csrf':csrf,'password':password})
        expect('I tuoi articoli' in body and [c.value for c in jar]!=before_cookie,'login succeeds and rotates session ID')
        csrf=field(body,'csrf')
        for fields in [dict(action='save',id='bad',translation='bad'),dict(action='preview',**{'body[]':'bad'}),dict(action='password',**{'current_password[]':'bad'})]:
            status,response,_=get_text('/admin/?page=settings',dict(fields,csrf=csrf))
            expect(status==422 and 'Fatal error' not in response and 'Warning:' not in response,'malformed authenticated form fails without PHP diagnostics')
        status,preview,_=get_text('/admin/',{'action':'preview','csrf':csrf,'body':'<script>alert(1)</script> [bad](javascript:alert)'})
        rendered=json.loads(preview)['html']
        expect(status==200 and '<script>' not in rendered and 'href="javascript:' not in rendered,'preview escapes active HTML and unsafe URLs')
        expect(upload_photo(b'<?php echo "fake image";',csrf)[0]==422,'image MIME spoofing rejected')
        photo=subprocess.run(['php','-r','imagepng(imagecreatetruecolor(2,2));'],capture_output=True,check=True).stdout
        status,response,_=upload_photo(photo+b'<?php echo "trailing payload";',csrf)
        uploaded=json.loads(response)
        expect(status==200 and re.fullmatch(r'[a-f0-9]{32}\.png',uploaded['name']),'valid image uploaded with random safe filename')
        stored=(root/'media'/uploaded['name']).read_bytes()
        expect(stored.startswith(b'\x89PNG') and b'trailing payload' not in stored,'photo re-encoding removes appended payload')
        status,body,_=get_text('/admin/?page=settings',{'action':'backup_create','csrf':csrf})
        expect(status==200 and 'Backup creato' in body,'authenticated data backup creation')
        name=re.search(r'download_backup=(alternpress-data-[^"&]+)',body).group(1)
        status,archive,headers=request('/admin/?download_backup='+name)
        expect(status==200 and archive[:2]==b'\x1f\x8b' and 'attachment' in headers.get('Content-Disposition',''),'authenticated backup download')
        expect(request('/admin/?download_backup='+name,auth=False)[1][:2]!=b'\x1f\x8b','anonymous backup download denied')
        expect(request('/admin/?download_backup=../../config.local.php')[0]==422,'backup download traversal rejected')
        status,body,_=upload_archive(archive,csrf)
        expect(status==200 and 'Prima di ripristinare' in body,'restore preview accepts own archive')
        token=field(body,'restore_token')
        expect(request('/admin/?page=settings',dict(action='restore_apply',csrf=csrf,restore_token=token))[0]==422,'restore requires explicit confirmation')
        draft.write_text(draft.read_text()+'\nChanged after preview\n')
        status,body,_=get_text('/admin/?page=settings',dict(action='restore_apply',csrf=csrf,restore_token=token,confirm_restore='1'))
        expect(status==422 and 'cambiato dopo' in body,'restore rejects intervening changes')
        status,body,_=upload_archive(archive,csrf);token=field(body,'restore_token')
        auth_before=(root/'storage/auth.php').read_bytes()
        status,body,_=get_text('/admin/?page=settings',dict(action='restore_apply',csrf=csrf,restore_token=token,confirm_restore='1'))
        expect(status==200 and 'Ripristino completato' in body and 'Changed after' not in draft.read_text(),'restore succeeds after fresh preview')
        expect((root/'storage/auth.php').read_bytes()==auth_before,'restore preserves administrator credentials')
        status,body,_=get_text('/admin/?page=settings',{'action':'check','csrf':csrf})
        expect(status==200 and 'Ultimo controllo' in body,'diagnostics works over HTTP')
        status,body,_=get_text('/admin/',{'action':'logout','csrf':csrf})
        expect(status==200 and 'Entra e scrivi' in body,'logout returns login page')
        for path in ['/storage/auth.php','/content/it/home/index.md','/lib/bootstrap.php']:
            expect(request(path,auth=False)[0]==403,'private route denied: '+path)
        # Complete CLI backup includes code, excludes previous archives/staging.
        result=subprocess.run(['php',str(root/'alt-cli'),'backup'],capture_output=True,text=True,check=True)
        backup_path=Path(re.search(r'^Backup: (.+)$',result.stdout,re.M).group(1))
        verified=subprocess.run(['php',str(root/'alt-cli'),'backup:verify',str(backup_path)],capture_output=True,text=True,check=True)
        expect('Backup verified' in verified.stdout,'complete CLI backup and checksum verification')
        expect(backup_path.stat().st_mode & 0o777 == 0o600,'complete backup is private on disk')
        print(str(checks)+' HTTP security checks passed.')
    finally:
        server.terminate();server.wait(timeout=5);log.close()
