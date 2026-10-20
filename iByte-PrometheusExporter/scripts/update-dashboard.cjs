const fs = require('node:fs');
const path = require('node:path');
const file = path.join(__dirname, '../release/Monitoring Setup/dashboard.json');
const dashboard = JSON.parse(fs.readFileSync(file, 'utf8'));

dashboard.templating.list = dashboard.templating.list.filter(v => v.name !== 'panel');
const clean = (expr) => expr.replaceAll('panel_id=~"$panel",', '').replaceAll('{panel_id=~"$panel"},', '')
  .replaceAll('ibyte_pelican_exporter_enabled', 'ibyte_pelican_exporter_key_active');
// Rename legacy names from earlier previews when present.
const renames = [
  ['ibyte_pelican_node_backup_count', 'ibyte_pelican_node_backups_stored'],
  ['ibyte_pelican_node_backup_bytes', 'ibyte_pelican_node_backups_bytes'],
];
for (const variable of dashboard.templating.list) {
  variable.definition = clean(variable.definition);
  if (variable.query && variable.query.query) variable.query.query = variable.definition;
}
for (const panel of dashboard.panels) {
  if (panel.title === 'Collection enabled (0 = disabled)') panel.title = 'Scrape key active (0 = idle)';
  for (const target of panel.targets || []) {
    target.expr = clean(target.expr);
    for (const [from, to] of renames) target.expr = target.expr.replaceAll(from, to);
    // Show readable names on usage/limit graphs and the state table.
    if (target.expr.includes('server_uuid=~"$server"') && panel.type === 'timeseries' &&
        !target.legendFormat.includes('{{name}}')) {
      target.legendFormat = target.legendFormat.replace('{{server_uuid}}', '{{name}} ({{owner}}, {{server_uuid}})');
    }
  }
  if (panel.type === 'table') panel.fieldConfig = {defaults: {custom: {filterable: true}}, overrides: []};
}

const nextId = () => Math.max(...dashboard.panels.map(p => p.id)) + 1;
let nextY = Math.max(...dashboard.panels.map(p => p.gridPos.y + p.gridPos.h));
if (!dashboard.panels.some(p => p.title === 'Backups stored per node (count)')) {
  dashboard.panels.push({id: nextId(), type: 'timeseries', title: 'Backups stored per node (count)',
    datasource: '${DS_PROMETHEUS}', gridPos: {h: 6, w: 12, x: 0, y: nextY},
    targets: [{refId: 'A', expr: 'ibyte_pelican_node_backups_stored{node_id=~"$node"}', legendFormat: 'Node {{node_id}}'}],
    fieldConfig: {defaults: {min: 0, custom: {spanNulls: false}}, overrides: []}});
  dashboard.panels.push({id: nextId(), type: 'timeseries', title: 'Backup storage per node (bytes)',
    datasource: '${DS_PROMETHEUS}', gridPos: {h: 6, w: 12, x: 12, y: nextY},
    targets: [{refId: 'A', expr: 'ibyte_pelican_node_backups_bytes{node_id=~"$node"}', legendFormat: 'Node {{node_id}}'}],
    fieldConfig: {defaults: {unit: 'bytes', min: 0, custom: {spanNulls: false}}, overrides: []}});
  dashboard.panels.push({id: nextId(), type: 'text', title: 'Reading these graphs',
    gridPos: {h: 4, w: 24, x: 0, y: nextY + 6}, options: {mode: 'markdown', content:
      'CPU 1.0 means one logical core. Unlimited allocations have no limit line. Missing or stale usage appears as a gap. Node health describes API polling; disk sample age is unknown because Wings caches disk use. Collection, scraping and dashboard refresh have independent timing. History begins with the first scrape and lasts until Prometheus retention removes it.'}});
}
dashboard.version = (dashboard.version || 0) + 1;
fs.writeFileSync(file, JSON.stringify(dashboard, null, 2) + '\n');
console.log('dashboard ok, panels:', dashboard.panels.length);
