<div class="panel" id="offline-recovery">
    <div class="section-heading"><div><h2>Offline counter recovery</h2><p class="muted">Recover access when a prepared checkout browser is unavailable.</p></div><span class="badge">{{ $lease ? 'Counter held offline' : 'No offline hold' }}</span></div>
    @if($message)<p class="notice" role="status">{{ $message }}</p>@endif
    @error('offline')<p class="error" role="alert">{{ $message }}</p>@enderror
    <ol class="recovery-steps">
        <li>Open the original browser profile, sign in online, synchronize pending sales and finish its session. Keep its site data and receipts.</li>
        <li>If you have an offline recovery JSON export, restore it in <a href="/offline-checkout.html">Offline checkout</a>, then synchronize and finish. An older export can recover only the sales it contains.</li>
        <li>If neither is available, wait for the offline selling period to expire and use the release below. Preserve any files or receipts found later for manual reconciliation.</li>
    </ol>
    @if(!$available)<p class="muted">Counter release requires an initialized database with the recovery update installed.</p>
    @elseif($lease)
        <div class="backup-overview">
            <div><span class="section-kicker">PREPARED</span><strong>{{ \Illuminate\Support\Carbon::parse($snapshot['preparedAt'])->timezone('Asia/Shanghai')->format('M j · g:i A') }}</strong><small>Session {{ strtoupper(substr($lease->id, 0, 8)) }} · Drawer {{ $snapshot['cashSessionNumber'] }}</small></div>
            <div><span class="section-kicker">OFFLINE SELLING ENDS</span><strong>{{ \Illuminate\Support\Carbon::parse($snapshot['validUntil'])->timezone('Asia/Shanghai')->format('M j · g:i A') }}</strong><small>{{ $expired ? 'Selling period expired. Refresh before releasing.' : 'Release stays unavailable until this time. Refresh afterward.' }}</small></div>
            <div><span class="section-kicker">CONFIRMED SALES</span><strong>{{ (int) $lease->next_sequence - 1 }}</strong><small>Unsent sales: unknown. They are absent from server reports and database backups.</small></div>
        </div>
        <details><summary>Last resort: release unavailable checkout</summary>
            <p class="notice">Release stops this session from uploading new sales and reopens connected operations. It does not recover or discard stock, cash or debt records. The old browser may contain unconfirmed sales that need manual review. Confirmed uploads remain saved and can be rechecked.</p>
            <form wire:submit="release">
                <label>Reason<input type="text" wire:model="reason" minlength="3" maxlength="200" required placeholder="For example: original browser profile was lost">@error('reason')<span class="error">{{ $message }}</span>@enderror</label>
                <label class="check"><input type="checkbox" wire:model="acknowledged">I checked for the original browser and a recovery export. I understand that unconfirmed sales may be missing and must be reconciled.</label>@error('acknowledged')<p class="error">{{ $message }}</p>@enderror
                <label>Type RELEASE COUNTER 1<input type="text" wire:model="confirmation" required autocomplete="off" spellcheck="false">@error('confirmation')<span class="error">{{ $message }}</span>@enderror</label>
                <button type="submit" wire:loading.attr="disabled" @disabled(!$expired || $lease->id !== $leaseId)>Release Counter 1</button>
            </form>
        </details>
    @else<p class="muted">Counter 1 is available for connected operations. No session needs to be released.</p>@endif
    @if(count($history))
        <h3>Recorded counter releases</h3>
        <div class="table-wrap"><table><thead><tr><th>Released</th><th>Session</th><th>Reason</th><th>Confirmed sales</th><th>Unconfirmed sales</th></tr></thead><tbody>
        @foreach($history as $release)<tr><td>{{ \Illuminate\Support\Carbon::parse($release['releasedAt'])->timezone('Asia/Shanghai')->format('M j · g:i A') }}</td><td>{{ strtoupper(substr($release['leaseId'], 0, 8)) }}</td><td class="backup-history-note">{{ $release['reason'] }}</td><td>{{ $release['confirmedSales'] }}</td><td>Unknown · manual review needed</td></tr>@endforeach
        </tbody></table></div>
    @endif
</div>
