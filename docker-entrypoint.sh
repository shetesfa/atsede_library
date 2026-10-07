#!/bin/bash
set -e

# Default to 80 if PORT is not set by hosting environment
ACTUAL_PORT="${PORT:-80}"

# Safely inject the exact numeric port into Apache config
sed -i "s/Listen .*/Listen ${ACTUAL_PORT}/g" /etc/apache2/ports.conf
sed -i "s/<VirtualHost \*:.*>/<VirtualHost \*:${ACTUAL_PORT}>/g" /etc/apache2/sites-available/000-default.conf

echo "Starting Apache on port ${ACTUAL_PORT}..."

# Start Apache in foreground
exec apache2-foreground
