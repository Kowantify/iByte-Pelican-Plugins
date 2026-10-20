# Compatibility matrix

| Target | Exact revision/tool | Evidence | Support |
|---|---|---|---|
| Panel v1.0.0-rc.1 | 46fc1cf0ebce3f43a5dd38bb2c4a89ad0f668e00 | Native service/controller/schedule/registry/ZIP backend tests; SQLite/Windows | Preview source contract only |
| Wings v1.0.0-rc.1 | 7b489da7b8196d0b4baef3e2db062c06d743e2f9 | Router and dependency patches apply; daemon/helper cross-build for Linux amd64 and arm64 | Preview cross-build targets, no runtime certification |
| Wings dependency override | golang.org/x/net v0.60.0 | Explicit go.mod/go.sum patch; checksum DB validation; source audit rerun | Required for this candidate |
| Go | 1.27.2 windows/amd64 compiler | Engine/helper tests and Linux cross-builds | Tested compiler |
| PHP | 8.5.11 Windows NTS | Lint and real upstream framework tests | Tested runtime for local fixtures |
| Restic | v0.19.1 / 6aa3a516ce654808a1f28f9fa21e9b7c8e6e90bf plus explicit dependency lock patch | Central Go 1.27.2 builds; official Windows baseline and rebuilt local test runtime; Linux amd64/arm64 artifacts | Engine-local verified; Linux execution pending |
| Native queue/cache | SQLite + mocked bus, array cache in local fixtures | Durable rows/replayed job objects/atomic one-use tests | Real database/Redis workers and cache locks not certified |
| MariaDB, Debian 13, PHP 8.5 production topology | User context only | No environment access or runtime tests | Unverified |
| Older betas, floating main, Pterodactyl Wings | Not selected | Official tag lists compared; legacy targets Pterodactyl | Unsupported |
| Linux arm64 | Cross-compiled | No emulator or physical node available | Execution unverified |
| S3 over HTTPS | Structured profile code and explicit fresh provision | No remote endpoint test | Unverified |
| SFTP, old repositories, custom mounts/roots, merge restore | Excluded | No support claim | Unsupported |

There is currently **no production-supported matrix row**. The newest fetched official tags were release candidates, not final stable 1.0 tags. `panel_version` is strict and operation source hashes fail closed. Runtime compatibility with an operator's migrated Panel must never be inferred from a matching display version alone.
