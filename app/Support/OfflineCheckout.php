<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class OfflineCheckout
{
    private bool $applying = false;

    private ?Carbon $saleTime = null;

    public function time(): Carbon
    {
        return $this->saleTime?->copy() ?? now();
    }

    public function active(): ?object
    {
        if (! PosStore::database() || ! PosStore::connection()->getSchemaBuilder()->hasTable('offline_checkouts')) {
            return null;
        }

        return PosStore::connection()->table('offline_checkouts')->where('active', true)->first();
    }

    public function assertWritable(): void
    {
        if (! $this->applying && $this->active()) {
            throw ValidationException::withMessages(['offline' => 'Counter 1 is using offline checkout. Synchronize and finish that session in its original browser, or review Offline counter recovery in Settings before changing shop records.']);
        }
    }

    public function prepare(string $deviceId, string $id): array
    {
        return PosStore::atomic(function () use ($deviceId, $id): array {
            Validator::make(compact('deviceId', 'id'), ['deviceId' => 'required|uuid', 'id' => 'required|uuid'])->validate();
            if (! PosStore::database() || ! PosStore::ready()) {
                throw ValidationException::withMessages(['offline' => 'Offline checkout requires the initialized shared database.']);
            }
            $existing = PosStore::connection()->table('offline_checkouts')->where('id', $id)->first();
            if ($existing) {
                if ($existing->device_id !== $deviceId || ! $existing->active) {
                    throw ValidationException::withMessages(['offline' => 'This offline session belongs to another browser or has already finished.']);
                }

                return json_decode($existing->snapshot, true, flags: JSON_THROW_ON_ERROR);
            }
            if ($this->active()) {
                throw ValidationException::withMessages(['offline' => 'Another browser already holds Counter 1. Finish its offline session first.']);
            }
            $cash = SessionCash::requireOpen();
            $stock = SessionBatches::overview()['products'];
            $products = array_map(function (array $product) use ($stock): array {
                return array_intersect_key($product, array_flip(['id', 'name', 'sku', 'category', 'unit', 'options']))
                    + ['available' => $stock[$product['id']]['sellable'] ?? 0];
            }, DemoCatalogue::products());
            $until = now()->addMinutes(min(120, (int) config('session.lifetime', 120)));
            $midnight = now('Asia/Shanghai')->addDay()->startOfDay();
            if ($until->greaterThan($midnight)) {
                $until = $midnight;
            }
            $profile = app(ShopPreferences::class)->receiptProfile();
            $snapshot = ['version' => 2, 'id' => $id, 'deviceId' => $deviceId, 'shop' => $profile['shopName'], 'receiptProfile' => $profile,
                'preparedAt' => now()->toIso8601String(), 'validUntil' => $until->toIso8601String(),
                'cashSessionId' => $cash['id'], 'cashSessionNumber' => $cash['number'], 'products' => array_values($products),
                'customers' => array_values(array_map(fn (array $customer): array => array_intersect_key($customer, array_flip(['id', 'name'])), SessionCredit::customers()))];
            PosStore::connection()->table('offline_checkouts')->insert(['id' => $id, 'device_id' => $deviceId,
                'active' => true, 'snapshot' => json_encode($snapshot, JSON_THROW_ON_ERROR), 'next_sequence' => 1]);

            return $snapshot;
        });
    }

    public function sync(array $payload): array
    {
        return PosStore::atomic(function () use ($payload): array {
            $data = Validator::make($payload, [
                'leaseId' => 'required|uuid', 'deviceId' => 'required|uuid', 'eventId' => 'required|uuid',
                'sequence' => 'required|integer|min:1|max:1000000', 'occurredAt' => 'required|date_format:Y-m-d\\TH:i:s.v\\Z',
                'cart' => 'required|array|min:1|max:200', 'cart.*' => 'required|integer|min:1|max:9999',
                'method' => 'required|in:Cash,GCash,Maya,Credit', 'received' => 'present|nullable|string|max:30',
                'confirmed' => 'required|boolean', 'customerId' => 'present|nullable|string|max:120',
                'dueDate' => 'present|nullable|string|max:10', 'total' => 'required|integer|min:1|max:9007199254740991',
                'paymentVersion' => 'sometimes|integer|in:2',
                'discount' => 'sometimes|nullable|string|max:30', 'discountReason' => 'sometimes|nullable|string|max:200',
            ])->validate();
            foreach (['received', 'customerId', 'dueDate'] as $field) {
                $data[$field] ??= '';
            }
            ksort($data['cart']);
            ksort($data);
            $hash = hash('sha256', json_encode($data, JSON_THROW_ON_ERROR));
            $lease = $this->lease($data['leaseId'], $data['deviceId']);
            $events = PosStore::connection()->table('offline_sale_events');
            $saved = (clone $events)->where('event_id', $data['eventId'])->first();
            if ($saved) {
                if (! hash_equals($saved->payload_hash, $hash)) {
                    throw ValidationException::withMessages(['offline' => 'A saved sale identifier was reused with different details. Keep the local record for review.']);
                }

                return json_decode($saved->receipt, true, flags: JSON_THROW_ON_ERROR);
            }
            if (! $lease->active || (int) $lease->next_sequence !== (int) $data['sequence']) {
                if ($lease->recovery_release ?? null) {
                    throw ValidationException::withMessages(['offline' => 'The owner released this unavailable checkout. New uploads are blocked. Keep this recovery copy and reconcile its unconfirmed sales manually; confirmed uploads can still be rechecked.']);
                }
                throw ValidationException::withMessages(['offline' => 'Upload pending sales in their original order. This session may already be finished.']);
            }
            $snapshot = json_decode($lease->snapshot, true, flags: JSON_THROW_ON_ERROR);
            $at = Carbon::parse($data['occurredAt']);
            if ($at->lessThan(Carbon::parse($snapshot['preparedAt'])) || $at->greaterThanOrEqualTo(Carbon::parse($snapshot['validUntil']))
                || $at->greaterThan(now()->addMinute()) || ($lease->last_event_at && $at->lessThan(Carbon::parse($lease->last_event_at)))) {
                throw ValidationException::withMessages(['offline' => 'The saved sale time is outside its prepared session. Keep the local record for review.']);
            }
            foreach (SessionSales::all() as $sale) {
                if ($sale['eventId'] === $data['eventId']) {
                    throw ValidationException::withMessages(['offline' => 'This identifier already belongs to another transaction.']);
                }
            }
            $this->applying = true;
            $this->saleTime = $at;
            try {
                $allowUnrecordedDigital = ($snapshot['version'] ?? 1) < 2 && ! isset($data['paymentVersion']);
                $received = $allowUnrecordedDigital && in_array($data['method'], ['GCash', 'Maya']) ? '' : $data['received'];
                $draft = SessionSales::draft($data['cart'], $data['method'], $received, $data['confirmed'], $data['customerId'], $data['dueDate'], $allowUnrecordedDigital, $data['discount'] ?? '', $data['discountReason'] ?? '');
                if ($draft['total'] !== (int) $data['total']) {
                    throw ValidationException::withMessages(['offline' => 'The saved sale total does not match the prepared prices. Keep it for review.']);
                }
                $receipt = SessionSales::complete($data['eventId'], $data['cart'], $data['method'], $received, $data['confirmed'], $data['customerId'], $data['dueDate'], $snapshot['cashSessionId'], $allowUnrecordedDigital, $data['discount'] ?? '', $data['discountReason'] ?? '');
                $receipt['offline'] = ['leaseId' => $lease->id, 'sequence' => (int) $data['sequence'],
                    'localNumber' => 'OFF-'.strtoupper(substr($lease->id, 0, 8)).'-'.str_pad((string) $data['sequence'], 6, '0', STR_PAD_LEFT),
                    'syncedAt' => now()->toIso8601String()];
                $sales = SessionSales::all();
                $sales[$receipt['id']] = $receipt;
                PosStore::put('demo.sales', $sales);
                $events->insert(['event_id' => $data['eventId'], 'lease_id' => $lease->id, 'sequence' => $data['sequence'],
                    'payload_hash' => $hash, 'receipt' => json_encode($receipt, JSON_THROW_ON_ERROR)]);
                PosStore::connection()->table('offline_checkouts')->where('id', $lease->id)->update([
                    'next_sequence' => (int) $data['sequence'] + 1, 'last_event_at' => $at]);

                return $receipt;
            } finally {
                $this->applying = false;
                $this->saleTime = null;
            }
        });
    }

    public function finish(string $id, string $deviceId, int $lastSequence): void
    {
        PosStore::atomic(function () use ($id, $deviceId, $lastSequence): void {
            $lease = $this->lease($id, $deviceId);
            if ($lastSequence !== (int) $lease->next_sequence - 1) {
                throw ValidationException::withMessages(['offline' => 'Synchronize every saved sale before finishing offline checkout.']);
            }
            PosStore::connection()->table('offline_checkouts')->where('id', $id)->update(['active' => false]);
        });
    }

    public function recoveryAvailable(): bool
    {
        return PosStore::database() && PosStore::ready() && PosStore::connection()->getSchemaBuilder()->hasColumn('offline_checkouts', 'recovery_release');
    }

    public function releaseHistory(): array
    {
        if (! $this->recoveryAvailable()) {
            return [];
        }
        $history = PosStore::connection()->table('offline_checkouts')->whereNotNull('recovery_release')->get()
            ->map(fn (object $lease): array => json_decode($lease->recovery_release, true, flags: JSON_THROW_ON_ERROR) + ['leaseId' => $lease->id])->all();
        usort($history, fn (array $a, array $b): int => strcmp($b['releasedAt'], $a['releasedAt']));

        return $history;
    }

    public function releaseUnavailable(string $id, string $eventId, string $reason, string $confirmation, bool $acknowledged): array
    {
        return PosStore::atomic(function () use ($id, $eventId, $reason, $confirmation, $acknowledged): array {
            if ((int) auth()->id() !== User::OWNER_ID || ! $this->recoveryAvailable()) {
                throw ValidationException::withMessages(['offline' => 'Sign in as the owner in the initialized shop to recover Counter 1.']);
            }
            $reason = trim($reason);
            Validator::make(compact('id', 'eventId', 'reason', 'confirmation', 'acknowledged'), [
                'id' => 'required|uuid', 'eventId' => 'required|uuid', 'reason' => 'required|string|min:3|max:200',
                'confirmation' => 'required|in:RELEASE COUNTER 1', 'acknowledged' => 'accepted',
            ])->validate();
            $lease = PosStore::connection()->table('offline_checkouts')->where('id', $id)->first();
            if (! $lease) {
                throw ValidationException::withMessages(['offline' => 'This checkout could not be found. Refresh Settings before continuing.']);
            }
            if ($lease->recovery_release) {
                $saved = json_decode($lease->recovery_release, true, flags: JSON_THROW_ON_ERROR);
                if ($saved['eventId'] !== $eventId || $saved['reason'] !== $reason || $saved['ownerId'] !== User::OWNER_ID) {
                    throw ValidationException::withMessages(['offline' => 'This checkout was already released. Its recorded reason cannot be replaced.']);
                }

                return $saved;
            }
            if (! $lease->active) {
                throw ValidationException::withMessages(['offline' => 'This checkout already finished. Refresh Settings to review the current counter.']);
            }
            $snapshot = json_decode($lease->snapshot, true, flags: JSON_THROW_ON_ERROR);
            if (now()->lessThan(Carbon::parse($snapshot['validUntil']))) {
                throw ValidationException::withMessages(['offline' => 'Wait until this checkout’s offline selling period expires. The original browser may still be recording sales.']);
            }
            $release = ['eventId' => $eventId, 'ownerId' => User::OWNER_ID, 'reason' => $reason, 'releasedAt' => now()->toIso8601String(),
                'validUntil' => $snapshot['validUntil'], 'cashSessionId' => $snapshot['cashSessionId'],
                'confirmedSales' => (int) $lease->next_sequence - 1, 'unconfirmedSales' => 'Unknown', 'acknowledged' => true];
            PosStore::connection()->table('offline_checkouts')->where('id', $id)->update(['active' => false, 'recovery_release' => json_encode($release, JSON_THROW_ON_ERROR)]);

            return $release;
        });
    }

    private function lease(string $id, string $deviceId): object
    {
        if (! PosStore::database()) {
            throw ValidationException::withMessages(['offline' => 'Offline checkout requires the shared database.']);
        }
        $lease = PosStore::connection()->table('offline_checkouts')->where('id', $id)->first();
        if (! $lease || $lease->device_id !== $deviceId) {
            throw ValidationException::withMessages(['offline' => 'Use the original prepared checkout browser.']);
        }

        return $lease;
    }
}
