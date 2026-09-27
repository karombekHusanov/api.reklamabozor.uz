# Centrifugo — realtime presence

Mini app keeps one WebSocket; connected = online. Presence lives in
Centrifugo memory (no DB writes, no heartbeat requests); `stats:publish-live`
(scheduler, every 10s) pushes live stats to every client. Code: `config/realtime.php`,
`app/Services/Realtime/*`, miniapp `src/core/stores/realtime.store.ts`.

## Prod rollout (once)

1. Binary (pin version, verify checksum):
   ```bash
   V=6.9.6
   curl -sLO https://github.com/centrifugal/centrifugo/releases/download/v$V/centrifugo_${V}_linux_amd64.tar.gz
   curl -sL https://github.com/centrifugal/centrifugo/releases/download/v$V/centrifugo_${V}_checksums.txt | grep linux_amd64 | sha256sum -c
   tar xzf centrifugo_${V}_linux_amd64.tar.gz && install -m 755 centrifugo /usr/local/bin/centrifugo
   ```
2. `/etc/centrifugo/config.json` from `config.example.json` — fresh random
   `hmac_secret_key` + `http_api.key` (`openssl rand -hex 32`), `chmod 640`,
   owner `root:centrifugo`. Listens on `127.0.0.1:8001` only.
3. systemd `/etc/systemd/system/centrifugo.service`:
   ```ini
   [Unit]
   Description=Centrifugo
   After=network.target
   [Service]
   User=centrifugo
   ExecStart=/usr/local/bin/centrifugo -c /etc/centrifugo/config.json
   Restart=always
   LimitNOFILE=65536
   [Install]
   WantedBy=multi-user.target
   ```
   `useradd -r -s /usr/sbin/nologin centrifugo && systemctl enable --now centrifugo`
4. nginx, inside the `api.` server block (no new DNS/cert needed):
   ```nginx
   location /connection/websocket {
       proxy_pass http://127.0.0.1:8001;
       proxy_http_version 1.1;
       proxy_set_header Upgrade $http_upgrade;
       proxy_set_header Connection "upgrade";
       proxy_set_header Host $host;
       proxy_read_timeout 1h;
   }
   ```
   The `/api` HTTP API stays internal (never proxied).
5. Backend `.env`:
   ```
   REALTIME_ENABLED=true
   CENTRIFUGO_WS_URL=wss://api.<domain>/connection/websocket
   CENTRIFUGO_API_URL=http://127.0.0.1:8001/api
   CENTRIFUGO_API_KEY=...
   CENTRIFUGO_HMAC_SECRET=...
   ```
   then `config:cache` + reload php-fpm + restart worker (GOTCHA #1).

Rollback: `REALTIME_ENABLED=false` → mini app falls back to polling, online
counts to the Sanctum `last_used_at` heuristic.

Scale notes: ~10–30 KB RAM per connection (10k ≈ 300 MB); nginx
`worker_connections` ≥ 2× expected sockets.
