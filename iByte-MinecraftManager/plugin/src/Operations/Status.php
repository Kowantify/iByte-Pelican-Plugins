<?php

declare(strict_types=1);

namespace IByte\MinecraftManager\Operations;

enum Status: string
{
    case Planned = 'planned';
    case Queued = 'queued';
    case Preflight = 'preflight';
    case Recovery = 'recovery';
    case Downloading = 'downloading';
    case Verifying = 'verifying';
    case Applying = 'applying';
    case VerifyingResult = 'verifying-result';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    case Cancelled = 'cancelled';
    case Cancelling = 'cancelling';
    case RollingBack = 'rolling-back';
    case RolledBack = 'rolled-back';
    case NeedsReconciliation = 'needs-reconciliation';
    case Blocked = 'blocked';
}
