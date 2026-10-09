<div>
    <h2>{{ $receipt['shop'] }}</h2>
    <p class="receipt-note">{{ isset($receipt['number']) ? 'SESSION SALE · NOT A FISCAL RECEIPT' : 'PREVIEW · NOT A COMPLETED SALE' }}@if($isCopy)<br><strong>COPY</strong>@endif</p>
    @if(isset($receipt['number']))<p>{{ $receipt['number'] }}<br>{{ \Illuminate\Support\Carbon::parse($receipt['time'])->setTimezone('Asia/Shanghai')->format('M j, Y · g:i A') }}<br>{{ $receipt['operator'] }} · {{ $receipt['counter'] }}</p>@endif
    @foreach($receipt['lines'] as $line)<div class="product"><p class="spread"><span>{{ $line['name'] }}</span><strong>{{ number_format($line['total']/100,2) }}</strong></p><small>{{ $line['label'] }} × {{ $line['quantity'] }} @ {{ number_format($line['price']/100,2) }}</small></div>@endforeach
    <hr><p class="spread"><strong>Total</strong><strong>{{ number_format($receipt['total']/100,2) }}</strong></p><p>Payment: {{ $receipt['method'] }}</p>
    @if($receipt['method']==='Cash')<p class="spread"><span>Cash received</span><span>{{ number_format($receipt['cashReceived']/100,2) }}</span></p><p class="spread"><span>Change</span><span>{{ number_format($receipt['change']/100,2) }}</span></p>@endif
    @if($receipt['method']==='Credit')<p>Customer: {{ $receipt['customer'] }}<br>On credit · Amount owed: {{ number_format($receipt['total']/100,2) }}<br>Due: {{ $receipt['dueDate'] ?: 'Not set' }}</p>@endif
    <p class="muted">Prototype receipt · Currency and tax settings pending.</p>
    <div class="form-actions no-print"><button type="button" @click="window.print()">Print / Save PDF</button></div>
</div>
