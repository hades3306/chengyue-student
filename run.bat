@echo off
setlocal
cd /d "%~dp0"

set "PHP=%~dp0..\tools\php\php.exe"
if not exist "%PHP%" set "PHP=php"

echo [澄岳学务] 正在启动学生管理系统：http://127.0.0.1:18100
start "ChengyueStudent" "%PHP%" -S 127.0.0.1:18100 -t "%~dp0public"

echo [澄岳学务] 正在启动合作方学籍数据交换服务：http://127.0.0.1:18101/student-status.txt
start "EduDataExchange" "%PHP%" -S 127.0.0.1:18101 -t "%~dp0integrations\edu-data-exchange" "%~dp0integrations\edu-data-exchange\router.php"

timeout /t 2 /nobreak >nul
start "" http://127.0.0.1:18100/

echo.
echo 服务已启动。关闭对应命令行窗口即可停止服务。
echo 默认账号：admin / Chengyue@2026
endlocal
