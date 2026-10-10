#!/bin/bash
set -e

# Render provides the port to listen on via $PORT (usually 10000).
# Apache's stock config hardcodes port 80, so rewrite it at container start.
PORT="${PORT:-80}"
sed -i "s/80/${PORT}/g" /etc/apache2/ports.conf
sed -i "s/:80/:${PORT}/g" /etc/apache2/sites-enabled/000-default.conf

exec apache2-foreground
