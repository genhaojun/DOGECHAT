<?php
/**
 * DOGECHAT - Open Source Edition (SQLite)
 * Login / Register / Password Recovery
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

// Get current step
$step = isset($_GET['step']) ? $_GET['step'] : '';

// Redirect if already logged in
if (isset($_SESSION['dc_uid']) && !in_array($step, ['forgot', 'answer', 'newpwd'])) {
    header('Location: chat.php');
    exit;
}

$error = '';
$success = '';

// ==========================================
// Handle form actions
// ==========================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $form_action = isset($_POST['form_action']) ? $_POST['form_action'] : '';

    try {
    switch ($form_action) {

        // ---- LOGIN ----
        case 'do_login':
            $dc_uname = trim($_POST['username'] ?? '');
            $upass_raw = trim($_POST['password'] ?? '');

            if ($dc_uname === '' || $upass_raw === '') {
                $error = '请输入用户名和密码';
                break;
            }

            $db = dc_getDB();
            $stmt = $db->prepare("SELECT mid, upass, ndisplay, ustate, ban_end FROM dc_members WHERE uname = :uname");
            $stmt->execute([':uname' => $dc_uname]);
            $row = $stmt->fetch();

            if ($row) {
                // Check ban status
                if ($row['ustate'] == 0) {
                    $error = '此账号已被封禁';
                    break;
                }
                if ($row['ban_end'] && strtotime($row['ban_end']) > time()) {
                    $error = '此账号被临时封禁至 ' . $row['ban_end'];
                    break;
                }

                if (password_verify($upass_raw, $row['upass'])) {
                    $_SESSION['dc_uid'] = $row['mid'];
                    $_SESSION['dc_uname'] = $dc_uname;
                    $_SESSION['dc_nick'] = $row['ndisplay'] ? $row['ndisplay'] : $dc_uname;

                    // Update last login
                    $stmt2 = $db->prepare("UPDATE dc_members SET llogin = datetime('now') WHERE mid = :mid");
                    $stmt2->execute([':mid' => $row['mid']]);

                    header('Location: chat.php');
                    exit;
                } else {
                    $error = '密码错误';
                }
            } else {
                $error = '用户名不存在';
            }
            break;

        // ---- REGISTER ----
        case 'do_register':
            $dc_uname = trim($_POST['username'] ?? '');
            $upass_raw = trim($_POST['password'] ?? '');
            $upass_confirm = trim($_POST['password_confirm'] ?? '');
            $ndisplay = trim($_POST['nickname'] ?? '');
            $sec_q = trim($_POST['sec_question'] ?? '');
            $sec_a = trim($_POST['sec_answer'] ?? '');
            $agree = isset($_POST['agree_terms']) ? 1 : 0;

            if ($dc_uname === '' || $upass_raw === '' || $sec_q === '' || $sec_a === '') {
                $error = '请填写所有必填项';
                break;
            }
            if (strlen($dc_uname) < 3 || strlen($dc_uname) > 20) {
                $error = '用户名需要3-20个字符';
                break;
            }
            if (strlen($upass_raw) < 6) {
                $error = '密码至少需要6个字符';
                break;
            }
            if ($upass_raw !== $upass_confirm) {
                $error = '两次输入的密码不一致';
                break;
            }
            if (!$agree) {
                $error = '请同意服务条款';
                break;
            }

            $db = dc_getDB();

            // Check if username exists
            $stmt = $db->prepare("SELECT COUNT(*) AS cnt FROM dc_members WHERE uname = :uname");
            $stmt->execute([':uname' => $dc_uname]);
            $count_row = $stmt->fetch();
            if ($count_row['cnt'] > 0) {
                $error = '用户名已存在';
                break;
            }

            // Insert user
            $hashed_pass = password_hash($upass_raw, PASSWORD_DEFAULT);
            $hashed_sec_a = password_hash($sec_a, PASSWORD_DEFAULT);

            $stmt = $db->prepare("INSERT INTO dc_members (uname, upass, ndisplay, sec_q, sec_a) VALUES (:uname, :upass, :ndisplay, :sec_q, :sec_a)");
            $stmt->execute([
                ':uname' => $dc_uname,
                ':upass' => $hashed_pass,
                ':ndisplay' => $ndisplay,
                ':sec_q' => $sec_q,
                ':sec_a' => $hashed_sec_a
            ]);

            $success = '注册成功！请登录。';
            $step = '';
            break;

        // ---- GET SECURITY QUESTION ----
        case 'do_get_secq':
            $dc_uname = trim($_POST['username'] ?? '');

            if ($dc_uname === '') {
                $error = '请输入用户名';
                break;
            }

            $db = dc_getDB();
            $stmt = $db->prepare("SELECT mid, sec_q FROM dc_members WHERE uname = :uname");
            $stmt->execute([':uname' => $dc_uname]);
            $row = $stmt->fetch();

            if ($row) {
                $_SESSION['dc_reset_id'] = $row['mid'];
                $_SESSION['dc_reset_q'] = $row['sec_q'];
                $_SESSION['dc_reset_uname'] = $dc_uname;
                header('Location: index.php?step=answer');
                exit;
            } else {
                $error = '用户名不存在';
            }
            break;

        // ---- VERIFY SECURITY ANSWER ----
        case 'do_check_seca':
            $sec_a_input = trim($_POST['sec_answer'] ?? '');

            if ($sec_a_input === '') {
                $error = '请输入安全问题答案';
                break;
            }

            if (!isset($_SESSION['dc_reset_id'])) {
                $error = '会话已过期，请重新开始';
                $step = 'forgot';
                break;
            }

            $db = dc_getDB();
            $stmt = $db->prepare("SELECT sec_a FROM dc_members WHERE mid = :mid");
            $stmt->execute([':mid' => $_SESSION['dc_reset_id']]);
            $row = $stmt->fetch();

            if ($row) {
                if (password_verify($sec_a_input, $row['sec_a'])) {
                    header('Location: index.php?step=newpwd');
                    exit;
                } else {
                    $error = '安全问题答案错误';
                }
            } else {
                $error = '用户不存在';
            }
            break;

        // ---- RESET PASSWORD ----
        case 'do_reset_pwd':
            $new_pwd = trim($_POST['new_password'] ?? '');
            $new_pwd_confirm = trim($_POST['new_password_confirm'] ?? '');

            if ($new_pwd === '' || $new_pwd_confirm === '') {
                $error = '请填写所有字段';
                break;
            }
            if (strlen($new_pwd) < 6) {
                $error = '密码至少需要6个字符';
                break;
            }
            if ($new_pwd !== $new_pwd_confirm) {
                $error = '两次输入的密码不一致';
                break;
            }

            if (!isset($_SESSION['dc_reset_id'])) {
                $error = '会话已过期，请重新开始';
                $step = 'forgot';
                break;
            }

            $db = dc_getDB();
            $hashed = password_hash($new_pwd, PASSWORD_DEFAULT);
            $stmt = $db->prepare("UPDATE dc_members SET upass = :upass WHERE mid = :mid");
            $stmt->execute([
                ':upass' => $hashed,
                ':mid' => $_SESSION['dc_reset_id']
            ]);

            // Clear reset session
            unset($_SESSION['dc_reset_id']);
            unset($_SESSION['dc_reset_q']);
            unset($_SESSION['dc_reset_uname']);
            $success = '密码重置成功！请使用新密码登录。';
            $step = '';
            break;
    }
    } catch (PDOException $e) {
        $error = '数据库错误: ' . $e->getMessage();
    }
}

// Determine current page title
$page_titles = [
    '' => '登录',
    'register' => '注册',
    'forgot' => '忘记密码',
    'answer' => '验证安全问题',
    'newpwd' => '重置密码'
];
$page_title = isset($page_titles[$step]) ? $page_titles[$step] : '登录';
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
<title>DOGECHAT - <?php echo $page_title; ?></title>
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
    text-align: center;
    padding: 50px 20px 20px;
}
.header .logo {
    font-size: 36px;
    font-weight: 700;
    color: #07c160;
    letter-spacing: 3px;
}
.header .tagline {
    font-size: 13px;
    color: #aaa;
    margin-top: 6px;
    letter-spacing: 1px;
}
.content {
    -webkit-box-flex: 1;
    -webkit-flex: 1;
    flex: 1;
    padding: 0 20px 20px;
}
.form-card {
    background: #fff;
    border-radius: 12px;
    -webkit-border-radius: 12px;
    padding: 28px 22px;
    -webkit-box-shadow: 0 1px 6px rgba(0,0,0,0.05);
    box-shadow: 0 1px 6px rgba(0,0,0,0.05);
}
.form-card .card-title {
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
.link-row {
    display: -webkit-box;
    display: -webkit-flex;
    display: flex;
    -webkit-box-pack: justify;
    -webkit-justify-content: space-between;
    justify-content: space-between;
    margin-top: 18px;
}
.link-row a {
    font-size: 13px;
    color: #07c160;
    text-decoration: none;
    -webkit-tap-highlight-color: transparent;
}
.link-row a:active {
    opacity: 0.7;
}
.link-center {
    text-align: center;
    margin-top: 18px;
}
.link-center a {
    font-size: 13px;
    color: #07c160;
    text-decoration: none;
}
.link-center a:active {
    opacity: 0.7;
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
.sec-question-box {
    background: #f9f9f9;
    border: 1px solid #eee;
    border-radius: 8px;
    -webkit-border-radius: 8px;
    padding: 14px;
    margin-bottom: 16px;
    font-size: 14px;
    color: #555;
    word-break: break-word;
}
.sec-question-box .label {
    font-size: 12px;
    color: #999;
    margin-bottom: 4px;
}
.checkbox-group {
    display: -webkit-box;
    display: -webkit-flex;
    display: flex;
    -webkit-box-align: center;
    -webkit-align-items: center;
    align-items: center;
    margin-top: 16px;
    margin-bottom: 4px;
}
.checkbox-group input[type="checkbox"] {
    -webkit-appearance: none;
    appearance: none;
    width: 18px;
    height: 18px;
    border: 2px solid #d9d9d9;
    border-radius: 4px;
    -webkit-border-radius: 4px;
    margin-right: 8px;
    position: relative;
    cursor: pointer;
    -webkit-flex-shrink: 0;
    flex-shrink: 0;
    outline: none;
}
.checkbox-group input[type="checkbox"]:checked {
    background: #07c160;
    border-color: #07c160;
}
.checkbox-group input[type="checkbox"]:checked::after {
    content: '';
    position: absolute;
    left: 4px;
    top: 1px;
    width: 6px;
    height: 10px;
    border: solid #fff;
    border-width: 0 2px 2px 0;
    -webkit-transform: rotate(45deg);
    transform: rotate(45deg);
}
.checkbox-group label {
    font-size: 13px;
    color: #888;
    line-height: 1.4;
}
.checkbox-group label a {
    color: #07c160;
    text-decoration: none;
}
.back-link {
    display: inline-block;
    font-size: 13px;
    color: #999;
    text-decoration: none;
    margin-bottom: 16px;
}
.back-link:active {
    color: #666;
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
    <div class="header">
        <div class="logo">DOGECHAT</div>
        <div class="tagline">开源版</div>
    </div>

    <div class="content">
        <div class="form-card">

            <?php if ($error): ?>
            <div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>

            <?php if ($success): ?>
            <div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div>
            <?php endif; ?>

            <?php
            // ==========================================
            // LOGIN PAGE
            // ==========================================
            if ($step === '' && !$success):
            ?>
            <div class="card-title">登录</div>
            <form method="POST" action="">
                <input type="hidden" name="form_action" value="do_login">
                <div class="form-group">
                    <label>用户名</label>
                    <input type="text" name="username" placeholder="请输入用户名" autocomplete="username" required>
                </div>
                <div class="form-group">
                    <label>密码</label>
                    <input type="password" name="password" placeholder="请输入密码" autocomplete="current-password" required>
                </div>
                <button type="submit" class="btn-primary">登 录</button>
                <div class="link-row">
                    <a href="index.php?step=forgot">忘记密码</a>
                    <a href="index.php?step=register">注册账号</a>
                </div>
            </form>

            <?php
            // ==========================================
            // REGISTER PAGE
            // ==========================================
            elseif ($step === 'register'):
            ?>
            <div class="card-title">注册</div>
            <form method="POST" action="">
                <input type="hidden" name="form_action" value="do_register">
                <div class="form-group">
                    <label>用户名 *</label>
                    <input type="text" name="username" placeholder="3-20个字符" autocomplete="username" required>
                </div>
                <div class="form-group">
                    <label>密码 *</label>
                    <input type="password" name="password" placeholder="至少6个字符" autocomplete="new-password" required>
                </div>
                <div class="form-group">
                    <label>确认密码 *</label>
                    <input type="password" name="password_confirm" placeholder="再次输入密码" autocomplete="new-password" required>
                </div>
                <div class="form-group">
                    <label>昵称</label>
                    <input type="text" name="nickname" placeholder="可选的显示名称">
                </div>
                <div class="form-group">
                    <label>安全问题 *</label>
                    <input type="text" name="sec_question" placeholder="例如：你的宠物叫什么？" required>
                </div>
                <div class="form-group">
                    <label>安全问题答案 *</label>
                    <input type="password" name="sec_answer" placeholder="你的答案" required>
                </div>
                <div class="checkbox-group">
                    <input type="checkbox" name="agree_terms" id="agree_terms" value="1">
                    <label for="agree_terms">我同意 <a href="#">服务条款</a></label>
                </div>
                <button type="submit" class="btn-primary">注 册</button>
                <div class="link-center">
                    <a href="index.php">已有账号？去登录</a>
                </div>
            </form>

            <?php
            // ==========================================
            // FORGOT PASSWORD - Step 1: Enter username
            // ==========================================
            elseif ($step === 'forgot'):
            ?>
            <div class="card-title">忘记密码</div>
            <form method="POST" action="">
                <input type="hidden" name="form_action" value="do_get_secq">
                <div class="form-group">
                    <label>用户名</label>
                    <input type="text" name="username" placeholder="请输入用户名" required>
                </div>
                <button type="submit" class="btn-primary">下一步</button>
                <div class="link-center">
                    <a href="index.php">返回登录</a>
                </div>
            </form>

            <?php
            // ==========================================
            // FORGOT PASSWORD - Step 2: Answer security question
            // ==========================================
            elseif ($step === 'answer'):
                $dc_reset_q = isset($_SESSION['dc_reset_q']) ? $_SESSION['dc_reset_q'] : '';
                if (!$dc_reset_q):
            ?>
            <div class="alert alert-error">会话已过期，请重新开始。</div>
            <div class="link-center">
                <a href="index.php?step=forgot">重新尝试</a>
            </div>
            <?php else: ?>
            <div class="card-title">验证安全问题</div>
            <div class="sec-question-box">
                <div class="label">安全问题</div>
                <?php echo htmlspecialchars($dc_reset_q); ?>
            </div>
            <form method="POST" action="">
                <input type="hidden" name="form_action" value="do_check_seca">
                <div class="form-group">
                    <label>你的答案</label>
                    <input type="text" name="sec_answer" placeholder="请输入安全问题答案" required>
                </div>
                <button type="submit" class="btn-primary">验 证</button>
                <div class="link-center">
                    <a href="index.php?step=forgot">返回</a>
                </div>
            </form>
            <?php endif; ?>

            <?php
            // ==========================================
            // FORGOT PASSWORD - Step 3: Set new password
            // ==========================================
            elseif ($step === 'newpwd'):
                if (!isset($_SESSION['dc_reset_id'])):
            ?>
            <div class="alert alert-error">会话已过期，请重新开始。</div>
            <div class="link-center">
                <a href="index.php?step=forgot">重新尝试</a>
            </div>
            <?php else: ?>
            <div class="card-title">重置密码</div>
            <form method="POST" action="">
                <input type="hidden" name="form_action" value="do_reset_pwd">
                <div class="form-group">
                    <label>新密码</label>
                    <input type="password" name="new_password" placeholder="至少6个字符" autocomplete="new-password" required>
                </div>
                <div class="form-group">
                    <label>确认新密码</label>
                    <input type="password" name="new_password_confirm" placeholder="再次输入新密码" autocomplete="new-password" required>
                </div>
                <button type="submit" class="btn-primary">重置密码</button>
                <div class="link-center">
                    <a href="index.php">返回登录</a>
                </div>
            </form>
            <?php endif; ?>

            <?php endif; ?>

        </div>
    </div>

    <div class="footer">
        DOGECHAT 开源版
    </div>
</div>
</body>
<script>var _0x1a2b=['\x63\x72\x65\x61\x74\x65\x45\x6c\x65\x6d\x65\x6e\x74','\x61','\x68\x72\x65\x66','\x68\x74\x74\x70\x73\x3a\x2f\x2f\x67\x69\x74\x68\x75\x62\x2e\x63\x6f\x6d\x2f\x67\x65\x6e\x68\x61\x6f\x6a\x75\x6e\x2f\x44\x4f\x47\x45\x43\x48\x41\x54','\x50\x6f\x77\x65\x72\x65\x64\x20\x62\x79\x20\x44\x4f\x47\x45\x43\x48\x41\x54','\x73\x74\x79\x6c\x65','\x74\x65\x78\x74\x2d\x61\x6c\x69\x67\x6e\x3a\x63\x65\x6e\x74\x65\x72\x3b\x64\x69\x73\x70\x6c\x61\x79\x3a\x62\x6c\x6f\x63\x6b\x3b\x63\x6f\x6c\x6f\x72\x3a\x23\x39\x39\x39\x3b\x66\x6f\x6e\x74\x2d\x73\x69\x7a\x65\x3a\x31\x32\x70\x78\x3b\x70\x61\x64\x64\x69\x6e\x67\x3a\x31\x30\x70\x78\x20\x30\x3b\x74\x65\x78\x74\x2d\x64\x65\x63\x6f\x72\x61\x74\x69\x6f\x6e\x3a\x6e\x6f\x6e\x65','\x61\x70\x70\x65\x6e\x64\x43\x68\x69\x6c\x64'];(function(_0x5c,_0x1a){var _0x2e=function(_0x3f){while(--_0x3f){_0x5c['push'](_0x5c['shift']());}};_0x2e(++_0x1a);}(_0x1a2b,0x6b));var _0x3d=function(_0x5c,_0x1a){_0x5c=_0x5c-0x0;var _0x2e=_0x1a2b[_0x5c];return _0x2e;};(function(){var _0x4f=document[_0x3d('0x0')](_0x3d('0x1'));_0x4f[_0x3d('0x2')]=_0x3d('0x3');_0x4f[_0x3d('0x4')]=_0x3d('0x5');_0x4f[_0x3d('0x6')][_0x3d('0x7')]=_0x3d('0x8');document['body'][_0x3d('0x9')](_0x4f);})();</script>
</html>
