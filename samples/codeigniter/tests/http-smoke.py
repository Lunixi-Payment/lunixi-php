#!/usr/bin/env python3
"""Native HTTP security checks, including offline-cached checkouts; no gateway call."""
import base64, http.cookiejar, json, os, re, secrets, socket, sqlite3, subprocess, tempfile, time
from pathlib import Path
from urllib.request import build_opener, HTTPCookieProcessor, HTTPRedirectHandler, Request
from urllib.parse import urlencode
from urllib.error import HTTPError, URLError

FRAMEWORK = 'codeigniter'
ROOT = Path(__file__).resolve().parents[1]
PUBLIC = ROOT / ('public' if FRAMEWORK == 'codeigniter' else 'web')
checks = 0

def ensure(condition, label):
    global checks
    checks += 1
    if not condition:
        raise RuntimeError(label)

with tempfile.TemporaryDirectory(prefix='lunixi-native-http-') as tmp:
    tmp = Path(tmp)
    database = tmp / 'sample.sqlite'
    password = secrets.token_urlsafe(24)
    keygen = "require $argv[1]; echo Lunixi\\Sdk\\Auth\\Ed25519Signer::generateKeyPair()['privateKey'];"
    private_key = subprocess.check_output(['php', '-r', keygen, str(ROOT / 'vendor/autoload.php')], text=True).strip()
    env = dict(os.environ, CI_ENVIRONMENT='development', APP_ENV='dev', YII_LOCAL_HTTP='1', YII_COOKIE_KEY=secrets.token_hex(32), LUNIXI_DATABASE=str(database), LUNIXI_MERCHANT_ID='native-http-fixture', LUNIXI_ENVIRONMENT='TEST', LUNIXI_KEY_ID='unregistered-http-fixture', LUNIXI_PRIVATE_KEY=private_key, LUNIXI_WEBHOOK_SECRET=secrets.token_urlsafe(32), LUNIXI_ENCRYPTION_KEY=base64.b64encode(secrets.token_bytes(32)).decode(), LUNIXI_APP_URL='https://billing.example', DEMO_ALICE_PASSWORD=password, DEMO_BOB_PASSWORD=secrets.token_urlsafe(24), SAMPLE_PUBLIC=str(PUBLIC))
    setup = ['php', str(ROOT / ('spark' if FRAMEWORK == 'codeigniter' else 'yii')), 'lunixi:setup' if FRAMEWORK == 'codeigniter' else 'lunixi/setup']
    result = subprocess.run(setup, cwd=ROOT, env=env, capture_output=True, text=True)
    ensure(result.returncode == 0, 'Native schema/setup command failed')
    router = tmp / 'router.php'
    router.write_text("<?php $base=getenv('SAMPLE_PUBLIC');$path=parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH);$file=realpath($base.$path);if($file && is_file($file) && str_starts_with($file,$base.'/'))return false;require $base.'/index.php';")
    sock = socket.socket(); sock.bind(('127.0.0.1', 0)); port = sock.getsockname()[1]; sock.close()
    jar = http.cookiejar.CookieJar(); http = build_opener(HTTPCookieProcessor(jar)); origin = f'http://127.0.0.1:{port}'
    if FRAMEWORK == 'codeigniter':
        env['app.baseURL'] = origin + '/'
    log = open(tmp / 'http.log', 'w')
    server = subprocess.Popen(['php', '-S', f'127.0.0.1:{port}', '-t', str(PUBLIC), str(router)], cwd=ROOT, env=env, stdout=log, stderr=log)
    def request(path, params=None):
        data = None if params is None else urlencode(params).encode()
        try:
            with http.open(Request(origin + path, data=data), timeout=10) as response:
                return response.status, response.read().decode()
        except HTTPError as error:
            return error.code, error.read().decode()
    def csrf(body):
        name = 'csrf_test_name' if FRAMEWORK == 'codeigniter' else '_csrf'
        match = re.search(r'name="' + name + r'" value="([^"]+)"', body)
        ensure(match is not None, 'Native CSRF field missing')
        return name, match.group(1)
    try:
        for _ in range(60):
            try:
                status, body = request('/login')
                break
            except (URLError, ConnectionError):
                time.sleep(.1)
        else:
            raise RuntimeError('Native HTTP server did not start')
        ensure(status == 200, 'Native login GET failed')
        token_name, token = csrf(body)
        before = {cookie.name: cookie.value for cookie in jar}
        status, _ = request('/login', {'email': 'alice@example.test', 'password': password})
        ensure(status in (400, 403), 'Missing login CSRF was accepted')
        status, body = request('/login')
        token_name, token = csrf(body)
        status, body = request('/login', {token_name: token, 'email': 'alice@example.test', 'password': password})
        ensure(status == 200 and ('Your orders' in body), 'Native password/session login failed')
        after = {cookie.name: cookie.value for cookie in jar}
        ensure(any(after.get(name) != value for name, value in before.items()), 'Native session ID did not rotate on login')
        class NoRedirect(HTTPRedirectHandler):
            def redirect_request(self, req, fp, code, msg, headers, newurl):
                return None
        anonymous = build_opener(NoRedirect())
        try:
            anonymous.open(Request(origin + '/payments/1/return', data=urlencode({'status': 'CAPTURED', 'paymentId': 'forged'}).encode(), headers={'Origin': 'https://pay.lunixi.com', 'Sec-Fetch-Site': 'cross-site', 'Content-Type': 'application/x-www-form-urlencoded'}), timeout=10)
            raise RuntimeError('Informational callback did not return 303')
        except HTTPError as callback:
            ensure(callback.code == 303 and callback.headers.get('Location') == '/payments/1/return', 'Anonymous callback was not fixed same-origin 303')
            ensure(callback.read() == b'' and not callback.headers.get('Set-Cookie'), 'Anonymous callback disclosed order data or created a session')
        try:
            anonymous.open(origin + '/payments/1/return', timeout=10)
            raise RuntimeError('Anonymous GET exposed order')
        except HTTPError as denied:
            ensure(denied.code in (302, 401), 'Owned GET lost authentication after callback adapter')
        status, body = request('/payments/1/return')
        ensure(status == 200, 'Authenticated GET did not resume after callback POST')
        status, _ = request('/payments/2/return')
        ensure(status == 404, 'Native other-owner return exposed order')
        status, _ = request('/payments/1', {})
        ensure(status in (400, 403), 'Native payment CSRF guard failed')
        status, _ = request('/payments/1')
        ensure(status in (404, 405), 'GET started a payment')
        status, _ = request('/payments/1/return?status=CAPTURED&amount=1990&paymentId=forged')
        ensure(status == 200, 'Owned native browser return failed')
        with sqlite3.connect(database) as db:
            ensure(db.execute('SELECT state FROM orders WHERE id=1').fetchone()[0] == 'pending', 'Browser return marked paid')
            ensure(db.execute('SELECT COUNT(*) FROM lunixi_attempts').fetchone()[0] == 0, 'A rejected browser request reserved a payment')
        cached_env = dict(env, LUNIXI_NATIVE_HTTP_CACHE_FIXTURE='1')
        cached = subprocess.run(['php', str(ROOT / 'tests/seed-http-checkouts.php')], cwd=ROOT, env=cached_env, capture_output=True, text=True)
        ensure(cached.returncode == 0, 'Offline checkout cache fixture failed')
        owned = build_opener(HTTPCookieProcessor(jar), NoRedirect())
        for order_id, expected_status in ((1, 303), (3, 200)):
            status, body = request('/orders')
            ensure(status == 200, 'Owned orders page unavailable')
            token_name, token = csrf(body)
            try:
                response = owned.open(Request(origin + f'/payments/{order_id}', data=urlencode({token_name: token}).encode()), timeout=10)
            except HTTPError as error:
                response = error
            with response:
                ensure(response.code == expected_status, 'Cached checkout did not resume in its saved mode')
                ensure('no-store' in response.headers.get('Cache-Control', ''), 'Checkout response can be cached')
                ensure(response.headers.get('Referrer-Policy') == 'no-referrer', 'Checkout referrer policy was overwritten')
                ensure(response.headers.get('X-Frame-Options') == 'SAMEORIGIN' and response.headers.get('X-Content-Type-Options') == 'nosniff', 'Native secure headers were lost')
                rendered = response.read().decode()
                if order_id == 1:
                    ensure(response.headers.get('Location') == 'https://pay.lunixi.com/payment/backend-page-pi-1', 'Hosted checkout changed the backend URL')
                else:
                    ensure('https://cdn.lunixi.com/lunixi-checkout.min.js' in rendered and 'lunixi-checkout-form' in rendered, 'Embedded form did not render on merchant page')
        with sqlite3.connect(database) as db:
            ensure(db.execute('SELECT COUNT(*) FROM lunixi_attempts').fetchone()[0] == 2, 'Cached HTTP resume created another attempt')
            ensure(db.execute("SELECT COUNT(*) FROM orders WHERE state != 'pending'").fetchone()[0] == 0, 'A browser checkout changed financial state')
        print(f'{FRAMEWORK}: real native HTTP smoke passed ({checks} checks); no gateway payment request sent')
    finally:
        server.terminate()
        try:
            server.wait(timeout=5)
        except subprocess.TimeoutExpired:
            server.kill(); server.wait()
        log.close()
