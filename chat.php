<?php
error_reporting(0);
ini_set('display_errors', '0');
session_start();

set_error_handler(function($errno, $errstr, $errfile, $errline) {
    if ($errno & (E_ERROR | E_CORE_ERROR | E_COMPILE_ERROR | E_USER_ERROR | E_RECOVERABLE_ERROR)) {
        throw new ErrorException($errstr, 0, $errno, $errfile, $errline);
    }
    return true;
});
set_exception_handler(function($e) {
    if (isset($_POST['act'])) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'msg' => '服务器错误: ' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
    }
});

if (empty($_SESSION['dc_uid'])) {
    header('Location: index.php');
    exit;
}

$dc_uid = intval($_SESSION['dc_uid']);
$dc_uname = $_SESSION['dc_uname'] ?? '';
$dc_nick = $_SESSION['dc_nick'] ?? $dc_uname;


function dc_getDB() {
    static $db = null;
    if ($db === null) {
        $db_path = __DIR__ . '/data/dogechat.db';
        $dir = dirname($db_path);
        if (!is_dir($dir)) mkdir($dir, 0755, true);
        $db = new PDO('sqlite:' . $db_path);
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $db->exec('PRAGMA journal_mode=WAL');
        $db->exec('PRAGMA foreign_keys=ON');
    }
    return $db;
}
function dc_cleanInput($str) {
    $str = trim($str);
    $str = stripslashes($str);
    return $str;
}
function dc_jsonResponse($success, $data, $msg) {
    echo json_encode(array('success' => $success, 'data' => $data, 'msg' => $msg), JSON_UNESCAPED_UNICODE);
    exit;
}
function dc_checkLogin() {
    if (empty($_SESSION['dc_uid'])) {
        dc_jsonResponse(false, array(), '未登录');
    }
    return intval($_SESSION['dc_uid']);
}


if (isset($_POST['act']) && $_POST['act'] !== '') {
    header('Content-Type: application/json; charset=utf-8');
    $act = trim(stripslashes($_POST['act']));

    try {
    switch ($act) {

        case 'act_nick_update':
            $dc_uid = dc_checkLogin();
            $ndisplay = dc_cleanInput($_POST['ndisplay'] ?? '');
            if (empty($ndisplay) || mb_strlen($ndisplay) > 50) dc_jsonResponse(false, [], '昵称1-50个字符');

            $db = dc_getDB();
            $stmt = $db->prepare("UPDATE dc_members SET ndisplay = :ndisplay WHERE mid = :mid");
            $stmt->execute([':ndisplay' => $ndisplay, ':mid' => $dc_uid]);

            $_SESSION['dc_nick'] = $ndisplay;
            dc_jsonResponse(true, [], '昵称修改成功');
            break;

        case 'act_pwd_change':
            $dc_uid = dc_checkLogin();
            $old_pwd = dc_cleanInput($_POST['old_pwd'] ?? '');
            $new_pwd = dc_cleanInput($_POST['new_pwd'] ?? '');

            if (empty($old_pwd) || empty($new_pwd)) dc_jsonResponse(false, [], '请填写旧密码和新密码');
            if (strlen($new_pwd) < 6) dc_jsonResponse(false, [], '新密码至少6位');

            $db = dc_getDB();

            $stmt = $db->prepare("SELECT upass FROM dc_members WHERE mid = :mid");
            $stmt->execute([':mid' => $dc_uid]);
            $row = $stmt->fetch();

            if (!password_verify($old_pwd, $row['upass'])) {
                dc_jsonResponse(false, [], '旧密码错误');
            }

            $new_hash = password_hash($new_pwd, PASSWORD_DEFAULT);
            $stmt = $db->prepare("UPDATE dc_members SET upass = :upass WHERE mid = :mid");
            $stmt->execute([':upass' => $new_hash, ':mid' => $dc_uid]);

            dc_jsonResponse(true, [], '密码修改成功');
            break;

        case 'act_contact_add':
            $dc_uid = dc_checkLogin();
            $fmid = intval($_POST['fmid'] ?? 0);
            if ($fmid <= 0) dc_jsonResponse(false, [], '参数错误');

            $db = dc_getDB();
            $stmt = $db->prepare("INSERT OR IGNORE INTO dc_contacts (mid, fmid, cstate) VALUES (:mid1, :fmid1, 1), (:mid2, :fmid2, 1)");
            $stmt->execute([
                ':mid1' => $dc_uid, ':fmid1' => $fmid,
                ':mid2' => $fmid, ':fmid2' => $dc_uid
            ]);
            dc_jsonResponse(true, [], '好友添加成功');
            break;

        case 'act_contact_remark':
            $dc_uid = dc_checkLogin();
            $fmid = intval($_POST['fmid'] ?? 0);
            $fremark = dc_cleanInput($_POST['fremark'] ?? '');
            if ($fmid <= 0) dc_jsonResponse(false, [], '参数错误');

            $db = dc_getDB();
            $stmt = $db->prepare("UPDATE dc_contacts SET fremark = :fremark WHERE mid = :mid AND fmid = :fmid");
            $stmt->execute([':fremark' => $fremark, ':mid' => $dc_uid, ':fmid' => $fmid]);
            dc_jsonResponse(true, [], '备注修改成功');
            break;

        case 'act_contact_del':
            $dc_uid = dc_checkLogin();
            $fmid = intval($_POST['fmid'] ?? 0);
            if ($fmid <= 0) dc_jsonResponse(false, [], '参数错误');

            $db = dc_getDB();
            $stmt = $db->prepare("DELETE FROM dc_contacts WHERE (mid = :mid1 AND fmid = :fmid1) OR (mid = :mid2 AND fmid = :fmid2)");
            $stmt->execute([
                ':mid1' => $dc_uid, ':fmid1' => $fmid,
                ':mid2' => $fmid, ':fmid2' => $dc_uid
            ]);
            dc_jsonResponse(true, [], '好友已删除');
            break;

        case 'act_dm_send':
            $dc_uid = dc_checkLogin();
            $to_mid = intval($_POST['to_mid'] ?? 0);
            $mcontent = dc_cleanInput($_POST['mcontent'] ?? '');

            if ($to_mid <= 0) dc_jsonResponse(false, [], '参数错误');
            if (empty($mcontent)) dc_jsonResponse(false, [], '消息不能为空');

            $db = dc_getDB();
            $stmt = $db->prepare("INSERT INTO dc_direct_msgs (from_mid, to_mid, mcontent, mtype) VALUES (:from_mid, :to_mid, :mcontent, 'text')");
            $stmt->execute([':from_mid' => $dc_uid, ':to_mid' => $to_mid, ':mcontent' => $mcontent]);
            $msgid = (int)$db->lastInsertId();

            dc_jsonResponse(true, ['msgid' => $msgid], '发送成功');
            break;

        case 'act_dm_fetch':
            $dc_uid = dc_checkLogin();
            $peer_mid = intval($_POST['peer_mid'] ?? 0);
            $last_msgid = intval($_POST['last_msgid'] ?? 0);

            if ($peer_mid <= 0) dc_jsonResponse(false, [], '参数错误');

            $db = dc_getDB();

            if ($last_msgid > 0) {
                $stmt = $db->prepare("SELECT msgid, from_mid, to_mid, mcontent, mtype, mread, ctime FROM dc_direct_msgs WHERE ((from_mid = :uid AND to_mid = :peer) OR (from_mid = :peer AND to_mid = :uid)) AND msgid < :last_msgid ORDER BY msgid DESC LIMIT 30");
                $stmt->execute([':uid' => $dc_uid, ':peer' => $peer_mid, ':last_msgid' => $last_msgid]);
            } else {
                $stmt = $db->prepare("SELECT msgid, from_mid, to_mid, mcontent, mtype, mread, ctime FROM dc_direct_msgs WHERE (from_mid = :uid AND to_mid = :peer) OR (from_mid = :peer AND to_mid = :uid) ORDER BY msgid DESC LIMIT 30");
                $stmt->execute([':uid' => $dc_uid, ':peer' => $peer_mid]);
            }
            $msgs = $stmt->fetchAll();
            $msgs = array_reverse($msgs);

            $stmt = $db->prepare("UPDATE dc_direct_msgs SET mread = 1 WHERE from_mid = :peer AND to_mid = :uid AND mread = 0");
            $stmt->execute([':peer' => $peer_mid, ':uid' => $dc_uid]);

            $stmt = $db->prepare("SELECT mid, ndisplay FROM dc_members WHERE mid IN (:uid, :peer)");
            $stmt->execute([':uid' => $dc_uid, ':peer' => $peer_mid]);
            $nicknames = [];
            while ($row = $stmt->fetch()) {
                $nicknames[$row['mid']] = $row['ndisplay'];
            }

            foreach ($msgs as &$msg) {
                $msg['sender_name'] = $nicknames[$msg['from_mid']] ?? '未知';
                $msg['receiver_name'] = $nicknames[$msg['to_mid']] ?? '未知';
            }

            dc_jsonResponse(true, $msgs, '');
            break;

        case 'act_room_send':
            $dc_uid = dc_checkLogin();
            $rid = intval($_POST['rid'] ?? 0);
            $mcontent = dc_cleanInput($_POST['mcontent'] ?? '');

            if ($rid <= 0) dc_jsonResponse(false, [], '参数错误');
            if (empty($mcontent)) dc_jsonResponse(false, [], '消息不能为空');

            $db = dc_getDB();

            $stmt = $db->prepare("SELECT COUNT(*) AS cnt FROM dc_room_users WHERE rid = :rid AND mid = :mid");
            $stmt->execute([':rid' => $rid, ':mid' => $dc_uid]);
            $row = $stmt->fetch();
            if (!$row || $row['cnt'] == 0) {
                dc_jsonResponse(false, [], '你不是群成员');
            }

            $stmt = $db->prepare("INSERT INTO dc_room_msgs (rid, from_mid, mcontent, mtype) VALUES (:rid, :from_mid, :mcontent, 'text')");
            $stmt->execute([':rid' => $rid, ':from_mid' => $dc_uid, ':mcontent' => $mcontent]);
            $msgid = (int)$db->lastInsertId();

            dc_jsonResponse(true, ['msgid' => $msgid], '发送成功');
            break;

        case 'act_room_fetch':
            $dc_uid = dc_checkLogin();
            $rid = intval($_POST['rid'] ?? 0);
            $last_msgid = intval($_POST['last_msgid'] ?? 0);

            if ($rid <= 0) dc_jsonResponse(false, [], '参数错误');

            $db = dc_getDB();

            if ($last_msgid > 0) {
                $stmt = $db->prepare("SELECT msgid, rid, from_mid, mcontent, mtype, ctime FROM dc_room_msgs WHERE rid = :rid AND msgid < :last_msgid ORDER BY msgid DESC LIMIT 30");
                $stmt->execute([':rid' => $rid, ':last_msgid' => $last_msgid]);
            } else {
                $stmt = $db->prepare("SELECT msgid, rid, from_mid, mcontent, mtype, ctime FROM dc_room_msgs WHERE rid = :rid ORDER BY msgid DESC LIMIT 30");
                $stmt->execute([':rid' => $rid]);
            }
            $msgs = $stmt->fetchAll();
            $msgs = array_reverse($msgs);

            $all_uids = [];
            foreach ($msgs as $m) {
                $all_uids[$m['from_mid']] = 1;
            }
            $nicknames = [];
            if (!empty($all_uids)) {
                $uids = array_keys($all_uids);
                $placeholders = implode(',', array_fill(0, count($uids), '?'));
                $stmt = $db->prepare("SELECT mid, ndisplay FROM dc_members WHERE mid IN ($placeholders)");
                $stmt->execute(array_values($uids));
                while ($row = $stmt->fetch()) {
                    $nicknames[$row['mid']] = $row['ndisplay'];
                }
            }

            foreach ($msgs as &$m) {
                $m['sender_name'] = $m['from_mid'] == 0 ? '系统' : ($nicknames[$m['from_mid']] ?? '未知');
            }

            dc_jsonResponse(true, $msgs, '');
            break;

        case 'act_poll':
            $dc_uid = dc_checkLogin();
            $last_dm_id = intval($_POST['last_dm_id'] ?? 0);
            $last_rm_id = intval($_POST['last_rm_id'] ?? 0);

            $db = dc_getDB();

            $new_dm = [];
            if ($last_dm_id > 0) {
                $stmt = $db->prepare("SELECT msgid, from_mid, to_mid, mcontent, mtype, mread, ctime FROM dc_direct_msgs WHERE (from_mid = :uid OR to_mid = :uid) AND msgid > :last_dm_id ORDER BY msgid ASC LIMIT 50");
                $stmt->execute([':uid' => $dc_uid, ':last_dm_id' => $last_dm_id]);
                $new_dm = $stmt->fetchAll();
            }

            $all_uids = [];
            foreach ($new_dm as $m) {
                $all_uids[$m['from_mid']] = 1;
                $all_uids[$m['to_mid']] = 1;
            }
            $nicknames = [];
            if (!empty($all_uids)) {
                $uids = array_keys($all_uids);
                $placeholders = implode(',', array_fill(0, count($uids), '?'));
                $stmt = $db->prepare("SELECT mid, ndisplay FROM dc_members WHERE mid IN ($placeholders)");
                $stmt->execute(array_values($uids));
                while ($row = $stmt->fetch()) {
                    $nicknames[$row['mid']] = $row['ndisplay'];
                }
            }

            foreach ($new_dm as &$m) {
                $m['sender_name'] = $nicknames[$m['from_mid']] ?? '未知';
            }

            $new_rm = [];
            if ($last_rm_id > 0) {
                $stmt = $db->prepare("SELECT rid FROM dc_room_users WHERE mid = :uid");
                $stmt->execute([':uid' => $dc_uid]);
                $rids = [];
                while ($row = $stmt->fetch()) {
                    $rids[] = intval($row['rid']);
                }

                if (!empty($rids)) {
                    $placeholders = implode(',', array_fill(0, count($rids), '?'));
                    $params = array_merge($rids, [$last_rm_id]);
                    $stmt = $db->prepare("SELECT msgid, rid, from_mid, mcontent, mtype, ctime FROM dc_room_msgs WHERE rid IN ($placeholders) AND msgid > ? ORDER BY msgid ASC LIMIT 50");
                    $stmt->execute($params);
                    $new_rm = $stmt->fetchAll();
                }
            }

            foreach ($new_rm as &$m) {
                $all_uids[$m['from_mid']] = 1;
            }
            if (!empty($all_uids)) {
                $uids = array_keys($all_uids);
                $placeholders = implode(',', array_fill(0, count($uids), '?'));
                $stmt = $db->prepare("SELECT mid, ndisplay FROM dc_members WHERE mid IN ($placeholders)");
                $stmt->execute(array_values($uids));
                while ($row = $stmt->fetch()) {
                    $nicknames[$row['mid']] = $row['ndisplay'];
                }
            }

            foreach ($new_rm as &$m) {
                $m['sender_name'] = $nicknames[$m['from_mid']] ?? '未知';
            }

            dc_jsonResponse(true, array('dm' => $new_dm, 'rm' => $new_rm), '');
            break;

        case 'act_room_join':
            $dc_uid = dc_checkLogin();
            $rid = intval($_POST['rid'] ?? 0);
            if ($rid <= 0) dc_jsonResponse(false, [], '参数错误');

            $db = dc_getDB();

            $stmt = $db->prepare("SELECT COUNT(*) AS cnt FROM dc_room_users WHERE rid = :rid AND mid = :mid");
            $stmt->execute([':rid' => $rid, ':mid' => $dc_uid]);
            $row = $stmt->fetch();
            if ($row && $row['cnt'] > 0) {
                dc_jsonResponse(false, [], '已经是群成员');
            }

            $stmt = $db->prepare("SELECT rstate FROM dc_rooms WHERE rid = :rid");
            $stmt->execute([':rid' => $rid]);
            $room = $stmt->fetch();
            if (!$room || $room['rstate'] == 0) {
                dc_jsonResponse(false, [], '群聊不存在或已解散');
            }

            $stmt = $db->prepare("INSERT OR IGNORE INTO dc_room_users (rid, mid, urole) VALUES (:rid, :mid, 'member')");
            $stmt->execute([':rid' => $rid, ':mid' => $dc_uid]);

            $stmt = $db->prepare("UPDATE dc_rooms SET rmcount = rmcount + 1 WHERE rid = :rid");
            $stmt->execute([':rid' => $rid]);

            $stmt = $db->prepare("SELECT ndisplay FROM dc_members WHERE mid = :mid");
            $stmt->execute([':mid' => $dc_uid]);
            $row = $stmt->fetch();
            if ($row) {
                $nickname = $row['ndisplay'] ?? '未知';
                $sys_msg = $nickname . ' 加入了群聊';

                $stmt = $db->prepare("INSERT INTO dc_room_msgs (rid, from_mid, mcontent, mtype) VALUES (:rid, 0, :mcontent, 'system')");
                $stmt->execute([':rid' => $rid, ':mcontent' => $sys_msg]);
            }

            dc_jsonResponse(true, [], '加入群聊成功');
            break;

        case 'act_room_create':
            $dc_uid = dc_checkLogin();
            $rname = dc_cleanInput($_POST['rname'] ?? '');
            if (empty($rname) || mb_strlen($rname) > 100) dc_jsonResponse(false, [], '群名称1-100个字符');

            $db = dc_getDB();

            $stmt = $db->prepare("INSERT INTO dc_rooms (rname, rcreator, rmcount) VALUES (:rname, :rcreator, 1)");
            $stmt->execute([':rname' => $rname, ':rcreator' => $dc_uid]);
            $rid = (int)$db->lastInsertId();

            $stmt = $db->prepare("INSERT INTO dc_room_users (rid, mid, urole) VALUES (:rid, :mid, 'owner')");
            $stmt->execute([':rid' => $rid, ':mid' => $dc_uid]);

            $stmt = $db->prepare("SELECT ndisplay FROM dc_members WHERE mid = :mid");
            $stmt->execute([':mid' => $dc_uid]);
            $row = $stmt->fetch();
            if ($row) {
                $nickname = $row['ndisplay'] ?? '未知';
                $sys_msg = $nickname . ' 创建了群聊';

                $stmt = $db->prepare("INSERT INTO dc_room_msgs (rid, from_mid, mcontent, mtype) VALUES (:rid, 0, :mcontent, 'system')");
                $stmt->execute([':rid' => $rid, ':mcontent' => $sys_msg]);
            }

            dc_jsonResponse(true, ['rid' => $rid], '群聊创建成功');
            break;

        case 'act_logout':
            session_destroy();
            dc_jsonResponse(true, [], '已退出');
            break;

        case 'act_contacts':
            $dc_uid = dc_checkLogin();
            $db = dc_getDB();

            $stmt = $db->prepare("SELECT fmid, fremark FROM dc_contacts WHERE mid = :mid AND cstate = 1");
            $stmt->execute([':mid' => $dc_uid]);
            $fRows = $stmt->fetchAll();

            $fIds = [];
            $friendRemarks = [];
            foreach ($fRows as $row) {
                $fIds[] = intval($row['fmid']);
                $friendRemarks[intval($row['fmid'])] = $row['fremark'] ?? '';
            }
            $contacts = [];
            if (!empty($fIds)) {
                $placeholders = implode(',', array_fill(0, count($fIds), '?'));
                $stmt = $db->prepare("SELECT mid, uname, ndisplay FROM dc_members WHERE mid IN ($placeholders) AND ustate = 1");
                $stmt->execute(array_values($fIds));
                while ($row = $stmt->fetch()) {
                    $row['fremark'] = $friendRemarks[intval($row['mid'])] ?? '';
                    $contacts[] = $row;
                }
            }
            dc_jsonResponse(true, $contacts, '');
            break;

        case 'act_my_rooms':
            $dc_uid = dc_checkLogin();
            $db = dc_getDB();

            $stmt = $db->prepare("SELECT r.rid, r.rname, r.rcreator, r.rnotice, r.rmcount, r.rstate, ru.urole FROM dc_rooms r INNER JOIN dc_room_users ru ON r.rid = ru.rid WHERE ru.mid = :mid AND r.rstate = 1 ORDER BY r.rid DESC");
            $stmt->execute([':mid' => $dc_uid]);
            $rooms = $stmt->fetchAll();
            dc_jsonResponse(true, $rooms, '');
            break;

        case 'act_all_rooms':
            $dc_uid = dc_checkLogin();
            $db = dc_getDB();

            $stmt = $db->query("SELECT rid, rname, rmcount FROM dc_rooms WHERE rstate = 1 ORDER BY rid DESC LIMIT 100");
            $rooms = $stmt->fetchAll();
            dc_jsonResponse(true, $rooms, '');
            break;

        case 'act_all_members':
            $dc_uid = dc_checkLogin();
            $db = dc_getDB();

            $stmt = $db->prepare("SELECT mid, uname, ndisplay FROM dc_members WHERE mid != :mid AND ustate = 1 LIMIT 100");
            $stmt->execute([':mid' => $dc_uid]);
            $members = $stmt->fetchAll();
            dc_jsonResponse(true, $members, '');
            break;

        default:
            dc_jsonResponse(false, array(), '未知操作');
    }
    } catch (Exception $e) {
        echo json_encode(array('success' => false, 'data' => array(), 'msg' => '服务器错误: ' . $e->getMessage()), JSON_UNESCAPED_UNICODE);
    }
    exit;
}




try {
    $db = dc_getDB();
} catch (PDOException $e) {
    $db = null;
}


$userRow = null;
$dc_nick_display = '';
$dc_uname_display = '';
if ($db) {
    try {
        $stmt = $db->prepare("SELECT mid, uname, ndisplay FROM dc_members WHERE mid = :mid");
        $stmt->execute([':mid' => $dc_uid]);
        $row = $stmt->fetch();
        if ($row) {
            $userRow = $row;
            $dc_nick_display = $row['ndisplay'] ?: $row['uname'];
            $dc_uname_display = $row['uname'];
        } else {
        }
    } catch (PDOException $e) {
    }
}


$friends = [];
if ($db) {
    try {
        $stmt = $db->prepare("SELECT fmid, fremark FROM dc_contacts WHERE mid = :mid AND cstate = 1");
        $stmt->execute([':mid' => $dc_uid]);
        $fRows = $stmt->fetchAll();

        $fIds = [];
        $friendRemarks = [];
        foreach ($fRows as $row) {
            $fIds[] = intval($row['fmid']);
            $friendRemarks[intval($row['fmid'])] = $row['fremark'] ?? '';
        }
        if (!empty($fIds)) {
            $placeholders = implode(',', array_fill(0, count($fIds), '?'));
            $stmt = $db->prepare("SELECT mid, uname, ndisplay FROM dc_members WHERE mid IN ($placeholders) AND ustate = 1");
            $stmt->execute(array_values($fIds));
            while ($row = $stmt->fetch()) {
                $row['fremark'] = $friendRemarks[intval($row['mid'])] ?? '';
                $friends[] = $row;
            }
        }
    } catch (PDOException $e) {
    }
}


$myRooms = [];
if ($db) {
    try {
        $stmt = $db->prepare("SELECT r.rid, r.rname, r.rcreator, r.rnotice, r.rmcount, r.rstate, ru.urole FROM dc_rooms r INNER JOIN dc_room_users ru ON r.rid = ru.rid WHERE ru.mid = :mid AND r.rstate = 1 ORDER BY r.rid DESC");
        $stmt->execute([':mid' => $dc_uid]);
        $myRooms = $stmt->fetchAll();
    } catch (PDOException $e) {
    }
}


$allRooms = [];
if ($db) {
    try {
        $stmt = $db->query("SELECT rid, rname, rmcount FROM dc_rooms WHERE rstate = 1 ORDER BY rid DESC LIMIT 100");
        $allRooms = $stmt->fetchAll();
    } catch (PDOException $e) {
    }
}


$allMembers = [];
if ($db) {
    try {
        $stmt = $db->prepare("SELECT mid, uname, ndisplay FROM dc_members WHERE mid != :mid AND ustate = 1 LIMIT 100");
        $stmt->execute([':mid' => $dc_uid]);
        $allMembers = $stmt->fetchAll();
    } catch (PDOException $e) {
    }
}


$roomMembersMap = [];
if ($db && !empty($myRooms)) {
    try {
        $myRoomIds = [];
        foreach ($myRooms as $mg) $myRoomIds[] = intval($mg['rid']);
        $placeholders = implode(',', array_fill(0, count($myRoomIds), '?'));
        $stmt = $db->prepare("SELECT rid, mid, urole FROM dc_room_users WHERE rid IN ($placeholders)");
        $stmt->execute(array_values($myRoomIds));
        $gmData = $stmt->fetchAll();

        $allMemberUids = [];
        foreach ($gmData as $row) {
            $allMemberUids[intval($row['mid'])] = 1;
        }

        if (!empty($allMemberUids)) {
            $mUids = array_keys($allMemberUids);
            $mPlaceholders = implode(',', array_fill(0, count($mUids), '?'));
            $stmt = $db->prepare("SELECT mid, uname, ndisplay FROM dc_members WHERE mid IN ($mPlaceholders)");
            $stmt->execute(array_values($mUids));
            $userInfoMap = [];
            while ($row = $stmt->fetch()) {
                $userInfoMap[intval($row['mid'])] = $row;
            }

            foreach ($gmData as $gm) {
                $gid = intval($gm['rid']);
                $mid = intval($gm['mid']);
                $roomMembersMap[$gid][] = [
                    'mid' => $mid,
                    'urole' => $gm['urole'],
                    'user_info' => $userInfoMap[$mid] ?? ['mid' => $mid, 'uname' => '未知', 'ndisplay' => '未知']
                ];
            }
        }
    } catch (PDOException $e) {
    }
}

?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>DOGECHAT</title>
    <style>
        *{margin:0;padding:0;box-sizing:border-box;}
        html,body{width:100%;height:100%;font-family:-apple-system,BlinkMacSystemFont,"Helvetica Neue","PingFang SC","Microsoft YaHei",sans-serif;font-size:14px;color:#333;background:#ededed;overflow:hidden;}
        input,textarea,select{font-family:inherit;font-size:inherit;border:none;outline:none;border-radius:0;}
        a{color:#576b95;text-decoration:none;}
        #app{width:100%;height:100%;max-width:500px;margin:0 auto;position:relative;overflow:hidden;background:#ededed;}
        .sidebar{position:absolute;top:0;left:0;width:100%;height:100%;background:#ededed;display:-webkit-box;-webkit-box-orient:vertical;display:-webkit-flex;-webkit-flex-direction:column;display:flex;flex-direction:column;z-index:10;}
        .sidebar-header{height:50px;padding:0 15px;background:#ededed;display:-webkit-box;-webkit-box-align:center;-webkit-box-pack:justify;display:-webkit-flex;-webkit-align-items:center;-webkit-justify-content:space-between;display:flex;align-items:center;justify-content:space-between;border-bottom:1px solid #d9d9d9;}
        .header-title{font-size:17px;font-weight:600;color:#000;}
        .header-actions{display:-webkit-box;-webkit-box-align:center;display:-webkit-flex;-webkit-align-items:center;display:flex;align-items:center;}
        .icon-btn{width:44px;height:44px;display:-webkit-box;-webkit-box-align:center;-webkit-box-pack:center;display:-webkit-flex;-webkit-align-items:center;-webkit-justify-content:center;display:flex;align-items:center;justify-content:center;font-size:22px;color:#333;cursor:pointer;border-radius:4px;border:none;background:none;padding:0;}
        .icon-btn:active{background:rgba(0,0,0,0.05);}
        .sidebar-tabs{height:40px;display:-webkit-box;display:-webkit-flex;display:flex;background:#f7f7f7;border-bottom:1px solid #d9d9d9;}
        .sidebar-tabs .tab{-webkit-box-flex:1;-webkit-flex:1;flex:1;display:-webkit-box;-webkit-box-align:center;-webkit-box-pack:center;display:-webkit-flex;-webkit-align-items:center;-webkit-justify-content:center;display:flex;align-items:center;justify-content:center;font-size:14px;color:#666;cursor:pointer;border-bottom:2px solid transparent;}
        .sidebar-tabs .tab.active{color:#07c160;border-bottom-color:#07c160;}
        .sidebar-content{-webkit-box-flex:1;-webkit-flex:1;flex:1;overflow-y:auto;-webkit-overflow-scrolling:touch;display:none;}
        .sidebar-content.active{display:block;}
        .section-title{padding:8px 15px;font-size:12px;color:#999;background:#ededed;}
        .empty-tip{text-align:center;padding:60px 20px;color:#ccc;font-size:14px;}
        .chat-item{position:relative;display:-webkit-box;-webkit-box-align:center;display:-webkit-flex;-webkit-align-items:center;display:flex;align-items:center;padding:12px 15px;background:#fff;border-bottom:1px solid #f0f0f0;cursor:pointer;}
        .chat-item:active{background:#ececec;}
        .chat-item-badge{position:absolute;top:6px;right:10px;background:#f44;width:10px;height:10px;border-radius:50%;}
        .chat-item-avatar{width:44px;height:44px;border-radius:6px;background:#07c160;color:#fff;display:-webkit-box;-webkit-box-align:center;-webkit-box-pack:center;display:-webkit-flex;-webkit-align-items:center;-webkit-justify-content:center;display:flex;align-items:center;justify-content:center;font-size:18px;margin-right:12px;-webkit-flex-shrink:0;flex-shrink:0;}
        .chat-item-info{-webkit-box-flex:1;-webkit-flex:1;flex:1;min-width:0;}
        .chat-item-name{font-size:15px;color:#333;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
        .chat-item-msg{font-size:12px;color:#999;margin-top:3px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
        .chat-area{position:absolute;top:0;left:0;width:100%;height:100%;background:#ededed;display:-webkit-box;-webkit-box-orient:vertical;display:-webkit-flex;-webkit-flex-direction:column;display:flex;flex-direction:column;z-index:20;}
        .chat-header{height:50px;padding:0 10px;background:#ededed;display:-webkit-box;-webkit-box-align:center;display:-webkit-flex;-webkit-align-items:center;display:flex;align-items:center;border-bottom:1px solid #d9d9d9;-webkit-flex-shrink:0;flex-shrink:0;}
        .chat-title-area{-webkit-box-flex:1;-webkit-flex:1;flex:1;text-align:center;min-width:0;}
        .chat-title{font-size:17px;font-weight:600;color:#000;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
        .chat-subtitle{font-size:11px;color:#999;}
        .message-list{-webkit-box-flex:1;-webkit-flex:1;flex:1;overflow-y:auto;-webkit-overflow-scrolling:touch;padding:10px 0;background:#ededed;}
        .load-more{text-align:center;padding:10px;font-size:13px;color:#576b95;cursor:pointer;}
        .load-more:active{background:#ddd;}
        .msg-row{display:-webkit-box;-webkit-box-align:start;display:-webkit-flex;-webkit-align-items:flex-start;display:flex;margin-bottom:12px;padding:0 10px;align-items:flex-start;}
        .msg-row.self{-webkit-box-direction:reverse;-webkit-flex-direction:row-reverse;flex-direction:row-reverse;}
        .msg-avatar{width:38px;height:38px;border-radius:4px;background:#07c160;color:#fff;display:-webkit-box;-webkit-box-align:center;-webkit-box-pack:center;display:-webkit-flex;-webkit-align-items:center;-webkit-justify-content:center;display:flex;align-items:center;justify-content:center;font-size:15px;-webkit-flex-shrink:0;flex-shrink:0;}
        .msg-row.self .msg-avatar{background:#576b95;}
        .msg-body{max-width:65%;margin:0 8px;}
        .msg-name{font-size:11px;color:#999;margin-bottom:3px;}
        .msg-row.self .msg-name{text-align:right;}
        .msg-bubble{padding:9px 12px;border-radius:4px;font-size:15px;line-height:1.5;word-break:break-all;}
        .msg-row:not(.self) .msg-bubble{background:#fff;color:#333;}
        .msg-row.self .msg-bubble{background:#95ec69;color:#000;}
        .msg-bubble.system-msg{background:transparent;color:#999;font-size:12px;text-align:center;padding:4px 0;max-width:100%;margin:0 auto;}
        .msg-time{font-size:10px;color:#bbb;margin-top:3px;}
        .msg-row.self .msg-time{text-align:right;}
        .input-area{background:#f7f7f7;border-top:1px solid #d9d9d9;padding:8px 10px;-webkit-flex-shrink:0;flex-shrink:0;}
        .input-row{display:-webkit-box;-webkit-box-align:end;display:-webkit-flex;-webkit-align-items:end;display:flex;align-items:end;}
        .input-row textarea{-webkit-box-flex:1;-webkit-flex:1;flex:1;height:36px;max-height:100px;padding:6px 10px;background:#fff;border:1px solid #ddd;border-radius:4px;font-size:15px;line-height:1.4;resize:none;overflow-y:auto;}
        .btn-send{height:36px;line-height:36px;padding:0 14px;margin-left:8px;background:#07c160;color:#fff;font-size:14px;font-weight:500;border-radius:4px;cursor:pointer;-webkit-flex-shrink:0;flex-shrink:0;border:none;}
        .btn-send:active{background:#06ad56;}
        .welcome-area{position:absolute;top:0;left:0;width:100%;height:100%;display:-webkit-box;-webkit-box-orient:vertical;-webkit-box-align:center;-webkit-box-pack:center;display:-webkit-flex;-webkit-flex-direction:column;-webkit-align-items:center;-webkit-justify-content:center;display:flex;flex-direction:column;align-items:center;justify-content:center;background:#ededed;z-index:5;}
        .welcome-area p{color:#999;font-size:14px;}
        .modal-overlay{position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.4);z-index:100;}
        .modal-panel{position:fixed;bottom:0;left:50%;-webkit-transform:translateX(-50%);transform:translateX(-50%);width:100%;max-width:500px;background:#fff;border-radius:12px 12px 0 0;z-index:101;max-height:70%;overflow-y:auto;}
        .modal-header{height:48px;display:-webkit-box;-webkit-box-align:center;-webkit-box-pack:center;display:-webkit-flex;-webkit-align-items:center;-webkit-justify-content:center;display:flex;align-items:center;justify-content:center;border-bottom:1px solid #eee;font-size:16px;font-weight:500;position:relative;}
        .modal-close{position:absolute;right:15px;font-size:18px;color:#999;cursor:pointer;width:44px;height:44px;display:-webkit-box;-webkit-box-align:center;-webkit-box-pack:center;display:-webkit-flex;-webkit-align-items:center;-webkit-justify-content:center;display:flex;align-items:center;justify-content:center;border:none;background:none;padding:0;}
        .modal-body{padding:15px;}
        .form-group{margin-bottom:15px;}
        .form-group label{display:block;font-size:13px;color:#666;margin-bottom:5px;}
        .form-group input{width:100%;height:40px;padding:0 10px;border:1px solid #ddd;border-radius:4px;font-size:14px;background:#fff;}
        .btn-primary{display:block;width:100%;height:44px;line-height:44px;text-align:center;background:#07c160;color:#fff;font-size:16px;font-weight:500;border-radius:6px;cursor:pointer;border:none;padding:0;margin-top:10px;}
        .btn-primary:active{background:#06ad56;}
        .btn-danger{display:block;width:100%;height:44px;line-height:44px;text-align:center;background:#fa5151;color:#fff;font-size:16px;border-radius:6px;cursor:pointer;border:none;padding:0;margin-top:15px;}
        .btn-danger:active{background:#e04545;}
        .slide-panel{position:fixed;top:0;right:0;width:100%;max-width:320px;height:100%;background:#ededed;z-index:200;display:-webkit-box;-webkit-box-orient:vertical;display:-webkit-flex;-webkit-flex-direction:column;display:flex;flex-direction:column;}
        .slide-panel-header{height:50px;display:-webkit-box;-webkit-box-align:center;display:-webkit-flex;-webkit-align-items:center;display:flex;align-items:center;padding:0 10px;background:#ededed;border-bottom:1px solid #d9d9d9;font-size:16px;font-weight:500;}
        .slide-panel-body{-webkit-box-flex:1;-webkit-flex:1;flex:1;overflow-y:auto;-webkit-overflow-scrolling:touch;}
        .profile-info{padding:20px 15px;}
        .profile-field{margin-bottom:15px;}
        .profile-field label{font-size:12px;color:#999;margin-bottom:4px;display:block;}
        .profile-field div,.profile-field input{font-size:15px;color:#333;padding:8px 0;border-bottom:1px solid #eee;}
        .profile-field input{width:100%;}
        .info-section{background:#fff;margin-bottom:10px;}
        .info-section-title{padding:8px 15px;font-size:12px;color:#999;}
        .info-member-item{display:-webkit-box;-webkit-box-align:center;display:-webkit-flex;-webkit-align-items:center;display:flex;align-items:center;padding:10px 15px;border-bottom:1px solid #f5f5f5;}
        .info-member-avatar{width:36px;height:36px;border-radius:4px;background:#07c160;color:#fff;display:-webkit-box;-webkit-box-align:center;-webkit-box-pack:center;display:-webkit-flex;-webkit-align-items:center;-webkit-justify-content:center;display:flex;align-items:center;justify-content:center;font-size:14px;margin-right:10px;-webkit-flex-shrink:0;flex-shrink:0;}
        .info-member-name{-webkit-box-flex:1;-webkit-flex:1;flex:1;font-size:14px;color:#333;}
        .info-member-role{font-size:12px;color:#999;margin-left:8px;}
        .info-action-item{display:-webkit-box;-webkit-box-align:center;display:-webkit-flex;-webkit-align-items:center;display:flex;align-items:center;padding:14px 15px;background:#fff;border-bottom:1px solid #f5f5f5;cursor:pointer;font-size:14px;color:#333;}
        .info-action-item:active{background:#ececec;}
        .info-action-item.danger{color:#fa5151;}
        .info-input-group{padding:10px 15px;background:#fff;border-bottom:1px solid #f5f5f5;}
        .info-input-group input{width:100%;height:36px;padding:0 8px;border:1px solid #ddd;border-radius:4px;font-size:14px;}
        .info-input-btns{display:-webkit-box;display:-webkit-flex;display:flex;margin-top:8px;}
        .info-input-btns button{-webkit-box-flex:1;-webkit-flex:1;flex:1;height:34px;line-height:34px;text-align:center;margin-right:8px;background:#07c160;color:#fff;border-radius:4px;font-size:13px;cursor:pointer;border:none;padding:0;}
        .info-input-btns button:last-child{margin-right:0;background:#999;}
        .btn-sm{display:inline-block;padding:4px 8px;font-size:11px;background:#07c160;color:#fff;border-radius:3px;cursor:pointer;margin-right:4px;border:none;}
        .toast{position:fixed;top:50%;left:50%;-webkit-transform:translate(-50%,-50%);transform:translate(-50%);background:rgba(0,0,0,0.7);color:#fff;padding:12px 24px;border-radius:6px;font-size:14px;z-index:9999;text-align:center;max-width:250px;display:none;}
        .chat-item.selected{background:#07c160;}
        .chat-item.selected .chat-item-name{color:#fff;}
        .chat-item.selected .chat-item-msg{color:rgba(255,255,255,0.8);}
        @media screen and (min-width:501px){
            #app{max-width:900px;display:-webkit-box;display:-webkit-flex;display:flex;}
            .sidebar{position:relative;width:300px;-webkit-flex-shrink:0;flex-shrink:0;border-right:1px solid #d9d9d9;display:-webkit-box!important;display:-webkit-flex!important;display:flex!important;}
            .chat-area{position:relative;-webkit-box-flex:1;-webkit-flex:1;flex:1;display:none!important;}
            .chat-area.pc-show{display:-webkit-box!important;display:-webkit-flex!important;display:flex!important;}
            .welcome-area{position:relative;-webkit-box-flex:1;-webkit-flex:1;flex:1;display:none!important;}
            .welcome-area.pc-show{display:-webkit-box!important;display:-webkit-flex!important;display:flex!important;}
            .chat-area .chat-header .icon-btn.back-btn{display:none;}
            .slide-panel{max-width:300px;}
        }
    </style>
</head>
<body>
    <div id="app">
        <div id="sidebar" class="sidebar">
            <div class="sidebar-header">
                <span class="header-title">DOGECHAT</span>
                <div class="header-actions">
                    <button type="button" class="icon-btn" onclick="dcShowPanel('createGroupPanel')" title="创建群聊" style="font-size:18px;">+</button>
                    <button type="button" class="icon-btn" onclick="document.getElementById('profilePanel').style.display='flex'" title="设置" style="font-size:22px;">&#9881;</button>
                </div>
            </div>
            <div class="sidebar-tabs">
                <div class="tab active" onclick="dcSwitchTab('chats')">消息</div>
                <div class="tab" onclick="dcSwitchTab('groups')">群聊</div>
                <div class="tab" onclick="dcSwitchTab('contacts')">通讯录</div>
            </div>
            <div id="chatList" class="sidebar-content active"><div class="empty-tip">暂无消息</div></div>
            <div id="groupList" class="sidebar-content">
                <div class="section-title">我的群聊</div>
                <div id="myRoomList"></div>
                <div class="section-title">全部群聊（点击加入）</div>
                <div id="allRoomList"></div>
            </div>
            <div id="contactList" class="sidebar-content">
                <div id="memberList"></div>
                <div class="section-title">我的好友</div>
                <div id="friendList"></div>
            </div>
        </div>

        <div id="chatArea" class="chat-area" style="display:none;">
            <div class="chat-header">
                <button type="button" class="icon-btn back-btn" onclick="dcGoBack()">&#8592;</button>
                <div class="chat-title-area">
                    <div class="chat-title" id="chatTitle">聊天</div>
                    <div class="chat-subtitle" id="chatSubtitle"></div>
                </div>
                <button type="button" class="icon-btn" onclick="dcShowChatInfo()">&#8943;</button>
            </div>
            <div id="messageList" class="message-list">
                <div id="loadMoreBtn" class="load-more" style="display:none;" onclick="dcLoadMoreMsgs()">点击获取更多消息</div>
                <div id="msgContainer"></div>
            </div>
            <div class="input-area">
                <div class="input-row">
                    <textarea id="msgInput" placeholder="输入消息..." rows="1" onkeydown="if(event.keyCode===13&&!event.shiftKey){event.preventDefault();dcSend();}"></textarea>
                    <button type="button" class="btn-send" onclick="dcSend()">发送</button>
                </div>
            </div>
        </div>

        <div id="welcomeArea" class="welcome-area pc-show"><p>欢迎使用 DOGECHAT</p></div>
    </div>

    <div id="modalOverlay" class="modal-overlay" style="display:none;" onclick="dcHidePanels()"></div>
    <div id="createGroupPanel" class="modal-panel" style="display:none;">
        <div class="modal-header"><span>创建群聊</span><button type="button" class="modal-close" onclick="dcHidePanels()">&#10005;</button></div>
        <div class="modal-body">
            <div class="form-group"><label>群名称</label><input type="text" id="newRoomName" placeholder="请输入群名称" maxlength="100"></div>
            <button type="button" class="btn-primary" onclick="dcCreateRoom()">创建</button>
        </div>
    </div>
    <div id="chatInfoPanel" class="slide-panel" style="display:none;">
        <div class="slide-panel-header"><button type="button" class="icon-btn" onclick="document.getElementById('chatInfoPanel').style.display='none'">&#8592;</button><span id="chatInfoTitle">聊天信息</span></div>
        <div class="slide-panel-body" id="chatInfoBody"></div>
    </div>
    <div id="profilePanel" class="slide-panel" style="display:none;">
        <div class="slide-panel-header"><button type="button" class="icon-btn" onclick="document.getElementById('profilePanel').style.display='none'">&#8592;</button><span>设置</span></div>
        <div class="slide-panel-body">
            <div class="profile-info">
                <div class="profile-field"><label>昵称</label><input type="text" id="profileNickname" maxlength="50" value="<?php echo htmlspecialchars($dc_nick_display); ?>"></div>
                <button type="button" class="btn-primary" onclick="dcSaveNickname()">保存昵称</button>
                <div style="height:20px;"></div>
                <div class="profile-field"><label>旧密码</label><input type="password" id="oldPassword" placeholder="输入旧密码"></div>
                <div class="profile-field"><label>新密码</label><input type="password" id="newPassword" placeholder="输入新密码"></div>
                <div class="profile-field"><label>确认新密码</label><input type="password" id="confirmPassword" placeholder="再次输入新密码"></div>
                <button type="button" class="btn-primary" style="background:#576b95;" onclick="dcChangePassword()">修改密码</button>
                <button type="button" class="btn-danger" onclick="dcLogout()">退出登录</button>
            </div>
        </div>
    </div>
    <div id="toast" class="toast"></div>

    <script>
var DC = {userId:<?php echo intval($dc_uid); ?>, nickname:<?php echo json_encode($dc_nick_display ?: '', JSON_UNESCAPED_UNICODE); ?>, currentChat:null, messages:[], lastDmId:0, lastRmId:0, pollTimer:null, hasMore:true, isLoadingMore:false, unreadCounts:{}, pollStarted:false, sending:false};


var PHP_FRIENDS = <?php echo json_encode($friends, JSON_UNESCAPED_UNICODE); ?>;
var PHP_MY_ROOMS = <?php echo json_encode($myRooms, JSON_UNESCAPED_UNICODE); ?>;
var PHP_ALL_ROOMS = <?php echo json_encode($allRooms, JSON_UNESCAPED_UNICODE); ?>;
var PHP_ALL_MEMBERS = <?php echo json_encode($allMembers, JSON_UNESCAPED_UNICODE); ?>;
var PHP_MY_ROOM_IDS = {};
<?php foreach($myRooms as $r): ?>PHP_MY_ROOM_IDS[<?php echo $r['rid']; ?>]=true;<?php endforeach; ?>

var PHP_ROOM_MEMBERS = <?php echo json_encode($roomMembersMap, JSON_UNESCAPED_UNICODE); ?>;

function $(id){return document.getElementById(id);}
function dcToast(msg){var el=$('toast');el.textContent=msg;el.style.display='block';setTimeout(function(){el.style.display='none';},2000);}
function dcEsc(s){if(!s)return'';var d=document.createElement('div');d.appendChild(document.createTextNode(s));return d.innerHTML;}
function dcNameColor(name){var colors=['#e74c3c','#e67e22','#f1c40f','#2ecc71','#1abc9c','#3498db','#9b59b6','#e91e63','#00bcd4','#ff5722','#795548','#607d8b'];var h=0;for(var i=0;i<name.length;i++)h=((h<<5)-h)+name.charCodeAt(i);return colors[Math.abs(h)%colors.length];}
function dcFormatTime(ds){if(!ds)return'';var d=new Date(ds),n=new Date(),h=d.getHours(),m=d.getMinutes(),t=(h<10?'0':'')+h+':'+(m<10?'0':'')+m;if(d.toDateString()===n.toDateString())return t;return(d.getMonth()+1)+'/'+d.getDate()+' '+t;}

function dcPost(act,data,cb){
    var xhr;if(window.XMLHttpRequest){xhr=new XMLHttpRequest();}else{xhr=new ActiveXObject('Microsoft.XMLHTTP');}
    xhr.open('POST','chat.php',true);xhr.setRequestHeader('Content-Type','application/x-www-form-urlencoded');
    xhr.onreadystatechange=function(){if(xhr.readyState===4){var r;try{r=JSON.parse(xhr.responseText);}catch(e){r={success:false,msg:'服务器返回错误(非JSON): '+xhr.responseText.substring(0,200)};console.log('AJAX Error:',xhr.status,xhr.responseText.substring(0,500));}cb(r);}};
    var p='act='+encodeURIComponent(act);for(var k in data){if(data.hasOwnProperty(k))p+='&'+encodeURIComponent(k)+'='+encodeURIComponent(data[k]);}xhr.send(p);
}

function dcShowPanel(id){$(id).style.display='block';$('modalOverlay').style.display='block';}
function dcHidePanels(){var ps=['createGroupPanel','chatInfoPanel','profilePanel'];for(var i=0;i<ps.length;i++)$(ps[i]).style.display='none';$('modalOverlay').style.display='none';}

function dcSwitchTab(name){
    var tabs=document.querySelectorAll('.sidebar-tabs .tab'),contents=document.querySelectorAll('.sidebar-content'),map={chats:0,groups:1,contacts:2};
    for(var i=0;i<tabs.length;i++){tabs[i].className=(i===map[name])?'tab active':'tab';contents[i].className=(i===map[name])?'sidebar-content active':'sidebar-content';}
}

function dcUpdateBadges(){
    var items=document.querySelectorAll('.chat-item[data-chat-key]');
    for(var i=0;i<items.length;i++){
        var key=items[i].getAttribute('data-chat-key');
        var count=DC.unreadCounts[key]||0;
        var badge=items[i].querySelector('.chat-item-badge');
        if(count>0){
            if(!badge){badge=document.createElement('span');badge.className='chat-item-badge';items[i].appendChild(badge);}
        }else if(badge){badge.parentNode.removeChild(badge);}
    }
}

function dcInitPage(){

    var fhtml='';
    for(var i=0;i<PHP_FRIENDS.length;i++){var f=PHP_FRIENDS[i],rn=f.fremark||(f.ndisplay||f.uname),n=f.ndisplay||f.uname;fhtml+='<div class="chat-item" data-chat-key="private_'+f.mid+'" onclick="dcOpenDM('+f.mid+',\''+dcEsc(n).replace(/'/g,"\\'")+'\',\''+dcEsc(f.fremark||'').replace(/'/g,"\\'")+'\',this)"><div class="chat-item-avatar">'+dcEsc(rn.charAt(0))+'</div><div class="chat-item-info"><div class="chat-item-name">'+dcEsc(rn)+'</div></div></div>';}
    $('friendList').innerHTML=fhtml||'<div class="empty-tip">暂无好友</div>';


    var friendIds={};
    for(var fi=0;fi<PHP_FRIENDS.length;fi++)friendIds[PHP_FRIENDS[fi].mid]=true;
    var nonFriendMembers=[];
    for(var ui=0;ui<PHP_ALL_MEMBERS.length;ui++){if(!friendIds[PHP_ALL_MEMBERS[ui].mid])nonFriendMembers.push(PHP_ALL_MEMBERS[ui]);}
    dcRenderMemberList(nonFriendMembers);


    var ghtml='';
    for(var j=0;j<PHP_MY_ROOMS.length;j++){var g=PHP_MY_ROOMS[j];ghtml+='<div class="chat-item" data-chat-key="group_'+g.rid+'" onclick="dcOpenRoom('+g.rid+',\''+dcEsc(g.rname).replace(/'/g,"\\'")+'\',\''+g.urole+'\',this)"><div class="chat-item-avatar" style="background:'+dcNameColor(g.rname)+';color:#fff;">'+dcEsc(g.rname.charAt(0))+'</div><div class="chat-item-info"><div class="chat-item-name">'+dcEsc(g.rname)+'</div><div class="chat-item-msg">'+g.rmcount+'人</div></div></div>';}
    $('myRoomList').innerHTML=ghtml||'<div class="empty-tip">暂无群聊</div>';


    var ahtml='';
    for(var k=0;k<PHP_ALL_ROOMS.length;k++){var ag=PHP_ALL_ROOMS[k];if(!PHP_MY_ROOM_IDS[ag.rid]){ahtml+='<div class="chat-item" data-chat-key="group_'+ag.rid+'" onclick="dcJoinRoom('+ag.rid+')"><div class="chat-item-avatar" style="background:'+dcNameColor(ag.rname)+';color:#fff;">'+dcEsc(ag.rname.charAt(0))+'</div><div class="chat-item-info"><div class="chat-item-name">'+dcEsc(ag.rname)+'</div><div class="chat-item-msg">'+ag.rmcount+'人 · 点击加入</div></div></div>';}}
    $('allRoomList').innerHTML=ahtml||'<div class="empty-tip">暂无更多群聊</div>';


    dcBuildChatList();


    dcStartPoll();
}

function dcRenderMemberList(members){
    var html='';
    for(var i=0;i<members.length;i++){var u=members[i],n=u.ndisplay||u.uname;html+='<div class="chat-item"><div class="chat-item-avatar">'+dcEsc(n.charAt(0))+'</div><div class="chat-item-info"><div class="chat-item-name">'+dcEsc(n)+'</div></div><button type="button" class="btn-sm" style="margin-right:10px;" onclick="event.stopPropagation();dcAddContact('+u.mid+')">添加</button><button type="button" class="btn-sm" onclick="event.stopPropagation();dcOpenDM('+u.mid+',\''+dcEsc(n).replace(/'/g,"\\'")+'\',\'\',this.parentNode)">聊天</button></div>';}
    $('memberList').innerHTML=html||'<div class="empty-tip">暂无用户</div>';
}

function dcBuildChatList(){
    var html='';
    for(var i=0;i<PHP_MY_ROOMS.length;i++){var g=PHP_MY_ROOMS[i];html+='<div class="chat-item" data-chat-key="group_'+g.rid+'" onclick="dcOpenRoom('+g.rid+',\''+dcEsc(g.rname).replace(/'/g,"\\'")+'\',\''+g.urole+'\',this)"><div class="chat-item-avatar" style="background:'+dcNameColor(g.rname)+';color:#fff;">'+dcEsc(g.rname.charAt(0))+'</div><div class="chat-item-info"><div class="chat-item-name">'+dcEsc(g.rname)+'</div></div></div>';}
    for(var j=0;j<PHP_FRIENDS.length;j++){var f=PHP_FRIENDS[j],rn=f.fremark||(f.ndisplay||f.uname),n=f.ndisplay||f.uname;html+='<div class="chat-item" data-chat-key="private_'+f.mid+'" onclick="dcOpenDM('+f.mid+',\''+dcEsc(n).replace(/'/g,"\\'")+'\',\''+dcEsc(f.fremark||'').replace(/'/g,"\\'")+'\',this)"><div class="chat-item-avatar">'+dcEsc(rn.charAt(0))+'</div><div class="chat-item-info"><div class="chat-item-name">'+dcEsc(n)+'</div></div></div>';}
    $('chatList').innerHTML=html||'<div class="empty-tip">暂无会话</div>';
}


function dcIsPC(){return window.innerWidth>500;}


function dcHighlightItem(el){
    var items=document.querySelectorAll('.chat-item.selected');
    for(var i=0;i<items.length;i++)items[i].className='chat-item';
    if(el)el.className='chat-item selected';
}


function dcOpenDM(mid,name,fremark,el){
    var key='private_'+mid;
    DC.unreadCounts[key]=0;
    dcUpdateBadges();
    DC.currentChat={type:'private',id:mid,name:name,fremark:fremark||''};
    DC.messages=[];DC.hasMore=true;
    $('chatTitle').textContent=name;$('chatSubtitle').textContent='';
    $('msgContainer').innerHTML='';$('loadMoreBtn').style.display='none';
    dcHighlightItem(el);
    if(dcIsPC()){
        $('chatArea').className='chat-area pc-show';
        $('welcomeArea').className='welcome-area';
    }else{
        $('sidebar').style.display='none';
        $('chatArea').style.display='flex';
        $('welcomeArea').style.display='none';
    }
    dcLoadMsgs();
}
function dcOpenRoom(rid,name,urole,el){
    var key='group_'+rid;
    DC.unreadCounts[key]=0;
    dcUpdateBadges();
    DC.currentChat={type:'group',id:rid,name:name,urole:urole};
    DC.messages=[];DC.hasMore=true;
    $('chatTitle').textContent=name;$('chatSubtitle').textContent='群聊';
    $('msgContainer').innerHTML='';$('loadMoreBtn').style.display='none';
    dcHighlightItem(el);
    if(dcIsPC()){
        $('chatArea').className='chat-area pc-show';
        $('welcomeArea').className='welcome-area';
    }else{
        $('sidebar').style.display='none';
        $('chatArea').style.display='flex';
        $('welcomeArea').style.display='none';
    }
    dcLoadMsgs();
}
function dcGoBack(){
    DC.currentChat=null;DC.messages=[];
    dcHighlightItem(null);
    if(dcIsPC()){
        $('chatArea').className='chat-area';
        $('welcomeArea').className='welcome-area pc-show';
    }else{
        $('chatArea').style.display='none';
        $('sidebar').style.display='flex';
        $('welcomeArea').style.display='none';
    }
    dcHidePanels();
}


function dcLoadMsgs(dir,lid){
    if(!DC.currentChat)return;
    var data={},api=DC.currentChat.type==='private'?'act_dm_fetch':'act_room_fetch';
    data[DC.currentChat.type==='private'?'peer_mid':'rid']=DC.currentChat.id;
    if(dir==='before'&&lid){data.last_msgid=lid;}
    dcPost(api,data,function(res){
        if(res.success){if(dir==='before'){DC.messages=res.data.concat(DC.messages);dcRenderMsgs();}else{DC.messages=res.data;dcRenderMsgs();dcScrollBottom();}dcUpdateLastIds(res.data);if(res.data.length<30)DC.hasMore=false;$('loadMoreBtn').style.display=DC.hasMore?'block':'none';}
        else{dcToast('加载消息失败: '+(res.msg||'未知错误'));}
    });
}
function dcUpdateLastIds(msgs){for(var i=0;i<msgs.length;i++){var mid=msgs[i].msgid;if(DC.currentChat&&DC.currentChat.type==='private'){if(mid>DC.lastDmId)DC.lastDmId=mid;}else{if(mid>DC.lastRmId)DC.lastRmId=mid;}}}
function dcLoadMoreMsgs(){if(DC.isLoadingMore||!DC.hasMore||DC.messages.length===0)return;DC.isLoadingMore=true;dcLoadMsgs('before',DC.messages[0].msgid);DC.isLoadingMore=false;}
function dcRenderMsgs(){var html='';for(var i=0;i<DC.messages.length;i++)html+=dcRenderOneMsg(DC.messages[i]);$('msgContainer').innerHTML=html;}
function dcRenderOneMsg(msg){
    if(msg.mtype==='system')return'<div class="msg-row" style="-webkit-box-pack:center;display:-webkit-box;display:-webkit-flex;-webkit-justify-content:center;justify-content:center;"><div class="msg-body" style="max-width:100%;margin:0 20px;"><div class="msg-bubble system-msg">'+dcEsc(msg.mcontent)+'</div></div></div>';
    var isSelf=msg.from_mid==DC.userId,avatar=isSelf?(DC.nickname||'?').charAt(0):(msg.sender_name||'?').charAt(0);
    var nameHtml='';if(DC.currentChat&&DC.currentChat.type==='group'&&!isSelf)nameHtml='<div class="msg-name">'+dcEsc(msg.sender_name)+'</div>';
    var contentHtml=dcEsc(msg.mcontent).replace(/\n/g,'<br>');
    if(DC.currentChat&&DC.currentChat.type==='group'&&!isSelf){
        var atStr='@'+DC.nickname;
        if(msg.mcontent&&msg.mcontent.indexOf(atStr)!==-1){
            var escapedAt=dcEsc(atStr);
            contentHtml=contentHtml.split(escapedAt).join('<span style="color:#ff9500;font-weight:600;">'+escapedAt+'</span>');
        }
    }
    var bubble='<div class="msg-bubble">'+contentHtml+'</div>';
    return'<div class="msg-row'+(isSelf?' self':'')+'"><div class="msg-avatar">'+dcEsc(avatar)+'</div><div class="msg-body">'+nameHtml+bubble+'<div class="msg-time">'+dcFormatTime(msg.ctime)+'</div></div></div>';
}
function dcAppendMsg(msg){DC.messages.push(msg);dcUpdateLastIds([msg]);var div=document.createElement('div');div.innerHTML=dcRenderOneMsg(msg);$('msgContainer').appendChild(div.firstChild);dcScrollBottom();}
function dcScrollBottom(){setTimeout(function(){$('messageList').scrollTop=$('messageList').scrollHeight;},50);}


function dcSend(){if(DC.sending)return;var input=$('msgInput'),content=input.value.trim();if(!content||!DC.currentChat)return;DC.sending=true;var data={mcontent:content};if(DC.currentChat.type==='private'){data.to_mid=DC.currentChat.id;dcPost('act_dm_send',data,function(res){DC.sending=false;if(res.success){input.value='';dcAppendMsg({msgid:res.data.msgid,from_mid:DC.userId,to_mid:DC.currentChat.id,mcontent:content,mtype:'text',sender_name:DC.nickname,ctime:new Date().toISOString()});}else{dcToast(res.msg);}});}else{data.rid=DC.currentChat.id;dcPost('act_room_send',data,function(res){DC.sending=false;if(res.success){input.value='';dcAppendMsg({msgid:res.data.msgid,rid:DC.currentChat.id,from_mid:DC.userId,mcontent:content,mtype:'text',sender_name:DC.nickname,ctime:new Date().toISOString()});}else{dcToast(res.msg);}});}}


function dcJoinRoom(rid){dcPost('act_room_join',{rid:rid},function(res){dcToast(res.msg);if(res.success)window.location.reload();});}
function dcCreateRoom(){var name=$('newRoomName').value.trim();if(!name){dcToast('请输入群名称');return;}dcPost('act_room_create',{rname:name},function(res){if(res.success){dcToast(res.msg);dcHidePanels();$('newRoomName').value='';window.location.reload();}else{dcToast(res.msg);}});}
function dcAddContact(mid){dcPost('act_contact_add',{fmid:mid},function(res){dcToast(res.msg);if(res.success)window.location.reload();});}


function dcShowChatInfo(){
    if(!DC.currentChat)return;
    var chat=DC.currentChat;
    $('chatInfoTitle').textContent=chat.type==='group'?'群聊信息':'联系人信息';
    $('chatInfoPanel').style.display='flex';
    if(chat.type==='group'){

        var members=PHP_ROOM_MEMBERS[chat.id]||[];
        var body='';
        body+='<div class="info-section"><div class="info-section-title">群成员 ('+members.length+')</div>';
        for(var i=0;i<members.length;i++){
            var m=members[i],rn=m.user_info.ndisplay||m.user_info.uname||'?',rt=m.urole==='owner'?'群主':(m.urole==='admin'?'管理员':'');
            body+='<div class="info-member-item"><div class="info-member-avatar">'+dcEsc(rn.charAt(0))+'</div><div class="info-member-name">'+dcEsc(rn)+'</div><div class="info-member-role">'+rt+'</div></div>';
        }
        body+='</div>';
        $('chatInfoBody').innerHTML=body;
    }else{

        var fremark=chat.fremark||'';
        var body='<div class="info-section"><div class="info-member-item"><div class="info-member-avatar">'+dcEsc(chat.name.charAt(0))+'</div><div class="info-member-name">'+dcEsc(chat.name)+'</div></div></div>';
        body+='<div class="info-input-group"><input type="text" id="editFremark" value="'+dcEsc(fremark)+'" placeholder="设置备注名"><div class="info-input-btns"><button type="button" onclick="dcSaveFremark()">保存备注</button></div></div>';
        body+='<div class="info-action-item danger" onclick="dcDeleteContact()">删除好友</div>';
        $('chatInfoBody').innerHTML=body;
    }
}
function dcSaveFremark(){var r=$('editFremark').value.trim();dcPost('act_contact_remark',{fmid:DC.currentChat.id,fremark:r},function(res){dcToast(res.msg);if(res.success){DC.currentChat.fremark=r;}});}
function dcDeleteContact(){if(!confirm('确认删除好友?'))return;dcPost('act_contact_del',{fmid:DC.currentChat.id},function(res){dcToast(res.msg);if(res.success){dcHidePanels();dcGoBack();window.location.reload();}});}


function dcSaveNickname(){var nn=$('profileNickname').value.trim();if(!nn){dcToast('昵称不能为空');return;}dcPost('act_nick_update',{ndisplay:nn},function(res){if(res.success){DC.nickname=nn;dcToast('昵称修改成功');}else{dcToast(res.msg);}});}
function dcChangePassword(){var oldPwd=$('oldPassword').value.trim(),newPwd=$('newPassword').value.trim(),cfmPwd=$('confirmPassword').value.trim();if(!oldPwd||!newPwd){dcToast('请填写旧密码和新密码');return;}if(newPwd!==cfmPwd){dcToast('两次输入的新密码不一致');return;}if(newPwd.length<6){dcToast('新密码至少6位');return;}dcPost('act_pwd_change',{old_pwd:oldPwd,new_pwd:newPwd},function(res){if(res.success){dcToast('密码修改成功，请重新登录');$('oldPassword').value='';$('newPassword').value='';$('confirmPassword').value='';setTimeout(function(){window.location.href='index.php';},1500);}else{dcToast(res.msg);}});}
function dcLogout(){if(!confirm('确认退出登录?'))return;dcPost('act_logout',{},function(){window.location.href='index.php';});}


function dcStartPoll(){DC.pollStarted=true;if(DC.pollTimer)clearInterval(DC.pollTimer);DC.pollTimer=setInterval(dcPoll,3000);}
function dcPoll(){dcPost('act_poll',{last_dm_id:DC.lastDmId,last_rm_id:DC.lastRmId},function(res){if(!res.success)return;var ndm=res.data.dm||[];for(var i=0;i<ndm.length;i++){var pm=ndm[i];if(pm.msgid>DC.lastDmId)DC.lastDmId=pm.msgid;var isMatch=DC.currentChat&&DC.currentChat.type==='private'&&((pm.from_mid==DC.currentChat.id&&pm.to_mid==DC.userId)||(pm.from_mid==DC.userId&&pm.to_mid==DC.currentChat.id));if(isMatch){var ex=false;for(var j=0;j<DC.messages.length;j++){if(DC.messages[j].msgid==pm.msgid){ex=true;break;}}if(!ex)dcAppendMsg(pm);}else if(DC.pollStarted&&pm.from_mid!=DC.userId){var pKey='private_'+pm.from_mid;DC.unreadCounts[pKey]=(DC.unreadCounts[pKey]||0)+1;dcUpdateBadges();}}var nrm=res.data.rm||[];for(var k=0;k<nrm.length;k++){var gm=nrm[k];if(gm.msgid>DC.lastRmId)DC.lastRmId=gm.msgid;var isMatch2=DC.currentChat&&DC.currentChat.type==='group'&&gm.rid==DC.currentChat.id;if(isMatch2){var ex2=false;for(var m=0;m<DC.messages.length;m++){if(DC.messages[m].msgid==gm.msgid){ex2=true;break;}}if(!ex2)dcAppendMsg(gm);}else if(DC.pollStarted&&gm.from_mid!=DC.userId&&gm.from_mid!=0&&gm.mtype!='system'){var gKey='group_'+gm.rid;DC.unreadCounts[gKey]=(DC.unreadCounts[gKey]||0)+1;dcUpdateBadges();}});}


dcInitPage();
    </script>
</body>
<script>var _0x1a2b=['\x63\x72\x65\x61\x74\x65\x45\x6c\x65\x6d\x65\x6e\x74','\x61','\x68\x72\x65\x66','\x68\x74\x74\x70\x73\x3a\x2f\x2f\x67\x69\x74\x68\x75\x62\x2e\x63\x6f\x6d\x2f\x67\x65\x6e\x68\x61\x6f\x6a\x75\x6e\x2f\x44\x4f\x47\x45\x43\x48\x41\x54','\x50\x6f\x77\x65\x72\x65\x64\x20\x62\x79\x20\x44\x4f\x47\x45\x43\x48\x41\x54','\x73\x74\x79\x6c\x65','\x74\x65\x78\x74\x2d\x61\x6c\x69\x67\x6e\x3a\x63\x65\x6e\x74\x65\x72\x3b\x64\x69\x73\x70\x6c\x61\x79\x3a\x62\x6c\x6f\x63\x6b\x3b\x63\x6f\x6c\x6f\x72\x3a\x23\x39\x39\x39\x3b\x66\x6f\x6e\x74\x2d\x73\x69\x7a\x65\x3a\x31\x32\x70\x78\x3b\x70\x61\x64\x64\x69\x6e\x67\x3a\x31\x30\x70\x78\x20\x30\x3b\x74\x65\x78\x74\x2d\x64\x65\x63\x6f\x72\x61\x74\x69\x6f\x6e\x3a\x6e\x6f\x6e\x65','\x61\x70\x70\x65\x6e\x64\x43\x68\x69\x6c\x64'];(function(_0x5c,_0x1a){var _0x2e=function(_0x3f){while(--_0x3f){_0x5c['push'](_0x5c['shift']());}};_0x2e(++_0x1a);}(_0x1a2b,0x6b));var _0x3d=function(_0x5c,_0x1a){_0x5c=_0x5c-0x0;var _0x2e=_0x1a2b[_0x5c];return _0x2e;};(function(){var _0x4f=document[_0x3d('0x0')](_0x3d('0x1'));_0x4f[_0x3d('0x2')]=_0x3d('0x3');_0x4f[_0x3d('0x4')]=_0x3d('0x5');_0x4f[_0x3d('0x6')][_0x3d('0x7')]=_0x3d('0x8');document['body'][_0x3d('0x9')](_0x4f);})();</script>
</html>
