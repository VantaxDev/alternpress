#!/usr/bin/env python3
"""Opt-in isolated Apache/SSI year-boundary integration test (stdlib only)."""
from pathlib import Path
import os, subprocess, time, urllib.request, json, tempfile, shutil, socket
workspace = tempfile.TemporaryDirectory(prefix='alternpress-footer-ssi-')
root = Path(workspace.name); root.chmod(0o755)
shutil.copytree(Path(__file__).resolve().parent / 'dist', root / 'site')
lib = Path(os.environ['FAKETIME_LIBRARY']).resolve()
assert lib.is_file(), 'Supply the test-only libfaketime path; never set the host clock'
with socket.socket() as sock:
 sock.bind(('127.0.0.1', 0)); port = sock.getsockname()[1]
apache = os.environ.get('APACHE_BINARY', shutil.which('apache2') or '/usr/sbin/apache2')
identity = 'User www-data\nGroup www-data' if os.getuid() == 0 else ''
config=f'''ServerRoot "{root}"
ServerName localhost
Listen 127.0.0.1:{port}
PidFile "{root}/httpd.pid"
ErrorLog "{root}/error.log"
LoadModule mpm_event_module /usr/lib/apache2/modules/mod_mpm_event.so
LoadModule authz_core_module /usr/lib/apache2/modules/mod_authz_core.so
LoadModule mime_module /usr/lib/apache2/modules/mod_mime.so
LoadModule dir_module /usr/lib/apache2/modules/mod_dir.so
LoadModule include_module /usr/lib/apache2/modules/mod_include.so
LoadModule filter_module /usr/lib/apache2/modules/mod_filter.so
{identity}
TypesConfig /etc/mime.types
DocumentRoot "{root}/site"
DirectoryIndex index.html
<Directory "{root}/site">
  Require all granted
  Options IncludesNOEXEC
  <FilesMatch "^(index|404)\\.html$">
    SetOutputFilter INCLUDES
  </FilesMatch>
</Directory>
'''
(root/'httpd.conf').write_text(config)
results=[]
for clock,year in [('2030-12-31 23:59:59','2030'),('2031-01-01 00:00:00','2031')]:
 env={**os.environ,'TZ':'Europe/Rome','LD_PRELOAD':str(lib),'FAKETIME':clock,'FAKETIME_DONT_FAKE_MONOTONIC':'1'}
 proc=subprocess.Popen([apache,'-f',str(root/'httpd.conf'),'-DFOREGROUND'],env=env,stdout=subprocess.DEVNULL,stderr=subprocess.PIPE)
 try:
  for attempt in range(30):
   try:
    with urllib.request.urlopen(f'http://127.0.0.1:{port}/') as response: response.read()
    break
   except Exception: time.sleep(.1)
  for path in ['/','/it/','/404.html']:
   request=urllib.request.Request(f'http://127.0.0.1:{port}'+path,headers={'If-Modified-Since':'Wed, 01 Jan 2040 00:00:00 GMT'})
   with urllib.request.urlopen(request,timeout=5) as response:
    html=response.read().decode()
    expected='© '+year+' SERVER TAMER DI DANIELE VANTAGGIATO - P.IVA 04370600274'
    assert expected in html,(clock,path,html[-1200:])
    assert '<!--#' not in html and '[an error occurred' not in html
    assert 'ETag' not in response.headers and 'Last-Modified' not in response.headers,dict(response.headers)
    results.append({'clock':clock,'path':path,'status':response.status,'business_line':expected,'validators_absent':True})
 finally:
  proc.terminate()
  try: proc.wait(timeout=5)
  except subprocess.TimeoutExpired: proc.kill();proc.wait()
  err=proc.stderr.read().decode(); assert proc.returncode in (0,-15),err
print(json.dumps(results,indent=2))

workspace.cleanup()
