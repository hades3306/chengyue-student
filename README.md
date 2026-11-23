# 澄岳学生管理系统

澄岳学生管理系统（品牌：澄岳学务）是澄岳教育科技有限公司面向校区教务与学生工作打造的综合管理平台，覆盖学籍、班级、课程、成绩、考勤、收费、师资与校区报表，帮助教务处将日常教学运行数据统一到一套工作台中。

## 技术栈

- PHP 8.1（原生开发，无框架、无 Composer、无外部 CDN）
- SQLite 3（首次访问自动初始化）
- HTML5 / CSS3 / 原生 JavaScript
- PHP 内置服务器运行，项目同级 `../tools/php` 提供便携式 PHP 运行环境

## 功能模块

| 模块 | 说明 |
| --- | --- |
| 工作台 | 学籍规模、今日出勤、课程数、学费收缴率、班级运行概览与校园通知 |
| 学生档案 | 学生基础信息、学籍状态、专业班级、成绩、考勤与缴费档案 |
| 班级管理 | 行政班级、专业归属、辅导员、固定教室、班级规模与运行指标 |
| 课程管理 | 学期课程、授课教师、学分学时、上课安排与修读规模 |
| 成绩管理 | 期中成绩录入、按课程/班级/学生筛选、成绩分布与均分分析 |
| 考勤管理 | 出勤、迟到、请假、缺勤登记，班级与日期维度考勤汇总 |
| 收费记录 | 学费应收、实缴、补缴登记、缴费状态与班级收缴进度 |
| 教师管理 | 教师档案、职称、院系、授课课时与辅导员任职情况 |
| 报表中心 | 专业分布、成绩分布、班级运行汇总、学业排名与教师课时报表 |

## 快速启动

### Windows

双击根目录的 `run.bat`，将启动两个本地服务并打开浏览器：

| 服务 | 地址 |
| --- | --- |
| 澄岳学生管理系统 | http://127.0.0.1:18100/ |
| 合作方学籍数据交换服务 | http://127.0.0.1:18101/student-status.txt |

### 手动启动

```bash
# 主系统
../tools/php/php.exe -S 127.0.0.1:18100 -t public

# 另开终端，启动合作方学籍数据交换服务
../tools/php/php.exe -S 127.0.0.1:18101 -t integrations/edu-data-exchange integrations/edu-data-exchange/router.php
```

已经安装 PHP 8.1 及 `pdo_sqlite`、`curl`、`mbstring` 扩展的环境也可以直接使用 `php -S` 启动。

## 默认账号

| 账号 | 密码 | 姓名 | 角色 | 部门 |
| --- | --- | --- | --- | --- |
| `admin` | `Chengyue@2026` | 陈砚清 | 系统管理员 | 信息中心 |
| `registrar` | `Campus@2026` | 苏婉宁 | 教务管理员 | 教务处 |

正式使用前请修改默认账号口令。

## 初始化与重置

首次访问任意页面时，系统会自动创建 `database/chengyue-campus.sqlite` 并写入院系、专业、班级、学生、课程、成绩、考勤、收费与通知等初始数据。

重置数据：

```bash
rm -f database/chengyue-campus.sqlite database/chengyue-campus.sqlite-wal database/chengyue-campus.sqlite-shm
../tools/php/php.exe database/init.php
```

Windows 命令提示符：

```bat
del /f /q database\chengyue-campus.sqlite*
..\tools\php\php.exe database\init.php
```

## 目录结构

```text
chengyue-student/
├─ lib/                          # 启动引导、数据库、认证与辅助函数
├─ database/
│  ├─ schema.sql                 # SQLite 表结构
│  ├─ init.php                   # 建表、初始数据与目录初始化
│  └─ chengyue-campus.sqlite     # 首次访问自动生成
├─ public/                       # Web 根目录
│  ├─ login.php / dashboard.php / students.php / classes.php
│  ├─ courses.php / grades.php / attendance.php / tuition.php
│  ├─ teachers.php / reports.php
│  ├─ partials/                  # 页面布局
│  ├─ assets/                    # 样式与脚本
│  ├─ cyedu-uploads/             # 学生头像与报名材料上传目录
│  └─ cyedu-*                    # 校内业务与运维接口目录
├─ integrations/
│  └─ edu-data-exchange/         # 合作方学籍数据交换服务
├─ docs/                         # 运维与接口资料
├─ storage/                      # 报表源文件、归档与导出目录
├─ run.bat / run.sh              # 一键启动脚本
└─ README.md
```

## 使用范围

本系统用于校区内部教学管理与学生事务处理，包含学生、成绩、考勤与收费等敏感信息，仅限在校内网络和授权环境中使用。请勿将服务直接暴露到公网，请勿录入真实身份证号、银行卡号等敏感数据，上传目录与归档目录应定期清理。
