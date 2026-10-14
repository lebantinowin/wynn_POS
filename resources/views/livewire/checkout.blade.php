<div>
    <div wire:offline class="notice">Connection unavailable. Use the prepared offline checkout in this browser. <a href="/offline-checkout.html">Open offline checkout</a></div>
    <div class="notice spread"><span>Keep selling during a connection interruption after preparing this browser with an open cash session.</span><a class="button" href="/offline-checkout.html">Offline checkout</a></div>
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
                    <p class="muted">{{ $batchStock[$product['id']]['sellable'] ?? 0 }} sellable · {{ $batchStock[$product['id']]['expired'] ?? 0 }} expired</p>
                    @if($inCartUnits)<span class="cart-status" role="status">✓ In cart · {{ $inCartUnits }} {{ $product['unit'] }}{{ $inCartUnits===1 ? '' : 's' }}</span>@endif
                    <div class="options">@foreach($product['options'] as $option)<button class="{{ ($optionQuantities[$option['id']] ?? 0) ? 'selected-option' : '' }}" wire:click="add('{{ $option['id'] }}')"><span>{{ $option['label'] }}@if($optionQuantities[$option['id']] ?? 0)<small>{{ $optionQuantities[$option['id']] }} in cart</small>@endif</span><strong>{{ \App\Support\ShopPreferences::amount($option['price']) }}</strong></button>@endforeach</div>
                </article>
            @empty<p>No matching product. Try another name, SKU or barcode.</p>@endforelse
            </div>
        </section>
        <section class="panel"><h2>Current cart <span class="muted">· Session preview</span></h2>
            @forelse($lines as $line)<div class="cart-line" wire:key="{{ $line['id'] }}"><img class="cart-image" src="{{ asset('images/product-placeholder.svg') }}" width="48" height="48" alt="Image placeholder for {{ $line['name'] }}"><div><strong>{{ $line['name'] }}</strong><p>{{ $line['label'] }} · {{ $line['baseQuantity'] }} base units</p></div><input aria-label="Quantity for {{ $line['name'] }} {{ $line['label'] }}" type="number" min="1" max="9999" value="{{ $line['quantity'] }}" wire:change="setQuantity('{{ $line['id'] }}', $event.target.value)"><strong>{{ \App\Support\ShopPreferences::amount($line['total']) }}</strong><button aria-label="Remove {{ $line['label'] }}" wire:click="remove('{{ $line['id'] }}')">×</button></div>@empty<div class="empty">Your cart is empty.<br>Add an individual item or package.</div>@endforelse
            <p class="spread"><span>Subtotal</span><strong>{{ \App\Support\ShopPreferences::amount($subtotal) }}</strong></p>
            <div class="form-grid"><label>Discount (PHP, optional)<input type="number" min="0" step="0.01" wire:model.live.debounce.250ms="discount" placeholder="0.00"></label><label>Discount reason<input wire:model.live.debounce.250ms="discountReason" maxlength="200" placeholder="Required when giving a discount"></label></div>
            @if($discountError)<p class="error" role="alert">{{ $discountError }}</p>@endif
            @if($discountAmount > 0)<p class="spread"><span>Discount</span><strong>−{{ \App\Support\ShopPreferences::amount($discountAmount) }}</strong></p>@endif
            <div class="total"><span>Total after discount*</span><strong>{{ $discountError ? 'Review discount' : \App\Support\ShopPreferences::amount($total) }}</strong></div>
            <label>Payment method<select wire:model.live="method"><option>Cash</option><option>GCash</option><option>Maya</option><option value="Credit">Grocery credit (utang)</option></select></label>
            @if($method==='Cash')<label>Cash received<input type="number" min="0" step="0.01" wire:model="received" placeholder="0.00"></label>
            @elseif($method==='Credit')
            <label>Credit customer<select wire:model.live="customerId"><option value="">Select customer</option>@foreach($customers as $customer)<option value="{{ $customer['id'] }}">{{ $customer['name'] }}</option>@endforeach</select></label>
            <label>Credit due date (optional)<input type="date" wire:model.live="dueDate"></label>
            <p class="muted">The full sale is added to this customer’s debt. Stock is deducted when you complete it. <a href="/credit" wire:navigate>Add a customer</a>.</p>
            @else<p class="notice">Use the printed {{ $method }} QR at the counter. Verify the received payment in the app.</p><label>{{ $method }} amount received (PHP)<input type="number" min="0" step="0.01" wire:model.blur="received" placeholder="0.00"></label><p class="muted">Enter the amount shown in the app. It must match the sale total.</p><label class="check"><input type="checkbox" wire:model="confirmed"> I verified the amount and payment in the app</label>@endif
            @if(!$cashSession)
            <form class="notice" wire:submit="openCashSession">
                <h3>Open a cash session to enable Complete sale</h3>
                <p>A session groups all sales for this counter, including GCash, Maya and grocery credit. Your cart stays here while you open it.</p>
                <label>Opening cash (PHP)<input type="number" min="0" step="0.01" wire:model="opening" placeholder="0.00" required></label>
                <p class="muted">Enter the cash already in the drawer before sales. Enter 0 if there is none.</p>
                <button type="submit" class="primary compact" wire:loading.attr="disabled">Open cash session and keep cart</button>
            </form>
            @endif
            <p id="checkout-completion-help" class="muted" role="status">@if(!$lines && !$cashSession)Add a product and open a cash session to enable Complete sale.@elseif(!$cashSession)Complete sale is disabled until you open a cash session above.@elseif(!$lines)Add a product to enable Complete sale.@else Payment and available stock will be checked before saving.@endif</p>
            <button type="button" class="primary" aria-describedby="checkout-completion-help" wire:click="complete" wire:loading.attr="disabled" @disabled(!$lines || !$cashSession || $discountError)>Complete sale</button>
            <div class="form-actions"><button wire:click="preview" wire:loading.attr="disabled" @disabled(!$lines)>Preview receipt</button><a class="button" href="/sales" wire:navigate>Receipt history</a></div>
            <form wire:submit="holdCart">
                <label>Cart label (optional)<input wire:model="holdLabel" maxlength="80" placeholder="Customer name or a short reminder"></label>
                <button type="submit" wire:loading.attr="disabled" @disabled(!$lines || !$canHoldCart)>Hold cart</button>
                <p class="muted">Hold before leaving checkout to save these items. Stock is not reserved, and payment details must be entered again after resuming.</p>
                @if(!$canHoldCart)<p class="notice">Apply the saved-cart migration to enable holding carts.</p>@endif
            </form>
            <p class="muted">Completing a sale deducts stock and saves the receipt. Preview does not change stock. Philippine pesos (PHP). Tax calculation and BIR invoice issuance are pending.</p>
        </section>
    </div>
    <section class="panel" aria-label="Held carts">
        <div class="section-heading"><div><h2>Held carts</h2><p class="muted">{{ count($heldCarts) }} of 20 carts on hold. Saved totals are estimates; resumed carts use current prices and available stock.</p></div></div>
        @if($lines && $heldCarts)<p class="notice">Hold or empty your current cart before resuming another.</p>@endif
        <div class="table-wrap"><table><thead><tr><th>Cart / saved items</th><th>Held at</th><th>Saved total</th><th>Actions</th></tr></thead><tbody>
            @forelse($heldCarts as $held)<tr wire:key="held-cart-{{ $held['id'] }}"><td><strong>{{ $held['label'] ?: 'Unlabelled cart' }}</strong>@foreach($held['lines'] as $savedLine)<br><small>{{ $savedLine['name'] }} · {{ $savedLine['label'] }} × {{ $savedLine['quantity'] }}</small>@endforeach</td><td>{{ \Illuminate\Support\Carbon::parse($held['time'])->timezone('Asia/Shanghai')->format('M j · g:i A') }}</td><td>{{ \App\Support\ShopPreferences::amount($held['total']) }}</td><td><div class="form-actions"><button type="button" wire:click="resumeCart('{{ $held['id'] }}')" wire:loading.attr="disabled" @disabled($cart !== [])>Resume</button><button type="button" wire:click="discardHeldCart('{{ $held['id'] }}')" wire:confirm="Discard this held cart?" wire:loading.attr="disabled">Discard</button></div></td></tr>@empty<tr><td colspan="4">No held carts. Use Hold cart to save your current items.</td></tr>@endforelse
        </tbody></table></div>
    </section>
    @if($receipt)<section class="panel receipt printable-receipt" wire:transition="receipt-preview">@include('components.receipt', ['receipt'=>$receipt, 'isCopy'=>false])</section>@endif
</div>
