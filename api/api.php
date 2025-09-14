<?php
// api/api.php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__.'/db.php';
require_once __DIR__.'/logic.php';

$a = $_GET['a'] ?? $_POST['a'] ?? 'get_state';

function j($x){ echo json_encode($x); exit; }

function intv($x){ return intval($x); }

switch($a){
  case 'get_state':
    $view = $_POST['view'] ?? 'player';
    $me = isset($_POST['me']) ? intval($_POST['me']) : null;
    $settings = [
      'status'=> get_setting('status'),
      'current_round'=> intval(get_setting('current_round')),
      'total_rounds'=> intval(get_setting('total_rounds')),
      'event_name'=> get_setting('event_name'),
      'locale'=> get_setting('locale'),
      'debug_mode'=> intval(get_setting('debug_mode')),
    ];
    $players = all("SELECT id,name,checked_in,active,dropped,pod,pod_seat,top8_seat FROM players WHERE active=1 ORDER BY id ASC");
    // Pods
    $pods=[];
    $rows = all("SELECT DISTINCT pod FROM players WHERE pod IS NOT NULL ORDER BY pod ASC");
    foreach($rows as $r){
      $seats = all("SELECT id,name,pod,pod_seat FROM players WHERE pod=? ORDER BY pod_seat ASC", [$r['pod']]);
      $pods[] = ['pod'=>intval($r['pod']),'seats'=>array_map(function($s){ return ['id'=>intval($s['id']),'name'=>$s['name'],'pod'=>intval($s['pod']),'seat'=>intval($s['pod_seat'])]; }, $seats)];
    }
    $cur = intval(get_setting('current_round'));
    $matches = $cur>0 ? round_matches($cur) : [];
    $stand = [];
    if(get_setting('status')=='standings' || get_setting('status')=='round' || get_setting('status')=='top8' || get_setting('status')=='complete'){
      $stand = standings(null);
    }

    $payload = [
      'year'=> intval(date('Y')),
      'settings'=> $settings,
      'players'=> array_map(function($p){ return [
        'id'=>intval($p['id']),'name'=>$p['name'],'checked_in'=>intval($p['checked_in'])==1,
        'active'=>intval($p['active'])==1,'dropped'=>intval($p['dropped'])==1,
        'pod'=>$p['pod']!==null? intval($p['pod']) : null,
        'pod_seat'=>$p['pod_seat']!==null? intval($p['pod_seat']) : null,
        'top8_seat'=>$p['top8_seat']!==null? intval($p['top8_seat']) : null
      ]; }, $players),
      'pods'=> $pods,
      'current_round'=> $cur>0?$cur:null,
      'matches'=> array_map(function($m){
        return [
          'id'=>intval($m['id']),
          'round'=>intval($m['round']),
          'table_no'=>$m['table_no']!==null?intval($m['table_no']):null,
          'p1_id'=>$m['p1_id']!==null?intval($m['p1_id']):null,
          'p2_id'=>$m['p2_id']!==null?intval($m['p2_id']):null,
          'p1_name'=>$m['p1_name'],'p2_name'=>$m['p2_name'],
          'p1_game_wins'=>$m['p1_game_wins']!==null?intval($m['p1_game_wins']):null,
          'p2_game_wins'=>$m['p2_game_wins']!==null?intval($m['p2_game_wins']):null,
          'confirmed'=>intval($m['confirmed'])==1,
          'is_bye'=>intval($m['is_bye'])==1,
          'pod_round'=>intval($m['pod_round'])==1,
          'top8_phase'=>$m['top8_phase']
        ];
      }, $matches),
      'standings'=> $stand
    ];

    // Player-specific helper
    if($me){
      $payload['my_match'] = null;
      foreach($matches as $m){
        if(intval($m['p1_id'])==$me || intval($m['p2_id'])==$me){
          $payload['my_match'] = [
            'id'=>intval($m['id']),'table_no'=>$m['table_no']!==null?intval($m['table_no']):null,
            'p1_id'=>$m['p1_id']!==null?intval($m['p1_id']):null,'p2_id'=>$m['p2_id']!==null?intval($m['p2_id']):null,
            'p1_name'=>$m['p1_name'],'p2_name'=>$m['p2_name']
          ];
          // Also include opponent's report if exists
          $oppReported = null;
          if(intval($m['p1_id'])==$me && intval($m['p2_reported'])==1 && $m['p2_game_wins']!==null){
            $oppReported = ['p1'=>intval($m['p1_game_wins']??0),'p2'=>intval($m['p2_game_wins']??0)];
          }
          if(intval($m['p2_id'])==$me && intval($m['p1_reported'])==1 && $m['p1_game_wins']!==null){
            $oppReported = ['p1'=>intval($m['p1_game_wins']??0),'p2'=>intval($m['p2_game_wins']??0)];
          }
          $payload['opp_report'] = $oppReported;
          break;
        }
      }
    }

    j($payload);
    break;

  case 'add_player':
    $name = trim($_POST['name'] ?? '');
    if(!$name) j(['ok'=>false,'error'=>'Name required']);
    q("INSERT INTO players (name,checked_in,active) VALUES (?,0,1)", [$name]);
    j(['ok'=>true]);
    break;

  case 'remove_player':
    $id = intv($_POST['id'] ?? 0);
    if($id<=0) j(['ok'=>false]);
    q("DELETE FROM players WHERE id=?", [$id]);
    j(['ok'=>true]);
    break;

  case 'start_event':
    set_setting('status','checkin');
    set_setting('current_round','0');
    set_setting('total_rounds','0');
    j(['ok'=>true]);
    break;

  case 'set_event_name':
    $v = trim($_POST['event_name'] ?? '');
    if($v) set_setting('event_name',$v);
    j(['ok'=>true]);
    break;

  case 'check_in':
    $pid = intv($_POST['player_id'] ?? 0);
    $checked = intv($_POST['checked'] ?? 1);
    q("UPDATE players SET checked_in=?, updated_at=? WHERE id=?", [$checked, date('c'), $pid]);
    j(['ok'=>true]);
    break;

  case 'create_pods':
    $force = intv($_POST['force'] ?? 0);
    if($force==1){
      // Drop unchecked players
      q("DELETE FROM players WHERE checked_in=0");
    }
    $res = assign_pods();
    if($res['ok']){
      // compute total rounds now based on #checked-in
      set_setting('total_rounds', compute_total_rounds());
    }
    j($res);
    break;

  case 'create_r1':
    j(create_round1());
    break;

  case 'submit_result':
    $mid = intv($_POST['match_id'] ?? 0);
    $rid = intv($_POST['reporter_id'] ?? 0);
    $gy = intv($_POST['g_you'] ?? 0);
    $go = intv($_POST['g_opp'] ?? 0);
    $m = one("SELECT * FROM matches WHERE id=?", [$mid]);
    if(!$m) j(['ok'=>false,'error'=>'no match']);
    if($m['is_bye']) j(['ok'=>false,'error'=>'bye match']);
    $isP1 = ($m['p1_id']==$rid);
    $isP2 = ($m['p2_id']==$rid);
    if(!$isP1 && !$isP2) j(['ok'=>false,'error'=>'not your match']);
    if($isP1){
      q("UPDATE matches SET p1_game_wins=?, p2_game_wins=?, p1_reported=1, updated_at=? WHERE id=?", [$gy,$go,date('c'),$mid]);
    } else {
      q("UPDATE matches SET p1_game_wins=?, p2_game_wins=?, p2_reported=1, updated_at=? WHERE id=?", [$go,$gy,date('c'),$mid]);
    }
    // try confirm if both reported and consistent
    $m = one("SELECT * FROM matches WHERE id=?", [$mid]);
    if(intval($m['p1_reported'])==1 && intval($m['p2_reported'])==1 && $m['p1_game_wins']!==null && $m['p2_game_wins']!==null){
      // confirm only if numbers sum consistently
      $sum1 = intval($m['p1_game_wins']); $sum2 = intval($m['p2_game_wins']);
      // Accept any numbers (players may have drawn); Admin can always override
      q("UPDATE matches SET confirmed=1 WHERE id=?", [$mid]);
    }
    j(['ok'=>true]);
    break;

  case 'admin_edit_result':
    $mid = intv($_POST['match_id'] ?? 0);
    $p1 = intv($_POST['p1'] ?? 0);
    $p2 = intv($_POST['p2'] ?? 0);
    q("UPDATE matches SET p1_game_wins=?, p2_game_wins=?, p1_reported=1, p2_reported=1, confirmed=1, updated_at=? WHERE id=?", [$p1,$p2,date('c'),$mid]);
    j(['ok'=>true]);
    break;

  case 'drop_player':
    $pid = intv($_POST['player_id'] ?? 0);
    q("UPDATE players SET dropped=1, updated_at=? WHERE id=?", [date('c'),$pid]);
    j(['ok'=>true]);
    break;

  case 'next_round':
    $cur = intv(get_setting('current_round'));
    $status = get_setting('status');
    if($status=='top8'){
      j(advance_top8());
      break;
    }
    // ensure all matches confirmed
    $un = one("SELECT COUNT(*) AS c FROM matches WHERE round=? AND confirmed=0", [$cur]);
    if($un && intval($un['c'])>0){
      j(['ok'=>false,'error'=>'not all results confirmed']); break;
    }
    $total = intv(get_setting('total_rounds'));
    if($cur >= $total){
      set_setting('status','standings');
      j(['ok'=>true]);
    } else {
      $next = $cur + 1;
      j(create_round($next));
    }
    break;

  case 'finalize_standings':
    set_setting('status','standings');
    j(['ok'=>true]);
    break;

  case 'create_top8':
    j(create_top8());
    break;

  /** Debug helpers **/
  case 'debug_populate':
    $n = intv($_POST['n'] ?? 16);
    q("DELETE FROM players");
    q("DELETE FROM matches");
    set_setting('status','pre');
    set_setting('current_round','0');
    set_setting('total_rounds','0');
    $names = [];
    for($i=1;$i<=$n;$i++) $names[] = 'Spieler '.$i;
    foreach($names as $nm){
      q("INSERT INTO players (name,checked_in,active) VALUES (?,0,1)", [$nm]);
    }
    j(['ok'=>true,'n'=>$n]);
    break;

  case 'debug_checkin_remaining':
    q("UPDATE players SET checked_in=1 WHERE checked_in=0");
    j(['ok'=>true]);
    break;

  case 'debug_copy_results':
    $cur = intv(get_setting('current_round'));
    $rows = all("SELECT * FROM matches WHERE round=? AND confirmed=0", [$cur]);
    foreach($rows as $m){
      if($m['is_bye']){
        q("UPDATE matches SET confirmed=1, p1_game_wins=2, p2_game_wins=0 WHERE id=?", [$m['id']]);
        continue;
      }
      if(intval($m['p1_reported'])==1 && intval($m['p2_reported'])==0 && $m['p1_game_wins']!==null){
        q("UPDATE matches SET p2_game_wins=?, p2_reported=1, confirmed=1 WHERE id=?", [$m['p2_game_wins'],$m['id']]);
      } elseif(intval($m['p2_reported'])==1 && intval($m['p1_reported'])==0 && $m['p2_game_wins']!==null){
        q("UPDATE matches SET p1_game_wins=?, p1_reported=1, confirmed=1 WHERE id=?", [$m['p1_game_wins'],$m['id']]);
      }
    }
    j(['ok'=>true]);
    break;

  default:
    j(['ok'=>false,'error'=>'unknown action']);
}
