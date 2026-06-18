<?php
// socket_server.php - NexusChat WebSocket Server
// Uses PHP streams — no ext-sockets extension required
// Run: php socket_server.php

require_once __DIR__ . '/config.php';

$host   = WS_HOST;
$port   = WS_PORT;

$clients = array(); // id => array(stream, handshake, username, room_id, buf, send_buf, last_pong)
$rooms   = array(); // room_id => array(client_id, ...)
$nextId  = 1;

// ─── Start server ─────────────────────────────────────────────────────────────
$errno  = 0;
$errstr = '';
$server = @stream_socket_server("tcp://$host:$port", $errno, $errstr, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN);

if (!$server) {
    echo "[ERROR] Could not start server on $host:$port — $errstr ($errno)\n";
    echo "[HINT]  Is port $port already in use?\n";
    exit(1);
}

stream_set_blocking($server, false);
echo "[NexusChat WS] Server started on $host:$port\n";
echo "[NexusChat WS] Using PHP streams — no ext-sockets required\n";
echo "[NexusChat WS] Press Ctrl+C to stop\n\n";

// ─── WebSocket helpers ────────────────────────────────────────────────────────
function wsHandshake($data) {
    preg_match('/Sec-WebSocket-Key:\s*(.+)\r\n/i', $data, $m);
    if (empty($m[1])) return '';
    $key = base64_encode(sha1(trim($m[1]) . '258EAFA5-E914-47DA-95CA-C5AB0DC85B11', true));
    return "HTTP/1.1 101 Switching Protocols\r\n"
         . "Upgrade: websocket\r\n"
         . "Connection: Upgrade\r\n"
         . "Sec-WebSocket-Accept: $key\r\n\r\n";
}

function wsEncode($payload, $opcode = 0x1) {
    $len   = strlen($payload);
    $frame = chr(0x80 | $opcode);
    if ($len < 126) {
        $frame .= chr($len);
    } elseif ($len < 65536) {
        $frame .= chr(126) . pack('n', $len);
    } else {
        $frame .= chr(127) . pack('J', $len);
    }
    return $frame . $payload;
}

function wsDecode(&$buf) {
    if (strlen($buf) < 2) return null;

    $b1     = ord($buf[1]);
    $opcode = ord($buf[0]) & 0x0F;
    $masked = ($b1 & 0x80) !== 0;
    $payLen = $b1 & 0x7F;
    $offset = 2;

    if ($payLen === 126) {
        if (strlen($buf) < 4) return null;
        $payLen = unpack('n', substr($buf, 2, 2))[1];
        $offset = 4;
    } elseif ($payLen === 127) {
        if (strlen($buf) < 10) return null;
        $payLen = unpack('J', substr($buf, 2, 8))[1];
        $offset = 10;
    }

    $maskLen = $masked ? 4 : 0;
    $total   = $offset + $maskLen + $payLen;
    if (strlen($buf) < $total) return null;

    $maskKey = $masked ? substr($buf, $offset, 4) : '';
    $payload = substr($buf, $offset + $maskLen, $payLen);

    if ($masked && $maskKey !== '') {
        for ($i = 0; $i < $payLen; $i++) {
            $payload[$i] = chr(ord($payload[$i]) ^ ord($maskKey[$i % 4]));
        }
    }

    $buf = substr($buf, $total);
    return array('opcode' => $opcode, 'payload' => $payload);
}

// ─── Helpers ──────────────────────────────────────────────────────────────────
function queueSend($id, $json) {
    global $clients;
    if (isset($clients[$id])) {
        $clients[$id]['send_buf'] .= wsEncode($json);
    }
}

function broadcastRoom($roomId, $data, $exclude = null) {
    global $rooms;
    $json = json_encode($data);
    if (empty($rooms[$roomId])) return;
    foreach ($rooms[$roomId] as $id) {
        if ($id === $exclude) continue;
        queueSend($id, $json);
    }
}

function safeClose($stream) {
    if (is_resource($stream)) {
        // suppress all errors/exceptions — stream may already be dead
        set_error_handler(function() { return true; });
        try {
            fclose($stream);
        } catch (Exception $e) {}
        restore_error_handler();
    }
}

function removeClient($id, $reason = '') {
    global $clients, $rooms;
    if (!isset($clients[$id])) return;

    $state    = $clients[$id];
    $roomId   = $state['room_id'];
    $username = $state['username'];

    // Remove from room
    if ($roomId !== null && isset($rooms[$roomId])) {
        $filtered = array();
        foreach ($rooms[$roomId] as $cid) {
            if ($cid !== $id) $filtered[] = $cid;
        }
        $rooms[$roomId] = $filtered;

        if ($username) {
            broadcastRoom($roomId, array(
                'type'     => 'system',
                'message'  => "$username left the room",
                'username' => $username,
                'room_id'  => $roomId,
                'ts'       => date('Y-m-d H:i:s'),
            ));
        }
    }

    safeClose($state['stream']);
    unset($clients[$id]);

    $who = $username ? $username : "client#$id";
    echo "[WS] Disconnected: $who" . ($reason ? " ($reason)" : '') . "\n";
}

function handleMessage($id, $msg) {
    global $clients, $rooms;

    if (!isset($clients[$id])) return;
    $state = &$clients[$id];

    $type = isset($msg['type']) ? $msg['type'] : '';

    switch ($type) {

        case 'auth':
            $username = preg_replace('/[^a-zA-Z0-9_\-]/', '', isset($msg['username']) ? $msg['username'] : '');
            $state['username'] = $username;
            queueSend($id, json_encode(array('type' => 'auth_ok', 'username' => $username)));
            echo "[WS] Auth: $username\n";
            break;

        case 'join_room':
            // room_id is always an integer for chat rooms
            $roomId   = isset($msg['room_id']) ? (int)$msg['room_id'] : 0;
            $oldRoom  = $state['room_id'];
            $username = $state['username'] ? $state['username'] : "anon#$id";

            // Leave old room
            if ($oldRoom !== null && isset($rooms[$oldRoom])) {
                $filtered = array();
                foreach ($rooms[$oldRoom] as $cid) {
                    if ($cid !== $id) $filtered[] = $cid;
                }
                $rooms[$oldRoom] = $filtered;
                broadcastRoom($oldRoom, array(
                    'type' => 'system', 'message' => "$username left the room",
                    'username' => $username, 'room_id' => $oldRoom, 'ts' => date('Y-m-d H:i:s'),
                ), $id);
            }

            // Join new room
            $state['room_id'] = $roomId;
            if (!isset($rooms[$roomId])) $rooms[$roomId] = array();
            $rooms[$roomId][] = $id;

            queueSend($id, json_encode(array('type' => 'joined', 'room_id' => $roomId)));
            broadcastRoom($roomId, array(
                'type' => 'system', 'message' => "$username joined the room",
                'username' => $username, 'room_id' => $roomId, 'ts' => date('Y-m-d H:i:s'),
            ), $id);
            echo "[WS] $username joined room $roomId\n";
            break;

        case 'message':
            $roomId   = $state['room_id'];
            $username = $state['username'];
            if ($roomId === null || !$username) break;
            $color = isset($msg['avatar_color']) ? $msg['avatar_color'] : '#6c63ff';
            $broadcast = array(
                'type'         => 'message',
                'id'           => isset($msg['id']) ? $msg['id'] : null,
                'username'     => $username,
                'message'      => isset($msg['message']) ? $msg['message'] : '',
                'room_id'      => $roomId,
                'ts'           => date('Y-m-d H:i:s'),
                'avatar_color' => $color,
                'avatar_url'   => isset($msg['avatar_url']) ? $msg['avatar_url'] : null,
                // File attachment fields — null when no attachment
                'file_id'      => isset($msg['file_id'])   ? (int)$msg['file_id']            : null,
                'file_name'    => isset($msg['file_name']) ? (string)$msg['file_name']        : null,
                'file_mime'    => isset($msg['file_mime']) ? (string)$msg['file_mime']        : null,
                'file_size'    => isset($msg['file_size']) ? (int)$msg['file_size']           : null,
            );
            broadcastRoom($roomId, $broadcast, $id);
            break;

        case 'reaction':
            $roomId = $state['room_id'];
            if ($roomId === null) break;
            broadcastRoom($roomId, array(
                'type'       => 'reaction',
                'message_id' => isset($msg['message_id']) ? $msg['message_id'] : 0,
                'emoji'      => isset($msg['emoji']) ? $msg['emoji'] : '',
                'username'   => $state['username'],
                'reactions'  => isset($msg['reactions']) ? $msg['reactions'] : array(),
                'room_id'    => $roomId,
            ));
            break;

        case 'message_deleted':
            $roomId = $state['room_id'];
            if ($roomId === null) break;
            broadcastRoom($roomId, array(
                'type'       => 'message_deleted',
                'message_id' => isset($msg['message_id']) ? (int)$msg['message_id'] : 0,
                'scope'      => isset($msg['scope']) ? $msg['scope'] : 'everyone',
                'username'   => $state['username'],
                'room_id'    => $roomId,
            ), $id);
            break;

        case 'message_edited':
            $roomId = $state['room_id'];
            if ($roomId === null) break;
            broadcastRoom($roomId, array(
                'type'       => 'message_edited',
                'message_id' => isset($msg['message_id']) ? (int)$msg['message_id'] : 0,
                'message'    => isset($msg['message']) ? (string)$msg['message'] : '',
                'edited_at'  => isset($msg['edited_at']) ? (string)$msg['edited_at'] : date('Y-m-d H:i:s'),
                'username'   => $state['username'],
                'room_id'    => $roomId,
            ), $id);
            break;

        case 'typing':
            $roomId = $state['room_id'];
            if ($roomId === null) break;
            broadcastRoom($roomId, array(
                'type'     => 'typing',
                'username' => $state['username'],
                'room_id'  => $roomId,
            ), $id);
            break;

        case 'room_updated':
            $roomId = strval(isset($msg['room_id']) ? $msg['room_id'] : '0');
            broadcastRoom($roomId, array(
                'type'    => 'room_updated',
                'room_id' => $roomId,
                'reason'  => isset($msg['reason']) ? $msg['reason'] : 'updated',
            ));
            break;

        case 'avatar_updated':
            $roomId   = $state['room_id'];
            $username = $state['username'];
            if ($roomId === null || !$username) break;
            broadcastRoom($roomId, array(
                'type'         => 'avatar_updated',
                'username'     => $username,
                'room_id'      => $roomId,
                'avatar_color' => isset($msg['avatar_color']) ? $msg['avatar_color'] : '#6c63ff',
                'avatar_url'   => isset($msg['avatar_url']) ? $msg['avatar_url'] : null,
            ), $id);
            break;

        case 'ping':
            queueSend($id, json_encode(array('type' => 'pong')));
            break;
    }
}

// ─── Main loop ────────────────────────────────────────────────────────────────
echo "[WS] Waiting for connections...\n";

$lastPingCheck = time();

while (true) {

    // Server-side ping & dead connection cleanup every 10s
    $now = time();
    if ($now - $lastPingCheck >= 10) {
        $lastPingCheck = $now;
        foreach ($clients as $id => $state) {
            if (!$state['handshake']) continue;
            if ($now - $state['last_pong'] > 60) {
                removeClient($id, 'ping timeout');
                continue;
            }
            if (isset($clients[$id])) {
                $clients[$id]['send_buf'] .= wsEncode('', 0x9); // server ping frame
            }
        }
    }

    // Build select arrays — validate every stream first
    $read       = array();
    $write      = array();
    $streamToId = array();

    if (is_resource($server) && !feof($server)) {
        $read[] = $server;
    }

    foreach ($clients as $id => $state) {
        $stream = $state['stream'];
        if (!is_resource($stream) || feof($stream)) {
            removeClient($id, 'stale stream');
            continue;
        }
        $read[] = $stream;
        $streamToId[(int)$stream] = $id;
        if ($state['send_buf'] !== '') {
            $write[] = $stream;
        }
    }

    if (empty($read)) {
        usleep(50000);
        continue;
    }

    $except  = null;
    $changed = @stream_select($read, $write, $except, 0, 50000);
    if ($changed === false || $changed === 0) continue;

    // Accept new connections
    if (in_array($server, $read, true)) {
        $newStream = @stream_socket_accept($server, 0);
        if ($newStream) {
            stream_set_blocking($newStream, false);
            $id = $nextId++;
            $clients[$id] = array(
                'stream'    => $newStream,
                'handshake' => false,
                'username'  => null,
                'room_id'   => null,
                'buf'       => '',
                'send_buf'  => '',
                'last_pong' => time(),
            );
            $streamToId[(int)$newStream] = $id;
            $peer = @stream_socket_get_name($newStream, true);
            echo "[WS] New connection #$id from $peer\n";
        }
    }

    // Flush write buffers
    foreach ($write as $stream) {
        $id = isset($streamToId[(int)$stream]) ? $streamToId[(int)$stream] : null;
        if ($id === null || !isset($clients[$id])) continue;
        $buf  = $clients[$id]['send_buf'];
        $sent = @fwrite($stream, $buf);
        if ($sent === false) {
            removeClient($id, 'write error');
        } elseif ($sent > 0) {
            $clients[$id]['send_buf'] = substr($buf, $sent);
        }
    }

    // Read incoming data
    foreach ($read as $stream) {
        if ($stream === $server) continue;
        $id = isset($streamToId[(int)$stream]) ? $streamToId[(int)$stream] : null;
        if ($id === null || !isset($clients[$id])) continue;

        $data = @fread($stream, 8192);
        if ($data === false || $data === '') {
            removeClient($id, 'connection closed');
            continue;
        }

        $clients[$id]['buf'] .= $data;
        $state = &$clients[$id];

        // WebSocket handshake
        if (!$state['handshake']) {
            if (strpos($state['buf'], "\r\n\r\n") !== false) {
                $response = wsHandshake($state['buf']);
                if ($response === '') {
                    removeClient($id, 'bad handshake');
                    continue;
                }
                $state['buf']       = '';
                $state['handshake'] = true;
                $state['last_pong'] = time();
                $state['send_buf'] .= $response;
                echo "[WS] Handshake OK for #$id\n";
            }
            continue;
        }

        // Parse WebSocket frames
        while ($state['buf'] !== '') {
            $frame = wsDecode($state['buf']);
            if ($frame === null) break;

            $opcode  = $frame['opcode'];
            $payload = $frame['payload'];

            if ($opcode === 0x8) {
                removeClient($id, 'client close');
                break;
            }
            if ($opcode === 0x9) {
                // ping — reply with pong
                if (isset($clients[$id])) $clients[$id]['send_buf'] .= wsEncode($payload, 0xA);
                continue;
            }
            if ($opcode === 0xA) {
                // pong — update liveness
                if (isset($clients[$id])) $clients[$id]['last_pong'] = time();
                continue;
            }
            if ($opcode === 0x1 || $opcode === 0x2) {
                $msg = @json_decode($payload, true);
                if (is_array($msg)) {
                    handleMessage($id, $msg);
                }
            }
        }
    }
}