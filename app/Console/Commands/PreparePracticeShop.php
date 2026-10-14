<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Support\DemoCatalogue;
use App\Support\PosStore;
use App\Support\PracticeShop;
use App\Support\SessionBatches;
use App\Support\ShopPreferences;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use RuntimeException;

#[Signature('practice:prepare {--check-owner : Print the practice login setup status}')]
#[Description('Prepare an isolated SQLite practice shop without replacing existing records or creating a password')]
class PreparePracticeShop extends Command
{
    public function handle(): int
    {
        try {
            File::ensureDirectoryExists(storage_path('app/practice'));
            $path = PracticeShop::databasePath(false);
            if ($this->option('check-owner')) {
                PracticeShop::assertPrepared();
                $this->line(User::query()->whereKey(User::OWNER_ID)->exists() ? 'configured' : 'needs-setup');

                return self::SUCCESS;
            }
            foreach (['sessions', 'cache/data', 'cache/locks', 'private'] as $directory) {
                File::ensureDirectoryExists(storage_path('app/practice/'.$directory));
            }
            if (! is_file($path)) {
                File::put($path, '');
            }
            $connection = config('pos.connection');
            $schema = PosStore::connection()->getSchemaBuilder();
            $tables = array_column($schema->getTables(), 'name');
            if ($tables !== [] && ! in_array('pos_workspace', $tables, true)) {
                throw new RuntimeException('Existing unrelated database detected. Practice setup did not change it.');
            }
            if ($schema->hasTable('pos_workspace') && PosStore::ready()) {
                PracticeShop::assertPrepared();
            }
            if ($this->call('migrate', ['--database' => $connection, '--force' => true, '--no-interaction' => true]) !== self::SUCCESS) {
                return self::FAILURE;
            }
            if (! PosStore::ready()) {
                $product = ['id' => 1, 'name' => 'Practice biscuits', 'sku' => 'PRACTICE-001', 'category' => 'Pantry',
                    'stock' => 12, 'openingQuantity' => 12, 'unit' => 'piece', 'baseCost' => 400, 'reorder' => 6, 'trackExpiry' => true,
                    'openingExpiry' => now('Asia/Shanghai')->addDays(90)->toDateString(),
                    'options' => [['id' => 'practice-one', 'label' => 'Individual', 'units' => 1, 'price' => 1000, 'barcode' => 'PRAC001'],
                        ['id' => 'practice-pack', 'label' => 'Pack of 3', 'units' => 3, 'price' => 2800, 'barcode' => 'PRAC003']]];
                $state = ['batches' => [], 'products' => [], 'movements' => []];
                SessionBatches::add($state, $product, 12, 'Opening stock', 'practice-opening', 'Practice opening stock', $product['openingExpiry'], 4800);
                $settings = array_replace(ShopPreferences::defaults(), ['shopName' => "Wynn's Mart — Practice", 'footer' => 'Sample purchase — practice only']);
                PosStore::atomic(function () use ($product, $state, $settings): void {
                    PosStore::import(['products' => [$product], 'batches' => $state['batches'], 'batchProducts' => $state['products'], 'batchMovements' => $state['movements']], PracticeShop::SOURCE);
                    PosStore::connection()->table('shop_settings')->insert(['id' => 1, 'snapshot' => json_encode($settings, JSON_THROW_ON_ERROR), 'updated_at' => now()]);
                });
                $this->info('Practice stock prepared: 12 biscuits, cost PHP 4.00 each. No sales or cash sessions created.');
            } else {
                PosStore::atomic(function (): void {
                    foreach (SessionBatches::state()['batches'] as $batch) {
                        if ($batch['sourceId'] !== 'practice-opening') {
                            continue;
                        }
                        $product = DemoCatalogue::find($batch['productId']);
                        if ($product !== null && ! array_key_exists('openingQuantity', $product)) {
                            $product['openingQuantity'] = $batch['initialQuantity'];
                            DemoCatalogue::save($product);
                        }
                    }
                });
                $this->info('Existing practice records retained. The starting checklist applies to a fresh practice shop.');
            }
            $this->info('Create or sign into the separate practice owner account. Main shop records were not accessed.');

            return self::SUCCESS;
        } catch (RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
    }
}
