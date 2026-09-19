@echo off
REM One-command entry point for the plugin e2e harness.
REM tsx is borrowed from platform/node_modules purely so the platform's
REM TypeScript Recorder can be imported read-only; nothing is written there.
node "%~dp0..\..\..\platform\node_modules\tsx\dist\cli.mjs" "%~dp0orchestrate.mjs" %*
