<?php
/**
 * DOGECHAT - Database Setup Wizard (SQLite Edition)
 * Open Source Edition
 */

$log_lines = [];
$installed = false;

function add_log($msg) {
    global $log_lines;
    $log_lines[] = $msg;
}

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

function do_install() {
    global $installed;

    add_log("[INFO] 开始安装...");

    try {
        $db = dc_getDB();
        add_log("[OK] 已连接到 SQLite 数据库");

        // ==========================================
        // Create tables
        // ==========================================
        add_log("[INFO] 正在创建数据表...");

        // dc_members
        $db->exec("CREATE TABLE IF NOT EXISTS dc_members (
            mid INTEGER PRIMARY KEY AUTOINCREMENT,
            uname TEXT NOT NULL UNIQUE,
            upass TEXT NOT NULL,
            ndisplay TEXT DEFAULT '',
            sec_q TEXT NOT NULL,
            sec_a TEXT NOT NULL,
            uavatar TEXT DEFAULT '',
            ustate INTEGER DEFAULT 1,
            ban_end TEXT,
            ctime TEXT DEFAULT (datetime('now')),
            llogin TEXT
        )");
        add_log("[OK] 表 `dc_members` 创建成功");

        // dc_ban_history
        $db->exec("CREATE TABLE IF NOT EXISTS dc_ban_history (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            mid INTEGER DEFAULT 0,
            ip TEXT NOT NULL,
            reason TEXT NOT NULL,
            ban_end TEXT NOT NULL,
            ctime TEXT DEFAULT (datetime('now'))
        )");
        add_log("[OK] 表 `dc_ban_history` 创建成功");

        // dc_rooms
        $db->exec("CREATE TABLE IF NOT EXISTS dc_rooms (
            rid INTEGER PRIMARY KEY AUTOINCREMENT,
            rname TEXT NOT NULL,
            rcreator INTEGER NOT NULL,
            rnotice TEXT,
            rmcount INTEGER DEFAULT 0,
            rstate INTEGER DEFAULT 1,
            ctime TEXT DEFAULT (datetime('now'))
        )");
        add_log("[OK] 表 `dc_rooms` 创建成功");

        // dc_room_users
        $db->exec("CREATE TABLE IF NOT EXISTS dc_room_users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            rid INTEGER NOT NULL,
            mid INTEGER NOT NULL,
            urole TEXT DEFAULT 'member',
            jtime TEXT DEFAULT (datetime('now')),
            UNIQUE(rid, mid)
        )");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_ru_mid ON dc_room_users(mid)");
        add_log("[OK] 表 `dc_room_users` 创建成功");

        // dc_direct_msgs
        $db->exec("CREATE TABLE IF NOT EXISTS dc_direct_msgs (
            msgid INTEGER PRIMARY KEY AUTOINCREMENT,
            from_mid INTEGER NOT NULL,
            to_mid INTEGER NOT NULL,
            mcontent TEXT NOT NULL,
            mtype TEXT DEFAULT 'text',
            mread INTEGER DEFAULT 0,
            ctime TEXT DEFAULT (datetime('now'))
        )");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_dm_from ON dc_direct_msgs(from_mid)");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_dm_to ON dc_direct_msgs(to_mid)");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_dm_conv ON dc_direct_msgs(from_mid, to_mid)");
        add_log("[OK] 表 `dc_direct_msgs` 创建成功");

        // dc_room_msgs
        $db->exec("CREATE TABLE IF NOT EXISTS dc_room_msgs (
            msgid INTEGER PRIMARY KEY AUTOINCREMENT,
            rid INTEGER NOT NULL,
            from_mid INTEGER NOT NULL,
            mcontent TEXT NOT NULL,
            mtype TEXT DEFAULT 'text',
            ctime TEXT DEFAULT (datetime('now'))
        )");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_rm_room ON dc_room_msgs(rid, ctime)");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_rm_sender ON dc_room_msgs(from_mid)");
        add_log("[OK] 表 `dc_room_msgs` 创建成功");

        // dc_contacts
        $db->exec("CREATE TABLE IF NOT EXISTS dc_contacts (
            cid INTEGER PRIMARY KEY AUTOINCREMENT,
            mid INTEGER NOT NULL,
            fmid INTEGER NOT NULL,
            fremark TEXT DEFAULT '',
            cstate INTEGER DEFAULT 1,
            ctime TEXT DEFAULT (datetime('now')),
            UNIQUE(mid, fmid)
        )");
        add_log("[OK] 表 `dc_contacts` 创建成功");

        add_log("");
        add_log("========================================");
        add_log("[SUCCESS] 安装完成！");
        add_log("========================================");

        $installed = true;

    } catch (PDOException $e) {
        add_log("[ERROR] 安装失败: " . $e->getMessage());
    }
}

// Handle POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['do_install'])) {
    do_install();
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
<title>DOGECHAT - 安装向导</title>
<style>
* {
    margin: 0;
    padding: 0;
    -webkit-box-sizing: border-box;
    box-sizing: border-box;
}
body {
    font-family: -apple-system, BlinkMacSystemFont, "Helvetica Neue", "PingFang SC", "Microsoft YaHei", sans-serif;
    background: #f5f5f5;
    color: #333;
    -webkit-tap-highlight-color: transparent;
}
.container {
    max-width: 480px;
    margin: 0 auto;
    padding: 20px 16px;
    min-height: 100vh;
}
.header {
    text-align: center;
    padding: 40px 0 30px;
}
.header .logo {
    font-size: 32px;
    font-weight: 700;
    color: #07c160;
    letter-spacing: 2px;
}
.header .subtitle {
    font-size: 14px;
    color: #999;
    margin-top: 8px;
}
.card {
    background: #fff;
    border-radius: 12px;
    -webkit-border-radius: 12px;
    padding: 24px 20px;
    margin-bottom: 16px;
    -webkit-box-shadow: 0 1px 4px rgba(0,0,0,0.06);
    box-shadow: 0 1px 4px rgba(0,0,0,0.06);
}
.card h2 {
    font-size: 18px;
    font-weight: 600;
    margin-bottom: 20px;
    color: #333;
}
.card p {
    font-size: 14px;
    color: #666;
    line-height: 1.6;
    margin-bottom: 16px;
}
.btn-install {
    display: block;
    width: 100%;
    height: 48px;
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
}
.btn-install:active {
    background: #06ad56;
}
.log-area {
    background: #1a1a2e;
    border-radius: 8px;
    -webkit-border-radius: 8px;
    padding: 16px;
    max-height: 400px;
    overflow-y: auto;
    -webkit-overflow-scrolling: touch;
    font-family: "SF Mono", "Menlo", "Monaco", "Courier New", monospace;
    font-size: 12px;
    line-height: 1.8;
    color: #ccc;
}
.log-area .log-ok {
    color: #07c160;
}
.log-area .log-err {
    color: #ff4d4f;
}
.log-area .log-info {
    color: #69b1ff;
}
.log-area .log-success {
    color: #ffd700;
    font-weight: bold;
}
.success-box {
    background: #f0fff4;
    border: 1px solid #b7eb8f;
    border-radius: 8px;
    -webkit-border-radius: 8px;
    padding: 16px;
    margin-top: 16px;
}
.success-box h3 {
    color: #07c160;
    font-size: 16px;
    margin-bottom: 12px;
}
.success-box p {
    font-size: 13px;
    color: #333;
    line-height: 1.6;
    margin-bottom: 8px;
}
.success-box .warn {
    margin-top: 12px;
    padding: 10px;
    background: #fffbe6;
    border: 1px solid #ffe58f;
    border-radius: 6px;
    -webkit-border-radius: 6px;
    font-size: 13px;
    color: #d48806;
}
.footer {
    text-align: center;
    padding: 20px 0;
    font-size: 12px;
    color: #bbb;
}
</style>
</head>
<body>
<div class="container">
    <div class="header">
        <div class="logo">DOGECHAT</div>
        <div class="subtitle">数据库安装向导 (SQLite)</div>
    </div>

    <?php if (!$installed): ?>
    <div class="card">
        <h2>一键安装</h2>
        <p>系统将自动创建 SQLite 数据库文件及所有数据表，无需手动配置数据库连接信息。</p>
        <p>数据库文件将保存在 <code>data/dogechat.db</code>。</p>
        <form method="POST" action="">
            <button type="submit" name="do_install" class="btn-install">一键安装</button>
        </form>
    </div>
    <?php endif; ?>

    <?php if (!empty($log_lines)): ?>
    <div class="card">
        <h2>安装日志</h2>
        <div class="log-area">
            <?php foreach ($log_lines as $line): ?>
                <?php
                $cls = '';
                if (strpos($line, '[OK]') === 0) $cls = 'log-ok';
                elseif (strpos($line, '[ERROR]') === 0) $cls = 'log-err';
                elseif (strpos($line, '[INFO]') === 0) $cls = 'log-info';
                elseif (strpos($line, '[SUCCESS]') === 0) $cls = 'log-success';
                ?>
                <div class="<?php echo $cls; ?>"><?php echo htmlspecialchars($line); ?></div>
            <?php endforeach; ?>
        </div>

        <?php if ($installed): ?>
        <div class="success-box">
            <h3>安装成功！</h3>
            <p>DOGECHAT 已使用 SQLite 数据库安装成功。</p>
            <p>数据库文件位置：data/dogechat.db</p>
            <div class="warn">
                安装完成后请删除 setup.php 以确保安全。
            </div>
        </div>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <div class="footer">
        DOGECHAT 开源版
    </div>
</div>
</body>
</html>
