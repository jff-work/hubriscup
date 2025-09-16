<?php
// api/db.php
date_default_timezone_set('UTC');

function db_path(){
  $root = dirname(__DIR__);
  $data = $root . '/data';
  if(!is_dir($data)) mkdir($data, 0770, true);
  return $data . '/app.db';
}

function db(){
  static $pdo = null;
  if($pdo) return $pdo;
  $pdo = new PDO('sqlite:'.db_path());
  $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
  init_schema($pdo);
  return $pdo;
}

function init_schema($pdo){
  // Migrations: ensure added columns exist
  $pdo->exec("
  CREATE TABLE IF NOT EXISTS settings (
    key TEXT PRIMARY KEY,
    value TEXT
  );
  CREATE TABLE IF NOT EXISTS players (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL,
    checked_in INTEGER DEFAULT 0,
    active INTEGER DEFAULT 1,
    dropped INTEGER DEFAULT 0,
    pod INTEGER DEFAULT NULL,
    pod_seat INTEGER DEFAULT NULL,
    top8_seat INTEGER DEFAULT NULL,
    created_at TEXT DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT DEFAULT CURRENT_TIMESTAMP,
    no_phone INTEGER DEFAULT 0
  );
  CREATE TABLE IF NOT EXISTS matches (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    round INTEGER NOT NULL,
    table_no INTEGER,
    p1_id INTEGER,
    p2_id INTEGER,
    p1_game_wins INTEGER,
    p2_game_wins INTEGER,
    p1_reported INTEGER DEFAULT 0,
    p2_reported INTEGER DEFAULT 0,
    confirmed INTEGER DEFAULT 0,
    is_bye INTEGER DEFAULT 0,
    pod_round INTEGER DEFAULT 0,
    top8_phase TEXT DEFAULT NULL,
    created_at TEXT DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT DEFAULT CURRENT_TIMESTAMP
  );
  ");
  // Backfill/migrate columns if needed
  try {
    $cols = $pdo->query("PRAGMA table_info(players)")->fetchAll(PDO::FETCH_ASSOC);
    $needNoPhone = true;
    foreach($cols as $c){ if($c['name']==='no_phone') $needNoPhone=false; }
    if($needNoPhone){ $pdo->exec("ALTER TABLE players ADD COLUMN no_phone INTEGER DEFAULT 0"); }
  } catch(Exception $e){}

  // Defaults
  set_setting('status', get_setting('status') ?: 'pre');
  set_setting('event_name', get_setting('event_name') ?: ('Hubris Cup '.date('Y')));
  set_setting('current_round', get_setting('current_round') ?: '0');
  set_setting('total_rounds', get_setting('total_rounds') ?: '0');
  set_setting('debug_mode', get_setting('debug_mode') ?: '1');
  set_setting('locale', get_setting('locale') ?: 'de');
}

function get_setting($k){
  $stmt = db()->prepare("SELECT value FROM settings WHERE key=?"); $stmt->execute([$k]);
  $r = $stmt->fetch(PDO::FETCH_ASSOC); return $r ? $r['value'] : null;
}
function set_setting($k,$v){
  $stmt = db()->prepare("INSERT INTO settings (key,value) VALUES (?,?) ON CONFLICT(key) DO UPDATE SET value=excluded.value");
  $stmt->execute([$k,$v]);
}

function q($sql,$params=[]){ $stmt = db()->prepare($sql); $stmt->execute($params); return $stmt; }
function all($sql,$params=[]){ return q($sql,$params)->fetchAll(PDO::FETCH_ASSOC); }
function one($sql,$params=[]){ $st=q($sql,$params); $r=$st->fetch(PDO::FETCH_ASSOC); return $r?:null; }

function now(){ return date('c'); }
