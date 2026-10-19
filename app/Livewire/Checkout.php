<?php

namespace App\Livewire;

use App\Support\CheckoutCarts;
use App\Support\DemoCatalogue;
use App\Support\SaleDiscount;
use App\Support\SessionBatches;
use App\Support\SessionCash;
use App\Support\SessionCredit;
use App\Support\SessionSales;
use App\Support\ShopPreferences;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Component;

class Checkout extends Component
{
    public string $search = '';

    public array $cart = [];

    public string $method = 'Cash';

    public string $received = '';

    public string $opening = '';

    public string $holdLabel = '';

    public string $discount = '';

    public string $discountReason = '';

    public string $customerId = '';

    public string $dueDate = '';

    public bool $confirmed = false;

    #[Locked]
    public ?array $receipt = null;

    #[Locked]
    public string $saleEventId = '';

    #[Locked]
    public ?string $cashSessionId = null;

    #[Locked]
    public string $openEventId = '';

    public string $message = '';

    public function mount(): void
    {
        $this->saleEventId = (string) Str::uuid();
        $this->openEventId = (string) Str::uuid();
        $this->cashSessionId = SessionCash::active()['id'] ?? null;
    }

    public function openCashSession(): void
    {
        $this->resetErrorBag();
        $cashSession = SessionCash::open($this->openEventId, $this->opening, '');
        SessionCash::requireOpen($cashSession['id']);
        $this->cashSessionId = $cashSession['id'];
        $this->openEventId = (string) Str::uuid();
        $this->reset('opening');
        $this->message = $cashSession['number'].' opened. Your cart is ready to continue.';
    }

    public function updatedMethod(): void
    {
        $this->received = '';
        $this->confirmed = false;
        $this->receipt = null;
    }

    public function updatingReceived(): void
    {
        $this->confirmed = false;
        $this->receipt = null;
    }

    public function updatedConfirmed(): void
    {
        $this->receipt = null;
    }

    public function updatingDiscount(): void
    {
        $this->confirmed = false;
        $this->receipt = null;
    }

    public function updatedDiscountReason(): void
    {
        $this->receipt = null;
    }

    public function updatedCustomerId(): void
    {
        $this->receipt = null;
    }

    public function updatedDueDate(): void
    {
        $this->receipt = null;
    }

    public function add(string $id): void
    {
        $option = DemoCatalogue::option($id);
        if (! $option) {
            return;
        }
        $quantity = ($this->cart[$id] ?? 0) + 1;
        $this->setQuantity($id, $quantity);
        $this->receipt = null;
    }

    public function setQuantity(string $id, mixed $quantity): void
    {
        $this->resetErrorBag();
        if (! DemoCatalogue::option($id) || filter_var($quantity, FILTER_VALIDATE_INT) === false || (int) $quantity < 1 || (int) $quantity > 9999) {
            $this->addError('cart', 'Enter a whole quantity between 1 and 9999.');

            return;
        }
        $this->cart[$id] = (int) $quantity;
        $this->receipt = null;
        $this->confirmed = false;
        $this->message = '';
    }

    public function remove(string $id): void
    {
        unset($this->cart[$id]);
        $this->receipt = null;
        $this->confirmed = false;
        $this->message = '';
    }

    public function scan(): void
    {
        foreach (DemoCatalogue::products() as $product) {
            foreach ($product['options'] as $option) {
                if ($option['barcode'] === trim($this->search)) {
                    $this->add($option['id']);
                    $this->search = '';

                    return;
                }
            }
        }
        $this->addError('search', 'No matching barcode. Search by product name or SKU.');
    }

    public function lines(): array
    {
        $lines = [];
        foreach ($this->cart as $id => $quantity) {
            $option = DemoCatalogue::option($id);
            if ($option && is_int($quantity) && $quantity > 0 && $quantity <= 9999) {
                $lines[] = $option + ['quantity' => $quantity, 'total' => $option['price'] * $quantity, 'baseQuantity' => $option['units'] * $quantity];
            }
        }

        return $lines;
    }

    public function preview(): void
    {
        $this->resetErrorBag();
        $this->receipt = null;
        $this->receipt = SessionSales::draft($this->cart, $this->method, $this->received, $this->confirmed, $this->customerId, $this->dueDate, false, $this->discount, $this->discountReason);
    }

    public function holdCart(): void
    {
        $this->resetErrorBag();
        CheckoutCarts::hold($this->saleEventId, $this->cart, $this->holdLabel, $this->discount, $this->discountReason);
        $this->reset('cart', 'search', 'method', 'received', 'confirmed', 'customerId', 'dueDate', 'receipt', 'holdLabel', 'discount', 'discountReason');
        $this->saleEventId = (string) Str::uuid();
        $this->message = 'Cart saved on hold. You can start another cart or leave checkout.';
    }

    public function resumeCart(string $id): void
    {
        $this->resetErrorBag();
        if ($this->cart !== []) {
            $this->addError('heldCart', 'Hold or empty your current cart before resuming another.');

            return;
        }
        $record = CheckoutCarts::resume($id);
        $this->reset('search', 'method', 'received', 'confirmed', 'customerId', 'dueDate', 'receipt', 'holdLabel');
        $this->cart = $record['cart'];
        $this->discount = $record['discountInput'] ?? '';
        $this->discountReason = $record['discountReason'] ?? '';
        $this->saleEventId = (string) Str::uuid();
        $this->cashSessionId = SessionCash::active()['id'] ?? null;
        $this->message = 'Cart resumed. Check current stock and prices, then enter payment details again.';
        if ($record['pricesChanged']) {
            $this->message .= ' Prices or package sizes changed since this cart was held.';
        }
        if ($record['shortStock']) {
            $this->message .= ' Some items exceed current sellable stock. Reduce their quantities before completing the sale.';
        }
    }

    public function discardHeldCart(string $id): void
    {
        $this->resetErrorBag();
        CheckoutCarts::discard($id);
        $this->message = 'Held cart discarded. Stock and sales are unchanged.';
    }

    public function complete(): void
    {
        $this->resetErrorBag();
        $this->receipt = null;
        $sale = SessionSales::complete($this->saleEventId, $this->cart, $this->method, $this->received, $this->confirmed, $this->customerId, $this->dueDate, $this->cashSessionId, false, $this->discount, $this->discountReason);
        $this->reset('cart', 'search', 'received', 'confirmed', 'customerId', 'dueDate', 'discount', 'discountReason');
        $this->saleEventId = (string) Str::uuid();
        $this->receipt = $sale;
        $this->message = "Sale {$sale['number']} completed. Stock and batch costs updated.";
    }

    private function liveReceipt(array $pricing, string $discountError): ?array
    {
        if ($pricing['lines'] === [] || $discountError !== '' || ! in_array($this->method, ['Cash', 'GCash', 'Maya', 'Credit'], true)) {
            return null;
        }
        $received = null;
        if (preg_match('/^\d{1,12}(\.\d{1,2})?$/', $this->received)) {
            [$whole, $fraction] = array_pad(explode('.', $this->received, 2), 2, '');
            $received = (int) $whole * 100 + (int) str_pad($fraction, 2, '0');
        }
        $profile = app(ShopPreferences::class)->receiptProfile();
        $customer = SessionCredit::customers()[$this->customerId] ?? null;
        $paymentStatus = match ($this->method) {
            'Cash' => $received === null ? 'Payment not entered.' : ($received < $pricing['total'] ? 'Cash received is below the total.' : ''),
            'Credit' => $customer === null ? 'Select a credit customer.' : 'Will be recorded as grocery credit when completed.',
            default => ! $this->confirmed ? 'Awaiting app verification.' : ($received === $pricing['total'] ? 'App payment verified by owner.' : 'Received amount must match the total.'),
        };

        return ['shop' => $profile['shopName'], 'receiptProfile' => $profile, 'lines' => $pricing['lines'],
            'subtotal' => $pricing['subtotal'], 'discount' => $pricing['discount'], 'discountReason' => $pricing['discountReason'] ?? '',
            'total' => $pricing['total'], 'method' => $this->method, 'cashReceived' => $this->method === 'Cash' ? $received : null,
            'paymentReceived' => $this->method !== 'Credit' ? $received : null,
            'change' => $this->method === 'Cash' && $received !== null && $received >= $pricing['total'] ? $received - $pricing['total'] : null,
            'customer' => $this->method === 'Credit' ? ($customer['name'] ?? 'Not selected') : null, 'dueDate' => $this->dueDate,
            'paymentStatus' => $paymentStatus];
    }

    public function render(): View
    {
        $products = array_filter(DemoCatalogue::products(), fn ($p) => $this->search === '' || str_contains(strtolower($p['name'].' '.$p['sku'].' '.implode(' ', array_column($p['options'], 'barcode'))), strtolower($this->search)));
        $lines = $this->lines();
        $subtotal = array_sum(array_column($lines, 'total'));
        $pricing = ['lines' => $lines, 'subtotal' => $subtotal, 'discount' => 0, 'total' => $subtotal];
        $discountError = '';
        try {
            $pricing = SaleDiscount::apply($lines, $this->discount, $this->discountReason);
        } catch (ValidationException $exception) {
            $discountError = collect($exception->errors())->flatten()->first();
        }

        return view('livewire.checkout', ['products' => $products, 'lines' => $pricing['lines'], 'total' => $pricing['total'],
            'subtotal' => $subtotal, 'discountAmount' => $pricing['discount'], 'discountError' => $discountError,
            'liveReceipt' => $this->liveReceipt($pricing, $discountError),
            'customers' => SessionCredit::customers(), 'cashSession' => SessionCash::active(), 'batchStock' => SessionBatches::overview()['products'],
            'heldCarts' => CheckoutCarts::held(), 'canHoldCart' => CheckoutCarts::available()]);
    }
}
