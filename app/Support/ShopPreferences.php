<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class ShopPreferences
{
    private ?array $cached = null;

    public static function defaults(): array
    {
        return ['shopName' => config('app.name'), 'address' => '', 'contact' => '', 'footer' => '',
            'currency' => 'PHP', 'taxStatus' => 'Unconfirmed', 'invoiceRequirement' => 'BIR',
            'registeredName' => '', 'tin' => '', 'branchCode' => '', 'rdo' => '', 'permitReference' => ''];
    }

    public function all(): array
    {
        if ($this->cached !== null) {
            return $this->cached;
        }
        if (! PosStore::database()) {
            return $this->cached = array_replace(self::defaults(), session()->get('shop.preferences', []));
        }
        if (! PosStore::connection()->getSchemaBuilder()->hasTable('shop_settings')) {
            return $this->cached = self::defaults();
        }
        $snapshot = PosStore::connection()->table('shop_settings')->where('id', 1)->value('snapshot');

        return $this->cached = array_replace(self::defaults(), $snapshot ? json_decode($snapshot, true, flags: JSON_THROW_ON_ERROR) : []);
    }

    public function save(array $values): void
    {
        abort_unless(auth()->id() === User::OWNER_ID, 403);
        $values = array_map(fn (mixed $value): mixed => is_string($value) ? trim($value) : $value, $values);
        $valid = Validator::make($values, [
            'shopName' => 'required|string|max:120', 'address' => 'present|string|max:300',
            'contact' => 'present|string|max:100', 'footer' => 'present|string|max:200',
            'currency' => 'required|in:PHP', 'taxStatus' => 'required|in:Unconfirmed,Non-VAT,VAT',
            'invoiceRequirement' => 'required|in:BIR',
            'registeredName' => 'present|string|max:160', 'tin' => ['present', 'string', 'regex:/^(?:[0-9]{9}|[0-9]{3}-[0-9]{3}-[0-9]{3})?$/'],
            'branchCode' => ['present', 'string', 'regex:/^[0-9]{0,5}$/'],
            'rdo' => 'present|string|max:60', 'permitReference' => 'present|string|max:120',
        ])->validate();
        if (! PosStore::database()) {
            session()->put('shop.preferences', $valid);
            $this->cached = $valid;

            return;
        }
        PosStore::atomic(function () use ($valid): void {
            app(OfflineCheckout::class)->assertWritable();
            if (! PosStore::ready() || ! PosStore::connection()->getSchemaBuilder()->hasTable('shop_settings')) {
                throw ValidationException::withMessages(['settings' => 'Initialize the workspace and apply the shop-settings migration before saving.']);
            }
            PosStore::connection()->table('shop_settings')->updateOrInsert(['id' => 1], [
                'snapshot' => json_encode($valid, JSON_THROW_ON_ERROR), 'updated_at' => now(),
            ]);
        });
        $this->cached = $valid;
    }

    public function receiptProfile(): array
    {
        return array_intersect_key($this->all(), array_flip(['shopName', 'address', 'contact', 'footer', 'currency', 'taxStatus', 'invoiceRequirement']));
    }

    public static function amount(int|float $cents, ?array $profile = null): string
    {
        $currency = ($profile ?? app(self::class)->all())['currency'] ?? null;

        return ($currency === 'PHP' ? '₱' : '').number_format($cents / 100, 2);
    }

    public static function notice(?array $profile = null): string
    {
        $settings = $profile ?? app(self::class)->all();

        return (($settings['currency'] ?? null) === 'PHP' ? 'Amounts in Philippine pesos (PHP).' : 'Currency was not recorded.')
            .' Tax calculation is not configured. This sales slip is not a BIR invoice.';
    }
}
