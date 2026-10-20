// Maintainer utility (0.3.x): rebuild the MASTER dashboard.json for the
// iByte Prometheus Exporter (Pelican). Declarative panel list with grid pairing.
//
// Design notes (grounded in the production data the operator exported):
// - 2 nodes (Gameserver1/2 Wings), 7 servers, all running, CPU <= ~0.9 core each,
//   memory up to ~17 GiB per server, node capacities 60-120 GiB RAM / 500-1000 GB disk.
// - Server usage/history rows join name+owner at query time from
//   ibyte_pelican_server_info; node rows join node_name from ibyte_pelican_node_info.
// - Headroom (limit / used) is threshold-colored: <10% dark-red, <20% red, <35%
//   orange, <60% yellow, else green. CPU/MEM% panels use their own scales.
// - Server table is the drill-down master: sortable rows of name, owner, state,
//   CPU, memory, disk, headroom, uptime.
// Idempotent: running twice keeps one set of panels.
const fs = require('node:fs');
const path = require('node:path');
const file = path.join(__dirname, '../release/Monitoring Setup/dashboard.json');
const previous = fs.existsSync(file) ? JSON.parse(fs.readFileSync(file, 'utf8')) : null;

const dashboard = {
  __inputs: previous?.__inputs ?? [{
    name: 'DS_PROMETHEUS', label: 'Prometheus',
    description: 'Choose your Prometheus data source',
    type: 'datasource', pluginId: 'prometheus', pluginName: 'Prometheus',
  }],
  __requires: previous?.__requires ?? [
    {type: 'grafana', id: 'grafana', name: 'Grafana', version: '12.0.2'},
  ],
  id: null,
  uid: previous?.uid ?? 'ibyte-pelican-resources',
  title: 'Pelican servers',
  description: 'CPU, memory, disk, network and backup metrics for every Pelican node and game server. Names and owners are joined from info metrics at query time. Gaps are missing data, not zeros. Disk freshness depends on the Wings cache.',
  timezone: 'browser',
  schemaVersion: 41,
  version: (previous?.version ?? 0) + 1,
  refresh: '5s',
  time: previous?.time ?? {from: 'now-6h', to: 'now'},
  tags: previous?.tags ?? ['pelican', 'ibyte'],
  templating: previous?.templating ?? {
    list: [{
      name: 'node', type: 'query', label: 'Node',
      datasource: '${DS_PROMETHEUS}',
      definition: 'label_values(ibyte_pelican_node_collection_success,node_id)',
      query: {query: 'label_values(ibyte_pelican_node_collection_success,node_id)', refId: 'StandardVariableQuery'},
      refresh: 1, includeAll: true, multi: true, allValue: '.*', current: {},
    }, {
      name: 'server', type: 'query', label: 'Server UUID',
      datasource: '${DS_PROMETHEUS}',
      definition: 'label_values(ibyte_pelican_server_state{node_id=~"$node"},server_uuid)',
      query: {query: 'label_values(ibyte_pelican_server_state{node_id=~"$node"},server_uuid)', refId: 'StandardVariableQuery'},
      refresh: 1, includeAll: true, multi: true, allValue: '.*', current: {},
    }],
  },
};

// ---------------------------------------------------------------- helpers
const SERVER = 'node_id=~"$node",server_uuid=~"$server"';
const NODE = 'node_id=~"$node"';
const joinInfo = (expr) => `(${expr}) * on (server_uuid) group_left (name, owner) ibyte_pelican_server_info{${NODE}}`;
const joinNode = (expr) => `(${expr}) * on (node_id) group_left (node_name) ibyte_pelican_node_info{${NODE}}`;
const thresholds = (steps) => ({mode: 'absolute', steps});
const TH_HEADROOM = [[0, 'dark-red'], [10, 'dark-red'], [20, 'red'], [35, 'orange'], [60, 'yellow'], [101, 'green']];
const TH_CPU = [[0, 'green'], [60, '#eab839'], [80, 'orange'], [100, 'red']];
const TH_MEM = [[0, 'green'], [70, '#eab839'], [85, 'orange'], [100, 'red']];

const panels = [];
let id = 0;
const nextId = () => ++id;

const statPanel = (title, expr, unit, extra = {}) => ({
  id: nextId(), type: 'stat', title, datasource: '${DS_PROMETHEUS}',
  targets: [{refId: 'A', expr, instant: true, legendFormat: extra.legend ?? title}],
  fieldConfig: {defaults: {
    unit, min: 0, decimals: extra.decimals ?? 2,
    ...(extra.thresholds ? {thresholds: thresholds(extra.thresholds)} : {}),
    ...(extra.max ? {max: extra.max} : {}),
  }, overrides: []},
  gridPos: null,
  ...(extra.options ? {options: extra.options} : {}),
});

const barPanel = (title, expr, unit, x, legend = 'Node {{node_name}}') => ({
  id: nextId(), type: 'barchart', title, datasource: '${DS_PROMETHEUS}',
  targets: [{refId: 'A', expr, instant: true, legendFormat: legend}],
  options: {orientation: 'horizontal', showValue: 'always'},
  fieldConfig: {defaults: {unit, min: 0}, overrides: []},
  gridPos: null, _x: x,
});

const seriesPanel = (title, targets, unit, x) => ({
  id: nextId(), type: 'timeseries', title, datasource: '${DS_PROMETHEUS}',
  targets,
  fieldConfig: {defaults: {unit, min: 0, custom: {spanNulls: false}}, overrides: []},
  gridPos: null, _x: x,
});

const piePanel = (title, targets, x) => ({
  id: nextId(), type: 'piechart', title, datasource: '${DS_PROMETHEUS}',
  targets,
  options: {pieType: 'pie', orientation: 'auto'},
  fieldConfig: {defaults: {unit: 'none'}, overrides: []},
  gridPos: null, _x: x,
});

const tablePanel = (title, exprs) => ({
  id: nextId(), type: 'table', title, datasource: '${DS_PROMETHEUS}',
  targets: exprs.map((expr, i) => ({refId: String.fromCharCode(65 + i), expr, instant: true, format: 'table'})),
  options: {showHeader: true},
  fieldConfig: {defaults: {custom: {filterable: true, align: 'auto'}}, overrides: []},
  gridPos: null,
});

const rowPanel = (title) => ({
  id: nextId(), type: 'row', title,
  gridPos: {h: 1, w: 24, x: 0, y: 0},
});

const textPanel = () => ({
  id: nextId(), type: 'text', title: 'How to read these panels',
  gridPos: null,
  options: {mode: 'markdown', content:
    "CPU is in logical cores (1.0 = one core). Memory and storage are bytes; the limit line is the Panel allocation and unlimited servers have no limit line. 'Limit / current used' is elapsed headroom (max limit / current use), colored: dark-red below 10%, red below 20%, orange below 35%, yellow below 60%, else green. Node health describes Panel-to-Wings API polling; disk sample age is unknown because Wings caches disk use. Collection, scraping, and dashboard refresh are independent; gaps mean missing or stale data, not zero."},
});

// ================================================================ OVERVIEW ROW
panels.push(rowPanel('Fleet overview'));

// Inventory at a glance
panels.push(statPanel('Users', 'ibyte_prometheus_panel_users', 'none', {decimals: 0}));
panels.push(statPanel('Root admins', 'ibyte_prometheus_panel_root_admins', 'none', {decimals: 0}));
panels.push(statPanel('Nodes', 'ibyte_prometheus_panel_nodes', 'none', {decimals: 0}));
panels.push(statPanel('Nodes in maintenance', 'ibyte_prometheus_panel_nodes_maintenance', 'none', {decimals: 0, thresholds: [[0, 'green'], [1, 'orange']]}));
panels.push(statPanel('Servers (total)', 'ibyte_prometheus_panel_servers', 'none', {decimals: 0}));
panels.push(statPanel('Servers suspended', 'ibyte_prometheus_panel_servers_status{status="suspended"}', 'none', {decimals: 0, thresholds: [[0, 'green'], [1, 'orange']]}));
panels.push(statPanel('Servers installing', 'ibyte_prometheus_panel_servers_status{status="installing"}', 'none', {decimals: 0, thresholds: [[0, 'green'], [1, '#eab839']]}));
panels.push(statPanel('Eggs', 'ibyte_prometheus_panel_eggs', 'none', {decimals: 0}));

// Node health: all-up summary + worst age
panels.push(statPanel('Nodes reachable (of $node)',
  'sum(ibyte_prometheus_node_collection_success{'+NODE+'})', 'none', {decimals: 0}));
panels.push(statPanel('Worst node age (s)',
  'max(ibyte_prometheus_node_collection_age_seconds{'+NODE+'})', 's', {
    decimals: 0,
    thresholds: [[0, 'green'], [15, 'green'], [20, 'orange'], [30, 'red']],
  }));

// Failures
panels.push(statPanel('Node consecutive failures (max)',
  'max(ibyte_prometheus_node_consecutive_failures{'+NODE+'})', 'none', {
    decimals: 0,
    thresholds: [[0, 'green'], [1, 'orange'], [3, 'red']],
  }));
panels.push(statPanel('Servers offline (of $node)',
  "sum(ibyte_prometheus_server_state{" + SERVER + ",state=\"offline\"})", 'none', {
    decimals: 0,
    thresholds: [[0, 'green'], [1, '#eab839']],
  }));

// Usage vs limits, node-scoped: allocated vs capacity (percentunit)
panels.push(statPanel('Node memory allocated / capacity',
  'sum by (node_id) (ibyte_prometheus_server_memory_limit_bytes{'+SERVER+'}) / on (node_id) group_left (node_name) ibyte_prometheus_node_memory_capacity_bytes{'+NODE+'}',
  'percentunit', {thresholds: TH_MEM, legend: '{{node_name}}'}));
panels.push(statPanel('Node disk allocated / capacity',
  'sum by (node_id) (ibyte_prometheus_server_disk_limit_bytes{'+SERVER+'}) / on (node_id) group_left (node_name) ibyte_prometheus_node_disk_capacity_bytes{'+NODE+'}',
  'percentunit', {thresholds: TH_MEM, legend: '{{node_name}}'}));

// Node CPU allocated / capacity (sum of server cpu allocations vs node cpu capacity)
panels.push(statPanel('Node CPU allocated / capacity',
  'sum by (node_id) (ibyte_prometheus_server_cpu_limit_cores{'+SERVER+'}) / on (node_id) group_left (node_name) ibyte_prometheus_node_cpu_capacity_cores{'+NODE+'}',
  'percentunit', {thresholds: TH_MEM, legend: '{{node_name}}'}));

// ================================================================ TOP NODES ROW
panels.push(rowPanel('Node leaders'));

panels.push(barPanel('Top 5 nodes by live CPU',
  joinNode('topk(5, avg by (node_id) (ibyte_prometheus_server_cpu_usage_ratio{'+SERVER+'}))'), 'percentunit', 0));
panels.push(barPanel('Top 5 nodes by live memory',
  joinNode('topk(5, sum by (node_id) (ibyte_prometheus_server_memory_usage_bytes{'+SERVER+'}))'), 'bytes', 12));

// Node usage vs allocated, bars
panels.push(barPanel('Memory: live use vs allocated, per node',
  joinNode('sum by (node_id) (ibyte_prometheus_server_memory_usage_bytes{'+SERVER+'}) / sum by (node_id) (ibyte_prometheus_server_memory_limit_bytes{'+SERVER+'})'),
  'percentunit', 0));
panels.push(barPanel('Disk: live use vs allocated, per node',
  joinNode('sum by (node_id) (ibyte_prometheus_server_disk_usage_bytes{'+SERVER+'}) / sum by (node_id) (ibyte_prometheus_server_disk_limit_bytes{'+SERVER+'})'),
  'percentunit', 12));

// Allocations inventory per node
panels.push(barPanel('Allocations in use / total, per node',
  joinNode('ibyte_prometheus_node_allocations_used{'+NODE+'} / ibyte_prometheus_node_allocations{'+NODE+'}'),
  'percentunit', 0));
panels.push(barPanel('Backups bytes per node',
  joinNode('ibyte_prometheus_node_backups_bytes{'+NODE+'}'), 'bytes', 12));

// ================================================================ SERVER DRILLDOWN ROW
panels.push(rowPanel('Server drilldown (pick Node and Server above)'));

// Per-server master table: everything at a glance
panels.push(tablePanel('All servers: CPU / memory / disk / headroom / uptime',
  [
    joinInfo('ibyte_prometheus_server_cpu_usage_ratio{'+SERVER+'}'),
    joinInfo('ibyte_prometheus_server_memory_usage_bytes{'+SERVER+'}'),
    joinInfo('ibyte_prometheus_server_memory_limit_bytes{'+SERVER+'}'),
    joinInfo('ibyte_prometheus_server_memory_limit_bytes{'+SERVER+'} / ibyte_prometheus_server_memory_usage_bytes{'+SERVER+'}'),
    joinInfo('ibyte_prometheus_server_disk_usage_bytes{'+SERVER+'}'),
    joinInfo('ibyte_prometheus_server_disk_limit_bytes{'+SERVER+'}'),
    joinInfo('ibyte_prometheus_server_uptime_seconds{'+SERVER+'}'),
  ]));

// Per-server history, selected server only
panels.push(seriesPanel('CPU use (selected server) with limit',
  [
    {refId: 'A', expr: joinInfo('ibyte_prometheus_server_cpu_usage_ratio{'+SERVER+'}'), legendFormat: 'use'},
    {refId: 'B', expr: joinInfo('ibyte_prometheus_server_cpu_limit_cores{'+SERVER+'}'), legendFormat: 'limit'},
  ], 'none', 0));
panels.push(seriesPanel('Memory use / limit (selected server)',
  [
    {refId: 'A', expr: joinInfo('ibyte_prometheus_server_memory_usage_bytes{'+SERVER+'}'), legendFormat: 'use'},
    {refId: 'B', expr: joinInfo('ibyte_prometheus_server_memory_limit_bytes{'+SERVER+'}'), legendFormat: 'limit'},
  ], 'bytes', 12));

// Disk and network for selected server
panels.push(seriesPanel('Disk use / limit (selected server, Wings cache)',
  [
    {refId: 'A', expr: joinInfo('ibyte_prometheus_server_disk_usage_bytes{'+SERVER+'}'), legendFormat: 'use'},
    {refId: 'B', expr: joinInfo('ibyte_prometheus_server_disk_limit_bytes{'+SERVER+'}'), legendFormat: 'limit'},
  ], 'bytes', 0));
panels.push(seriesPanel('Network I/O (selected server)',
  [
    {refId: 'A', expr: joinInfo('ibyte_prometheus_server_network_rx_bytes{'+SERVER+'}'), legendFormat: 'received'},
    {refId: 'B', expr: joinInfo('ibyte_prometheus_server_network_tx_bytes{'+SERVER+'}'), legendFormat: 'sent'},
  ], 'bytes', 12));

// I/O per-second for selected server
panels.push(seriesPanel('Disk I/O per second (selected server)',
  [
    {refId: 'A', expr: joinInfo('rate(ibyte_prometheus_server_disk_write_bytes{'+SERVER+'}[1m])'), legendFormat: 'write/s'},
    {refId: 'B', expr: joinInfo('rate(ibyte_prometheus_server_disk_read_bytes{'+SERVER+'}[1m])'), legendFormat: 'read/s'},
  ], 'Bps', 0));
panels.push(seriesPanel('Server uptime (selected server)',
  [{refId: 'A', expr: joinInfo('ibyte_prometheus_server_uptime_seconds{'+SERVER+'}'), legendFormat: 'uptime'}], 's', 12));

// Headroom over time (selected server): limit / used memory and disk
panels.push(seriesPanel('Headroom over time (selected server)',
  [
    {refId: 'A', expr: joinInfo('ibyte_prometheus_server_memory_limit_bytes{'+SERVER+'} / ibyte_prometheus_server_memory_usage_bytes{'+SERVER+'}'), legendFormat: 'memory'},
    {refId: 'B', expr: joinInfo('ibyte_prometheus_server_disk_limit_bytes{'+SERVER+'} / ibyte_prometheus_server_disk_usage_bytes{'+SERVER+'}'), legendFormat: 'storage'},
  ], 'none', 0));
panels.push(piePanel('Servers by state (current)',
  [{
    refId: 'A',
    expr: 'sum by (state) (count by (server_uuid, state) (ibyte_prometheus_server_state{'+SERVER+'}))',
    instant: true, legendFormat: '{{state}}',
  }], 12));

// ================================================================ DISTRIBUTION ROW
panels.push(rowPanel('Fleet distribution'));

panels.push(piePanel('Memory allocated by server',
  [{refId: 'A', expr: joinInfo('ibyte_prometheus_server_memory_usage_bytes{'+SERVER+'}'), instant: true, legendFormat: '{{name}}'}], 0));
panels.push(piePanel('Disk used by server',
  [{refId: 'A', expr: joinInfo('ibyte_prometheus_server_disk_usage_bytes{'+SERVER+'}'), instant: true, legendFormat: '{{name}}'}], 12));

panels.push(textPanel('How to read these panels'));

// Grid layout. Rules in sequence:
// - row: separator, h=1, advance 2.
// - text: full width h=4.
// - table: full width h=14 (many rows).
// - stat: packs up to four per row (x = 0, 6, 12, 18), h=4; row advances on 4th or
//   when the next panel isn't a stat.
// - other (barchart/pie/timeseries): w=12, two per row, pairing by declared side.
let y = 0;
let i = 0;
while (i < panels.length) {
  const p = panels[i];
  if (p.type === 'row') { p.gridPos = {h: 1, w: 24, x: 0, y}; y += 2; i += 1; continue; }
  if (p.type === 'text') { p.gridPos = {h: 4, w: 24, x: 0, y}; y += 4; i += 1; continue; }
  if (p.type === 'table') { p.gridPos = {h: 14, w: 24, x: 0, y}; y += 14; i += 1; continue; }
  if (p.type === 'stat') {
    // Pack stats: up to 4 on this row starting at y.
    let slot = 0;
    while (i < panels.length && panels[i].type === 'stat' && slot < 4) {
      panels[i].gridPos = {h: 4, w: 6, x: slot * 6, y};
      slot += 1;
      i += 1;
    }
    y += 4;
    continue;
  }
  // wide panels
  const side = (p._x ?? 0) === 12 ? 1 : 0;
  p.gridPos = {h: 8, w: 12, x: side ? 12 : 0, y};
  i += 1;
  const next = panels[i];
  if (next && ['barchart', 'piechart', 'timeseries'].includes(next.type) &&
      ((next._x ?? 0) === 12 ? 1 : 0) !== side && !next.gridPos) {
    next.gridPos = {h: 8, w: 12, x: side ? 0 : 12, y};
    i += 1;
  }
  y += 8;
}

dashboard.panels = panels;
fs.writeFileSync(file, JSON.stringify(dashboard, null, 2) + '\n');
console.log('master dashboard rebuilt:', panels.length, 'panels');