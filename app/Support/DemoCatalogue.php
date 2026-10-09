<?php

namespace App\Support;

final class DemoCatalogue
{
    public static function products(): array
    {
        return session()->get('demo.products', self::defaults());
    }

    private static function defaults(): array
    {
        return [
            ['id' => 1, 'name' => 'Bottled drink', 'sku' => 'DRINK-001', 'category' => 'Beverages', 'stock' => 72, 'unit' => 'bottle', 'options' => [
                ['id' => 'drink-one', 'label' => 'Individual', 'units' => 1, 'price' => 2000, 'barcode' => 'DEMO-001'],
                ['id' => 'drink-pack', 'label' => 'Pack of 6', 'units' => 6, 'price' => 11000, 'barcode' => 'DEMO-006'],
                ['id' => 'drink-case', 'label' => 'Case of 24', 'units' => 24, 'price' => 42000, 'barcode' => 'DEMO-024'],
            ]],
            ['id' => 2, 'name' => 'Instant noodles', 'sku' => 'NOODLE-001', 'category' => 'Pantry', 'stock' => 48, 'unit' => 'packet', 'options' => [
                ['id' => 'noodle-one', 'label' => 'Individual', 'units' => 1, 'price' => 1500, 'barcode' => 'DEMO-101'],
                ['id' => 'noodle-pack', 'label' => 'Pack of 5', 'units' => 5, 'price' => 7000, 'barcode' => 'DEMO-105'],
            ]],
        ];
    }

    public static function option(string $id): ?array
    {
        foreach (self::products() as $product) {
            foreach ($product['options'] as $option) {
                if ($option['id'] === $id) return $option + ['name' => $product['name'], 'stock' => $product['stock'], 'productId' => $product['id']];
            }
        }
        return null;
    }

    public static function find(int $id): ?array
    {
        foreach (self::products() as $product) if ($product['id'] === $id) return $product;
        return null;
    }

    public static function save(array $product): void
    {
        $products = self::products();
        $index = array_search($product['id'], array_column($products, 'id'), true);
        if ($index === false) $products[] = $product;
        else $products[$index] = $product;
        session()->put('demo.products', $products);
    }

    public static function recordCount(array $entry): void
    {
        $history = session()->get('demo.counts', []);
        $history[] = $entry;
        session()->put('demo.counts', $history);
    }
}
