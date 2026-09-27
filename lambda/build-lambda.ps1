$ErrorActionPreference = "Stop"
Remove-Item -Force function.zip -ErrorAction SilentlyContinue
npm install
Compress-Archive -Path index.mjs, package.json, node_modules -DestinationPath function.zip -Force
Write-Host "Selesai: function.zip"
