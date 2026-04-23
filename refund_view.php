<?php
declare(strict_types=1);

if (session_status() !== PHP_SESSION_ACTIVE) {
    @session_start();
}

require_once dirname(__DIR__) . '/includes/order_refund.php';

function bvsrv_h($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function bvsrv_current_seller_id(): int
{
    foreach (['seller_id', 'user_id', 'member_id', 'id'] as $k) {
        if (isset($_SESSION[$k]) && is_numeric($_SESSION[$k]) && (int)$_SESSION[$k] > 0) {
            return (int)$_SESSION[$k];
        }
    }
    return function_exists('bv_order_refund_current_user_id') ? (int)bv_order_refund_current_user_id() : 0;
}

function bvsrv_csrf_token(): string
{
    $token = $_SESSION['_csrf_seller_refunds']['refund_actions'] ?? '';
    if (!is_string($token) || trim($token) === '') {
        $token = bin2hex(random_bytes(16));
        $_SESSION['_csrf_seller_refunds']['refund_actions'] = $token;
    }
    return $token;
}

function bvsrv_pick_money(array $sources, array $keys, float $default = 0.0): float
{
    foreach ($sources as $source) {
        if (!is_array($source)) {
            continue;
        }
        foreach ($keys as $key) {
            if (array_key_exists($key, $source) && $source[$key] !== null && is_numeric($source[$key])) {
                return (float)$source[$key];
            }
        }
    }
    return $default;
}

$sellerId = bvsrv_current_seller_id();
$refundId = (int)($_GET['id'] ?? 0);

if ($sellerId <= 0 || $refundId <= 0) {
    http_response_code(403);
    exit('Invalid access');
}

$refund = bv_order_refund_get_by_id($refundId);
if (!$refund) {
    http_response_code(404);
    exit('Refund not found');
}

$items = bv_order_refund_get_items_for_seller($refundId, $sellerId);
if ($items === []) {
    http_response_code(403);
    exit('No seller-owned refund items for this refund.');
}

$decision = bv_order_refund_get_seller_decision($refundId, $sellerId);
$requested = (float)($decision['requested_amount'] ?? 0);
$approved = (float)($decision['approved_amount'] ?? 0);
$status = (string)($decision['status'] ?? 'pending_approval');
$currency = (string)($refund['currency'] ?? 'USD');
$csrf = bvsrv_csrf_token();

$headerRequestedAmount = bvsrv_pick_money([
    $decision,
    $refund,
], [
    'requested_refund_amount',
    'requested_amount',
], $requested);

$headerApprovedAmount = bvsrv_pick_money([
    $decision,
    $refund,
], [
    'approved_refund_amount',
    'approved_amount',
], $approved);

$headerFeeLossAmount = bvsrv_pick_money([
    $decision,
    $refund,
], [
    'non_refundable_fee_amount',
    'fee_loss_amount',
], bvsrv_pick_money([
    $decision,
    $refund,
], ['platform_fee_loss'], 0.0) + bvsrv_pick_money([
    $decision,
    $refund,
], ['gateway_fee_loss'], 0.0));

$headerActualRefundAmount = bvsrv_pick_money([
    $decision,
    $refund,
], [
    'actual_refund_amount',
    'actual_refunded_amount',
], $headerApprovedAmount);
?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Seller Refund View</title>
    <style>
        body{font-family:Arial,sans-serif;background:#f4f6fb;margin:0;padding:24px;color:#1f2937}
        .container{max-width:960px;margin:0 auto}
        .card{background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:16px;margin-bottom:12px}
        table{width:100%;border-collapse:collapse}
        th,td{border-bottom:1px solid #eef2f7;padding:8px;text-align:left}
        .actions{display:flex;gap:8px;align-items:center;flex-wrap:wrap}
        .btn{padding:9px 12px;border-radius:8px;border:0;cursor:pointer;font-weight:700}
        .approve{background:#16a34a;color:#fff}
        .reject{background:#dc2626;color:#fff}
        .muted{color:#6b7280}
        textarea,input[type=number]{padding:8px;border:1px solid #d1d5db;border-radius:8px;width:100%}
    </style>
</head>
<body>
<div class="container">
    <div class="card">
        <h2>Refund #<?php echo bvsrv_h($refundId); ?> (Seller Slice)</h2>
        <div class="muted">Header status: <?php echo bvsrv_h((string)($refund['status'] ?? '')); ?></div>
        <div class="muted">Seller decision status: <?php echo bvsrv_h($status); ?></div>
        <div class="muted">Requested Amount: <?php echo bvsrv_h(number_format($headerRequestedAmount, 2) . ' ' . $currency); ?></div>
        <div class="muted">Approved Amount: <?php echo bvsrv_h(number_format($headerApprovedAmount, 2) . ' ' . $currency); ?></div>
        <div class="muted">Fee Deducted / Non-refundable Fee: <?php echo bvsrv_h(number_format($headerFeeLossAmount, 2) . ' ' . $currency); ?></div>
        <div class="muted">Actual Refund Amount: <?php echo bvsrv_h(number_format($headerActualRefundAmount, 2) . ' ' . $currency); ?></div>
    </div>

    <div class="card">
        <h3>Seller-owned refund items</h3>
        <table>
            <thead><tr><th>Item ID</th><th>Listing</th><th>Requested</th><th>Approved</th><th>Fee Loss</th><th>Actual / Net Refund</th></tr></thead>
            <tbody>
            <?php foreach ($items as $item): ?>
                <?php
                    $itemRequestedAmount = (float)($item['requested_refund_amount'] ?? 0);
                    $itemApprovedAmount = (float)($item['approved_refund_amount'] ?? 0);
                    $itemFeeLossAmount = bvsrv_pick_money([
                        $item,
                    ], [
                        'fee_loss_amount',
                        'non_refundable_fee_amount',
                    ], 0.0);
                    $itemActualRefundAmount = bvsrv_pick_money([
                        $item,
                    ], [
                        'actual_refund_after_fee',
                        'actual_refund_amount',
                        'actual_refunded_amount',
                        'net_refund_amount',
                    ], $itemApprovedAmount);
                ?>
                <tr>
                    <td><?php echo bvsrv_h((int)$item['id']); ?></td>
                    <td><?php echo bvsrv_h((string)($item['listing_title'] ?? 'Listing')); ?></td>
                    <td><?php echo bvsrv_h(number_format($itemRequestedAmount, 2)); ?></td>
                    <td><?php echo bvsrv_h(number_format($itemApprovedAmount, 2)); ?></td>
                    <td><?php echo bvsrv_h(number_format($itemFeeLossAmount, 2)); ?></td>
                    <td><?php echo bvsrv_h(number_format($itemActualRefundAmount, 2)); ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <?php if ($status === 'pending_approval'): ?>
    <div class="card">
        <form method="post" action="/seller/refund_action.php">
            <input type="hidden" name="csrf_token" value="<?php echo bvsrv_h($csrf); ?>">
            <input type="hidden" name="refund_id" value="<?php echo bvsrv_h($refundId); ?>">
            <input type="hidden" name="return_url" value="<?php echo bvsrv_h('/seller/refund_view.php?id=' . $refundId); ?>">
            <label>Approved Amount (your seller slice)</label>
            <input type="number" step="0.01" min="0" name="approved_refund_amount" value="<?php echo bvsrv_h(number_format($requested, 2, '.', '')); ?>">
            <label>Note</label>
            <textarea name="note" rows="3" placeholder="Optional decision note"></textarea>
            <div class="actions" style="margin-top:10px;">
                <button class="btn approve" type="submit" name="action" value="approve">Approve Seller Slice</button>
                <button class="btn reject" type="submit" name="action" value="reject">Reject Seller Slice</button>
            </div>
        </form>
    </div>
    <?php endif; ?>
</div>
</body>
</html>
