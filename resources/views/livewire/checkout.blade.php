<div>
    <div wire:offline class="notice">Connection unavailable. Offline sales are not implemented yet.</div>
    @if($message)<p class="success" role="status">{{ $message }}</p>@endif
    @if($errors->any())<div class="notice" role="alert">@foreach($errors->all() as $error)<p class="error">{{ $error }}</p>@endforeach</div>@endif
    <div class="notice spread"><span>{{ $cashSession ? $cashSession['number'].' · Open at Counter 1' : 'Open a cash session before completing a sale.' }}</span><a class="button" href="/cash" wire:navigate>{{ $cashSession ? 'Manage cash session' : 'Open cash session' }}</a></div>
    <div class="columns checkout panel-grid">
        <section class="panel"><h2>Find products</h2><form wire:submit="scan"><label>Search name / SKU, or scan and press Enter<input wire:model.live.debounce.250ms="search" placeholder="Try drink or DEMO-006" autofocus></label></form>
            <div class="product-grid">
            @forelse($products as $product)
                @php
                    $productLines = collect($lines)->where('productId', $product['id']);
                    $inCartUnits = $productLines->sum('baseQuantity');
                    $optionQuantities = $productLines->pluck('quantity', 'id');
                @endphp
                <article class="pos-product {{ $inCartUnits ? 'in-cart' : '' }}" wire:key="pos-product-{{ $product['id'] }}">
                    <img class="product-image" src="{{ asset('images/product-placeholder.svg') }}" width="240" height="180" alt="Image placeholder for {{ $product['name'] }}" loading="lazy">
                    <h3>{{ $product['name'] }}</h3><p class="muted">{{ $product['stock'] }} {{ $product['unit'] }}s · {{ $product['sku'] }}</p>
                    @if($inCartUnits)<span class="cart-status" role="status">✓ In cart · {{ $inCartUnits }} {{ $product['unit'] }}{{ $inCartUnits===1 ? '' : 's' }}</span>@endif
                    <div class="options">@foreach($product['options'] as $option)<button class="{{ ($optionQuantities[$option['id']] ?? 0) ? 'selected-option' : '' }}" wire:click="add('{{ $option['id'] }}')"><span>{{ $option['label'] }}@if($optionQuantities[$option['id']] ?? 0)<small>{{ $optionQuantities[$option['id']] }} in cart</small>@endif</span><strong>{{ number_format($option['price']/100,2) }}</strong></button>@endforeach</div>
                </article>
            @empty<p>No matching product. Try another name, SKU or barcode.</p>@endforelse
            </div>
        </section>
        <section class="panel"><h2>Current cart <span class="muted">· Session preview</span></h2>
            @forelse($lines as $line)<div class="cart-line" wire:key="{{ $line['id'] }}"><img class="cart-image" src="{{ asset('images/product-placeholder.svg') }}" width="48" height="48" alt="Image placeholder for {{ $line['name'] }}"><div><strong>{{ $line['name'] }}</strong><p>{{ $line['label'] }} · {{ $line['baseQuantity'] }} base units</p></div><input aria-label="Quantity for {{ $line['name'] }} {{ $line['label'] }}" type="number" min="1" max="9999" value="{{ $line['quantity'] }}" wire:change="setQuantity('{{ $line['id'] }}', $event.target.value)"><strong>{{ number_format($line['total']/100,2) }}</strong><button aria-label="Remove {{ $line['label'] }}" wire:click="remove('{{ $line['id'] }}')">×</button></div>@empty<div class="empty">Your cart is empty.<br>Add an individual item or package.</div>@endforelse
            <div class="total"><span>Total*</span><strong>{{ number_format($total/100,2) }}</strong></div>
            <label>Payment method<select wire:model.live="method"><option>Cash</option><option>GCash</option><option>Maya</option><option value="Credit">Grocery credit (utang)</option></select></label>
            @if($method==='Cash')<label>Cash received<input type="number" min="0" step="0.01" wire:model="received" placeholder="0.00"></label>
            @elseif($method==='Credit')
            <label>Credit customer<select wire:model.live="customerId"><option value="">Select customer</option>@foreach($customers as $customer)<option value="{{ $customer['id'] }}">{{ $customer['name'] }}</option>@endforeach</select></label>
            <label>Credit due date (optional)<input type="date" wire:model.live="dueDate"></label>
            <p class="muted">The full sale is added to this customer’s debt. Stock is deducted when you complete it. <a href="/credit" wire:navigate>Add a customer</a>.</p>
            @else<p class="notice">Use the printed {{ $method }} QR at the counter. Verify the received payment in the app.</p><label class="check"><input type="checkbox" wire:model="confirmed"> I verified the payment in the app</label>@endif
            <button class="primary" wire:click="complete" wire:loading.attr="disabled" @disabled(!$lines || !$cashSession)>Complete sale</button>
            <div class="form-actions"><button wire:click="preview" wire:loading.attr="disabled" @disabled(!$lines)>Preview receipt</button><a class="button" href="/sales" wire:navigate>Receipt history</a></div>
            <p class="muted">Completing a sale deducts stock and saves it in this browser session. Preview does not change stock. *Currency and tax settings pending.</p>
        </section>
    </div>
    @if($receipt)<section class="panel receipt printable-receipt" wire:transition="receipt-preview">@include('components.receipt', ['receipt'=>$receipt, 'isCopy'=>false])</section>@endif
</div>
