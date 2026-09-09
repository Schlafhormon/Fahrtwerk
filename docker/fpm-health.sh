#!/bin/sh
set -eu
SCRIPT_FILENAME=/var/www/html/public/index.php REQUEST_METHOD=GET REQUEST_URI=/health SCRIPT_NAME=/index.php SERVER_NAME=localhost SERVER_PORT=80 cgi-fcgi -bind -connect 127.0.0.1:9000 | grep -q '"status":"ok"'
