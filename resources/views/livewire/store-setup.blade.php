<div>
    @if(!$ready)
    <section class="panel operations"><h2>Move this browser’s records to PostgreSQL</h2>
    <p>Your current catalogue and history will become the shared shop records. A separate local archive is saved before import. Records in other browser sessions are kept separate.</p>
    <p class="muted">{{ $legacyCounts['products'] ?? 2 }} products · {{ $legacyCounts['sales'] ?? 0 }} sales · {{ $legacyCounts['returns'] ?? 0 }} returns · {{ $legacyCounts['debts'] ?? 0 }} debts</p>
    @error('storage')<p class="field-error">{{ $message }}</p>@enderror
    <button class="primary" wire:click="importCurrent" wire:loading.attr="disabled">Import this browser’s records</button>
    </section>
    @else
    <section class="panel"><h2>PostgreSQL storage</h2><p>Products, stock batches, transactions, customer balances and supplier balances are saved in the shared shop database.</p><p class="muted">Owner login protects this workspace. Manage backups and recovery checks below. Prepare offline checkout from Checkout while connected with a cash session open.</p></section>
    @endif
</div>
