# 合作方学籍数据交换服务

本目录提供合作院校与校区服务平台之间的学籍数据交换联调服务，返回固定的学籍状态报文，供学生管理系统内的数据拉取流程联调使用。

- `edu-data-exchange/student-status.txt`：学籍状态交换报文
- `edu-data-exchange/router.php`：报文服务入口，响应头携带 `X-Gateway-Marker: STUDENT-STATUS-GATEWAY`

启动：

```bash
../tools/php/php.exe -S 127.0.0.1:18101 -t integrations/edu-data-exchange integrations/edu-data-exchange/router.php
```

访问 `http://127.0.0.1:18101/student-status.txt` 获取报文。该服务与主系统一起由根目录 `run.bat` / `run.sh` 启动。
