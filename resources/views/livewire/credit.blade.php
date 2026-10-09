<div x-data @repayment-selected.window="$refs.repayment.scrollIntoView({behavior:window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 'instant' : 'smooth',block:'start'})">
    @if($message)<p class="success" role="status">{{ $message }}</p>@endif
    @if($errors->any())<div class="notice" role="alert">@foreach($errors->all() as $error)<p class="error">{{ $error }}</p>@endforeach</div>@endif
    <p class="notice">Cash loans and cash repayments use the open drawer. <a href="/cash" wire:navigate>Manage cash session</a>. GCash and Maya are tracked separately.</p>
    <section class="panel spread"><div><span class="section-kicker">All customer balances</span><h2 class="balance-total">{{ number_format($outstanding/100,2) }}</h2><p class="muted">Outstanding grocery credit + cash loans · Principal only</p></div><a class="button" href="/checkout" wire:navigate>Sell groceries on credit</a></section>
    <div class="columns panel-grid">
        <section class="panel"><h2>Add a customer</h2><form wire:submit="addCustomer">
            <label>Customer name<input wire:model="name" maxlength="120" required placeholder="Full name or familiar name"></label>
            <label>Contact number (optional)<input wire:model="phone" maxlength="40" type="tel"></label>
            <button type="submit" wire:loading.attr="disabled">Save customer</button>
        </form>
        <details class="ledger-details"><summary>Saved customers · {{ count($customers) }}</summary>@forelse($customers as $customer)<p class="spread"><strong>{{ $customer['name'] }}</strong><span class="muted">{{ $customer['phone'] ?: 'No contact number' }}</span></p>@empty<p class="muted">Add your first customer above.</p>@endforelse</details>
        </section>
        <section class="panel"><h2>Record a cash loan</h2><p class="muted">Record money already lent to a customer. This entry does not send money or create a merchandise sale.</p><form wire:submit="lend">
            <label>Loan customer<select wire:model="customerId" required><option value="">Select customer</option>@foreach($customers as $customer)<option value="{{ $customer['id'] }}">{{ $customer['name'] }}</option>@endforeach</select></label>
            <div class="form-grid"><label>Loan amount<input wire:model="amount" inputmode="decimal" placeholder="0.00" required></label><label>Money lent through<select wire:model="method"><option>Cash</option><option>GCash</option><option>Maya</option></select></label></div>
            <label>Loan due date (optional)<input type="date" wire:model="dueDate"></label>
            <label>Loan notes (optional)<textarea wire:model="notes" maxlength="500" rows="2"></textarea></label>
            <button type="submit" wire:loading.attr="disabled">Record cash loan</button>
        </form></section>
    </div>
    <section class="panel repayment-form" x-ref="repayment"><h2>Record a repayment</h2><p class="muted">Choose the debt being paid. Record only money you have received; repayments reduce debt and appear as collections in analytics.</p>
        <form wire:submit="repay">
            <label>Debt to repay<select wire:model.live="debtId" required><option value="">Select an outstanding debt</option>@foreach($openDebts as $debt)<option value="{{ $debt['id'] }}">{{ $debt['customer'] }} · {{ $debt['number'] }} · {{ $debt['type'] }} · {{ number_format($debt['balance']/100,2) }} remaining</option>@endforeach</select></label>
            <div class="form-grid three"><label>Repayment amount<input wire:model="repaymentAmount" inputmode="decimal" required placeholder="0.00"></label><label>Repayment method<select wire:model="repaymentMethod"><option>Cash</option><option>GCash</option><option>Maya</option></select></label><label>Payment reference (optional)<input wire:model="reference" maxlength="100"></label></div>
            <button type="submit" wire:loading.attr="disabled">Record repayment</button>
        </form>
    </section>
    <section class="panel"><div class="section-heading"><div><h2>Customer debts</h2><p class="muted">Original principal, remaining balance, and due dates</p></div><label class="inline-filter">Search customer or debt<input wire:model.live.debounce.250ms="search" placeholder="Name or DEBT-00001"></label></div>
        <div class="table-wrap"><table><thead><tr><th>Debt / customer</th><th>Type</th><th>Original amount</th><th>Remaining</th><th>Due / status</th><th></th></tr></thead><tbody>
            @forelse($debts as $debt)<tr wire:key="debt-{{ $debt['id'] }}"><td><strong>{{ $debt['customer'] }}</strong><br><small>{{ $debt['number'] }} · {{ \Illuminate\Support\Carbon::parse($debt['time'])->setTimezone('Asia/Shanghai')->format('M j, Y') }}</small>@if($debt['notes'])<div class="table-note">{{ $debt['notes'] }}</div>@endif</td><td>{{ $debt['type'] }}@if($debt['saleId'])<br><a href="/sales" wire:navigate>Receipt history</a>@else<br><small>{{ $debt['method'] }}</small>@endif</td><td>{{ number_format($debt['principal']/100,2) }}@if($debt['returnCredit'] ?? 0)<br><small>Return credit: {{ number_format($debt['returnCredit']/100,2) }}</small>@endif</td><td><strong>{{ number_format($debt['balance']/100,2) }}</strong></td><td>{{ $debt['dueDate'] ?: 'No due date' }}<br><span class="status-pill {{ $debt['balance'] > 0 && $debt['dueDate'] && $debt['dueDate'] < $today ? 'overdue' : '' }}">{{ $debt['balance'] === 0 ? (($debt['returnCredit'] ?? 0) > 0 ? 'Settled' : 'Paid') : ($debt['dueDate'] && $debt['dueDate'] < $today ? 'Overdue' : 'Open') }}</span></td><td>@if($debt['balance'] > 0)<button wire:click="selectDebt('{{ $debt['id'] }}')">Select for repayment</button>@endif</td></tr>@empty<tr><td colspan="6">No matching debts. Grocery credit starts at checkout; cash loans are recorded above.</td></tr>@endforelse
        </tbody></table></div>
    </section>
    <section class="panel"><h2>Repayment history</h2><div class="table-wrap"><table><thead><tr><th>Date</th><th>Customer / debt</th><th>Amount</th><th>Method</th><th>Reference</th></tr></thead><tbody>
        @forelse($repayments as $repayment)<tr><td>{{ \Illuminate\Support\Carbon::parse($repayment['time'])->setTimezone('Asia/Shanghai')->format('M j, Y · g:i A') }}</td><td>{{ $repayment['customer'] }}<br><small>{{ $openDebts[$repayment['debtId']]['number'] ?? (\App\Support\SessionCredit::debts()[$repayment['debtId']]['number'] ?? '') }}</small></td><td>{{ number_format($repayment['amount']/100,2) }}</td><td>{{ $repayment['method'] }}</td><td>{{ $repayment['reference'] ?: '—' }}</td></tr>@empty<tr><td colspan="5">No matching repayments yet.</td></tr>@endforelse
    </tbody></table></div></section>
</div>
