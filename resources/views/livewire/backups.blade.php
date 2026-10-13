<div>
    <section class="panel">
        <div class="section-heading">
            <div><h2>Backups &amp; recovery</h2><p class="muted">Keep a saved copy of your shop and check that it can be recovered.</p></div>
            <button class="primary compact" wire:click="backupNow" wire:loading.attr="disabled" @disabled(!$available)>Back up now</button>
        </div>
        @if($message)<p class="success" role="status">{{ $message }}</p>@endif
        @error('backup')<p class="error" role="alert">{{ $message }}</p>@enderror
        <p class="notice" wire:loading wire:target="backupNow,verify">Working on your backup. Please keep this page open; a recovery check can take a little longer.</p>
        @if(!$available)<p class="notice">Backups become available with the shared PostgreSQL workspace.</p>@endif
        <div class="backup-overview">
            <div><span class="section-kicker">LATEST SAVED BACKUP</span><strong>{{ $latest ? \Illuminate\Support\Carbon::parse($latest['completedAt'])->timezone('Asia/Shanghai')->format('M j, Y · g:i A') : 'No backup saved yet' }}</strong><small>{{ $latest ? number_format($latest['bytes'] / 1024, 1).' KB · '.$latest['source'] : 'Create the first copy with Back up now.' }}</small></div>
            <div><span class="section-kicker">AUTOMATIC BACKUPS</span><strong>{{ !$enabled ? 'Paused' : (($scheduler['installed'] ?? false) ? 'Scheduled on this computer' : 'Scheduler setup pending') }}</strong><small>{{ isset($scheduler['lastCheckedAt']) ? 'Last check: '.\Illuminate\Support\Carbon::parse($scheduler['lastCheckedAt'])->timezone('Asia/Shanghai')->format('M j · g:i A') : 'Runs while the shop computer is on and your Windows account is signed in.' }}</small></div>
            <div><span class="section-kicker">LATEST RECOVERY CHECK</span><strong>{{ $latest['verification'] ?? 'Not checked' }}</strong><small>{{ isset($latest['verifiedAt']) ? \Illuminate\Support\Carbon::parse($latest['verifiedAt'])->timezone('Asia/Shanghai')->format('M j, Y · g:i A') : 'Use Check recovery on a saved backup.' }}</small></div>
        </div>
        <form wire:submit="saveSettings" class="backup-preferences">
            <label class="check"><input type="checkbox" wire:model="enabled">Automatic backups</label>
            <label>Backup frequency<select wire:model="intervalHours"><option value="24">Daily</option><option value="6">Every 6 hours</option><option value="1">Hourly</option></select>@error('intervalHours')<span class="error">{{ $message }}</span>@enderror</label>
            <label>Keep backups for<select wire:model="retentionDays"><option value="7">7 days</option><option value="14">14 days</option><option value="30">30 days</option><option value="90">90 days</option></select>@error('retentionDays')<span class="error">{{ $message }}</span>@enderror</label>
            <button type="submit" wire:loading.attr="disabled">Save preferences</button>
        </form>
        <p class="muted">Archives are stored privately on this computer. Off-device copies are not configured. The newest successful backup is kept; old archives are cleaned up only after a new backup succeeds.</p>
        @if(!($scheduler['installed'] ?? false))<details><summary>Set up automatic backups on Windows</summary><p class="muted">From the project’s PowerShell terminal, run <code>.\install-backup-task.ps1</code>. It registers a task for your Windows account and checks the selected frequency hourly and when you sign in.</p></details>@endif
    </section>
    <section class="panel">
        <div class="section-heading"><div><h2>Backup history</h2><p class="muted">Recent attempts, saved copies and recovery results.</p></div></div>
        @if(count($history))
        <div class="table-wrap"><table><thead><tr><th>Created</th><th>Source</th><th>Backup</th><th>Size</th><th>Recovery</th><th>Action</th></tr></thead><tbody>
        @foreach($history as $record)
            <tr wire:key="backup-{{ $record['id'] }}">
                <td>{{ \Illuminate\Support\Carbon::parse($record['createdAt'])->timezone('Asia/Shanghai')->format('M j · g:i A') }}<small class="backup-id">{{ substr($record['id'], 0, 8) }}</small></td>
                <td>{{ $record['source'] }}</td>
                <td><span class="badge">{{ $record['status'] }}</span>@if(isset($record['error']))<small class="backup-history-note">{{ $record['error'] }}</small>@endif @if(isset($record['retentionWarning']))<small class="backup-history-note">{{ $record['retentionWarning'] }}</small>@endif</td>
                <td>{{ $record['bytes'] ? number_format($record['bytes'] / 1024, 1).' KB' : '—' }}</td>
                <td><span class="badge">{{ $record['verification'] }}</span>@if(isset($record['verifiedTables']))<small class="backup-id">{{ $record['verifiedTables'] }} tables · {{ $record['verifiedRows'] }} records matched</small>@endif @if(isset($record['verificationError']))<small class="backup-history-note">{{ $record['verificationError'] }}</small>@endif</td>
                <td>@if($record['status'] === 'Succeeded')<button wire:click="verify('{{ $record['id'] }}')" wire:loading.attr="disabled">Check recovery</button>@else<span class="muted">—</span>@endif</td>
            </tr>
        @endforeach
        </tbody></table></div>
        @else<div class="empty-state"><h3>Your first backup starts here</h3><p>Create a saved copy, then check recovery to verify the records in a separate temporary database.</p></div>@endif
        <p class="muted">Recovery checks restore a saved copy in a separate temporary PostgreSQL instance and compare every saved table and record.</p>
    </section>
</div>
