# Reverse proxy and TLS

Manager for Craft binds to `127.0.0.1:8080` and speaks plain HTTP. That is deliberate: it expects
something in front of it terminating TLS. This page covers that something, and the one setting that
is easy to get dangerously wrong.

TLS is not optional. Signed connector requests are tamper-evident, not confidential - over plain
HTTP an enrolment code is readable by anything on the path, and so is every report after it. The
connector refuses a platform address that is not HTTPS, so a misconfiguration here presents as sites
that will not pair rather than as sites reporting insecurely.

## Trusted proxies: read this first

Once something sits in front of Manager for Craft, every request arrives from the proxy. Manager
will report the proxy's address as the client address in the audit log, and rate-limit every site as
though it were one caller - unless you tell it which proxy to believe.

```dotenv
MANAGER_TRUSTED_PROXIES=127.0.0.1
```

Or a CIDR range if the proxy is elsewhere: `MANAGER_TRUSTED_PROXIES=10.0.1.0/24`.

**Never `*`.** A wildcard tells Manager for Craft to believe the forwarded headers on any request,
which lets any caller claim any source address. That defeats per-network rate limiting and puts
attacker-chosen addresses into the audit log. `manager:doctor` fails on a wildcard rather than
warning, because there is no configuration in which it is the right answer.

Get this wrong in the other direction - no trusted proxies configured - and Manager for Craft simply
ignores forwarded headers. Rate limits and audit entries then attribute everything to the proxy,
which is inaccurate but not dangerous. `manager:doctor` reports it so you know which state you are
in.

## Caddy

The shortest correct configuration, and it obtains and renews certificates itself:

```caddy
manager.example.org {
    reverse_proxy 127.0.0.1:8080

    # Bodies are streamed, not buffered, so a large backup upload does not land on the proxy's disk.
    #
    # Manager sets no backup ceiling of its own unless you set MANAGER_BACKUP_MAX_BYTES, so by
    # default **this line is the ceiling** - whatever you put here is the largest database anyone
    # can back up, and the refusal happens here, where the message cannot explain itself.
    request_body {
        max_size 20GB
    }
}
```

Set `MANAGER_TRUSTED_PROXIES=127.0.0.1` and `APP_URL=https://manager.example.org`.

## nginx

```nginx
server {
    listen 443 ssl http2;
    server_name manager.example.org;

    ssl_certificate     /etc/letsencrypt/live/manager.example.org/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/manager.example.org/privkey.pem;

    # Backup artifacts are uploaded through this. **nginx's default is 1 MB**, which rejects
    # essentially every real backup, and the symptom is a 413 the application never sees and cannot
    # explain. A live console ran at 2m and refused a 2.1 MB database for four nights.
    #
    # Manager sets no backup ceiling of its own unless you set MANAGER_BACKUP_MAX_BYTES, so by
    # default this line is the ceiling. If you do set one, keep this at or above it.
    client_max_body_size 20G;

    # Streamed through rather than buffered to disk first. A buffered upload writes an unencrypted-
    # adjacent copy of a customer database into the proxy's temp directory.
    proxy_request_buffering off;
    proxy_buffering off;

    # Long enough for a large artifact on a slow connection. Twenty gigabytes on a 20 Mbit uplink
    # is around two and a half hours, so this is sized against MANAGER_BACKUP_UPLOAD_WINDOW rather
    # than against how long a request usually takes.
    proxy_read_timeout 21600s;
    proxy_send_timeout 21600s;

    location / {
        proxy_pass http://127.0.0.1:8080;

        proxy_set_header Host              $host;
        proxy_set_header X-Real-IP         $remote_addr;
        proxy_set_header X-Forwarded-For   $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;
        proxy_set_header X-Forwarded-Host  $host;
    }
}

server {
    listen 80;
    server_name manager.example.org;
    return 301 https://$host$request_uri;
}
```

`X-Forwarded-Proto` matters more than it looks. Without it Manager for Craft believes it is being
reached over HTTP, marks session cookies as insecure, and generates `http://` links.
`manager:doctor` catches the cookie half of that.

## Traefik

```yaml
labels:
  - "traefik.enable=true"
  - "traefik.http.routers.manager.rule=Host(`manager.example.org`)"
  - "traefik.http.routers.manager.entrypoints=websecure"
  - "traefik.http.routers.manager.tls.certresolver=letsencrypt"
  - "traefik.http.services.manager.loadbalancer.server.port=8080"
```

Traefik sets the forwarded headers itself. Set `MANAGER_TRUSTED_PROXIES` to the Docker network range
Traefik runs on rather than to `127.0.0.1`.

## PHP's own body limit

The proxy is not the only thing that can refuse a large upload before Manager for Craft sees it.
PHP refuses a request whose `Content-Length` exceeds `post_max_size` before any application code
runs, and Laravel applies that to a `PUT` exactly as to a `POST`. So a generous
`client_max_body_size` with a default `post_max_size` fails in the same way, one layer further in.

```ini
; php.ini - 0 means unlimited, and Manager streams the upload to disk rather than reading it
; into memory, so this is not the protection it looks like.
post_max_size = 0
upload_max_filesize = 0
```

`manager:doctor` reports the effective number under **Upload path ceiling**, and fails if it is
below a ceiling you have configured. It cannot see the proxy - see below.

## When a backup fails with no correlation ID

Every refusal Manager for Craft composes carries a correlation identifier, in the response body and
in the `Manager-Correlation-Id` header, and writes a matching line to its log. So a connector
reporting

```
The platform rejected the artifact (HTTP 413). Correlation ID: unknown
```

is telling you the response **did not come from Manager for Craft at all**. Nothing will be in the
Laravel log, because nothing reached PHP. Look at the proxy.

Test it directly rather than inferring, with a body larger than the one that failed:

```bash
head -c 3000000 /dev/zero > /tmp/probe.bin
curl -i -X PUT --data-binary @/tmp/probe.bin \
  -H 'Content-Type: application/octet-stream' \
  https://manager.example.org/api/connector/v1/backups/probe/content
```

An HTML error page naming nginx is the proxy. A JSON body with a `correlation_id` means the request
got through and the fault is somewhere else - the signature is expected to fail here, so a `401` is
the healthy answer.

## Cloudflare and similar

Workable, with two things to know.

Free Cloudflare plans cap request bodies at 100 MB, which will reject backup artifacts from any site
of consequence. Either put backups on an S3-compatible store in the same network as Manager for
Craft, or exclude the connector API from the proxy.

And the client address arrives in `CF-Connecting-IP`, so trust Cloudflare's published ranges rather
than `127.0.0.1` - and keep that list current, because a stale range list means either broken rate
limiting or misattributed audit entries.

## Checking it

From outside:

```bash
curl -fsS https://manager.example.org/up      # liveness
curl -fsS https://manager.example.org/ready   # database, Redis, migrations, storage
```

Then, from the machine itself:

```bash
docker compose exec app php artisan manager:doctor
```

It checks the things this page can get wrong: a wildcard trusted-proxy setting, a session cookie not
marked secure against an HTTPS `APP_URL`, and an `APP_URL` that does not match how the site is
actually reached.

The real test is pairing a site. If the certificate chain is incomplete - a common nginx mistake,
using `cert.pem` where `fullchain.pem` was needed - a browser will accept it and the connector will
not, because it verifies properly. A site that will not pair while the dashboard loads fine in a
browser is almost always an incomplete chain.

