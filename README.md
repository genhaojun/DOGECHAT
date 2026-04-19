# DOGECHAT(巨信)开源版

QQ交流群：308780649

> 🚀 轻量级、零依赖、开箱即用的聊天系统

![PHP](https://img.shields.io/badge/PHP-7.4%2B-blue)
![SQLite](https://img.shields.io/badge/SQLite-3.0-green)
![License](https://img.shields.io/badge/License-MIT-orange)

---

<img width="899" height="645" alt="截屏2026-04-18 22 01 48" src="https://github.com/user-attachments/assets/8dcf6b2e-96fc-4ea7-847b-0f4fb50e1579" />


## ✨ 项目简介

DOGECHAT 是一个轻量级的即时通讯系统，采用纯 PHP + SQLite 开发。

## 🎯 核心特性

### 💬 即时通讯
- 📨 **私聊** — 一对一实时消息，自动轮询推送
- 👥 **群聊** — 创建群组、多人畅聊
- 🔴 **未读提醒** — 红色圆点提示未读消息，点击即消

### 👤 用户系统
- 🔐 **注册登录** — 用户名+密码，密保问题找回
- ✏️ **修改昵称** — 随时更改显示名称
- 🔑 **修改密码** — 支持旧密码验证
- 🚫 **账号封禁** — 管理员可封禁违规用户

### 👥 社交功能
- 🤝 **好友管理** — 添加/删除好友，设置备注名
- 🏠 **群组系统** — 一键创建群聊，自由加入退出
- 📇 **通讯录** — 查看所有注册用户，快速添加好友

### 🛡️ 管理后台
- 📊 **数据统计** — 用户数、消息数、群聊数一目了然
- 👥 **用户管理** — 启用/禁用/删除用户
- 🏠 **群聊管理** — 查看/删除违规群聊

### 📱 界面设计
- 🎨 **超级熟悉易上手的UI界面**
- 📱 **移动端适配** — 完美兼容 iOS 5+ / Android 4+
- ⚡ **极速加载** — 纯原生 JS，无任何前端框架依赖
- 🌙 **iOS 5 兼容** — 全部 CSS 使用 -webkit- 前缀

## 🔧 技术栈

| 技术 | 说明 |
|------|------|
| 🐘 PHP 7.4+ | 后端逻辑，纯原生开发 |
| 🗄️ SQLite 3 | 零配置数据库，PDO 驱动 |
| 📄 HTML5/CSS3 | 前端页面，无框架依赖 |
| ☕ JavaScript ES5 | 前端交互，兼容老设备 |
| 🔒 PDO Prepared | SQL 注入防护 |

## 📦 项目结构

```
dogechat/
├── setup.php        # 📋 一键安装向导
├── index.php        # 🔐 登录/注册/找回密码
├── chat.php         # 💬 聊天主页面 + API
├── admin.php        # 🛡️ 管理后台
├── reg.html         # 📜 用户条款
├── data/            # 🗄️ SQLite 数据库
│   └── dogechat.db
└── README.md        # 📖 项目说明
```

## 🚀 快速部署

### 环境要求
- PHP 7.4+（需启用 `pdo_sqlite` 扩展）

### 安装步骤

```bash
# 1. 上传所有文件到网站根目录

# 2. 确保目录有写权限
chmod -R 755 /path/to/dogechat/
chmod -R 777 /path/to/dogechat/data/

# 3. 浏览器访问安装页面
https://your-domain.com/setup.php

# 4. 点击"一键安装"

# 5. 安装完成后删除安装文件
rm setup.php
```

### 访问地址

| 页面 | 地址 |
|------|------|
| 🏠 首页（登录） | `https://your-domain.com/index.php` |
| 💬 聊天 | `https://your-domain.com/chat.php` |
| 🛡️ 管理后台 | `https://your-domain.com/admin.php` |
| 📜 用户条款 | `https://your-domain.com/reg.html` |

### 默认管理员密码

```
dc_admin_2024
```

> ⚠️ 请在首次登录后立即修改管理员密码！

## 📱 功能截图

### 聊天界面
- 左侧边栏：消息 / 群聊 / 通讯录 三个标签页
- 右侧聊天区：消息气泡 + 输入框
- 自己的消息靠右（绿色气泡 🟢）
- 别人的消息靠左（白色气泡 ⚪）
- 系统消息居中（灰色文字）

### 管理后台
- 📊 统计面板：用户数 / 消息数 / 群聊数
- 👥 用户管理：启用 / 禁用 / 删除
- 🏠 群聊管理：查看 / 删除

## 📋 数据库表结构

| 表名 | 说明 |
|------|------|
| `dc_members` | 用户账号表 |
| `dc_ban_history` | 封禁日志表 |
| `dc_rooms` | 群聊表 |
| `dc_room_users` | 群成员表 |
| `dc_direct_msgs` | 私聊消息表 |
| `dc_room_msgs` | 群聊消息表 |
| `dc_contacts` | 好友关系表 |

## 🔄 API 接口

所有接口通过 POST 请求调用，参数 `act` 指定操作类型。

| act | 说明 |
|-----|------|
| `act_dm_send` | 发送私聊消息 |
| `act_dm_fetch` | 获取私聊消息 |
| `act_room_send` | 发送群聊消息 |
| `act_room_fetch` | 获取群聊消息 |
| `act_poll` | 轮询新消息 |
| `act_room_create` | 创建群聊 |
| `act_room_join` | 加入群聊 |
| `act_contact_add` | 添加好友 |
| `act_contact_del` | 删除好友 |
| `act_contact_remark` | 设置好友备注 |
| `act_nick_update` | 修改昵称 |
| `act_pwd_change` | 修改密码 |
| `act_logout` | 退出登录 |

## 🤝 贡献

欢迎提交 Issue 和 Pull Request！

---

<p align="center">
  Powered by <strong>APPDOGE & DogeCloud and @genhaojun</strong>
</p>
