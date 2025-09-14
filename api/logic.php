<?php
// api/logic.php
require_once __DIR__.'/db.php';

/** Utility **/
function shuffle_assoc(&$arr){
  $keys = array_keys($arr);
  shuffle($keys);
  $new = [];
  foreach($keys as $k) $new[$k] = $arr[$k];
  $arr = $new;
}
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

/** Pod building: prefer 8s, allow 7/6; if impossible (e.g., 17), allow exactly one 9.
 * Returns array of pod sizes, minimizing number of damaged pods (<8 or >8).
 */
function compute_pod_sizes($n){
  // Search combinations of sizes in allowed set
  $best = null;
  $allowed = [8,7,6];
  // First try without 9
  $res = search_sizes($n, $allowed);
  if(!$res){
    // allow a single 9 then others 8/7/6
    $allowed2 = [9,8,7,6];
    $res2 = search_sizes($n, $allowed2);
    $res = $res2;
  }
  if(!$res) return null;
  // Choose the combo minimizing number of pods not equal to 8, then minimize number of pods overall
  usort($res, function($a,$b){
    $damA = count(array_filter($a, fn($x)=>$x!=8));
    $damB = count(array_filter($b, fn($x)=>$x!=8));
    if($damA != $damB) return $damA - $damB;
    return count($a) - count($b);
  });
  return $res[0];
}

function search_sizes($n, $allowed){
  $sols = [];
  // simple DFS with pruning (n<=32)
  $stack = [ [[], 0] ]; // (sizes, sum)
  while($stack){
    [$sizes, $sum] = array_pop($stack);
    if($sum == $n){
      $sols[] = $sizes; continue;
    }
    if($sum > $n) continue;
    foreach($allowed as $s){
      $ns = $sizes; $ns[] = $s;
      $stack[] = [$ns, $sum + $s];
    }
  }
  // Normalize: sort each solution descending (8s first)
  $norm = [];
  foreach($sols as $s){
    rsort($s);
    $norm[] = $s;
  }
  // dedupe
  $uniq = [];
  $hashes = [];
  foreach($norm as $s){
    $h = implode('-', $s);
    if(!isset($hashes[$h])){ $hashes[$h]=1; $uniq[] = $s; }
  }
  return $uniq;
}

function assign_pods(){
  $players = checked_in_players();
  $n = count($players);
  if($n < 6) return ['ok'=>false,'error'=>'Need at least 6 checked-in players'];
  $sizes = compute_pod_sizes($n);
  if(!$sizes) return ['ok'=>false,'error'=>'Could not compute pod sizes'];
  // Randomize players first
  shuffle($players);
  clear_pods();
  $podNo = 1;
  $idx = 0;
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

/** Cross pair mapping: i vs i+N/2 (even). For odd N, we pair as mirror floor(N/2) and give one BYE to last seat. */
function cross_pairs_for_pod($podPlayers){
  // $podPlayers array of ['id','name','pod','pod_seat']
  usort($podPlayers, fn($a,$b)=> $a['pod_seat'] <=> $b['pod_seat']);
  $N = count($podPlayers);
  $pairs = [];
  $used = array_fill(1, $N, false);
  if($N % 2 == 1){
    // give bye to highest seat for determinism
    $byeSeat = $N;
  } else {
    $byeSeat = null;
  }
  $half = intdiv($N,2);
  for($i=1;$i<=$N;$i++){
    if($byeSeat && $i==$byeSeat) continue;
    if($used[$i]) continue;
    $j = $i + $half;
    if($j > $N) $j -= $N;
    if($byeSeat && $j==$byeSeat){
      // adjust: pick next available
      $j = ($j % $N) + 1;
    }
    if($used[$j]){
      // fallback: find any unused
      for($k=1;$k<=$N;$k++){
        if(($byeSeat && $k==$byeSeat)) continue;
        if(!$used[$k] && $k!=$i){ $j=$k; break; }
      }
    }
    $pairs[] = [$podPlayers[$i-1], $podPlayers[$j-1]];
    $used[$i]=true; $used[$j]=true;
  }
  return [$pairs, $byeSeat ? $podPlayers[$byeSeat-1] : null];
}

function round_matches($round){
  return all("SELECT m.*, p1.name AS p1_name, p2.name AS p2_name
    FROM matches m
    LEFT JOIN players p1 ON p1.id=m.p1_id
    LEFT JOIN players p2 ON p2.id=m.p2_id
    WHERE m.round=?", [$round]);
}

function player_matches_before_round($player_id, $round){
  return all("SELECT * FROM matches WHERE (p1_id=? OR p2_id=?) AND round < ?", [$player_id,$player_id,$round]);
}

function player_points($player_id, $upToRound=null){
  $sql = "SELECT * FROM matches WHERE (p1_id=? OR p2_id=?) AND confirmed=1";
  $params = [$player_id,$player_id];
  if($upToRound!==null){ $sql .= " AND round<=?"; $params[]=$upToRound; }
  $rows = all($sql,$params);
  $mp=0;
  foreach($rows as $m){
    if($m['is_bye']){
      $mp += 3; continue;
    }
    if($m['p1_id']==$player_id){
      $g1=$m['p1_game_wins']; $g2=$m['p2_game_wins'];
    }else{
      $g2=$m['p1_game_wins']; $g1=$m['p2_game_wins'];
    }
    if($g1===null || $g2===null) continue;
    if($g1>$g2) $mp+=3;
    elseif($g1==$g2) $mp+=1;
  }
  return $mp;
}

function all_points($upToRound=null){
  $players = all("SELECT * FROM players WHERE active=1");
  $pts = [];
  foreach($players as $p){
    $pts[$p['id']] = player_points($p['id'], $upToRound);
  }
  return $pts;
}

function swiss_pairings($player_ids, $round, $scope='global'){
  // Sort by points desc, then random within bracket.
  $pts = all_points($round-1);
  $players = [];
  foreach($player_ids as $pid){
    $players[] = ['id'=>$pid, 'mp'=> ($pts[$pid] ?? 0)];
  }
  usort($players, function($a,$b){ return $b['mp'] <=> $a['mp']; });

  // group into brackets
  $br = [];
  foreach($players as $pl){
    $br[$pl['mp']] = $br[$pl['mp']] ?? [];
    $br[$pl['mp']][] = $pl['id'];
  }
  // random shuffle inside each bracket
  foreach($br as &$arr){ shuffle($arr); }
  unset($arr);

  $pairs = [];
  $waiting = null;
  $down = []; // queue for downpair
  foreach($br as $mp=>$arr){
    if($waiting!==null){
      array_unshift($arr, $waiting);
      $waiting=null;
    }
    while(count($arr)>=2){
      $a = array_shift($arr);
      $b = array_shift($arr);
      if(rematch($a,$b,$round)){
        // try to swap b with some other
        $swapped=false;
        for($i=0;$i<count($arr);$i++){
          if(!rematch($a,$arr[$i],$round)){
            $tmp=$arr[$i]; $arr[$i]=$b; $b=$tmp; $swapped=true; break;
          }
        }
        // if still rematch, accept
      }
      $pairs[] = [$a,$b];
    }
    if(count($arr)==1){
      // downpair
      $waiting = array_shift($arr);
    }
  }
  if($waiting!==null){
    // bye needed
    $pairs[] = [$waiting, null]; // bye
  }
  return $pairs;
}

function rematch($a,$b,$round){
  $m = one("SELECT 1 FROM matches WHERE confirmed=1 AND round < ? AND ((p1_id=? AND p2_id=?) OR (p1_id=? AND p2_id=?))",
    [$round, $a,$b, $b,$a]);
  return $m ? true : false;
}

/** Create Round 1: cross-pair inside each pod. */
function create_round1(){
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

/** Create next Swiss (round 2/3 within pod, else global) */
function create_round($round){
  q("DELETE FROM matches WHERE round=?", [$round]);
  $players = all("SELECT * FROM players WHERE active=1 AND dropped=0");
  // choose scope
  $total_rounds = intval(get_setting('total_rounds'));
  $pairsAll = [];
  $table = 1;
  if($round<=3){
    // pair per pod Swiss within pod
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
    // global Swiss
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

/** Standings math with MTR-style tiebreakers */
function standings($upToRound=null){
  // get players
  $players = all("SELECT id,name FROM players WHERE active=1");
  // Build maps
  $mp = []; $gwp=[]; $omw=[]; $ogw=[];
  $pmw = []; // per-player match win %
  $oppList = []; $oppGWPs = [];
  foreach($players as $p){
    $pid=$p['id'];
    $mp[$pid] = 0;
    $gamesPlayed = 0; $gamePts=0;
    $oppList[$pid] = [];
    $oppGWPs[$pid]=[];
    // collect matches
    $rows = all("SELECT * FROM matches WHERE confirmed=1 AND (p1_id=? OR p2_id=?)".($upToRound?" AND round<=".intval($upToRound):""), [$pid,$pid]);
    foreach($rows as $m){
      if($m['is_bye']){
        // MP for bye
        $mp[$pid] += 3;
        continue;
      }
      $g1 = ($m['p1_id']==$pid) ? intval($m['p1_game_wins']) : intval($m['p2_game_wins']);
      $g2 = ($m['p1_id']==$pid) ? intval($m['p2_game_wins']) : intval($m['p1_game_wins']);
      // MP
      if($g1>$g2) $mp[$pid]+=3;
      elseif($g1==$g2) $mp[$pid]+=1;
      // Game points
      $gamePts += (3*$g1) + (1*max(0, ($g1+$g2>0 ? ($g1+$g2) - $g1 - $g2 : 0))); // draws are represented by equal g1=g2>0? We'll treat simply: each game not won is either draw(1) or loss(0). For simplicity count draw=0 for now.
      $gamesPlayed += ($g1 + $g2);
      // opp
      $opp = ($m['p1_id']==$pid) ? $m['p2_id'] : $m['p1_id'];
      if($opp) $oppList[$pid][] = $opp;
    }
    $pmw[$pid] = 0;
    $den = 3 * count(array_filter($rows, fn($m)=> !$m['is_bye']));
    if($den>0) $pmw[$pid] = max(0.33, $mp[$pid] / $den);
    // GWP: compute from games
    if($gamesPlayed>0){
      $gwp[$pid] = max(0.33, ($gamePts) / (3 * $gamesPlayed));
    } else {
      $gwp[$pid] = 0.33;
    }
  }
  // OMW / OGW
  foreach($players as $p){
    $pid=$p['id'];
    $opps = $oppList[$pid];
    if(!$opps){ $omw[$pid]=0.33; $ogw[$pid]=0.33; continue; }
    $sumM=0; $sumG=0; $n=0;
    foreach($opps as $oid){
      // fetch opponent PMW/GWP excluding byes
      $sumM += max(0.33, $pmw[$oid] ?? 0.33);
      $sumG += max(0.33, $gwp[$oid] ?? 0.33);
      $n++;
    }
    $omw[$pid] = $n? ($sumM/$n):0.33;
    $ogw[$pid] = $n? ($sumG/$n):0.33;
  }
  // Sort
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
      'id'=>$pid,'name'=>$p['name'],
      'mp'=>$mp[$pid],
      'omw'=>round($omw[$pid], 6),
      'gwp'=>round($gwp[$pid], 6),
      'ogw'=>round($ogw[$pid], 6),
    ];
  }
  return $out;
}

/** Determine total Swiss rounds from checked-in count */
function compute_total_rounds(){
  $n = count(checked_in_players());
  if($n<=24) return 5;
  else return 6;
}

/** Top 8 creation: random seating 1..8, cross pair QF */
function create_top8(){
  $stand = standings(null);
  $top = array_slice($stand,0,8);
  if(count($top)<8) return ['ok'=>false,'error'=>'Need 8 players'];
  // Random seating
  $seats = range(1,8); shuffle($seats);
  foreach($top as $i=>$row){
    q("UPDATE players SET top8_seat=? WHERE id=?", [$seats[$i], $row['id']]);
  }
  // QF pairings (1v5,2v6,3v7,4v8)
  q("DELETE FROM matches WHERE round>=100"); // reserve 100+ for top8
  $map = [1=>5, 2=>6, 3=>7, 4=>8];
  $table=1;
  foreach($map as $a=>$b){
    $p1 = one("SELECT * FROM players WHERE top8_seat=?", [$a]);
    $p2 = one("SELECT * FROM players WHERE top8_seat=?", [$b]);
    q("INSERT INTO matches (round, table_no, p1_id, p2_id, top8_phase) VALUES (100, ?, ?, ?, 'QF')", [$table,$p1['id'],$p2['id']]);
    $table++;
  }
  set_setting('status','top8');
  set_setting('current_round','100');
  return ['ok'=>true];
}

function advance_top8(){
  $cur = intval(get_setting('current_round'));
  if($cur==100){
    // create semis from QF winners
    $qf = all("SELECT * FROM matches WHERE round=100 AND confirmed=1 ORDER BY table_no ASC");
    if(count($qf)<4) return ['ok'=>false,'error'=>'Not all QFs confirmed'];
    $winners = [];
    foreach($qf as $m){
      $winners[] = winner_of($m);
    }
    // pair 1v2 and 3v4 winners
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
         (($m['p2_game_wins'] > $m['p1_game_wins']) ? $m['p2_id'] : $m['p1_id']); // ties: favor p1 deterministically
}
