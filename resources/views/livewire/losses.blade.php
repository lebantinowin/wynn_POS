<div>
    @if($message)<p class="success" role="status">{{ $message }}</p>@endif
    @if($errors->any())<div class="notice" role="alert">@foreach($errors->all() as $error)<p class="error">{{ $error }}</p>@endforeach</div>@endif
    <div class="columns panel-grid"><section class="panel"><h2>Record a stock loss</h2><form wire:submit="record">
        <label>Lost product / package<select wire:model.live="optionId" required><option value="">Select product option</option>@foreach($products as $product)@foreach($product['options'] as $saleOption)<option value="{{ $saleOption['id'] }}">{{ $product['name'] }} · {{ $saleOption['label'] }}</option>@endforeach@endforeach</select></label>
        <div class="form-grid"><label>Lost quantity<input type="number" min="1" max="100000" wire:model.live="quantity" required></label><label>Loss reason<select wire:model="reason">@foreach($reasons as $item)<option>{{ $item }}</option>@endforeach</select></label></div>
        @if($option)<p class="notice">Available: {{ $option['stock'] }} base units. Each selected {{ $option['label'] }} deducts {{ $option['units'] }} base units.</p>@endif
        <label>What happened?<textarea wire:model="notes" rows="3" minlength="3" maxlength="500" required placeholder="For example, one item was consumed without payment."></textarea></label>
        <label>Purchase cost per selected item / package (optional)<input wire:model="unitCost" inputmode="decimal" placeholder="Leave blank if unknown"></label>
        <p class="muted">Enter acquisition cost for one selected option, not its selling price. Unknown costs remain unvalued in reports.</p>
        <button type="submit" wire:loading.attr="disabled">Record loss & deduct stock</button>
    </form></section>
    <section class="panel"><h2>Keep every deduction traceable</h2><p>Use this section for unpaid consumption, damaged or expired products, and missing stock. Each entry keeps the quantity, reason, notes, and before-and-after stock count.</p>
        <p>If a customer owes you for groceries, complete a grocery credit sale at checkout instead. That creates their debt and deducts the stock once.</p>
        <p class="muted">Loss records appear in Dashboard and Reports. Cash shortages and expenses are tracked in Shifts & Expenses. Returned goods discarded through Transactions appear here without another stock deduction.</p><a class="button" href="/reports" wire:navigate>View loss analytics</a>
    </section></div>
    <section class="panel"><h2>Stock loss history</h2><div class="table-wrap"><table><thead><tr><th>Recorded</th><th>Product / quantity</th><th>Reason / notes</th><th>Stock before → after</th><th>Recorded cost</th></tr></thead><tbody>
        @forelse($losses as $loss)<tr wire:key="loss-{{ $loss['id'] }}"><td>{{ \Illuminate\Support\Carbon::parse($loss['time'])->setTimezone('Asia/Shanghai')->format('M j, Y · g:i A') }}<br><small>{{ $loss['actor'] }}</small></td><td><strong>{{ $loss['name'] }}</strong><br>{{ $loss['option'] }} × {{ $loss['quantity'] }}<br><small>{{ $loss['baseQuantity'] }} base units lost</small></td><td>{{ $loss['reason'] }}<div class="table-note">{{ $loss['notes'] }}</div></td><td>{{ $loss['before'] }} → {{ $loss['after'] }}</td><td>{{ $loss['totalCost'] !== null ? number_format($loss['totalCost']/100,2) : 'Unknown' }}</td></tr>@empty<tr><td colspan="5">No stock losses recorded in this session.</td></tr>@endforelse
    </tbody></table></div></section>
</div>
