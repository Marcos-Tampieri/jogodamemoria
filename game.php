<?php
// game.php (file-backed) - API REST JSON para memória multijogador 2 players.
// Armazenamento: data/storage.json (JSON)
// Use a pasta "data/" com permissão de escrita pelo servidor web.

header('Content-Type: application/json; charset=utf-8');

const STORAGE_DIR = __DIR__ . '/data';
const STORAGE_FILE = STORAGE_DIR . '/storage.json';
const ICONS = [
  "🐶","🐱","🦊","🦁","🐮","🐷","🐸","🐵","🐔","🐧",
  "🐢","🐙","🦋","🌵","🌸","🍎","🍓","🍔","⚽","🎲"
];

// ---------- Helpers JSON/file locking ----------
function ensure_storage_exists() {
    if (!is_dir(STORAGE_DIR)) {
        mkdir(STORAGE_DIR, 0755, true);
    }
    if (!file_exists(STORAGE_FILE)) {
        $initial = [
            'nextGameId' => 1,
            'nextPlayerId' => 1,
            'games' => new stdClass(),   // objetos vazios para manter chaves como strings
            'players' => new stdClass()
        ];
        file_put_contents(STORAGE_FILE, json_encode($initial, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), LOCK_EX);
    }
}

// Abre o arquivo e obtém um lock exclusivo, retornando [$storageArray, $fp]
// Use save_and_unlock($fp, $storage) para persistir.
function load_and_lock_exclusive() {
    ensure_storage_exists();
    $fp = fopen(STORAGE_FILE, 'c+');
    if ($fp === false) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'cannot open storage file']);
        exit;
    }
    if (!flock($fp, LOCK_EX)) {
        fclose($fp);
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'cannot lock storage file']);
        exit;
    }
    rewind($fp);
    $contents = stream_get_contents($fp);
    $storage = $contents ? json_decode($contents, true) : null;
    if (!is_array($storage)) {
        $storage = [
            'nextGameId' => 1,
            'nextPlayerId' => 1,
            'games' => [],
            'players' => []
        ];
    }
    return [$storage, $fp];
}

function load_shared() {
    ensure_storage_exists();
    $fp = fopen(STORAGE_FILE, 'r');
    if ($fp === false) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'cannot open storage file']);
        exit;
    }
    if (!flock($fp, LOCK_SH)) {
        fclose($fp);
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'cannot lock storage file (shared)']);
        exit;
    }
    $contents = stream_get_contents($fp);
    $storage = $contents ? json_decode($contents, true) : null;
    flock($fp, LOCK_UN);
    fclose($fp);
    if (!is_array($storage)) {
        $storage = [
            'nextGameId' => 1,
            'nextPlayerId' => 1,
            'games' => [],
            'players' => []
        ];
    }
    return $storage;
}

// Persistir modificações e liberar lock
function save_and_unlock($fp, $storage) {
    // reescrever arquivo atomically mantendo o handle
    rewind($fp);
    ftruncate($fp, 0);
    $written = fwrite($fp, json_encode($storage, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    fflush($fp);
    flock($fp, LOCK_UN);
    fclose($fp);
    return $written !== false;
}

// JSON helper responses
function json_ok($data = []) {
    echo json_encode(array_merge(['ok' => true], $data), JSON_UNESCAPED_UNICODE);
    exit;
}
function json_err($msg, $code = 400) {
    http_response_code($code);
    echo json_encode(['ok' => false, 'error' => $msg], JSON_UNESCAPED_UNICODE);
    exit;
}

// read JSON body
$raw = file_get_contents('php://input');
$body = json_decode($raw, true);
if (!is_array($body)) {
    // fallback to form params
    $body = $_POST + $_GET;
}

$action = $body['action'] ?? null;
if (!$action) json_err('missing action');

// ---------- Actions ----------
switch ($action) {
    case 'create': action_create($body); break;
    case 'join': action_join($body); break;
    case 'state': action_state($body); break;
    case 'flip': action_flip($body); break;
    case 'reset': action_reset($body); break;
    default: json_err('unknown action');
}

// ---------- Implementations ----------

function action_create($body) {
    list($storage, $fp) = load_and_lock_exclusive();

    $name = trim($body['player_name'] ?? 'Jogador 1');

    // build deck (40 cards)
    $icons = ICONS;
    $pairArray = array_merge($icons, $icons);
    shuffle($pairArray);

    $gameId = $storage['nextGameId'];
    $storage['nextGameId'] = $gameId + 1;

    $now = (new DateTime())->format('c');

    $gameObj = [
        'id' => $gameId,
        'deck' => array_values($pairArray),
        'matched' => array_fill(0, count($pairArray), false),
        'flipped' => [], // temporary flipped indices
        'moves' => 0,
        'current_player_id' => null,
        'status' => 'waiting',
        'created_at' => $now,
        'updated_at' => $now
    ];

    $storage['games'][(string)$gameId] = $gameObj;

    // create player
    $playerId = $storage['nextPlayerId'];
    $storage['nextPlayerId'] = $playerId + 1;

    $playerObj = [
        'id' => $playerId,
        'game_id' => $gameId,
        'name' => $name,
        'player_number' => 1,
        'score' => 0,
        'created_at' => $now
    ];
    $storage['players'][(string)$playerId] = $playerObj;

    // set current_player_id to this player
    $storage['games'][(string)$gameId]['current_player_id'] = $playerId;

    save_and_unlock($fp, $storage);

    json_ok(['game_id' => $gameId, 'player_id' => $playerId, 'player_number' => 1]);
}

function action_join($body) {
    $gameId = isset($body['game_id']) ? (int)$body['game_id'] : 0;
    if (!$gameId) json_err('missing game_id');

    list($storage, $fp) = load_and_lock_exclusive();

    if (!isset($storage['games'][(string)$gameId])) {
        save_and_unlock($fp, $storage);
        json_err('game not found', 404);
    }

    // count players for this game
    $count = 0;
    foreach ($storage['players'] as $p) if ((int)$p['game_id'] === $gameId) $count++;
    if ($count >= 2) {
        save_and_unlock($fp, $storage);
        json_err('game already has 2 players', 409);
    }

    $name = trim($body['player_name'] ?? 'Jogador 2');
    $playerNumber = $count + 1;
    $playerId = $storage['nextPlayerId'];
    $storage['nextPlayerId'] = $playerId + 1;
    $now = (new DateTime())->format('c');

    $playerObj = [
        'id' => $playerId,
        'game_id' => $gameId,
        'name' => $name,
        'player_number' => $playerNumber,
        'score' => 0,
        'created_at' => $now
    ];
    $storage['players'][(string)$playerId] = $playerObj;

    if ($playerNumber === 2) {
        $storage['games'][(string)$gameId]['status'] = 'playing';
        $storage['games'][(string)$gameId]['updated_at'] = $now;
    }

    save_and_unlock($fp, $storage);

    json_ok(['game_id' => $gameId, 'player_id' => $playerId, 'player_number' => $playerNumber]);
}

function action_state($body) {
    $gameId = isset($body['game_id']) ? (int)$body['game_id'] : 0;
    if (!$gameId) json_err('missing game_id');

    $storage = load_shared();

    if (!isset($storage['games'][(string)$gameId])) json_err('game not found', 404);
    $game = $storage['games'][(string)$gameId];

    // collect players for the game, ordered by player_number
    $players = [];
    foreach ($storage['players'] as $p) {
        if ((int)$p['game_id'] === $gameId) $players[] = $p;
    }
    usort($players, function($a,$b){ return $a['player_number'] <=> $b['player_number']; });

    json_ok([
        'game' => [
            'id' => (int)$game['id'],
            'deck' => $game['deck'],
            'matched' => $game['matched'],
            'flipped' => $game['flipped'],
            'moves' => (int)$game['moves'],
            'current_player_id' => $game['current_player_id'] ? (int)$game['current_player_id'] : null,
            'status' => $game['status']
        ],
        'players' => array_values($players)
    ]);
}

function action_flip($body) {
    $gameId = isset($body['game_id']) ? (int)$body['game_id'] : 0;
    $playerId = isset($body['player_id']) ? (int)$body['player_id'] : 0;
    $index = isset($body['index']) ? (int)$body['index'] : null;
    if (!$gameId || !$playerId || $index === null) json_err('missing parameters');

    list($storage, $fp) = load_and_lock_exclusive();

    if (!isset($storage['games'][(string)$gameId])) {
        save_and_unlock($fp, $storage);
        json_err('game not found', 404);
    }
    $game = &$storage['games'][(string)$gameId];

    if (!isset($storage['players'][(string)$playerId]) || (int)$storage['players'][(string)$playerId]['game_id'] !== $gameId) {
        save_and_unlock($fp, $storage);
        json_err('player not in this game', 403);
    }

    // check turn
    if ((int)$game['current_player_id'] !== $playerId) {
        save_and_unlock($fp, $storage);
        json_err('not this player turn', 409);
    }

    $deck = $game['deck'];
    $matched = $game['matched'];
    $flipped = $game['flipped'];

    $totalCards = count($deck);
    if ($index < 0 || $index >= $totalCards) {
        save_and_unlock($fp, $storage);
        json_err('index out of range', 400);
    }
    if (!empty($matched[$index]) && $matched[$index] === true) {
        save_and_unlock($fp, $storage);
        json_err('card already matched', 409);
    }
    if (in_array($index, $flipped, true)) {
        save_and_unlock($fp, $storage);
        json_err('card already flipped', 409);
    }

    // add flip
    $flipped[] = $index;
    $game['flipped'] = array_values($flipped);

    $response = [
        'flipped' => $game['flipped'],
        'match' => null,
        'next_player_id' => $game['current_player_id'],
        'scores' => null,
        'game_over' => false,
        'moves' => (int)$game['moves']
    ];

    if (count($game['flipped']) === 1) {
        // just recorded first flip
        $game['updated_at'] = (new DateTime())->format('c');
        save_and_unlock($fp, $storage);
        json_ok($response);
    }

    if (count($game['flipped']) === 2) {
        $i1 = $game['flipped'][0];
        $i2 = $game['flipped'][1];
        $is_match = ($deck[$i1] === $deck[$i2]);

        if ($is_match) {
            // mark matched
            $game['matched'][$i1] = true;
            $game['matched'][$i2] = true;

            // increment player's score
            $storage['players'][(string)$playerId]['score'] = (int)$storage['players'][(string)$playerId]['score'] + 1;

            // clear flipped, keep same player's turn
            $game['flipped'] = [];
            $game['updated_at'] = (new DateTime())->format('c');

            // check all matched
            $all = true;
            foreach ($game['matched'] as $m) { if ($m !== true) { $all = false; break; } }
            if ($all) {
                $game['status'] = 'finished';
            }

            // collect players array
            $players = [];
            foreach ($storage['players'] as $p) if ((int)$p['game_id'] === $gameId) $players[] = $p;
            usort($players, function($a,$b){ return $a['player_number'] <=> $b['player_number']; });

            $response['match'] = true;
            $response['flipped'] = [];
            $response['next_player_id'] = $playerId;
            $response['scores'] = array_values($players);
            $response['moves'] = (int)$game['moves'];
            $response['game_over'] = $all ? true : false;

            save_and_unlock($fp, $storage);
            json_ok($response);
        } else {
            // not match: increment moves, switch turn to other player (if exists)
            $game['moves'] = (int)$game['moves'] + 1;
            $otherId = $playerId; // fallback
            foreach ($storage['players'] as $p) {
                if ((int)$p['game_id'] === $gameId && (int)$p['id'] !== $playerId) { $otherId = (int)$p['id']; break; }
            }
            $game['current_player_id'] = $otherId;
            $game['updated_at'] = (new DateTime())->format('c');

            // collect players
            $players = [];
            foreach ($storage['players'] as $p) if ((int)$p['game_id'] === $gameId) $players[] = $p;
            usort($players, function($a,$b){ return $a['player_number'] <=> $b['player_number']; });

            $response['match'] = false;
            $response['next_player_id'] = $otherId;
            $response['scores'] = array_values($players);
            $response['moves'] = (int)$game['moves'];
            $response['flipped'] = $game['flipped'];
            $response['game_over'] = false;

            save_and_unlock($fp, $storage);
            json_ok($response);
        }
    }

    // defensive fallback
    $game['flipped'] = [];
    $game['updated_at'] = (new DateTime())->format('c');
    save_and_unlock($fp, $storage);
    json_ok(['info' => 'reset flipped']);
}

function action_reset($body) {
    $gameId = isset($body['game_id']) ? (int)$body['game_id'] : 0;
    if (!$gameId) json_err('missing game_id');

    list($storage, $fp) = load_and_lock_exclusive();

    if (!isset($storage['games'][(string)$gameId])) {
        save_and_unlock($fp, $storage);
        json_err('game not found', 404);
    }

    // rebuild deck
    $icons = ICONS;
    $pairArray = array_merge($icons, $icons);
    shuffle($pairArray);

    $game = &$storage['games'][(string)$gameId];
    $game['deck'] = array_values($pairArray);
    $game['matched'] = array_fill(0, count($pairArray), false);
    $game['flipped'] = [];
    $game['moves'] = 0;
    $game['status'] = 'playing';
    $game['updated_at'] = (new DateTime())->format('c');

    // reset players scores
    foreach ($storage['players'] as &$p) {
        if ((int)$p['game_id'] === $gameId) $p['score'] = 0;
    }
    unset($p);

    // set current_player_id to player_number = 1 if exists
    $p1 = null;
    foreach ($storage['players'] as $p) {
        if ((int)$p['game_id'] === $gameId && (int)$p['player_number'] === 1) { $p1 = (int)$p['id']; break; }
    }
    if ($p1 !== null) $game['current_player_id'] = $p1;

    save_and_unlock($fp, $storage);
    json_ok(['game_id' => $gameId, 'reset' => true]);
}