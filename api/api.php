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
    $players = all("SELECT id,name,checked_in,active,dropped,pod,pod_seat,top8_seat,no_phone FROM players WHERE active=1 ORDER BY id ASC");
    $pods=[]; $rows = all("SELECT DISTINCT pod FROM players WHERE pod IS NOT NULL ORDER BY pod ASC");
    foreach($rows as $r){
      $seats = all("SELECT id,name,pod,pod_seat FROM players WHERE pod=? ORDER BY pod_seat ASC", [$r['pod']]);
      $pods[] = ['pod'=>intval($r['pod']),'seats'=>array_map(function($s){ return ['id'=>intval($s['id']),'name'=>$s['name'],'pod'=>intval($s['pod']),'seat'=>intval($s['pod_seat'])]; }, $seats)];
    }
    $cur = intval(get_setting('current_round'));
    $matches = $cur>0 ? round_matches($cur) : [];
    $stand = [];
    if(in_array(get_setting('status'), ['standings','round','top8','top8_draft','complete'])){
      $stand = standings(null);
    }
    
    // For top 8 statuses, also get Swiss standings (pre-top 8) for non-top 8 players
    $swiss_stand = [];
    if(in_array(get_setting('status'), ['top8_draft', 'top8'])){
      // Get the last round of Swiss before top 8 (usually round 5 or 6)
      $total_rounds = intval(get_setting('total_rounds'));
      $swiss_stand = standings($total_rounds);
    }

    $payload = [
      'year'=> intval(date('Y')),
      'settings'=> $settings,
      'players'=> array_map(function($p){ return [
        'id'=>intval($p['id']),'name'=>$p['name'],'checked_in'=>intval($p['checked_in'])==1,
        'active'=>intval($p['active'])==1,'dropped'=>intval($p['dropped'])==1,
        'pod'=>$p['pod']!==null? intval($p['pod']) : null,
        'pod_seat'=>$p['pod_seat']!==null? intval($p['pod_seat']) : null,
        'top8_seat'=>$p['top8_seat']!==null? intval($p['top8_seat']) : null,
        'no_phone'=>intval($p['no_phone'])==1
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
          'p1_no_phone'=>intval($m['p1_no_phone'])==1,
          'p2_no_phone'=>intval($m['p2_no_phone'])==1,
          'p1_game_wins'=>$m['p1_game_wins']!==null?intval($m['p1_game_wins']):null,
          'p2_game_wins'=>$m['p2_game_wins']!==null?intval($m['p2_game_wins']):null,
          'draws'=>$m['draws']!==null?intval($m['draws']):null,
          'confirmed'=>intval($m['confirmed'])==1,
          'is_bye'=>intval($m['is_bye'])==1,
          'pod_round'=>intval($m['pod_round'])==1,
          'top8_phase'=>$m['top8_phase']
        ];
      }, $matches),
      'standings'=> $stand,
      'swiss_standings'=> $swiss_stand
    ];

    if($me){
      // Determine which standings this player should see during top 8 phases
      if(in_array(get_setting('status'), ['top8_draft', 'top8'])){
        $myPlayer = null;
        foreach($players as $p){
          if(intval($p['id']) == $me){
            $myPlayer = $p;
            break;
          }
        }
        
        // If player has a top8_seat, they are in top 8 and should see current standings
        // If not, they should see Swiss standings (final pre-top 8 standings)
        if($myPlayer && $myPlayer['top8_seat'] === null){
          $payload['standings'] = $swiss_stand;
        }
      }
      
      $payload['my_match'] = null;
      foreach($matches as $m){
        if(intval($m['p1_id'])==$me || intval($m['p2_id'])==$me){
          $payload['my_match'] = [
            'id'=>intval($m['id']),'table_no'=>$m['table_no']!==null?intval($m['table_no']):null,
            'p1_id'=>$m['p1_id']!==null?intval($m['p1_id']):null,'p2_id'=>$m['p2_id']!==null?intval($m['p2_id']):null,
            'p1_name'=>$m['p1_name'],'p2_name'=>$m['p2_name'],
            'confirmed'=>intval($m['confirmed'])==1,
            'p1_reported'=>intval($m['p1_reported'])==1,
            'p2_reported'=>intval($m['p2_reported'])==1,
            'is_bye'=>intval($m['is_bye'])==1
          ];
          
          // Determine match state for this player
          $isP1 = (intval($m['p1_id'])==$me);
          $isP2 = (intval($m['p2_id'])==$me);
          $iReported = ($isP1 && intval($m['p1_reported'])==1) || ($isP2 && intval($m['p2_reported'])==1);
          $oppReported = ($isP1 && intval($m['p2_reported'])==1) || ($isP2 && intval($m['p1_reported'])==1);
          
          $payload['match_state'] = [
            'i_reported' => $iReported,
            'opponent_reported' => $oppReported,
            'confirmed' => intval($m['confirmed'])==1,
            'can_confirm' => $oppReported && !intval($m['confirmed']),
            'can_change' => $iReported && !intval($m['confirmed'])
          ];
          
          // If opponent reported, show their result (swapped for this player's perspective)
          $oppReportedResult = null;
          if($oppReported && $m['p1_game_wins']!==null && $m['p2_game_wins']!==null){
            if($isP1){
              // I'm P1, opponent is P2
              // Show result from my perspective (P1) in input boxes
              // Show result from my perspective in message too
              $oppReportedResult = [
                'you'=>intval($m['p1_game_wins']), 
                'opp'=>intval($m['p2_game_wins']),
                'draws'=>intval($m['draws'] ?? 0),
                'opponent_name'=>isset($m['p2_name']) ? $m['p2_name'] : 'Unknown',
                'opponent_original'=>intval($m['p1_game_wins']) . '-' . intval($m['p2_game_wins']) . '-' . intval($m['draws'] ?? 0)
              ];
            } else {
              // I'm P2, opponent is P1
              // Show result from my perspective (P2) in input boxes
              // Show result from my perspective in message too
              $oppReportedResult = [
                'you'=>intval($m['p2_game_wins']), 
                'opp'=>intval($m['p1_game_wins']),
                'draws'=>intval($m['draws'] ?? 0),
                'opponent_name'=>isset($m['p1_name']) ? $m['p1_name'] : 'Unknown',
                'opponent_original'=>intval($m['p2_game_wins']) . '-' . intval($m['p1_game_wins']) . '-' . intval($m['draws'] ?? 0)
              ];
            }
          }
          $payload['opp_report'] = $oppReportedResult;
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

  case 'get_zugesagt':
    // Return zugesagt.json content
    $zugesagtPath = __DIR__.'/../zugesagt.json';
    if(file_exists($zugesagtPath)){
      $zugesagt = json_decode(file_get_contents($zugesagtPath), true);
      if(is_array($zugesagt)){
        j(['ok'=>true, 'players'=>$zugesagt]);
      } else {
        j(['ok'=>false, 'error'=>'Invalid zugesagt.json format']);
      }
    } else {
      j(['ok'=>false, 'error'=>'zugesagt.json not found']);
    }
    break;

  case 'new_event':
    // Clear all data and reset to preparation stage
    q("DELETE FROM players");
    q("DELETE FROM matches");
    set_setting('status','pre');
    set_setting('current_round','0');
    set_setting('total_rounds','0');
    set_setting('event_name','Hubris Cup '.date('Y'));
    j(['ok'=>true]);
    break;

  case 'check_in':
    $pid = intv($_POST['player_id'] ?? 0);
    $checked = intv($_POST['checked'] ?? 1);
    q("UPDATE players SET checked_in=?, updated_at=? WHERE id=?", [$checked, date('c'), $pid]);
    j(['ok'=>true]);
    break;

  case 'admin_checkin_no_phone':
    $pid = intv($_POST['player_id'] ?? 0);
    q("UPDATE players SET checked_in=1, no_phone=1, updated_at=? WHERE id=?", [date('c'), $pid]);
    j(['ok'=>true]);
    break;

  case 'create_pods':
    $force = intv($_POST['force'] ?? 0);
    if($force==1){ q("DELETE FROM players WHERE checked_in=0"); }
    $res = assign_pods();
    if($res['ok']){ set_setting('total_rounds', compute_total_rounds()); }
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
    $gd = intv($_POST['g_draws'] ?? 0);
    $m = one("SELECT * FROM matches WHERE id=?", [$mid]);
    if(!$m) j(['ok'=>false,'error'=>'no match']);
    if($m['is_bye']) j(['ok'=>false,'error'=>'bye match']);
    $isP1 = ($m['p1_id']==$rid);
    $isP2 = ($m['p2_id']==$rid);
    if(!$isP1 && !$isP2) j(['ok'=>false,'error'=>'not your match']);
    
    // Check if result is already confirmed
    if(intval($m['confirmed'])==1) j(['ok'=>false,'error'=>'result already confirmed']);
    
    // If this is a change (opponent already reported), reset the other player's report
    if($isP1 && intval($m['p2_reported'])==1){
      q("UPDATE matches SET p1_game_wins=?, p2_game_wins=?, draws=?, p1_reported=1, p2_reported=0, confirmed=0, updated_at=? WHERE id=?", [$gy,$go,$gd,date('c'),$mid]);
    } elseif($isP2 && intval($m['p1_reported'])==1){
      q("UPDATE matches SET p1_game_wins=?, p2_game_wins=?, draws=?, p1_reported=0, p2_reported=1, confirmed=0, updated_at=? WHERE id=?", [$go,$gy,$gd,date('c'),$mid]);
    } else {
      // First report
      if($isP1){
        q("UPDATE matches SET p1_game_wins=?, p2_game_wins=?, draws=?, p1_reported=1, updated_at=? WHERE id=?", [$gy,$go,$gd,date('c'),$mid]);
      } else {
        q("UPDATE matches SET p1_game_wins=?, p2_game_wins=?, draws=?, p2_reported=1, updated_at=? WHERE id=?", [$go,$gy,$gd,date('c'),$mid]);
      }
    }
    j(['ok'=>true]);
    break;

  case 'confirm_result':
    $mid = intv($_POST['match_id'] ?? 0);
    $cid = intv($_POST['confirmer_id'] ?? 0);
    $m = one("SELECT * FROM matches WHERE id=?", [$mid]);
    if(!$m) j(['ok'=>false,'error'=>'no match']);
    if($m['is_bye']) j(['ok'=>false,'error'=>'bye match']);
    $isP1 = ($m['p1_id']==$cid);
    $isP2 = ($m['p2_id']==$cid);
    if(!$isP1 && !$isP2) j(['ok'=>false,'error'=>'not your match']);
    
    // Check if result is already confirmed
    if(intval($m['confirmed'])==1) j(['ok'=>false,'error'=>'result already confirmed']);
    
    // Check if opponent has reported
    $opponentReported = ($isP1 && intval($m['p2_reported'])==1) || ($isP2 && intval($m['p1_reported'])==1);
    if(!$opponentReported) j(['ok'=>false,'error'=>'opponent has not reported yet']);
    
    // Confirm the result
    q("UPDATE matches SET confirmed=1, updated_at=? WHERE id=?", [date('c'),$mid]);
    j(['ok'=>true]);
    break;

  case 'admin_edit_result':
    $mid = intv($_POST['match_id'] ?? 0);
    $p1 = intv($_POST['p1'] ?? 0);
    $p2 = intv($_POST['p2'] ?? 0);
    $draws = intv($_POST['draws'] ?? 0);
    q("UPDATE matches SET p1_game_wins=?, p2_game_wins=?, draws=?, p1_reported=1, p2_reported=1, confirmed=1, updated_at=? WHERE id=?", [$p1,$p2,$draws,date('c'),$mid]);
    j(['ok'=>true]);
    break;

  case 'admin_confirm_result':
    $mid = intv($_POST['match_id'] ?? 0);
    $confirmed = intv($_POST['confirmed'] ?? 0);
    $m = one("SELECT * FROM matches WHERE id=?", [$mid]);
    if(!$m) j(['ok'=>false,'error'=>'no match']);
    if($m['is_bye']) j(['ok'=>false,'error'=>'bye match']);
    
    // Admin can confirm/unconfirm any result that has been entered
    if($m['p1_game_wins']===null || $m['p2_game_wins']===null) {
      j(['ok'=>false,'error'=>'no result to confirm']);
    }
    
    if($confirmed) {
      // When confirming, ensure both players are marked as reported (like player confirmation flow)
      q("UPDATE matches SET confirmed=1, p1_reported=1, p2_reported=1, updated_at=? WHERE id=?", [date('c'),$mid]);
    } else {
      // When unconfirming, just set confirmed=0
      q("UPDATE matches SET confirmed=0, updated_at=? WHERE id=?", [date('c'),$mid]);
    }
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
    if($status=='top8'){ j(advance_top8()); break; }
    $un = one("SELECT COUNT(*) AS c FROM matches WHERE round=? AND confirmed=0", [$cur]);
    if($un && intval($un['c'])>0){ j(['ok'=>false,'error'=>'not all results confirmed']); break; }
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

  case 'start_top8':
    set_setting('status','top8');
    j(['ok'=>true]);
    break;

  /** Debug helpers **/
  case 'debug_populate':
    $n = intv($_POST['n'] ?? 16);
    q("DELETE FROM players"); q("DELETE FROM matches");
    set_setting('status','pre'); set_setting('current_round','0'); set_setting('total_rounds','0');
    for($i=1;$i<=$n;$i++){ q("INSERT INTO players (name,checked_in,active) VALUES (?,0,1)", ['Spieler '.$i]); }
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
      if(intval($m['is_bye'])==1){
        q("UPDATE matches SET confirmed=1, p1_game_wins=2, p2_game_wins=0 WHERE id=?", [$m['id']]);
        continue;
      }
      // Copy results from the reported side to auto-confirm
      if(intval($m['p1_reported'])==1 && intval($m['p2_reported'])==0 && $m['p1_game_wins']!==null){
        q("UPDATE matches SET p2_game_wins=?, p2_reported=1, confirmed=1 WHERE id=?", [$m['p2_game_wins'],$m['id']]);
      } elseif(intval($m['p2_reported'])==1 && intval($m['p1_reported'])==0 && $m['p2_game_wins']!==null){
        q("UPDATE matches SET p1_game_wins=?, p1_reported=1, confirmed=1 WHERE id=?", [$m['p1_game_wins'],$m['id']]);
      }
    }
    j(['ok'=>true]);
    break;

  case 'debug_randomize_results':
    $cur = intv(get_setting('current_round'));
    $rows = all("SELECT * FROM matches WHERE round=? AND confirmed=0", [$cur]);
    foreach($rows as $m){
      if(intval($m['is_bye'])==1){
        q("UPDATE matches SET confirmed=1, p1_game_wins=2, p2_game_wins=0 WHERE id=?", [$m['id']]);
        continue;
      }
      $opts = [[2,0],[2,1],[1,2],[0,2]]; $r = $opts[array_rand($opts)];
      q("UPDATE matches SET p1_game_wins=?, p2_game_wins=?, p1_reported=1, p2_reported=1, confirmed=1 WHERE id=?", [$r[0],$r[1],$m['id']]);
    }
    j(['ok'=>true]);
    break;

  default:
    j(['ok'=>false,'error'=>'unknown action']);
}
