<div x-data="{supplierOpen:false}">
    @if($message)<p class="success" role="status">{{ $message }}</p>@endif
    @if($errors->any())<section class="notice" role="alert">@foreach($errors->all() as $error)<p class="error">{{ $error }}</p>@endforeach</section>@endif
    <p><a class="button" href="#supplier-balances">Supplier balances & payments ↓</a></p>
    <section class="panel">
        <div class="section-heading"><div><h2>Supplier directory</h2><p class="muted">Your purchasing contacts, in one place.</p></div><button class="primary compact" type="button" wire:click="cancelSupplierEdit" @click="supplierOpen=true">+ Add supplier</button></div>
        @if($suppliers)
        <div class="supplier-grid">
            @foreach($suppliers as $supplier)
            <article class="supplier-card" wire:key="supplier-{{ $supplier['id'] }}"><div class="spread"><h3>{{ $supplier['name'] }}</h3><button wire:click="editSupplier('{{ $supplier['id'] }}')" @click="supplierOpen=true">Edit</button></div><p>{{ $supplier['contact'] ?: 'No contact person' }}</p><p class="muted">{{ $supplier['phone'] ?: 'No phone added' }}<br>{{ $supplier['email'] ?: 'No email added' }}</p>@if($supplier['notes'])<p class="muted">{{ $supplier['notes'] }}</p>@endif</article>
            @endforeach
        </div>
        @else
        <div class="empty-state"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.3" aria-hidden="true"><path d="M3 21V7l9-4 9 4v14M7 21v-5h10v5M7 8h2m6 0h2M7 12h2m6 0h2"/></svg><h3>Your suppliers will appear here</h3><p>Add your first supplier to start creating purchase orders and tracking deliveries.</p></div>
        @endif
        <form class="supplier-form" wire:submit="saveSupplier" x-show="supplierOpen" x-cloak x-transition:enter="motion-enter" x-transition:enter-start="motion-hidden" x-transition:enter-end="motion-visible" x-transition:leave="motion-leave" x-transition:leave-start="motion-visible" x-transition:leave-end="motion-hidden">
            <h2>{{ $supplierEditingId ? 'Edit supplier' : 'New supplier' }}</h2>
            <div class="columns"><div><label>Supplier name<input wire:model="name" maxlength="120" placeholder="e.g. Central Wholesale"></label><label>Contact person <span class="muted">· Optional</span><input wire:model="contact" placeholder="Contact name"></label><label>Phone <span class="muted">· Optional</span><input type="tel" wire:model="phone" placeholder="Phone number"></label></div><div><label>Email <span class="muted">· Optional</span><input type="email" wire:model="email" placeholder="supplier@example.com"></label><label>Notes <span class="muted">· Optional</span><input wire:model="supplierNotes" maxlength="500" placeholder="Delivery days, contact preferences…"></label></div></div>
            <div class="form-actions"><button class="primary compact" type="submit" wire:loading.attr="disabled">Save supplier</button><button type="button" wire:click="cancelSupplierEdit" @click="supplierOpen=false">Cancel</button></div>
        </form>
    </section>
    <details class="panel order-create">
        <summary>Create purchase order</summary>
        <form wire:submit="createOrder">
            <p class="muted">Choose a supplier and the products you want to order. Stock changes when you receive a delivery.</p>
            <label>Supplier<select wire:model="supplierId"><option value="">Choose a supplier</option>@foreach($suppliers as $supplier)<option value="{{ $supplier['id'] }}">{{ $supplier['name'] }}</option>@endforeach</select></label>
            @foreach($lines as $index=>$line)
            <div class="option-form" wire:key="po-line-{{ $index }}"><label>Product / package<select wire:model="lines.{{ $index }}.optionId"><option value="">Choose a product option</option>@foreach($products as $product)@foreach($product['options'] as $option)<option value="{{ $option['id'] }}">{{ $product['name'] }} — {{ $option['label'] }}</option>@endforeach @endforeach</select></label><label>Quantity<input type="number" min="1" wire:model="lines.{{ $index }}.quantity"></label><label>Expected unit cost<input type="number" min="0.01" step="0.01" wire:model="lines.{{ $index }}.cost" placeholder="0.00"></label><div><p class="muted">Cost of one selected item or package.</p>@if(count($lines)>1)<button type="button" wire:click="removeLine({{ $index }})">Remove line</button>@endif</div></div>
            @endforeach
            <label>Order notes <span class="muted">· Optional</span><input wire:model="orderNotes" maxlength="500" placeholder="Add delivery instructions or notes"></label>
            <div class="form-actions"><button class="primary compact" type="submit" wire:loading.attr="disabled">Record order</button><button type="button" wire:click="addLine">+ Add product</button></div><p class="muted">Orders are recorded here; they are not sent to suppliers automatically.</p>
        </form>
    </details>
    @if($receivingOrder)
    <form class="panel" wire:submit="receiveOrder" wire:transition="order-receiving">
        <div class="section-heading"><div><h2>Receive {{ $receivingOrder['number'] }}</h2><p class="muted">{{ $receivingOrder['supplier'] }} · Enter only what arrived in this delivery.</p></div><span class="badge">{{ $receivingOrder['status'] }}</span></div>
        @foreach($receivingOrder['lines'] as $index=>$line)
        <article class="product" wire:key="delivery-{{ $receivingOrder['id'] }}-{{ $index }}"><h3>{{ $line['name'] }} — {{ $line['label'] }}</h3><p class="muted">Ordered {{ $line['quantity'] }} · Received {{ $line['received'] }} · Remaining {{ $line['quantity']-$line['received'] }} · {{ $line['units'] }} base units each</p><div class="option-form"><label>Received now<input type="number" min="0" max="{{ $line['quantity']-$line['received'] }}" wire:model="deliveries.{{ $index }}.quantity"></label><label>Actual unit cost<input type="number" min="0.01" step="0.01" wire:model="deliveries.{{ $index }}.cost"></label><label>Expiry date<input type="date" wire:model="deliveries.{{ $index }}.expiry"></label></div></article>
        @endforeach
        <label>Payment due date (optional)<input type="date" wire:model="dueDate"></label><label>Invoice / delivery reference <span class="muted">· Optional</span><input wire:model="reference" maxlength="100" placeholder="e.g. INV-001"></label><div class="form-actions"><button class="primary compact" type="submit" wire:loading.attr="disabled">Record delivery</button><button type="button" wire:click="cancelReceiving">Cancel</button></div><p class="muted">Leave undelivered lines at zero. Expiry-tracked products require a date. Recorded deliveries appear in Inventory history and create an unpaid bill below.</p>
    </form>
    @endif
    <section class="panel">
        <div class="section-heading"><div><h2>Purchase orders</h2><p class="muted">Track ordered quantities and incoming deliveries.</p></div><span class="badge">{{ count($orders) }} orders</span></div>
        @forelse($orders as $order)
        <article class="product" wire:key="order-{{ $order['id'] }}"><div class="spread"><div><h3>{{ $order['number'] }} · {{ $order['supplier'] }}</h3><span class="badge">{{ $order['status'] }}</span></div>@if($order['status']!=='Received')<button wire:click="startReceiving('{{ $order['id'] }}')">Receive delivery →</button>@endif</div><div class="table-wrap"><table><thead><tr><th>Product / option</th><th>Ordered</th><th>Received</th><th>Expected cost</th></tr></thead><tbody>@foreach($order['lines'] as $line)<tr><td>{{ $line['name'] }} — {{ $line['label'] }}</td><td>{{ $line['quantity'] }}</td><td>{{ $line['received'] }}</td><td>{{ number_format($line['cost']*$line['quantity']/100,2) }}</td></tr>@endforeach</tbody></table></div><p class="muted">{{ $order['createdAt'] }} · {{ $order['notes'] }}</p></article>
        @empty
        <div class="empty-state"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.3" aria-hidden="true"><path d="M6 3h12v18l-3-2-3 2-3-2-3 2zM9 7h6M9 11h6M9 15h3"/></svg><h3>No purchase orders yet</h3><p>Create an order above. Partial and completed deliveries will be tracked here.</p></div>
        @endforelse
    </section>
    <livewire:payables />
</div>
