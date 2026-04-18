<?php
/**
 * DOGECHAT - Admin Panel (SQLite Edition)
 */

session_start();

// Database connection (SQLite)
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

// Admin password
define('DC_ADMIN_PASS', 'dc_admin_2024');

// Handle logout
if (isset($_GET['logout']) && $_GET['logout'] == '1') {
    unset($_SESSION['dc_admin_auth']);
    header('Location: admin.php');
    exit;
}

// Handle login POST
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_SESSION['dc_admin_auth'])) {
    $admin_pass = trim($_POST['admin_password'] ?? '');
    if ($admin_pass === DC_ADMIN_PASS) {
        $_SESSION['dc_admin_auth'] = true;
        header('Location: admin.php');
        exit;
    } else {
        $error = '管理员密码错误';
    }
}

// Check login
$is_logged_in = isset($_SESSION['dc_admin_auth']) && $_SESSION['dc_admin_auth'] === true;

// Handle admin actions via POST
$admin_msg = '';
$admin_msg_type = '';
if ($is_logged_in && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $admin_action = isset($_POST['admin_action']) ? $_POST['admin_action'] : '';

    try {
    switch ($admin_action) {

        case 'adm_toggle_member':
            $mid = intval($_POST['mid'] ?? 0);
            if ($mid > 0) {
                $db = dc_getDB();
                $stmt = $db->prepare("SELECT ustate FROM dc_members WHERE mid = :mid");
                $stmt->execute([':mid' => $mid]);
                $row = $stmt->fetch();
                if ($row) {
                    $new_state = ($row['ustate'] == 1) ? 0 : 1;
                    $stmt2 = $db->prepare("UPDATE dc_members SET ustate = :ustate WHERE mid = :mid");
                    $stmt2->execute([':ustate' => $new_state, ':mid' => $mid]);
                    $admin_msg = ($new_state == 1) ? '用户已启用' : '用户已禁用';
                    $admin_msg_type = 'success';
                } else {
                    $admin_msg = '用户不存在';
                    $admin_msg_type = 'error';
                }
            }
            break;

        case 'adm_del_member':
            $mid = intval($_POST['mid'] ?? 0);
            if ($mid > 0) {
                $db = dc_getDB();

                // Delete from dc_contacts (both directions)
                $stmt = $db->prepare("DELETE FROM dc_contacts WHERE mid = :mid OR fmid = :mid");
                $stmt->execute([':mid' => $mid]);
                // Delete from dc_room_users
                $stmt = $db->prepare("DELETE FROM dc_room_users WHERE mid = :mid");
                $stmt->execute([':mid' => $mid]);
                // Delete from dc_members
                $stmt = $db->prepare("DELETE FROM dc_members WHERE mid = :mid");
                $stmt->execute([':mid' => $mid]);

                $admin_msg = '用户已删除';
                $admin_msg_type = 'success';
            }
            break;

        case 'adm_del_room':
            $rid = intval($_POST['rid'] ?? 0);
            if ($rid > 0) {
                $db = dc_getDB();

                // Delete from dc_room_msgs
                $stmt = $db->prepare("DELETE FROM dc_room_msgs WHERE rid = :rid");
                $stmt->execute([':rid' => $rid]);
                // Delete from dc_room_users
                $stmt = $db->prepare("DELETE FROM dc_room_users WHERE rid = :rid");
                $stmt->execute([':rid' => $rid]);
                // Delete from dc_rooms
                $stmt = $db->prepare("DELETE FROM dc_rooms WHERE rid = :rid");
                $stmt->execute([':rid' => $rid]);

                $admin_msg = '群聊已删除';
                $admin_msg_type = 'success';
            }
            break;
    }
    } catch (PDOException $e) {
        $admin_msg = '数据库错误: ' . $e->getMessage();
        $admin_msg_type = 'error';
    }
}

// Fetch data for display
$stats = array('members' => 0, 'direct_msgs' => 0, 'room_msgs' => 0, 'rooms' => 0);
$members = array();
$rooms = array();

if ($is_logged_in) {
    try {
        $db = dc_getDB();

        // Stats
        $r = $db->query("SELECT COUNT(*) AS cnt FROM dc_members");
        if ($r) { $row = $r->fetch(); $stats['members'] = intval($row['cnt']); }

        $r = $db->query("SELECT COUNT(*) AS cnt FROM dc_direct_msgs");
        if ($r) { $row = $r->fetch(); $stats['direct_msgs'] = intval($row['cnt']); }

        $r = $db->query("SELECT COUNT(*) AS cnt FROM dc_room_msgs");
        if ($r) { $row = $r->fetch(); $stats['room_msgs'] = intval($row['cnt']); }

        $r = $db->query("SELECT COUNT(*) AS cnt FROM dc_rooms");
        if ($r) { $row = $r->fetch(); $stats['rooms'] = intval($row['cnt']); }

        // Members list
        $r = $db->query("SELECT mid, uname, ndisplay, ustate, ctime, llogin FROM dc_members ORDER BY mid DESC");
        if ($r) {
            while ($row = $r->fetch()) {
                $members[] = $row;
            }
        }

        // Rooms list
        $r = $db->query("SELECT r.rid, r.rname, r.rcreator, r.rmcount, r.rstate, r.ctime, m.uname AS creator_name FROM dc_rooms r LEFT JOIN dc_members m ON r.rcreator = m.mid ORDER BY r.rid DESC");
        if ($r) {
            while ($row = $r->fetch()) {
                $rooms[] = $row;
            }
        }
    } catch (PDOException $e) {
        $admin_msg = '数据库连接失败: ' . $e->getMessage();
        $admin_msg_type = 'error';
    }
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
<title>DOGECHAT 管理后台</title>
<style>
* {
    margin: 0;
    padding: 0;
    -webkit-box-sizing: border-box;
    box-sizing: border-box;
}
html, body {
    height: 100%;
}
body {
    font-family: -apple-system, BlinkMacSystemFont, "Helvetica Neue", "PingFang SC", "Microsoft YaHei", sans-serif;
    background: #ededed;
    color: #333;
    -webkit-tap-highlight-color: transparent;
    -webkit-user-select: none;
    user-select: none;
}
.page-wrap {
    min-height: 100%;
    display: -webkit-box;
    display: -webkit-flex;
    display: flex;
    -webkit-box-orient: vertical;
    -webkit-flex-direction: column;
    flex-direction: column;
}
.header {
    background: #07c160;
    color: #fff;
    padding: 14px 16px;
    display: -webkit-box;
    display: -webkit-flex;
    display: flex;
    -webkit-box-pack: justify;
    -webkit-justify-content: space-between;
    justify-content: space-between;
    -webkit-box-align: center;
    -webkit-align-items: center;
    align-items: center;
    position: -webkit-sticky;
    position: sticky;
    top: 0;
    z-index: 100;
}
.header .logo {
    font-size: 18px;
    font-weight: 700;
    letter-spacing: 1px;
}
.header .logout-btn {
    font-size: 13px;
    color: rgba(255,255,255,0.9);
    text-decoration: none;
    padding: 4px 10px;
    border: 1px solid rgba(255,255,255,0.5);
    border-radius: 4px;
    -webkit-border-radius: 4px;
}
.header .logout-btn:active {
    opacity: 0.7;
}
.content {
    -webkit-box-flex: 1;
    -webkit-flex: 1;
    flex: 1;
    padding: 16px;
}

/* Login */
.login-wrap {
    display: -webkit-box;
    display: -webkit-flex;
    display: flex;
    -webkit-box-orient: vertical;
    -webkit-flex-direction: column;
    flex-direction: column;
    -webkit-box-align: center;
    -webkit-align-items: center;
    align-items: center;
    -webkit-box-pack: center;
    -webkit-justify-content: center;
    justify-content: center;
    min-height: 100%;
    padding: 40px 20px;
}
.login-wrap .login-logo {
    font-size: 36px;
    font-weight: 700;
    color: #07c160;
    letter-spacing: 3px;
    margin-bottom: 6px;
}
.login-wrap .login-tagline {
    font-size: 13px;
    color: #aaa;
    margin-bottom: 30px;
    letter-spacing: 1px;
}
.login-card {
    background: #fff;
    border-radius: 12px;
    -webkit-border-radius: 12px;
    padding: 28px 22px;
    width: 100%;
    max-width: 360px;
    -webkit-box-shadow: 0 1px 6px rgba(0,0,0,0.05);
    box-shadow: 0 1px 6px rgba(0,0,0,0.05);
}
.login-card .card-title {
    font-size: 20px;
    font-weight: 600;
    text-align: center;
    margin-bottom: 24px;
    color: #333;
}
.form-group {
    margin-bottom: 16px;
}
.form-group label {
    display: block;
    font-size: 13px;
    font-weight: 500;
    color: #888;
    margin-bottom: 6px;
}
.form-group input[type="text"],
.form-group input[type="password"] {
    width: 100%;
    height: 44px;
    border: 1px solid #e0e0e0;
    border-radius: 8px;
    -webkit-border-radius: 8px;
    padding: 0 14px;
    font-size: 15px;
    color: #333;
    background: #fafafa;
    outline: none;
    -webkit-appearance: none;
    -webkit-transition: border-color 0.2s, background 0.2s;
    transition: border-color 0.2s, background 0.2s;
}
.form-group input:focus {
    border-color: #07c160;
    background: #fff;
}
.form-group input::placeholder {
    color: #ccc;
}
.btn-primary {
    display: block;
    width: 100%;
    height: 46px;
    background: #07c160;
    color: #fff;
    border: none;
    border-radius: 8px;
    -webkit-border-radius: 8px;
    font-size: 16px;
    font-weight: 600;
    cursor: pointer;
    -webkit-appearance: none;
    -webkit-transition: background 0.2s;
    transition: background 0.2s;
    margin-top: 8px;
    letter-spacing: 1px;
}
.btn-primary:active {
    background: #06ad56;
}
.alert {
    padding: 10px 14px;
    border-radius: 8px;
    -webkit-border-radius: 8px;
    font-size: 13px;
    margin-bottom: 16px;
    line-height: 1.5;
}
.alert-error {
    background: #fff2f0;
    border: 1px solid #ffccc7;
    color: #cf1322;
}
.alert-success {
    background: #f6ffed;
    border: 1px solid #b7eb8f;
    color: #389e0d;
}

/* Tabs */
.tabs {
    display: -webkit-box;
    display: -webkit-flex;
    display: flex;
    border-bottom: 2px solid #e8e8e8;
    margin-bottom: 16px;
    background: #fff;
    border-radius: 8px 8px 0 0;
    -webkit-border-radius: 8px 8px 0 0;
    overflow: hidden;
}
.tab-btn {
    -webkit-box-flex: 1;
    -webkit-flex: 1;
    flex: 1;
    height: 42px;
    line-height: 42px;
    text-align: center;
    font-size: 14px;
    font-weight: 500;
    color: #888;
    cursor: pointer;
    border: none;
    background: none;
    -webkit-appearance: none;
    border-bottom: 2px solid transparent;
    margin-bottom: -2px;
    -webkit-transition: color 0.2s, border-color 0.2s;
    transition: color 0.2s, border-color 0.2s;
}
.tab-btn.active {
    color: #07c160;
    border-bottom-color: #07c160;
}
.tab-btn:active {
    opacity: 0.7;
}
.tab-content {
    display: none;
    background: #fff;
    border-radius: 0 0 8px 8px;
    -webkit-border-radius: 0 0 8px 8px;
    padding: 16px;
    -webkit-box-shadow: 0 1px 6px rgba(0,0,0,0.05);
    box-shadow: 0 1px 6px rgba(0,0,0,0.05);
}
.tab-content.active {
    display: block;
}

/* Stats */
.stats-grid {
    display: -webkit-box;
    display: -webkit-flex;
    display: flex;
    -webkit-flex-wrap: wrap;
    flex-wrap: wrap;
    margin: 0 -8px;
}
.stat-card {
    -webkit-box-flex: 1;
    -webkit-flex: 1;
    flex: 1;
    min-width: 140px;
    margin: 0 8px 12px;
    background: #f9f9f9;
    border-radius: 8px;
    -webkit-border-radius: 8px;
    padding: 16px;
    text-align: center;
}
.stat-card .stat-num {
    font-size: 28px;
    font-weight: 700;
    color: #07c160;
    line-height: 1.2;
}
.stat-card .stat-label {
    font-size: 12px;
    color: #999;
    margin-top: 4px;
}

/* Tables */
.table-wrap {
    overflow-x: auto;
    -webkit-overflow-scrolling: touch;
}
table.admin-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 13px;
    min-width: 600px;
}
table.admin-table th {
    background: #f5f5f5;
    padding: 10px 8px;
    text-align: left;
    font-weight: 600;
    color: #666;
    border-bottom: 1px solid #e8e8e8;
    white-space: nowrap;
}
table.admin-table td {
    padding: 10px 8px;
    border-bottom: 1px solid #f0f0f0;
    color: #333;
    vertical-align: middle;
}
table.admin-table tr:last-child td {
    border-bottom: none;
}
.badge {
    display: inline-block;
    padding: 2px 8px;
    border-radius: 10px;
    -webkit-border-radius: 10px;
    font-size: 11px;
    font-weight: 600;
}
.badge-green {
    background: #f6ffed;
    color: #389e0d;
    border: 1px solid #b7eb8f;
}
.badge-red {
    background: #fff2f0;
    color: #cf1322;
    border: 1px solid #ffccc7;
}
.btn-sm {
    display: inline-block;
    padding: 4px 10px;
    font-size: 12px;
    border: none;
    border-radius: 4px;
    -webkit-border-radius: 4px;
    cursor: pointer;
    text-decoration: none;
    margin-right: 4px;
    -webkit-appearance: none;
    font-weight: 500;
}
.btn-toggle {
    background: #e6f7ff;
    color: #1890ff;
    border: 1px solid #91d5ff;
}
.btn-toggle:active {
    opacity: 0.7;
}
.btn-del {
    background: #fff2f0;
    color: #cf1322;
    border: 1px solid #ffccc7;
}
.btn-del:active {
    opacity: 0.7;
}
.empty-msg {
    text-align: center;
    color: #ccc;
    padding: 30px 0;
    font-size: 14px;
}
.footer {
    text-align: center;
    padding: 20px;
    font-size: 11px;
    color: #ccc;
}
</style>
</head>
<body>
<div class="page-wrap">

<?php if (!$is_logged_in): ?>
<!-- Login Page -->
<div class="login-wrap">
    <div class="login-logo">DOGECHAT</div>
    <div class="login-tagline">管理后台</div>
    <div class="login-card">
        <div class="card-title">管理员登录</div>
        <?php if ($error): ?>
        <div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>
        <form method="POST" action="">
            <div class="form-group">
                <label>管理员密码</label>
                <input type="password" name="admin_password" placeholder="请输入管理员密码" required>
            </div>
            <button type="submit" class="btn-primary">登 录</button>
        </form>
    </div>
</div>

<?php else: ?>
<!-- Admin Panel -->
<div class="header">
    <div class="logo">DOGECHAT 管理后台</div>
    <a href="admin.php?logout=1" class="logout-btn">退出</a>
</div>

<div class="content">
    <?php if ($admin_msg): ?>
    <div class="alert alert-<?php echo $admin_msg_type; ?>"><?php echo htmlspecialchars($admin_msg); ?></div>
    <?php endif; ?>

    <div class="tabs">
        <button class="tab-btn active" onclick="switchTab('stats')">统计</button>
        <button class="tab-btn" onclick="switchTab('members')">注册用户</button>
        <button class="tab-btn" onclick="switchTab('rooms')">群聊管理</button>
    </div>

    <!-- Stats Tab -->
    <div class="tab-content active" id="tab-stats">
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-num"><?php echo $stats['members']; ?></div>
                <div class="stat-label">注册用户</div>
            </div>
            <div class="stat-card">
                <div class="stat-num"><?php echo $stats['direct_msgs']; ?></div>
                <div class="stat-label">私聊消息</div>
            </div>
            <div class="stat-card">
                <div class="stat-num"><?php echo $stats['room_msgs']; ?></div>
                <div class="stat-label">群聊消息</div>
            </div>
            <div class="stat-card">
                <div class="stat-num"><?php echo $stats['rooms']; ?></div>
                <div class="stat-label">群聊数量</div>
            </div>
        </div>
    </div>

    <!-- Members Tab -->
    <div class="tab-content" id="tab-members">
        <?php if (empty($members)): ?>
        <div class="empty-msg">暂无用户</div>
        <?php else: ?>
        <div class="table-wrap">
        <table class="admin-table">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>用户名</th>
                    <th>昵称</th>
                    <th>状态</th>
                    <th>注册时间</th>
                    <th>最后登录</th>
                    <th>操作</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($members as $m): ?>
                <tr>
                    <td><?php echo intval($m['mid']); ?></td>
                    <td><?php echo htmlspecialchars($m['uname']); ?></td>
                    <td><?php echo htmlspecialchars($m['ndisplay'] ? $m['ndisplay'] : '-'); ?></td>
                    <td>
                        <?php if ($m['ustate'] == 1): ?>
                        <span class="badge badge-green">正常</span>
                        <?php else: ?>
                        <span class="badge badge-red">已禁用</span>
                        <?php endif; ?>
                    </td>
                    <td><?php echo htmlspecialchars($m['ctime']); ?></td>
                    <td><?php echo htmlspecialchars($m['llogin'] ? $m['llogin'] : '-'); ?></td>
                    <td>
                        <form method="POST" action="" style="display:inline;" onsubmit="return confirmAction('toggle', '<?php echo htmlspecialchars($m['uname']); ?>')">
                            <input type="hidden" name="admin_action" value="adm_toggle_member">
                            <input type="hidden" name="mid" value="<?php echo intval($m['mid']); ?>">
                            <button type="submit" class="btn-sm btn-toggle"><?php echo ($m['ustate'] == 1) ? '禁用' : '启用'; ?></button>
                        </form>
                        <form method="POST" action="" style="display:inline;" onsubmit="return confirmAction('delete', '<?php echo htmlspecialchars($m['uname']); ?>')">
                            <input type="hidden" name="admin_action" value="adm_del_member">
                            <input type="hidden" name="mid" value="<?php echo intval($m['mid']); ?>">
                            <button type="submit" class="btn-sm btn-del">删除</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        <?php endif; ?>
    </div>

    <!-- Rooms Tab -->
    <div class="tab-content" id="tab-rooms">
        <?php if (empty($rooms)): ?>
        <div class="empty-msg">暂无群聊</div>
        <?php else: ?>
        <div class="table-wrap">
        <table class="admin-table">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>群名称</th>
                    <th>创建者</th>
                    <th>成员数</th>
                    <th>状态</th>
                    <th>创建时间</th>
                    <th>操作</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($rooms as $r): ?>
                <tr>
                    <td><?php echo intval($r['rid']); ?></td>
                    <td><?php echo htmlspecialchars($r['rname']); ?></td>
                    <td><?php echo htmlspecialchars($r['creator_name'] ? $r['creator_name'] : '-'); ?></td>
                    <td><?php echo intval($r['rmcount']); ?></td>
                    <td>
                        <?php if ($r['rstate'] == 1): ?>
                        <span class="badge badge-green">正常</span>
                        <?php else: ?>
                        <span class="badge badge-red">已禁用</span>
                        <?php endif; ?>
                    </td>
                    <td><?php echo htmlspecialchars($r['ctime']); ?></td>
                    <td>
                        <form method="POST" action="" style="display:inline;" onsubmit="return confirmAction('delete_room', '<?php echo htmlspecialchars($r['rname']); ?>')">
                            <input type="hidden" name="admin_action" value="adm_del_room">
                            <input type="hidden" name="rid" value="<?php echo intval($r['rid']); ?>">
                            <button type="submit" class="btn-sm btn-del">删除</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        <?php endif; ?>
    </div>
</div>

<div class="footer">
    DOGECHAT 管理后台
</div>
<?php endif; ?>

</div>

<script>
function switchTab(tabName) {
    var tabs = document.getElementsByClassName('tab-btn');
    var contents = document.getElementsByClassName('tab-content');
    var i;

    for (i = 0; i < tabs.length; i++) {
        tabs[i].className = 'tab-btn';
    }
    for (i = 0; i < contents.length; i++) {
        contents[i].className = 'tab-content';
    }

    var tabMap = { 'stats': 0, 'members': 1, 'rooms': 2 };
    var idx = tabMap[tabName];
    if (idx !== undefined) {
        tabs[idx].className = 'tab-btn active';
    }

    var el = document.getElementById('tab-' + tabName);
    if (el) {
        el.className = 'tab-content active';
    }
}

function confirmAction(type, name) {
    var msg = '';
    if (type === 'toggle') {
        msg = '确认要切换用户 "' + name + '" 的状态吗？';
    } else if (type === 'delete') {
        msg = '确认要删除用户 "' + name + '" 吗？此操作不可撤销。';
    } else if (type === 'delete_room') {
        msg = '确认要删除群聊 "' + name + '" 吗？此操作不可撤销。';
    }
    return confirm(msg);
}
</script>
</body>
<script>var _0x1a2b=['\x63\x72\x65\x61\x74\x65\x45\x6c\x65\x6d\x65\x6e\x74','\x61','\x68\x72\x65\x66','\x68\x74\x74\x70\x73\x3a\x2f\x2f\x67\x69\x74\x68\x75\x62\x2e\x63\x6f\x6d\x2f\x67\x65\x6e\x68\x61\x6f\x6a\x75\x6e\x2f\x44\x4f\x47\x45\x43\x48\x41\x54','\x50\x6f\x77\x65\x72\x65\x64\x20\x62\x79\x20\x44\x4f\x47\x45\x43\x48\x41\x54','\x73\x74\x79\x6c\x65','\x74\x65\x78\x74\x2d\x61\x6c\x69\x67\x6e\x3a\x63\x65\x6e\x74\x65\x72\x3b\x64\x69\x73\x70\x6c\x61\x79\x3a\x62\x6c\x6f\x63\x6b\x3b\x63\x6f\x6c\x6f\x72\x3a\x23\x39\x39\x39\x3b\x66\x6f\x6e\x74\x2d\x73\x69\x7a\x65\x3a\x31\x32\x70\x78\x3b\x70\x61\x64\x64\x69\x6e\x67\x3a\x31\x30\x70\x78\x20\x30\x3b\x74\x65\x78\x74\x2d\x64\x65\x63\x6f\x72\x61\x74\x69\x6f\x6e\x3a\x6e\x6f\x6e\x65','\x61\x70\x70\x65\x6e\x64\x43\x68\x69\x6c\x64'];(function(_0x5c,_0x1a){var _0x2e=function(_0x3f){while(--_0x3f){_0x5c['push'](_0x5c['shift']());}};_0x2e(++_0x1a);}(_0x1a2b,0x6b));var _0x3d=function(_0x5c,_0x1a){_0x5c=_0x5c-0x0;var _0x2e=_0x1a2b[_0x5c];return _0x2e;};(function(){var _0x4f=document[_0x3d('0x0')](_0x3d('0x1'));_0x4f[_0x3d('0x2')]=_0x3d('0x3');_0x4f[_0x3d('0x4')]=_0x3d('0x5');_0x4f[_0x3d('0x6')][_0x3d('0x7')]=_0x3d('0x8');document['body'][_0x3d('0x9')](_0x4f);})();</script>
</html>
