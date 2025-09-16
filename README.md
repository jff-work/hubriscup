# HubrisCup (MTG mini tournament tool)

Stack: Plain HTML/JS (jQuery) + PHP (no framework) + SQLite. NGINX or PHP built‑in server.
Two UIs: `/` (Player, mobile-first) and `/admin` (Admin). Default locale: German (with English toggle).

## Original Prompt
# HubrisCup – Concise Requirements

## 0) Scope & Tech

* **Purpose:** Run small MTG draft events (16–32 players) with Swiss + Top 8.
* **Stack:** Frontend = plain HTML/JS + jQuery. Backend = PHP (no big framework). DB = SQLite. Server = NGINX/Ubuntu (works locally on macOS too).
* **Routes:** `/` (Player, default) and `/admin` (Admin). **No auth**.
* **Locale:** German & English; **default = German**.
* **Responsive:** Player optimized for phone; Admin for desktop (both work on either).

## 1) Tournament Structure

* **Size:** 16–32 players.
* **Rounds:**

  * ≤24 players → **5** Swiss rounds + **Top 8** (QF/SF/Final).
  * ≥25 players → **6** Swiss rounds + **Top 8**.
* **Draft Pods (before R1):** Make pods of **8** where possible; use **7/6** as needed; **no pod < 6**.
  Examples: 21 → 8/7/6; 22 → 8/8/6.
* **Swiss Pairings:**

  * **R1–R3:** within each pod; **R1 = cross-pairings** inside pod (e.g., 1–5, 2–6, 3–7, 4–8).
  * **R4+**: across all players (outside pods).
* **Top 8:** Create a new “pod”; **random seating**; cross-pairings; play QF → SF → Final.

## 2) Roles & Screens

### Player (`/`)

* **Welcome:** “Willkommen beim Hubris Cup {Jahr}”.
* **Select Player:** List of names; tap to set “me” (stored locally).
* **Always-available menu:** **Change player** (switch at any time).
* **Check-in:** Single **Check-in** button. Footer note: “to drop, talk to TO directly”.
* **Draft Seat:** Prominent “Your draft seat: Pod X, Seat Y” + simple table visualization showing draft order; highlight their seat.
* **Round View:** Shows **Table**, **Opponent**, **Result entry** (any standard MTG result).

  * If opponent has already entered, show their result and a **Confirm** state.
  * **No-phone players:** result entry disabled with info that TO must enter.
* **Standings:** Shown between rounds and after Swiss; also after Top 8 concludes.

### Admin (`/admin`)

* **Preparation:** Player list with **Add** / **Remove**; **Start tournament** button.
* **Check-in:** Same list, live status; **Create pods** button.

  * If some not checked-in, warn; continuing **drops** those players.
  * Extra button per player: **“no-phone manual checkin”** → checks-in + flags as no-phone.
  * Legend: **Orange = no phone** (manual results).
* **Pods:** List/visual of pods & seats; button **Create pairings for Round 1**.
* **Rounds:** Pairings table with per-match results state.

  * Green indicators: per-side entered; fully green when confirmed.
  * **Edit result** dialog per match.
  * **Drop player next round** controls.
  * **Next round** enables only when all results confirmed.
  * After final Swiss round: **Create final standings before Top 8**.
* **Standings:** List sorted by **match points** (tie-break specifics not mandated).
* **Top 8:** Create Top 8 draft (random seating + cross-pairings), then run 3 KO rounds; final standings and a winner message.

## 3) Debug Mode (Admin)

* **Populate with X random players** (pre-tournament).
* **Check-in remaining players** (on check-in page).
* **Copy results from the other side** (auto-confirm where one side already reported).
* *(Implementation note: a “Randomize results” helper may exist for testing, but original required buttons are the three above.)*

## 4) Status/Colors & Legends

* **Green badge:** checked-in.
* **Orange badge:** **no-phone/manual** → TO must enter results. Legend shown on Player & Admin.

## 5) Behavioral Rules

* **Flow gating:** Each page shows **only the current stage**; no multi-stage clutter.
* **Drops:** Players request via TO; Admin marks drops (effective next round).
* **R1 cross-pairings & pod visuals** must reflect **standard MTG seating/draft order**.

---

This is the condensed, implementation-ready spec capturing your original requirements + the follow-up updates.


## Flow separation
The UI strictly follows the required stages, showing only the relevant screen at each step:
- **Preparation → Check-in → Pods → Round(s) → Standings → Top 8 → Final standings**
Player UI always has a top menu with **Change player**.

## Key features
- Pods: prefer 8; allow 7/6; never below 6; if necessary, a single 9 (e.g. 17 → 9+8). Examples: 21 → 8/7/6; 22 → 8/8/6.
- R1 cross-pairings inside pod; R2–R3 Swiss inside pod; R4+ global Swiss.
- 16–24 players → 5 Swiss + Top 8; 25–32 → 6 Swiss + Top 8.
- Standings: MP → OMW% → GWP% → OGW% with 33% floors; byes excluded from OMW%/OGW%.
- Admin check-in **No-phone manual checkin**: marks a player orange; they cannot submit results, TO must enter them.
- Debug: Populate X players, Check‑in remaining, **Copy results** (confirm from reported side), **Randomize results** (arbitrary for current round).

## Quick run (macOS or Linux)
```bash
brew install php sqlite   # or apt install php php-sqlite3 on Ubuntu
unzip hubriscup.zip && cd hubriscup
cd public && ln -s ../api api && cd ..
php -S 127.0.0.1:8080 -t public
# Player: http://127.0.0.1:8080/   Admin: http://127.0.0.1:8080/admin/
```

For NGINX/PHP‑FPM, see the earlier instructions I gave you or adapt the sample server block.
SQLite db lives in `/data/app.db`.
