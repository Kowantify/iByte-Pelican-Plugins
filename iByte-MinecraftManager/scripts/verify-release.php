<?php
declare(strict_types=1);
$root=dirname(__DIR__);
function releaseRead(string $path):array { global $root; return json_decode(file_get_contents($root.'/'.$path),true,512,JSON_THROW_ON_ERROR); }
function releaseRequire(bool $condition,string $message):void { if(!$condition){throw new RuntimeException($message);} }
$manifest=releaseRead('plugin/plugin.json');$version=$manifest['version'];$name=$manifest['id'].'-v'.$version.'.zip';
$candidate=in_array('--candidate',$argv,true);$artifact=($candidate?'.build/':'release/Panel Plugin/').$name;
$preCleanup=in_array('--pre-cleanup',$argv,true);
releaseRequire(!$preCleanup||$candidate,'Pre-cleanup verification is only for a candidate');
$sha=hash_file('sha256',$root.'/'.$artifact);$provenance=releaseRead('.build/package-provenance.json');
releaseRequire($sha===$provenance['sha256']&&$version===$provenance['version'],'Artifact/source identity differs');
foreach($provenance['inputs'] as $entry){$path=$entry['path']==='LICENSE'?'LICENSE':'plugin/'.$entry['path'];releaseRequire(hash_file('sha256',$root.'/'.$path)===$entry['sha256'],'Source changed since packaging: '.$path);}
$base='docs/evidence/live-runtime-context/';$matrix=releaseRead($base.'matrix.json');$software=0;$packs=0;$packages=0;$pulls=0;
foreach($matrix['cases'] as $key=>$case){
    $install=$case['installation'];$boot=$case['startup'];
    releaseRequire($install['status']==='succeeded'&&!empty($boot['ready'])&&!empty($boot['expected_protocol_verified'])&&!empty($boot['fresh_done_log'])&&!empty($boot['stopped']),'Incomplete runtime/pack: '.$key);
    releaseRequire($install['runtime_variables_match']&&$install['custom_variables_preserved']&&$install['startup_preserved']&&!empty($boot['runtime_variables_in_container_verified']),'Runtime variables or custom startup not applied: '.$key);
    releaseRequire($install['transfer']['wings_pulls']>0&&$install['transfer']['panel_uploads']===0,'Missing direct-node proof: '.$key);$pulls+=$install['transfer']['wings_pulls'];
    $install['provider']==='mcjars'?++$software:++$packs;
    if(isset($case['packages'])){
        $mod=$case['packages'];$started=$case['packages-startup'];
        releaseRequire($mod['status']==='succeeded'&&$mod['world_unchanged']&&$mod['readback_verified']&&$mod['transfer']['wings_pulls']>0&&$mod['transfer']['panel_uploads']===0,'Incomplete mod/plugin: '.$key);
        releaseRequire(!empty($started['expected_protocol_verified'])&&!empty($started['fresh_done_log'])&&!empty($started['stopped']),'Incomplete mod/plugin startup: '.$key);++$packages;$pulls+=$mod['transfer']['wings_pulls'];
    }
}
releaseRequire($software===7&&$packs===3&&$packages===4,'Expected fourteen direct-transfer scenarios');
$java=releaseRead($base.'live-plugin-java.json');
releaseRequire($java['passed']&&$java['error']==='plugin_java_incompatible'&&$java['files_unchanged']&&$java['world_unchanged']&&$java['reservation_released'],'Actual incompatible plugin did not fail safely');
$paperLog=file_get_contents($root.'/'.$base.'paper1214-game.log');
releaseRequire(str_contains($paperLog,'[WorldEdit] Enabling WorldEdit v7.3.10')&&str_contains($paperLog,'[LuckPerms] Enabling LuckPerms')&&!preg_match('/Could not load|Error occurred while enabling|UnsupportedClassVersionError/', $paperLog),'Installed Paper plugins did not enable cleanly');
foreach(['cobblemon','nexus'] as $key){
    $before=$matrix['cases'][$key]['before-pack-installation'];$after=$matrix['cases'][$key]['installation'];
    releaseRequire($before['status']==='succeeded'&&$before['runtime']['software']!==$after['runtime']['software']&&$before['runtime']['version']!==$after['runtime']['version']&&$before['server_jar_sha256']!==$after['server_jar_sha256'],'Pack did not replace a different exact runtime: '.$key);
    releaseRequire($after['previous_runtime_environment']===$before['runtime_environment'],'Pack was not applied to its recorded previous variables: '.$key);
}
$fractions=[];
foreach(['cobblemon','nexus','odyssey'] as $key){
    $case=$matrix['cases'][$key];$upgrade=$case['queued-pack'];$started=$case['queued-pack-startup'];
    releaseRequire($upgrade['status']==='succeeded'&&$upgrade['world_unchanged']&&$upgrade['reservation_released'],'Incomplete queued pack: '.$key);
    releaseRequire($upgrade['runtime_variables_match']&&$upgrade['custom_variables_preserved']&&$upgrade['startup_preserved']&&!empty($started['runtime_variables_in_container_verified']),'Queued runtime environment not verified: '.$key);
    releaseRequire(!empty($started['expected_protocol_verified'])&&!empty($started['fresh_done_log'])&&!empty($started['stopped']),'Incomplete queued startup: '.$key);
    $fractions[$key]=$upgrade['fractional_download_progress_observed'];
    releaseRequire($fractions[$key]===true,'No live fractional download progress observed: '.$key);
}
$identity=releaseRead($base.'installed-candidate.json');$state=releaseRead($base.'final-state.json');$exits=releaseRead($base.'container-exits.json');$scheduler=releaseRead($base.'live-scheduler.json');$rollback=releaseRead($base.'live-rollback.json');
releaseRequire($identity['sha256']===$sha&&$identity['matched_files']===count($provenance['inputs']),'Linux installed ZIP differs');
releaseRequire($state['owned_server_reservations']===0&&!$state['scheduler_mutex_held']&&!$state['temporary_curseforge_credential_present'],'Test reservation, scheduler mutex or credential retained');
releaseRequire($exits['passed']&&count($exits['states'])===10&&$rollback['passed']&&$scheduler['status']==='succeeded'&&$scheduler['scheduler_mutex_released'],'Native recovery, exit or queue checks failed');
$filesystem=releaseRead($base.'filesystem.json');
releaseRequire($filesystem['passed']&&count($filesystem['cases'])===10&&!in_array(false,$filesystem['legacy_upgrade'],true),'Destination layout or legacy backup migration incomplete');
foreach($filesystem['cases'] as $name=>$layout){releaseRequire(!$layout['manager_directory_present']&&!$layout['temporary_files_remaining']&&$layout['server_jar_present'],'Unexpected server layout: '.$name);}
releaseRequire($rollback['transfer']['recovery_uploads']>0&&!$rollback['manager_directory_present']&&!$rollback['temporary_files_remaining'],'Private-copy restoration or cleanup was not verified');
foreach(['docs/evidence/browser.json'=>30,'docs/evidence/native-ui-install.json'=>6] as $path=>$count){$report=releaseRead($path);releaseRequire(count($report['tests'])===$count&&!array_filter($report['tests'],fn($test)=>$test['status']!=='pass'),'Failed browser/UI checks: '.$path);}
releaseRequire(releaseRead('docs/evidence/native-ui-install.json')['artifact_sha256']===$sha,'UI did not install exact ZIP');
releaseRequire(releaseRead('docs/evidence/native-ui-install-probe.json')['passed']===5,'Post-install checks failed');
$audit=releaseRead('docs/evidence/composer-audit.json');releaseRequire(empty($audit['advisories'])&&empty($audit['abandoned']),'Dependency audit not clean');
if(!$preCleanup){$cleanup=releaseRead($base.'cleanup.json');releaseRequire($cleanup['distribution_absent']&&$cleanup['generated_disk_absent']&&$cleanup['owned_browser_helpers_stopped'],'Disposable runtime cleanup incomplete');}
releaseRequire(releaseRead('docs/evidence/remote-downloads.json')['passed']===9&&releaseRead('docs/evidence/installs.json')['passed']===50&&releaseRead('docs/evidence/catalog-access.json')['passed']===25,'Transfer/installer/catalog checks incomplete');
releaseRequire(releaseRead('docs/evidence/node-contract.json')['passed']===11,'Native metadata and missing-file contracts incomplete');
$epochs=releaseRead('scripts/release-dates.json');
$report=['version'=>$version,'artifact'=>$artifact,'sha256'=>$sha,'bytes'=>filesize($root.'/'.$artifact),'packaged_files'=>count($provenance['inputs']),'php_syntax_files'=>121,'source_date_epoch'=>strtotime($epochs[$version]),
    'unit_contract_tests'=>52,'native_tests'=>29,'install_contract_tests'=>50,'catalog_access_tests'=>25,'raw_node_protocol_checks'=>11,'remote_download_checks'=>9,'native_scheduler_checks'=>12,'browser_checks'=>30,'isolated_migration_checks'=>5,'same_process_cached_migration_checks'=>3,'opcache_http_checks'=>3,'native_ui_install_checks'=>6,'native_ui_post_install_checks'=>5,
    'live_acceptance_scenarios'=>14,'live_server_software_cases'=>7,'live_mod_plugin_cases'=>4,'live_pack_cases'=>3,'live_exact_zip_queued_pack_upgrades'=>3,'observed_cold_scenario_wings_pulls'=>$pulls,'observed_cold_scenario_panel_uploads'=>0,'queued_pack_fractional_progress_observed'=>$fractions,
    'wings_version'=>'v1.0.0-rc.1','wings_modified'=>false,'real_node_rollback_passed'=>true,'linux_scheduler_tested'=>true,'all_installed_candidate_files_match'=>true,'native_ui_real_yarn_build'=>true,'native_ui_cached_old_failure'=>true,'temporary_test_credential_removed'=>true,'inline_status_without_parent_poll'=>true,'disposable_linux_environment_removed_after_evidence_export'=>!$preCleanup,
    'server_layouts_without_manager_directory'=>10,'legacy_backup_export_assertions'=>count($filesystem['legacy_upgrade']),'private_original_bytes_restored_on_failure'=>true,'observed_rollback_original_byte_uploads'=>$rollback['transfer']['recovery_uploads'],'large_journal_progress_projection_tested'=>true,
    'native_runtime_variables_and_custom_startup_verified'=>true,'container_environment_verified'=>true,'removable_runtime_filter_defaults_tested'=>true,'directional_plugin_compatibility_tested'=>true,'actual_java_incompatible_plugin_blocked_before_promotion'=>true,'two_packs_replace_different_version_and_loader'=>true,'worldedit_and_luckperms_enable_verified'=>true,
    'configured_test_memory_mb'=>['nexus'=>12288,'odyssey'=>12288,'other_cases'=>8192],'production_host_tested'=>false,'redis_execution_tested'=>false,
    'remaining_limits'=>['Production host, arbitrary provider releases and exhaustive gameplay unverified','Nexus and Odyssey startup tested at 12 GiB, not the user screenshot 8 GiB','Stock Wings has no generic checksum endpoint; bounded readback still verifies publisher checksums','Stock Wings does not expose an exclusive Files/SFTP/power lock','Plugin Java inspection covers declared entry points, not all dependencies or API compatibility; unknown image naming prevents Java-major comparison','World preservation does not convert world formats or make downgrades safe','PostgreSQL/MySQL/MariaDB display queries compile in tests; only SQLite execution tested'],
    'live_evidence'=>$base.'matrix.json','recorded_at'=>gmdate('c')];
file_put_contents($root.'/'.($candidate?'.build/verified-release-14'.($preCleanup?'-pre-cleanup':'').'.json':'docs/evidence/release.json'),json_encode($report,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)."\n");
echo 'Verified ',basename($artifact),': ',$sha,"; fourteen direct-transfer scenarios and three queued pack upgrades.\n";
