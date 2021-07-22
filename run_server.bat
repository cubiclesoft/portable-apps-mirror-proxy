@echo off
cls

title PHP Portable Apps mirror/proxy server

echo Tip:  Install this mirror/proxy server as a system service that starts at boot by right-clicking 'config.bat' and selecting 'Run as administrator'.  Then use the 'service' and 'install' options.
echo.

php-win\php.exe server.php

pause
