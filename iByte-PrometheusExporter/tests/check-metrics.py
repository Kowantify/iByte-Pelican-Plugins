import subprocess

# Validate the current fixture exposition with promtool and report detailed findings.
out = open('/opt/ibyte-prometheus/metrics.txt').read()
lines = out.splitlines()
for name in ('server_info', 'node_backups_stored', 'node_backups_bytes'):
    help_lines = [line for line in lines if line.startswith(f'# HELP ibyte_pelican_{name} ')]
    type_lines = [line for line in lines if line.startswith(f'# TYPE ibyte_pelican_{name} ')]
    print(name, 'help:', len(help_lines), 'type:', len(type_lines))

result = subprocess.run(['docker', 'exec', '-i', 'monitoring-prometheus-1', 'promtool', 'check', 'metrics'],
                        stdin=open('/opt/ibyte-prometheus/metrics.txt'), capture_output=True, text=True)
print('promtool exit:', result.returncode)
print(result.stdout.strip())
print(result.stderr.strip())