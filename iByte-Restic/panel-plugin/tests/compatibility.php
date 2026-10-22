<?php

use IByte\Restic\Support\Compatibility;
use IByte\Restic\Support\RuntimeDiagnostics;

$contract = Compatibility::panelSource();
check($contract['matches'], 'Panel compatibility is detected from the runtime service contracts');
check($contract['file_mismatches'] === [] && $contract['dependency_mismatches'] === [], 'runtime diagnostics do not depend on source hashes or composer.lock pins');
check($contract['panel_tag'] === 'runtime-detected' && $contract['panel_commit'] === null, 'runtime compatibility does not require version metadata files');
check(! is_file($root.'/panel-plugin/compatibility.json'), 'the installable plugin has no embedded compatibility JSON dependency');
$diagnostics = RuntimeDiagnostics::snapshot();
check($diagnostics['ready'] && $diagnostics['panel_source_matches'], 'runtime diagnostics report readiness from discovered Panel contracts');
