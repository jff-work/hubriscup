# HubrisCup (MTG mini tournament tool)

Plain HTML/JS (jQuery) frontend + PHP backend (no framework) + SQLite. Two screens:
- `/` Player UI (German by default; EN available via language toggle)
- `/admin` Admin UI

## Features
- Player list, add/remove players, check-in, pods (6–8 ideal; allows 9 only if necessary to make totals work, e.g., 17 → 9+8), pod seatings.
- R1 cross-pairings **inside the draft pod** (mirror seatings). R2–R3 Swiss **within pod**. R4+ Swiss **global**.
- Rounds: 16–24 players ⇒ 5 Swiss rounds + Top 8; 25–32 ⇒ 6 + Top 8.
- Results are double-confirmed by players; admin can edit any result.
- Standings with MTR-style tiebreakers: MP → OMW% → GWP% → OGW% (33% floors; byes excluded from OMW%).
- Top 8: random seating, cross-pair (1–5, 2–6, 3–7, 4–8), single-elim QF→SF→F; final standings & fun congrats.
- Debug mode: populate X random players; check-in remaining; auto-copy pending results.
- Responsive: player view optimized for phone; admin optimized for desktop.

## Quick setup (Ubuntu + NGINX + PHP + SQLite)
1) Install packages (Ubuntu 22.04+):
   ```bash
   sudo apt update
   sudo apt install -y nginx php-fpm php-sqlite3 unzip
   ```

2) Deploy files:
   ```bash
   sudo mkdir -p /var/www/hubriscup
   sudo chown -R $USER:$USER /var/www/hubriscup
   unzip hubriscup.zip -d /var/www/hubriscup
   ```

3) NGINX server block (example):
   ```nginx
   server {
     listen 80;
     server_name _;
     root /var/www/hubriscup/public;

     index index.html index.php;

     location / {
       try_files $uri $uri/ /index.html;
     }

     location /admin {
       alias /var/www/hubriscup/public/admin;
       index index.html;
     }

     location /api {
       alias /var/www/hubriscup/api;
       index api.php;
       rewrite ^/api/?(.*)$ /api.php?$1 last;
     }

     location ~ \.php$ {
       include snippets/fastcgi-php.conf;
       fastcgi_pass unix:/var/run/php/php-fpm.sock;
     }

     location ~* \.(db|sqlite)$ {
       deny all;
     }
   }
   ```
   Enable and reload NGINX:
   ```bash
   sudo tee /etc/nginx/sites-available/hubriscup <<'CONF'
   (paste the block above)
   CONF
   sudo ln -s /etc/nginx/sites-available/hubriscup /etc/nginx/sites-enabled/hubriscup
   sudo nginx -t && sudo systemctl reload nginx
   ```

4) Permissions for SQLite file:
   ```bash
   sudo chown -R www-data:www-data /var/www/hubriscup/data
   sudo chmod 770 /var/www/hubriscup/data
   ```

5) Visit:
   - Player UI: http://your-host/
   - Admin UI:  http://your-host/admin

## Config
- Default locale is German (`de`). A simple toggle exists in both UIs.
- Event name/year defaults to **Hubris Cup 2025**; change in Admin → Preparation or Settings box.

## Notes on pods
- Targets 8-seat pods. If remainder 6–7, uses one smaller pod (6 or 7). If remainder 1–5 (only problematic is 17 players), it allows **a single pod of 9** to guarantee all pods have ≥6 seats and exactly cover 16–32 players. Example: 21 → 8,7,6; 22 → 8,8,6; 17 → 9,8.

## Development
- All PHP is in `/api` with a single endpoint `api.php` (action-driven, `a=`). SQLite DB lives in `/data/app.db` (created on first run).
- Core pairing & standings logic is in `logic.php`.
