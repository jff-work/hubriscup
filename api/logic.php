<?php
// api/logic.php
require_once __DIR__.'/db.php';

/** Utility **/
function player_list($only_active=false){
  $sql = "SELECT * FROM players";
  if($only_active) $sql .= " WHERE active=1";
  $sql .= " ORDER BY id ASC";
  return all($sql);
}
function checked_in_players(){
  return all("SELECT * FROM players WHERE checked_in=1 AND active=1 ORDER BY id ASC");
}
function clear_pods(){
  q("UPDATE players SET pod=NULL, pod_seat=NULL");
}

/** Pod building: prefer 8s, then evens, then odds. Allow 9/10 when beneficial.
 * Returns array of pod sizes, maximizing 8s, then evens, then minimizing odds.
 * Examples: 25 → 8/8/9; 26 → 8/8/10; 27 → 8/7/6/6; 28 → 8/8/6/6
 */
function compute_pod_sizes($n){
  // Try with all allowed sizes (8,7,6,9,10)
  $allowed = [8,7,6,9,10];
  $res = search_sizes($n, $allowed);
  
  if(!$res) return null;
  
  // Sort solutions: maximize 8s, then evens, then minimize odds, then minimize total pods
  usort($res, function($a,$b){
    // Count 8s (highest priority)
    $eightsA = count(array_filter($a, fn($x)=>$x==8));
    $eightsB = count(array_filter($b, fn($x)=>$x==8));
    if($eightsA != $eightsB) return $eightsB - $eightsA;
    
    // Count evens (second priority)
    $evensA = count(array_filter($a, fn($x)=>$x%2==0));
    $evensB = count(array_filter($b, fn($x)=>$x%2==0));
    if($evensA != $evensB) return $evensB - $evensA;
    
    // Minimize odds (third priority)
    $oddsA = count(array_filter($a, fn($x)=>$x%2==1));
    $oddsB = count(array_filter($b, fn($x)=>$x%2==1));
    if($oddsA != $oddsB) return $oddsA - $oddsB;
    
    // Finally, minimize total pods
    return count($a) - count($b);
  });
  
  return $res[0];
}

function search_sizes($n, $allowed){
  $sols = [];
  $stack = [ [[], 0] ];
  while($stack){
    [$sizes, $sum] = array_pop($stack);
    if($sum == $n){ $sols[] = $sizes; continue; }
    if($sum > $n) continue;
    foreach($allowed as $s){
      $ns = $sizes; $ns[] = $s;
      $stack[] = [$ns, $sum + $s];
    }
  }
  $norm = [];
  foreach($sols as $s){ rsort($s); $norm[] = $s; }
  $uniq = []; $hashes = [];
  foreach($norm as $s){ $h = implode('-', $s); if(!isset($hashes[$h])){ $hashes[$h]=1; $uniq[] = $s; } }
  return $uniq;
}

function assign_pods(){
  // Auto-save current state before creating pods
  save_current_state("Before Pods Assignment", "Auto-saved before assigning pods");
  
  $players = checked_in_players();
  $n = count($players);
  if($n < 6) return ['ok'=>false,'error'=>'Need at least 6 checked-in players'];
  $sizes = compute_pod_sizes($n);
  if(!$sizes) return ['ok'=>false,'error'=>'Could not compute pod sizes'];
  shuffle($players);
  clear_pods();
  $podNo = 1; $idx = 0;
  foreach($sizes as $size){
    for($seat=1; $seat<=$size; $seat++){
      if(!isset($players[$idx])) break;
      $p = $players[$idx];
      q("UPDATE players SET pod=?, pod_seat=? WHERE id=?", [$podNo, $seat, $p['id']]);
      $idx++;
    }
    $podNo++;
  }
  set_setting('status','pods');
  return ['ok'=>true,'sizes'=>$sizes];
}

/** Cross pair mapping inside a pod */
function cross_pairs_for_pod($podPlayers){
  usort($podPlayers, fn($a,$b)=> $a['pod_seat'] <=> $b['pod_seat']);
  $N = count($podPlayers);
  $pairs = [];
  $used = array_fill(1, $N, false);
  $byeSeat = ($N % 2 == 1) ? $N : null;
  $half = intdiv($N,2);
  for($i=1;$i<=$N;$i++){
    if($byeSeat && $i==$byeSeat) continue;
    if($used[$i]) continue;
    $j = $i + $half; if($j > $N) $j -= $N;
    if($byeSeat && $j==$byeSeat){ $j = ($j % $N) + 1; }
    if($used[$j]){ for($k=1;$k<=$N;$k++){ if((!$byeSeat || $k!=$byeSeat) && !$used[$k] && $k!=$i){ $j=$k; break; } } }
    $pairs[] = [$podPlayers[$i-1], $podPlayers[$j-1]];
    $used[$i]=true; $used[$j]=true;
  }
  return [$pairs, $byeSeat ? $podPlayers[$byeSeat-1] : null];
}

function round_matches($round){
  return all("SELECT m.*, p1.name AS p1_name, p2.name AS p2_name, p1.no_phone AS p1_no_phone, p2.no_phone AS p2_no_phone
    FROM matches m
    LEFT JOIN players p1 ON p1.id=m.p1_id
    LEFT JOIN players p2 ON p2.id=m.p2_id
    WHERE m.round=?", [$round]);
}

function player_points($player_id, $upToRound=null){
  $sql = "SELECT * FROM matches WHERE (p1_id=? OR p2_id=?) AND confirmed=1";
  $params = [$player_id,$player_id];
  if($upToRound!==null){ $sql .= " AND round<=?"; $params[]=$upToRound; }
  $rows = all($sql,$params);
  $mp=0;
  foreach($rows as $m){
    if($m['is_bye']){ $mp += 3; continue; }
    if($m['p1_id']==$player_id){ $g1=$m['p1_game_wins']; $g2=$m['p2_game_wins']; }
    else { $g2=$m['p1_game_wins']; $g1=$m['p2_game_wins']; }
    if($g1===null || $g2===null) continue;
    if($g1>$g2) $mp+=3; elseif($g1==$g2) $mp+=1;
  }
  return $mp;
}

function all_points($upToRound=null){
  $players = all("SELECT * FROM players WHERE active=1 AND dropped=0");
  $pts = [];
  foreach($players as $p){ $pts[$p['id']] = player_points($p['id'], $upToRound); }
  return $pts;
}

function swiss_pairings($player_ids, $round, $scope='global'){
  $pts = all_points($round-1);
  $players = [];
  foreach($player_ids as $pid){ $players[] = ['id'=>$pid, 'mp'=> ($pts[$pid] ?? 0)]; }
  usort($players, function($a,$b){ return $b['mp'] <=> $a['mp']; });

  $br = [];
  foreach($players as $pl){ $br[$pl['mp']] = $br[$pl['mp']] ?? []; $br[$pl['mp']][] = $pl['id']; }
  foreach($br as &$arr){ shuffle($arr); } unset($arr);

  $pairs = []; $waiting = null;
  foreach($br as $mp=>$arr){
    if($waiting!==null){ array_unshift($arr, $waiting); $waiting=null; }
    while(count($arr)>=2){
      $a = array_shift($arr); $b = array_shift($arr);
      if(rematch($a,$b,$round)){
        $swapped=false;
        for($i=0;$i<count($arr);$i++){
          if(!rematch($a,$arr[$i],$round)){ $tmp=$arr[$i]; $arr[$i]=$b; $b=$tmp; $swapped=true; break; }
        }
      }
      $pairs[] = [$a,$b];
    }
    if(count($arr)==1){ $waiting = array_shift($arr); }
  }
  if($waiting!==null){ $pairs[] = [$waiting, null]; }
  return $pairs;
}

function rematch($a,$b,$round){
  $m = one("SELECT 1 FROM matches WHERE confirmed=1 AND round < ? AND ((p1_id=? AND p2_id=?) OR (p1_id=? AND p2_id=?))",
    [$round, $a,$b, $b,$a]);
  return $m ? true : false;
}

/** Round creation **/
function create_round1(){
  // Auto-save current state before creating first round
  save_current_state("Before Round 1", "Auto-saved before creating first round");
  
  $pods = all("SELECT DISTINCT pod FROM players WHERE pod IS NOT NULL ORDER BY pod ASC");
  if(!$pods) return ['ok'=>false,'error'=>'No pods'];
  q("DELETE FROM matches WHERE round=1");
  $table = 1;
  foreach($pods as $row){
    $podNo = $row['pod'];
    $podPlayers = all("SELECT id,name,pod,pod_seat FROM players WHERE pod=? ORDER BY pod_seat ASC", [$podNo]);
    [$pairs, $bye] = cross_pairs_for_pod($podPlayers);
    foreach($pairs as $pp){
      $p1=$pp[0]['id']; $p2=$pp[1]['id'];
      q("INSERT INTO matches (round,table_no,p1_id,p2_id,pod_round,is_bye) VALUES (1,?,?,?,?,0)", [$table,$p1,$p2,1]);
      $table++;
    }
    if($bye){
      q("INSERT INTO matches (round,table_no,p1_id,p2_id,pod_round,is_bye,confirmed,p1_game_wins,p2_game_wins) VALUES (1,?,?,NULL,1,1,1,2,0)", [$table,$bye['id']]);
      $table++;
    }
  }
  set_setting('current_round', '1');
  set_setting('status','round');
  return ['ok'=>true];
}

function create_round($round){
  // Auto-save current state before creating new round
  save_current_state("Before Round $round", "Auto-saved before creating round $round");
  
  q("DELETE FROM matches WHERE round=?", [$round]);
  $players = all("SELECT * FROM players WHERE active=1 AND dropped=0");
  $table = 1;
  
  // Rounds 1-3: within pods; Rounds 4+: global Swiss
  if($round <= 3){
    $pods = all("SELECT DISTINCT pod FROM players WHERE pod IS NOT NULL ORDER BY pod ASC");
    foreach($pods as $row){
      $podNo = $row['pod'];
      $ids = array_map(fn($r)=>$r['id'], all("SELECT id FROM players WHERE active=1 AND dropped=0 AND pod=?", [$podNo]));
      if(!$ids) continue;
      $pairs = swiss_pairings($ids, $round, 'pod');
      foreach($pairs as $pp){
        $p1 = $pp[0]; $p2 = $pp[1];
        if($p2===null){
          q("INSERT INTO matches (round,table_no,p1_id,p2_id,pod_round,is_bye,confirmed,p1_game_wins,p2_game_wins) VALUES (?,?,?,NULL,1,1,1,2,0)", [$round,$table,$p1]);
        } else {
          q("INSERT INTO matches (round,table_no,p1_id,p2_id,pod_round) VALUES (?,?,?, ?,1)", [$round,$table,$p1,$p2]);
        }
        $table++;
      }
    }
  } else {
    // Global Swiss for rounds 4+
    $ids = array_map(fn($r)=>$r['id'], $players);
    $pairs = swiss_pairings($ids, $round, 'global');
    foreach($pairs as $pp){
      $p1=$pp[0]; $p2=$pp[1];
      if($p2===null){
        q("INSERT INTO matches (round,table_no,p1_id,p2_id,pod_round,is_bye,confirmed,p1_game_wins,p2_game_wins) VALUES (?,?,?,NULL,0,1,1,2,0)", [$round,$table,$p1]);
      } else {
        q("INSERT INTO matches (round,table_no,p1_id,p2_id,pod_round) VALUES (?,?,?, ?,0)", [$round,$table,$p1,$p2]);
      }
      $table++;
    }
  }
  
  set_setting('current_round', strval($round));
  set_setting('status','round');
  return ['ok'=>true];
}

/** Standings (MTR-style tiebreakers: MP → OMW% → GWP% → OGW% with 33% floors) */
function standings($upToRound=null){
  $players = all("SELECT id,name FROM players WHERE active=1");
  $mp = []; $gwp=[]; $omw=[]; $ogw=[]; $pmw=[]; $oppList=[];
  
  // Calculate match points and game win percentages
  foreach($players as $p){
    $pid=$p['id']; $mp[$pid]=0; $oppList[$pid]=[];
    $rows = all("SELECT * FROM matches WHERE confirmed=1 AND (p1_id=? OR p2_id=?)".($upToRound?" AND round<=".intval($upToRound):""), [$pid,$pid]);
    $gamesPlayed=0; $gamePts=0;
    
    foreach($rows as $m){
      if($m['is_bye']){ 
        $mp[$pid]+=3; 
        continue; 
      }
      
      $g1 = ($m['p1_id']==$pid) ? intval($m['p1_game_wins']) : intval($m['p2_game_wins']);
      $g2 = ($m['p1_id']==$pid) ? intval($m['p2_game_wins']) : intval($m['p1_game_wins']);
      $draws = intval($m['draws'] ?? 0);
      
      // Match points: 3 for win, 1 for draw, 0 for loss
      if($g1>$g2) $mp[$pid]+=3; 
      elseif($g1==$g2) $mp[$pid]+=1;
      
      // Game win percentage calculation (including draws)
      $gamePts += (3*$g1); // 3 points per game won
      $gamesPlayed += ($g1 + $g2 + $draws);
      
      // Track opponents (exclude byes from OMW/OGW)
      $opp = ($m['p1_id']==$pid) ? $m['p2_id'] : $m['p1_id'];
      if($opp) $oppList[$pid][] = $opp;
    }
    
    // Match win percentage with 33% floor
    $matchesPlayed = count(array_filter($rows, fn($m)=> !$m['is_bye']));
    $pmw[$pid] = $matchesPlayed > 0 ? max(0.33, $mp[$pid] / (3 * $matchesPlayed)) : 0.33;
    
    // Game win percentage with 33% floor
    $gwp[$pid] = $gamesPlayed > 0 ? max(0.33, $gamePts / (3 * $gamesPlayed)) : 0.33;
  }
  
  // Calculate opponent match win % and opponent game win %
  foreach($players as $p){
    $pid=$p['id']; $opps = $oppList[$pid];
    if(!$opps){ 
      $omw[$pid]=0.33; 
      $ogw[$pid]=0.33; 
      continue; 
    }
    
    $sumM=0; $sumG=0; $n=0;
    foreach($opps as $oid){
      $sumM += max(0.33, $pmw[$oid] ?? 0.33);
      $sumG += max(0.33, $gwp[$oid] ?? 0.33);
      $n++;
    }
    $omw[$pid] = $n ? ($sumM/$n) : 0.33;
    $ogw[$pid] = $n ? ($sumG/$n) : 0.33;
  }
  
  // Sort by tiebreakers: MP → OMW% → GWP% → OGW%
  usort($players, function($a,$b) use ($mp,$omw,$gwp,$ogw){
    if($mp[$a['id']] != $mp[$b['id']]) return $mp[$b['id']] <=> $mp[$a['id']];
    if(abs($omw[$a['id']]-$omw[$b['id']])>1e-9) return ($omw[$b['id']] <=> $omw[$a['id']]);
    if(abs($gwp[$a['id']]-$gwp[$b['id']])>1e-9) return ($gwp[$b['id']] <=> $gwp[$a['id']]);
    if(abs($ogw[$a['id']]-$ogw[$b['id']])>1e-9) return ($ogw[$b['id']] <=> $ogw[$a['id']]);
    return 0;
  });
  
  $out=[]; 
  foreach($players as $p){ 
    $pid=$p['id']; 
    $out[] = [
      'id'=>$pid,
      'name'=>$p['name'],
      'mp'=>$mp[$pid],
      'omw'=>round($omw[$pid],6),
      'gwp'=>round($gwp[$pid],6),
      'ogw'=>round($ogw[$pid],6)
    ]; 
  }
  return $out;
}

function compute_total_rounds(){
  $n = count(checked_in_players());
  return ($n<=24) ? 5 : 6;
}

function create_top8(){
  // Auto-save current state before creating Top 8
  save_current_state("Before Top 8", "Auto-saved before creating Top 8 bracket");
  
  $stand = standings(null);
  $top = array_slice($stand,0,8);
  if(count($top)<8) return ['ok'=>false,'error'=>'Need 8 players'];
  
  // Random seating for Top 8 draft
  $seats = range(1,8); 
  shuffle($seats);
  foreach($top as $i=>$row){ 
    q("UPDATE players SET top8_seat=? WHERE id=?", [$seats[$i], $row['id']]); 
  }
  
  // Clear any existing Top 8 matches
  q("DELETE FROM matches WHERE round>=100");
  
  // Create cross-pairings for Quarterfinals: 1-5, 2-6, 3-7, 4-8
  $crossMap = [1=>5, 2=>6, 3=>7, 4=>8];
  $table=1;
  foreach($crossMap as $a=>$b){
    $p1 = one("SELECT * FROM players WHERE top8_seat=?", [$a]);
    $p2 = one("SELECT * FROM players WHERE top8_seat=?", [$b]);
    if($p1 && $p2){
      q("INSERT INTO matches (round, table_no, p1_id, p2_id, top8_phase) VALUES (100, ?, ?, ?, 'QF')", [$table,$p1['id'],$p2['id']]);
      $table++;
    }
  }
  
  set_setting('status','top8_draft');
  set_setting('current_round','100');
  return ['ok'=>true];
}

function advance_top8(){
  $cur = intval(get_setting('current_round'));
  if($cur==100){
    $qf = all("SELECT * FROM matches WHERE round=100 AND confirmed=1 ORDER BY table_no ASC");
    if(count($qf)<4) return ['ok'=>false,'error'=>'Not all QFs confirmed'];
    $winners = [];
    foreach($qf as $m){ $winners[] = winner_of($m); }
    q("DELETE FROM matches WHERE round=101");
    q("INSERT INTO matches (round, table_no, p1_id, p2_id, top8_phase) VALUES (101,1,?,?,'SF')", [$winners[0], $winners[1]]);
    q("INSERT INTO matches (round, table_no, p1_id, p2_id, top8_phase) VALUES (101,2,?,?,'SF')", [$winners[2], $winners[3]]);
    set_setting('current_round','101');
  } elseif($cur==101){
    $sf = all("SELECT * FROM matches WHERE round=101 AND confirmed=1 ORDER BY table_no ASC");
    if(count($sf)<2) return ['ok'=>false,'error'=>'Not all SF confirmed'];
    $winners=[]; foreach($sf as $m){ $winners[] = winner_of($m); }
    q("DELETE FROM matches WHERE round=102");
    q("INSERT INTO matches (round, table_no, p1_id, p2_id, top8_phase) VALUES (102,1,?,?,'F')", [$winners[0], $winners[1]]);
    set_setting('current_round','102');
  } elseif($cur==102){
    $f = one("SELECT * FROM matches WHERE round=102 AND confirmed=1");
    if(!$f) return ['ok'=>false,'error'=>'Final not confirmed'];
    set_setting('status','complete');
  }
  return ['ok'=>true];
}

function winner_of($m){
  if($m['is_bye']) return $m['p1_id'];
  return ($m['p1_game_wins'] > $m['p2_game_wins']) ? $m['p1_id'] :
         (($m['p2_game_wins'] > $m['p1_game_wins']) ? $m['p2_id'] : $m['p1_id']);
}

/** Event State Management **/
function save_current_state($name = null, $description = null){
  // Generate automatic name if not provided
  if(!$name){
    $status = get_setting('status');
    $round = get_setting('current_round');
    $timestamp = date('Y-m-d H:i:s');
    
    if($status === 'pre') $name = "Pre-tournament - $timestamp";
    elseif($status === 'checkin') $name = "Check-in - $timestamp";
    elseif($status === 'pods') $name = "Pods - $timestamp";
    elseif($status === 'round') $name = "Round $round - $timestamp";
    elseif($status === 'top8_draft') $name = "Top 8 Draft - $timestamp";
    elseif($status === 'top8') $name = "Top 8 Round $round - $timestamp";
    elseif($status === 'standings') $name = "Final Standings - $timestamp";
    elseif($status === 'complete') $name = "Tournament Complete - $timestamp";
    else $name = "State - $timestamp";
  }
  
  // Create state data object
  $state = [
    'settings' => all("SELECT * FROM settings"),
    'players' => all("SELECT * FROM players"),
    'matches' => all("SELECT * FROM matches"),
    'saved_at' => date('c')
  ];
  
  // Save to database
  q("INSERT INTO saved_states (name, description, state_data, created_at) VALUES (?, ?, ?, ?)", 
    [$name, $description, json_encode($state), date('c')]);
  
  return ['ok' => true, 'name' => $name];
}

function load_saved_state($state_id){
  $saved = one("SELECT * FROM saved_states WHERE id=?", [$state_id]);
  if(!$saved) return ['ok' => false, 'error' => 'State not found'];
  
  $state = json_decode($saved['state_data'], true);
  if(!$state) return ['ok' => false, 'error' => 'Invalid state data'];
  
  // Clear current data
  q("DELETE FROM players");
  q("DELETE FROM matches");
  q("DELETE FROM settings");
  
  // Restore settings
  foreach($state['settings'] as $setting){
    q("INSERT INTO settings (key, value) VALUES (?, ?)", [$setting['key'], $setting['value']]);
  }
  
  // Restore players
  foreach($state['players'] as $player){
    $columns = array_keys($player);
    $placeholders = str_repeat('?,', count($columns) - 1) . '?';
    $sql = "INSERT INTO players (" . implode(',', $columns) . ") VALUES ($placeholders)";
    q($sql, array_values($player));
  }
  
  // Restore matches
  foreach($state['matches'] as $match){
    $columns = array_keys($match);
    $placeholders = str_repeat('?,', count($columns) - 1) . '?';
    $sql = "INSERT INTO matches (" . implode(',', $columns) . ") VALUES ($placeholders)";
    q($sql, array_values($match));
  }
  
  return ['ok' => true, 'loaded_state' => $saved['name']];
}

function get_saved_states(){
  return all("SELECT id, name, description, created_at FROM saved_states ORDER BY created_at DESC");
}

function delete_saved_state($state_id){
  $affected = q("DELETE FROM saved_states WHERE id=?", [$state_id])->rowCount();
  return ['ok' => $affected > 0, 'deleted' => $affected > 0];
}
