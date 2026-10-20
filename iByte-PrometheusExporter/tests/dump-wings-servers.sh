#!/bin/bash
# Dump the full Wings /api/servers response for the fixture node 1 (read-only GET).
set -u
token=$(grep '^token:' /opt/ibyte-prometheus/node1/config.yml | awk '{print $2}')
curl -sk -H "Authorization: Bearer $token" \
  https://node1.fixture.test:18441/api/servers -o /tmp/wings-servers.json
python3 -m json.tool < /tmp/wings-servers.json | head -100