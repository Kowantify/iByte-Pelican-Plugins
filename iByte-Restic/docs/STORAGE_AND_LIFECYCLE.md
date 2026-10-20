# Storage, encryption and server lifecycle

## Downloads and encryption

Native Download authorizes the user with Panel RBAC and a short-lived, single-use signed link. Panel calls the authenticated Wings addon; Restic decrypts the selected snapshot on the node, and Panel streams an ordinary tar archive. The downloader needs no Restic password. The downloaded archive is plaintext: store it securely.

Host configuration stores a node profile reference. At the user's explicit request, the latest revision also stores a separate encrypted recovery escrow and durable owner/repository inventory. Root admins can open **Restic Backups > Administrative**, reauthenticate with their current password and view/download a single-use recovery kit. Ordinary forms and customer APIs do not reveal keys. Private profile/state files remain mode 0600. Per-server password files live in the protected addon state directory; the original profile password remains for configuration/bootstrap compatibility. See [RECOVERY.md](RECOVERY.md) for secure capture, audit and retained-server recovery. No remote system was accessed to read keys in this session.

Restic has repository key files protected by the repository password. Preserve the complete repository, its password, the protected addon config/profile, S3 credentials if applicable, and daemon/plugin identity mappings. New server repositories use independent random passwords. Previously shared v3 credentials are upgraded using committed escrow acknowledgement; offline/deleted repositories may retain their earlier original credential. Losing credentials/key material can make recovery impossible. Protecting the password alone does not substitute for protecting the repository's key files. Transparent downloads should be preferred to handing passwords to users.

## What Size means

For Restic rows in the existing native customer Backups table and admin Backup Host backups relation, **Size = new compressed blob bytes added when that snapshot was created** (`data_added_packed`). A row description says “Added compressed data”; the tooltip also gives full logical restore size. Logical restore bytes are retained separately in daemon mappings and used for archive/free-space safeguards. Wings/S3 size behavior is unchanged.

Ten snapshots of an unchanged 4 GiB world do not each store another 4 GiB. The first stores its new chunks after compression; later snapshots may add zero data chunks or a small amount of changed content. Each still restores the full world. New metadata, snapshot/index files, encryption and pack overhead also occupy space.

Shared chunks have no single permanent snapshot owner. Deleting the first snapshot leaves chunks needed by later snapshots intact. Therefore the row size is **historical additions**, not exclusive current disk usage or bytes freed by deletion. It must not be summed as an exact current repository size or used as a per-snapshot reclaim estimate. Forgetting snapshots and pruning packs are separate. The current retention revision queues delayed/coalesced prune after confirmed forget; it never wipes repositories. See [RETENTION.md](RETENTION.md).

Older successful snapshots recover their original counters through automatic scheduled statistics synchronization. Until a matching summary is available, added size shows **Unknown** instead of mislabelling the previous logical-byte value. An older node without the new metric fields can still complete existing jobs, but cannot supply this accounting; update both node and plugin for these features.

## Admin repository totals and ratios

Open **Admin > Restic Backups > Stats**. Each successful new backup/prune measures repository statistics on the node and saves them with the operation. The normal Panel scheduler backfills missing/stale metrics without an admin trigger. Customer Backups > Storage statistics reads the same saved repository measurement. Rendering never contacts nodes or runs Restic. Measurements identify their timestamp and remain readable offline. A failed measurement records a maintenance warning without pretending the verified snapshot failed; synchronization retries it. Stored values are measured snapshots, not live counters.

- **Repository files:** sum of regular file lengths in a local repository, including metadata and unpruned/unreferenced packs. This is an actual measured directory-file total, not allocated disk blocks. Symlinks/special files cause measurement failure. Remote S3 physical/object billing size is explicitly unavailable, not invented from blob sizes.
- **Referenced compressed blobs:** Restic `stats --mode raw-data` total_size, counting each referenced blob once across remaining snapshots. Includes blob encryption overhead but omits repository file/pack overhead and unreferenced packs.
- **Total logical restores:** Restic `stats --mode restore-size` across all retained snapshots, representing their restored file contents.
- **Dedup estimate:** total logical restores / unique uncompressed referenced blob bytes. For example, approximately 40 GiB logical across ten 4 GiB identical snapshots and 4 GiB unique gives about 10x. Restic's raw uncompressed counter includes tree/encryption overhead, so this is labelled an estimate rather than an exact pure file-data ratio; tiny repositories can report less than 1x. Zero denominator shows N/A.
- **Compression ratio:** Restic's uncompressed/compressed ratio for compressed blobs; 2x means that compressed subset uses about half the corresponding uncompressed blob space. **Compression saving** is Restic's overall percentage across referenced blobs, including uncompressed blobs. Already compressed content may save little; an unavailable ratio shows N/A.

The actual local file total is shown separately from both ratios, because metadata, old packs and incomplete operations can consume space beyond currently referenced blobs. No filesystem/cloud billing guarantee is made. References: exact Restic v0.19.1 `internal/data/snapshot.go`, `internal/ui/backup/json.go`, `cmd/restic/cmd_stats.go`; [official scripting fields](https://restic.readthedocs.io/en/stable/075_scripting.html).

## Deletion and transfer policy

Native Delete defaults to keeping a fresh final backup; Transfer uses that retention policy. Older locks, unfinished backups or unresolved jobs block retained cleanup. Root admins confirm with their current Panel password only. Delete also offers explicit server-plus-repository destruction: no final backup, native force deletion, then durable cleanup of all captured local source repositories. Offline storage stays pending until verified; keys/history remain. Direct unconfirmed actions remain guarded; no Panel core patch is installed. See RECOVERY.md for supported scope.

Backups do not transfer; destination backups use a separate repository/key. Forgotten unreferenced packs can remain until maintenance. preview.2 adds authenticated original-node check/stats/prune without a live source server, with optional scheduled maintenance in Administrative. Recovery stays manual into a separate empty directory; no repository wipe or automatic adoption is performed. preview.2 first prepares a fresh locked final backup with a free quota slot and requires a second native confirmation after verified success. Retained check/stats/prune can run on the original node without a live game directory; optional automation and exports are under Administrative. See [RECOVERY.md](RECOVERY.md) for the complete policy and upgrade/recovery procedure.

Tests execute native admin confirmations, deletion, transfer service and destination callback with disposable SQLite/HTTP mocks. Real Linux multi-node lifecycle, MariaDB/Redis concurrency and browser acceptance remain production gates.
