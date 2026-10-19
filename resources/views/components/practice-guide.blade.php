@props(['section', 'compact' => false])
<section class="practice-guide operations-link {{ $compact ? 'checkout-help' : 'panel' }}" aria-label="Owner practice walkthrough">
    <details @if($section === 'dashboard') open @endif @if($compact) x-data @click.outside="$el.open=false" @keydown.escape.window="$el.open=false" @endif>
        <summary><strong>{{ $compact ? 'Help' : 'Owner practice walkthrough' }}</strong>@unless($compact)<span class="muted">Manual entry · No scanner or printer needed</span>@endunless</summary>
        @if($compact)<div class="checkout-help-content"><h2>Owner practice walkthrough</h2>@endif
        <p class="muted">Follow these steps once in a fresh practice shop. Relaunching keeps your progress and does not reset records. The expected totals below apply to this sequence only; extra transactions change them.</p>
        <ol class="practice-steps">
            <li><a href="{{ route('workspace', ['section' => 'products']) }}" wire:navigate>Review the sample product</a><p>Practice biscuits starts with 12 pieces. Base cost: ₱4 each. Individual: ₱10, barcode <code>PRAC001</code>. Pack of 3: ₱28, barcode <code>PRAC003</code>. An opening batch has a recorded expiry date.</p></li>
            <li><a href="{{ route('workspace', ['section' => 'cash']) }}" wire:navigate>Open the drawer</a><p>Enter an opening float of ₱1,000. This is practice cash.</p></li>
            <li><a href="{{ route('workspace', ['section' => 'checkout']) }}" wire:navigate>Make a cash sale</a><p>Enter <code>PRAC003</code> twice and add one individual by searching “Practice biscuits”. Review 2 packs + 1 piece: ₱66 total. Pay with ₱100 cash; change should be ₱34. Complete one sale. Stock should become 5 pieces.</p></li>
            <li><a href="{{ route('workspace', ['section' => 'sales']) }}" wire:navigate>Review the sale and return one pack</a><p>Open its saved receipt or print preview; this should not create another sale. Return 1 pack as Restock and refund ₱28 Cash. Stock should become 8 pieces and the return should have its own history record.</p></li>
            <li><a href="{{ route('workspace', ['section' => 'losses']) }}" wire:navigate>Record unpaid consumption</a><p>Record 1 individual biscuit with reason Unpaid consumption and a practice note. Its batch supplies the ₱4 loss cost; leave the manual estimate blank. Stock should become 7 pieces.</p></li>
            <li><a href="{{ route('workspace', ['section' => 'cash']) }}" wire:navigate>Record an expense</a><p>Record a ₱5 Cash operating expense with a practice note. Expected drawer cash should become ₱1,033.</p></li>
            <li><a href="{{ route('workspace', ['section' => 'credit']) }}" wire:navigate>Try grocery credit and a cash loan</a><p>Add “Practice customer”. In Checkout, sell 1 individual biscuit on Credit to that customer. In Customer Credit &amp; Loans, lend ₱30 Cash. Repay the ₱10 grocery debt in Cash, then repay ₱10 of the cash loan in Cash. The customer should owe ₱20 on the cash loan and ₱0 on groceries.</p></li>
            <li><a href="{{ route('workspace', ['section' => 'cash']) }}" wire:navigate>Close the drawer</a><p>Expected cash should be ₱1,023. Count ₱1,023 and close the session: difference ₱0.</p></li>
            <li><a href="{{ route('workspace', ['section' => 'reports']) }}" wire:navigate>Compare reports and exports</a><p>For this sequence: net sales ₱48, collections ₱58, sales cost ₱20, gross profit ₱28, operating profit ₱19, stock 6 pieces / ₱24 value, and customer balance ₱20. Download a CSV and open Print view. Confirm both are marked as practice.</p></li>
        </ol>
        <p class="notice">This prepares owner review. It does not mark owner acceptance complete or issue BIR invoices. Confirm the result of your practice run before moving to live use.</p>
        @if($compact)</div>@endif
    </details>
</section>
