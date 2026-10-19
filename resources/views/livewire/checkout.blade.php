<div>
    <div wire:offline class="notice">Connection unavailable. Use the prepared offline checkout in this browser. <a href="/offline-checkout.html">Open offline checkout</a></div>
    @if($message)<p class="success" role="status">{{ $message }}</p>@endif
    @if($errors->any())<div class="notice" role="alert">@foreach($errors->all() as $error)<p class="error">{{ $error }}</p>@endforeach</div>@endif
    <div class="checkout-tools" role="region" aria-label="Checkout tools">
        <span class="checkout-session-state">{{ $cashSession ? $cashSession['number'].' · Open at Counter 1' : 'No open cash session' }}</span>
        <div class="checkout-tool-actions">@if($cashSession)<a class="button" href="/cash" wire:navigate>Manage cash session</a>@endif<a class="button" href="/offline-checkout.html" title="Prepare this browser with an open cash session before selling offline.">Offline checkout</a></div>
    </div>
    <div class="columns checkout panel-grid checkout-workspace">
        <section class="panel checkout-catalogue" aria-label="Products"><h2>Products</h2><form wire:submit="scan"><label>Search name / SKU, or scan and press Enter<input wire:model.live.debounce.250ms="search" placeholder="Try drink or DEMO-006" autofocus></label></form>
            <div class="product-grid">
            @forelse($products as $product)
                @php
                    $productLines = collect($lines)->where('productId', $product['id']);
                    $inCartUnits = $productLines->sum('baseQuantity');
                    $optionQuantities = $productLines->pluck('quantity', 'id');
                @endphp
                <article class="pos-product {{ $inCartUnits ? 'in-cart' : '' }}" wire:key="pos-product-{{ $product['id'] }}">
                    <img class="product-image" src="{{ asset('images/product-placeholder.svg') }}" width="240" height="180" alt="Image placeholder for {{ $product['name'] }}" loading="lazy">
                    <h3>{{ $product['name'] }}</h3><p class="muted">{{ $batchStock[$product['id']]['sellable'] ?? 0 }} {{ $product['unit'] }}{{ ($batchStock[$product['id']]['sellable'] ?? 0) === 1 ? '' : 's' }} available · {{ $product['sku'] }}@if(($batchStock[$product['id']]['expired'] ?? 0) > 0)<br><span class="error">{{ $batchStock[$product['id']]['expired'] }} expired · unavailable for sale</span>@endif</p>
                    @if($inCartUnits)<span class="cart-status" role="status">✓ In cart · {{ $inCartUnits }} {{ $product['unit'] }}{{ $inCartUnits===1 ? '' : 's' }}</span>@endif
                    <div class="options">@foreach($product['options'] as $option)<button class="{{ ($optionQuantities[$option['id']] ?? 0) ? 'selected-option' : '' }}" wire:click="add('{{ $option['id'] }}')"><span>{{ $option['label'] }}@if($optionQuantities[$option['id']] ?? 0)<small>{{ $optionQuantities[$option['id']] }} in cart</small>@endif</span><strong>{{ \App\Support\ShopPreferences::amount($option['price']) }}</strong></button>@endforeach</div>
                </article>
            @empty<p>No matching product. Try another name, SKU or barcode.</p>@endforelse
            </div>
        </section>
        <section class="panel checkout-cart" aria-label="Cart"><h2>Cart</h2>
            @forelse($lines as $line)<div class="cart-line" wire:key="{{ $line['id'] }}"><img class="cart-image" src="{{ asset('images/product-placeholder.svg') }}" width="48" height="48" alt="Image placeholder for {{ $line['name'] }}"><div><strong>{{ $line['name'] }}</strong><p>{{ $line['label'] }} · {{ $line['baseQuantity'] }} base units</p></div><input aria-label="Quantity for {{ $line['name'] }} {{ $line['label'] }}" type="number" min="1" max="9999" value="{{ $line['quantity'] }}" wire:change="setQuantity('{{ $line['id'] }}', $event.target.value)"><strong>{{ \App\Support\ShopPreferences::amount($line['total']) }}</strong><button aria-label="Remove {{ $line['label'] }}" wire:click="remove('{{ $line['id'] }}')">×</button></div>@empty<div class="empty">Your cart is empty.<br>Add an individual item or package.</div>@endforelse
            <details class="checkout-disclosure" wire:ignore.self><summary>Hold this cart</summary><form wire:submit="holdCart">
                <label>Cart label (optional)<input wire:model="holdLabel" maxlength="80" placeholder="Customer name or a short reminder"></label>
                <button type="submit" wire:loading.attr="disabled" @disabled(!$lines || !$canHoldCart)>Hold cart</button>
                <p class="muted">Saves items without reserving stock. Enter payment again when resuming.</p>
                @if(!$canHoldCart)<p class="notice">Apply the saved-cart migration to enable holding carts.</p>@endif
            </form></details>
        </section>
        <section class="panel checkout-summary" aria-label="Receipt Overview"><h2>Receipt Overview</h2>
            <p class="spread"><span>Subtotal</span><strong>{{ \App\Support\ShopPreferences::amount($subtotal) }}</strong></p>
            <details class="checkout-disclosure" wire:ignore.self @if($discount !== '' || $discountReason !== '' || $discountError) open @endif><summary>Discount</summary><div class="form-grid"><label>Discount (PHP, optional)<input type="number" min="0" step="0.01" wire:model.live.debounce.250ms="discount" placeholder="0.00"></label><label>Discount reason<input wire:model.live.debounce.250ms="discountReason" maxlength="200" placeholder="Required when giving a discount"></label></div></details>
            @if($discountError)<p class="error" role="alert">{{ $discountError }}</p>@endif
            @if($discountAmount > 0)<p class="spread"><span>Discount</span><strong>−{{ \App\Support\ShopPreferences::amount($discountAmount) }}</strong></p>@endif
            <div class="total"><span>Total</span><strong>{{ $discountError ? 'Review discount' : \App\Support\ShopPreferences::amount($total) }}</strong></div>
            <div class="checkout-payment-fields"><label>Payment method<select wire:model.live="method"><option>Cash</option><option>GCash</option><option>Maya</option><option value="Credit">Grocery credit (utang)</option></select></label>
            @if($method==='Cash')<label>Cash received<input type="number" min="0" step="0.01" wire:model.live.debounce.250ms="received" placeholder="0.00"></label>
            @elseif($method!=='Credit')<label>{{ $method }} amount received (PHP)<input type="number" min="0" step="0.01" wire:model.live.debounce.250ms="received" placeholder="0.00"></label>@endif</div>
            @if($method==='Credit')
            <div class="checkout-payment-fields">
            <label>Credit customer<select wire:model.live="customerId"><option value="">Select customer</option>@foreach($customers as $customer)<option value="{{ $customer['id'] }}">{{ $customer['name'] }}</option>@endforeach</select></label>
            <label>Credit due date (optional)<input type="date" wire:model.live="dueDate"></label></div>
            <p class="muted">Added to this customer’s grocery debt. <a href="/credit" wire:navigate>Add a customer</a>.</p>
            @elseif($method!=='Cash')<p class="muted">Use the printed QR and enter the received amount from {{ $method }}. It must match the total.</p><label class="check"><input type="checkbox" wire:model.live="confirmed"> I verified the amount and payment in the app</label>@endif
            @if(!$cashSession)
            <form class="notice checkout-session-opening" wire:submit="openCashSession">
                <h3>Open cash session</h3>
                <p>Enter starting drawer cash, or 0 if none. Your cart stays here.</p>
                <div><label>Opening cash (PHP)<input type="number" min="0" step="0.01" wire:model="opening" placeholder="0.00" required></label><button type="submit" class="primary compact" wire:loading.attr="disabled">Open cash session</button></div>
            </form>
            @endif
            <p id="checkout-completion-help" class="{{ !$cashSession || $lines ? 'sr-only' : 'muted' }}" role="status">@if(!$cashSession)Open a cash session to complete this sale.@elseif(!$lines)Add a product to start.@else Payment and available stock will be checked before saving.@endif</p>
            <button type="button" class="primary" aria-describedby="checkout-completion-help" wire:click="complete" wire:loading.attr="disabled" @disabled(!$lines || !$cashSession || $discountError)>Complete sale</button>
            <p class="muted checkout-tax-note">PHP · Tax unconfigured · Not a BIR invoice.</p>
            @if(isset($receipt['number']))<div class="checkout-receipt-output"><h3>Latest saved receipt</h3><div class="receipt printable-receipt" wire:transition="receipt-preview">@include('components.receipt', ['receipt'=>$receipt, 'isCopy'=>false, 'allowPrint'=>true, 'printLabel'=>'Print receipt'])</div></div>
            @elseif($liveReceipt)<div class="checkout-receipt-output" aria-label="Live receipt preview"><h3>Live preview</h3><div class="receipt">@include('components.receipt', ['receipt'=>$liveReceipt, 'isCopy'=>false, 'allowPrint'=>false])</div></div>@endif
        </section>
    </div>
    <section class="panel checkout-held-panel" aria-label="Held carts">
        <details class="checkout-held-carts" wire:ignore.self><summary>Held carts <span>{{ count($heldCarts) }} / 20</span></summary><p class="muted">Resumed carts use current prices and available stock.</p>
        @if($lines && $heldCarts)<p class="notice">Hold or empty your current cart before resuming another.</p>@endif
        <div class="table-wrap"><table><thead><tr><th>Cart / saved items</th><th>Held at</th><th>Saved total</th><th>Actions</th></tr></thead><tbody>
            @forelse($heldCarts as $held)<tr wire:key="held-cart-{{ $held['id'] }}"><td><strong>{{ $held['label'] ?: 'Unlabelled cart' }}</strong>@foreach($held['lines'] as $savedLine)<br><small>{{ $savedLine['name'] }} · {{ $savedLine['label'] }} × {{ $savedLine['quantity'] }}</small>@endforeach</td><td>{{ \Illuminate\Support\Carbon::parse($held['time'])->timezone('Asia/Shanghai')->format('M j · g:i A') }}</td><td>{{ \App\Support\ShopPreferences::amount($held['total']) }}</td><td><div class="form-actions"><button type="button" wire:click="resumeCart('{{ $held['id'] }}')" wire:loading.attr="disabled" @disabled($cart !== [])>Resume</button><button type="button" wire:click="discardHeldCart('{{ $held['id'] }}')" wire:confirm="Discard this held cart?" wire:loading.attr="disabled">Discard</button></div></td></tr>@empty<tr><td colspan="4">No held carts. Use Hold cart to save your current items.</td></tr>@endforelse
        </tbody></table></div></details>
    </section>
</div>
