import json
import re
from urllib.parse import urlencode
from urllib.request import urlopen

# Extract expressions with a simple regex instead of spawning node (permission denied
# for the host node binary inside the acceptance distro).
file = '/mnt/c/Users/kakow/OneDrive/Desktop/Production/Code/Pelican Extensions/iByte-PrometheusExporter/release/Monitoring Setup/dashboard.json'
expressions = set(re.findall(r'"expr":\s*"((?:[^"\\]|\\.)*)"', open(file).read()))
queries = [json.loads(f'"{expr}"') for expr in expressions]

base = 'http://127.0.0.1:9090/api/v1/query'
failures = []
for expr in queries:
    response = json.loads(urlopen(f'{base}?{urlencode({"query": expr})}').read())
    if response['status'] != 'success':
        failures.append((expr, response.get('error', 'unknown error')))

print(f'checked {len(queries)} expressions -> {len(failures)} errors')
for expr, error in failures:
    print('FAIL:', expr[:100])
    print('     ', error)
if not failures:
    print('PASS all dashboard expressions are valid PromQL')