<div>
    <section class="panel">
        <div class="section-heading">
            <div><h2>Backups &amp; recovery</h2><p class="muted">Keep a saved copy of your shop and check that it can be recovered.</p></div>
            <button class="primary compact" wire:click="backupNow" wire:loading.attr="disabled" @disabled(!$available)>Back up now</button>
        </div>
        @if($message)<p class="success" role="status">{{ $message }}</p>@endif
        @error('backup')<p class="error" role="alert">{{ $message }}</p>@enderror
        <p class="notice" wire:loading wire:target="backupNow,verify,copyExternal,verifyExternal">Working on your backup. Please keep this page open and the drive connected; a recovery check can take a little longer.</p>
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
        <p class="muted">Archives are stored privately on this computer. The newest successful backup is kept; old local archives are cleaned up only after a new backup succeeds.</p>
        @if(!($scheduler['installed'] ?? false))<details><summary>Set up automatic backups on Windows</summary><p class="muted">From the project’s PowerShell terminal, run <code>.\install-backup-task.ps1</code>. It registers a task for your Windows account and checks the selected frequency hourly and when you sign in.</p></details>@endif
    </section>
    <section class="panel">
        <div class="section-heading"><div><h2>USB / external drive copies</h2><p class="muted">Keep a second copy away from the shop computer.</p></div><span class="badge">{{ $savedExternalDirectory === '' ? 'Configure later' : 'Folder configured' }}</span></div>
        <p class="muted">Connect the drive to the shop computer and create a dedicated folder on it. Enter that computer’s folder path below. New successful backups will be copied automatically when the drive is connected.</p>
        <form wire:submit="saveExternalDirectory" class="external-backup-preferences">
            <label>Drive backup folder<input type="text" wire:model="externalDirectory" maxlength="500" placeholder="E:\WynnsMartBackups" autocomplete="off" spellcheck="false" aria-describedby="drive-folder-help">@error('externalDirectory')<span class="error">{{ $message }}</span>@enderror</label>
            <button type="submit" wire:loading.attr="disabled">Save drive folder</button>
        </form>
        <p class="muted" id="drive-folder-help">Leave blank to configure later or stop drive copies. Local backups continue. A folder path alone does not confirm that a drive is connected.</p>
        @if($savedExternalDirectory !== '')<p class="backup-destination"><strong>Saved folder:</strong> {{ $savedExternalDirectory }}</p>@endif
        <p class="muted">Each copy contains a database archive and matching recovery metadata. Keep both files together. A checksum match confirms the copy; Check drive recovery tests restoring its records. Drive files are kept until you remove them yourself.</p>
        <details><summary>What is included?</summary><p class="muted">The database includes shop records and the owner login. Archives are unencrypted. Application files, uploaded images and the private application configuration need a separate recovery copy.</p></details>
    </section>
    <section class="panel">
        <div class="section-heading"><div><h2>Backup history</h2><p class="muted">Recent attempts, saved copies and recovery results.</p></div></div>
        @if(count($history))
        <div class="table-wrap"><table><thead><tr><th>Created</th><th>Source</th><th>Local backup</th><th>Size</th><th>Local recovery</th><th>Drive copy</th><th>Actions</th></tr></thead><tbody>
        @foreach($history as $record)
            <tr wire:key="backup-{{ $record['id'] }}">
                <td>{{ \Illuminate\Support\Carbon::parse($record['createdAt'])->timezone('Asia/Shanghai')->format('M j · g:i A') }}<small class="backup-id">{{ substr($record['id'], 0, 8) }}</small></td>
                <td>{{ $record['source'] }}</td>
                <td><span class="badge">{{ $record['status'] }}</span>@if(isset($record['error']))<small class="backup-history-note">{{ $record['error'] }}</small>@endif @if(isset($record['retentionWarning']))<small class="backup-history-note">{{ $record['retentionWarning'] }}</small>@endif</td>
                <td>{{ $record['bytes'] ? number_format($record['bytes'] / 1024, 1).' KB' : '—' }}</td>
                <td><span class="badge">{{ $record['verification'] }}</span>@if(isset($record['verifiedTables']))<small class="backup-id">{{ $record['verifiedTables'] }} tables · {{ $record['verifiedRows'] }} records matched</small>@endif @if(isset($record['verificationError']))<small class="backup-history-note">{{ $record['verificationError'] }}</small>@endif</td>
                <td><span class="badge">{{ $record['externalCopy']['status'] ?? 'Not copied' }}</span>
                    @if(isset($record['externalCopy']['copiedAt']))<small class="backup-id">Checksum matched · {{ \Illuminate\Support\Carbon::parse($record['externalCopy']['copiedAt'])->timezone('Asia/Shanghai')->format('M j · g:i A') }}</small>@endif
                    @if(isset($record['externalCopy']['verification']))<small class="backup-id">Drive recovery: {{ $record['externalCopy']['verification'] }}</small>@endif
                    @if(isset($record['externalCopy']['error']))<small class="backup-history-note">{{ $record['externalCopy']['error'] }}</small>@endif
                    @if(isset($record['externalCopy']['verificationError']))<small class="backup-history-note">{{ $record['externalCopy']['verificationError'] }}</small>@endif
                </td>
                <td><div class="backup-actions">
                    @if($record['status'] === 'Succeeded')<button wire:click="verify('{{ $record['id'] }}')" wire:loading.attr="disabled">Check recovery</button>
                        <button wire:click="copyExternal('{{ $record['id'] }}')" wire:loading.attr="disabled" @disabled($savedExternalDirectory === '')>Copy to drive</button>
                        @if($savedExternalDirectory === '')<small class="backup-id">Set a drive folder first.</small>@endif
                    @endif
                    @if(($record['externalCopy']['status'] ?? '') === 'Succeeded')<button wire:click="verifyExternal('{{ $record['id'] }}')" wire:loading.attr="disabled">Check drive recovery</button>@endif
                </div></td>
            </tr>
        @endforeach
        </tbody></table></div>
        @else<div class="empty-state"><h3>Your first backup starts here</h3><p>Create a saved copy, then check recovery to verify the records in a separate temporary database.</p></div>@endif
        <p class="muted">Recovery checks restore a saved copy in a separate temporary PostgreSQL instance and compare every saved table and record.</p>
    </section>
</div>
