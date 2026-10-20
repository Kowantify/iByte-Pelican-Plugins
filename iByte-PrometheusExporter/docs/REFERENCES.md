# Pinned source contract reviewed

Local read-only official source mirrors under `../iByte-Restic/.upstream`:

| Component | Commit | Verified source behavior |
|---|---|---|
| Pelican Panel v1.0.0-rc.1 | `46fc1cf0ebce3f43a5dd38bb2c4a89ad0f668e00` | `AppServiceProvider` daemon HTTP macro uses the encrypted Node token. `Node::getConnectionAddress()` builds scheme/FQDN/port. `PluginService` autoloads plugin `src`, registers providers and views, then Filament plugin pages. Laravel lockfile has framework v13.35.0. |
| Pelican Wings v1.0.0-rc.1 | `7b489da7b8196d0b4baef3e2db062c06d743e2f9` | `router.go` protects `GET /api/servers` with bearer authentication; the same daemon token also authorizes destructive API routes. `router_system.go::getAllServers` returns all `APIResponse` objects. `server.go::ToAPIResponse` includes utilization and sensitive configuration. |
| Wings CPU | same commit | `environment/docker/stats.go::calculateDockerAbsoluteCpu` returns `100` for one logical core; ratio = `cpu_absolute / 100`. Values can exceed one core. |
| Wings memory | same commit | `environment/docker/stats.go::calculateDockerMemory` subtracts inactive file cache from Docker usage when available. |
| Wings disk | same commit | `server/resources.go::Proc` returns quota usage or `Filesystem::CachedUsage`. `server/filesystem/disk_space.go` caches game-directory scans; no source timestamp is in the all-server response. Five-second polling does not guarantee a new disk scan. |
| Wings stopped state | same commit | `ResourceUsage::Reset` sets CPU and memory to zero; disk remains independently available. State comes from `Environment.State`; suspended flag is separate. |

The source was inspected locally, not executed against disposable Wings in this session. `git ls-remote --tags` against both official GitHub repositories on 2026-10-19 showed `v1.0.0-rc.1` as the latest published tag for each. This preview targets only the commits above. The official documentation pages listed in `AGENTS.md` were not independently rechecked.
