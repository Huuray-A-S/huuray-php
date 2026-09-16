<?php

declare(strict_types=1);

/*
 * Quickstart — read-only.
 *
 * Every call here is safe to run against a live account: nothing is ordered,
 * nothing is delivered, nothing is spent.
 *
 *   HUURAY_API_TOKEN=... HUURAY_API_SECRET=... php examples/quickstart.php
 */

use Huuray\Exception\NotFoundException;
use Huuray\HuurayClient;

require __DIR__ . '/../vendor/autoload.php';

$huuray = new HuurayClient(
    apiToken: (string) getenv('HUURAY_API_TOKEN'),
    apiSecret: (string) getenv('HUURAY_API_SECRET'),
);

// 1. What can we spend? Amounts are in minor units: 50000 is 500.00.
foreach ($huuray->balances->list()->balances as $balance) {
    printf("%s  %s%s\n", $balance->currency ?? '', number_format($balance->balance / 100, 2, '.', ''), $balance->master ? '  (master)' : '');
}

// 2. What can we send? Leaving `all` false returns only products this account
//    can order, and includes the productToken you need in order to order them.
$products = $huuray->catalogue->list(all: false)->products;
printf("\n%d products available\n", count($products));

$first = null;
foreach ($products as $product) {
    if ($product->active && $product->productToken !== null) {
        $first = $product;
        break;
    }
}
if ($first === null || $first->productToken === null) {
    echo "No orderable products on this account.\n";
    exit(0);
}
printf("Example: %s (%s) — token %s\n", $first->brandName ?? '', $first->currency ?? '', $first->productToken);

// 3. Is it in stock?
$stock = $huuray->stock->check(productToken: $first->productToken)->stock;
printf("Stock: %s\n", $stock ?? 'unknown');

// 4. How would it be delivered? Templates are the emails and texts recipients get.
//    Handle both outcomes observed live: a 404 when the account had no templates,
//    and an empty `templates` list when it had only PDF templates.
try {
    $templates = $huuray->templates->list();
} catch (NotFoundException) {
    echo "\nNo templates found (404).\n";
    exit(0);
}

printf("\n%d delivery templates\n", count($templates->templates));
foreach (array_slice($templates->templates, 0, 5) as $template) {
    printf("  %d  %s (%s, %s)\n", $template->id, $template->name ?? '', $template->type ?? '', $template->language ?? '');
}
printf("%d PDF templates\n", count($templates->pdfTemplates));

/*
 * Sending an actual reward is one more call, and it needs an email or SMS
 * template from step 4 — `templates` above can be empty. It is commented out
 * because running it spends real money:
 *
 * $reward = $huuray->sendReward(
 *     productToken: $first->productToken,
 *     value: 50_00,                          // minor units — 50.00
 *     currency: (string) $first->currency,
 *     recipient: new Huuray\Recipient(name: 'Jane Doe', email: 'jane@example.com'),
 *     templateId: $templates->templates[0]->id,
 *     refId: 'quickstart-demo-1',            // your own key, required
 * );
 * echo $reward->orderUid, "\n";
 */
