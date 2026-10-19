<div>
    @php $receiptProfile = $receipt['receiptProfile'] ?? ['currency' => 'PHP']; @endphp
    <h2>{{ $receipt['shop'] }}</h2>
    @if(!empty($receiptProfile['address']))<p style="white-space:pre-line">{{ $receiptProfile['address'] }}</p>@endif
    @if(!empty($receiptProfile['contact']))<p>{{ $receiptProfile['contact'] }}</p>@endif
    <p class="receipt-note">{{ isset($receipt['number']) ? 'SAVED SALE · NOT A FISCAL RECEIPT' : 'PREVIEW · NOT A COMPLETED SALE' }}@if($isCopy)<br><strong>COPY</strong>@endif</p>
    @if(isset($receipt['number']))<p>{{ $receipt['number'] }}<br>{{ \Illuminate\Support\Carbon::parse($receipt['time'])->setTimezone('Asia/Shanghai')->format('M j, Y · g:i A') }}<br>{{ $receipt['operator'] }} · {{ $receipt['counter'] }}</p>@endif
    @if(isset($receipt['offline']))<p class="receipt-note">Original local receipt: {{ $receipt['offline']['localNumber'] }} · Upload confirmed</p>@endif
    @foreach($receipt['lines'] as $line)<div class="product"><p class="spread"><span>{{ $line['name'] }}</span><strong>{{ \App\Support\ShopPreferences::amount($line['total'], $receiptProfile) }}</strong></p><small>{{ $line['label'] }} × {{ $line['quantity'] }} @ {{ \App\Support\ShopPreferences::amount($line['price'], $receiptProfile) }}</small></div>@endforeach
    <hr>
    @if(($receipt['discount'] ?? 0) > 0)<p class="spread"><span>Subtotal before discount</span><span>{{ \App\Support\ShopPreferences::amount($receipt['subtotal'], $receiptProfile) }}</span></p><p class="spread"><span>Discount</span><span>−{{ \App\Support\ShopPreferences::amount($receipt['discount'], $receiptProfile) }}</span></p><p>Discount reason: {{ $receipt['discountReason'] }}</p><p class="muted">Line totals include their share of the discount.</p>@endif
    <p class="spread"><strong>Total</strong><strong>{{ \App\Support\ShopPreferences::amount($receipt['total'], $receiptProfile) }}</strong></p><p>Payment: {{ $receipt['method'] }}</p>
    @if(!empty($receipt['paymentStatus']))<p class="muted">{{ $receipt['paymentStatus'] }}</p>@endif
    @if($receipt['method']==='Cash')<p class="spread"><span>Cash received</span><span>{{ $receipt['cashReceived'] !== null ? \App\Support\ShopPreferences::amount($receipt['cashReceived'], $receiptProfile) : 'Not entered' }}</span></p><p class="spread"><span>Change</span><span>{{ $receipt['change'] !== null ? \App\Support\ShopPreferences::amount($receipt['change'], $receiptProfile) : '—' }}</span></p>@endif
    @if(in_array($receipt['method'], ['GCash', 'Maya']))<p class="spread"><span>{{ $receipt['method'] }} amount received</span><span>{{ ($receipt['paymentReceived'] ?? null) !== null ? \App\Support\ShopPreferences::amount($receipt['paymentReceived'], $receiptProfile) : 'Not recorded' }}</span></p>@endif
    @if($receipt['method']==='Credit')<p>Customer: {{ $receipt['customer'] }}<br>On credit · Amount owed: {{ \App\Support\ShopPreferences::amount($receipt['total'], $receiptProfile) }}<br>Due: {{ $receipt['dueDate'] ?: 'Not set' }}</p>@endif
    @if(!empty($receiptProfile['footer']))<p style="white-space:pre-line">{{ $receiptProfile['footer'] }}</p>@endif
    <p class="muted">{{ \App\Support\ShopPreferences::notice($receiptProfile) }}</p>
    @if($allowPrint ?? true)<div class="form-actions no-print"><button type="button" @click="window.print()">{{ $printLabel ?? 'Print / Save PDF' }}</button></div>@endif
</div>
