@echo off
rem Finto ssh.exe per le prove: inoltra l'ultimo argomento (il comando remoto) a backup-readonly.sh con una HOME finta.
"%FAKE_SH%" "%~dp0fake-ssh.sh" %*
exit /b %ERRORLEVEL%
