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

if (!function_exists('bvsrv_seller_refund_dashboard_url')) {
    function bvsrv_seller_refund_dashboard_url(): string
    {
        if (function_exists('bv_order_refund_seller_dashboard_url')) {
            $url = (string)bv_order_refund_seller_dashboard_url();
            if (trim($url) !== '') {
                return $url;
            }
        }
        return '/seller/refunds.php';
    }
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
$dashboardUrl = bvsrv_seller_refund_dashboard_url();

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
        :root{--bg:#f4f6fb;--text:#111827;--muted:#6b7280;--border:#e5e7eb;--card:#ffffff;--primary:#1d4ed8;--primary-soft:#dbeafe;--approve:#16a34a;--reject:#dc2626}
        *{box-sizing:border-box}
        body{font-family:Inter,Arial,sans-serif;background:var(--bg);margin:0;padding:24px;color:var(--text)}
        .container{max-width:1080px;margin:0 auto;display:flex;flex-direction:column;gap:16px}
        .card{background:var(--card);border:1px solid var(--border);border-radius:14px;padding:18px;box-shadow:0 2px 10px rgba(15,23,42,.03)}
        .header-bar{display:flex;gap:12px;justify-content:space-between;align-items:flex-start;flex-wrap:wrap}
        .page-title{margin:0;font-size:1.55rem;line-height:1.2}
        .meta{margin-top:6px;color:var(--muted);font-size:.93rem}
        .pill{display:inline-flex;align-items:center;padding:5px 10px;border-radius:999px;background:#f3f4f6;border:1px solid #e5e7eb;font-size:.78rem;color:#374151;text-transform:uppercase;letter-spacing:.03em;font-weight:700}
        .btn{display:inline-flex;align-items:center;justify-content:center;padding:10px 14px;border-radius:9px;border:1px solid transparent;cursor:pointer;font-weight:700;text-decoration:none;font-size:.92rem;white-space:nowrap}
        .btn-primary{background:var(--primary);color:#fff}
        .btn-secondary{background:#fff;color:#1f2937;border-color:#d1d5db}
        .btn-approve{background:var(--approve);color:#fff}
        .btn-reject{background:var(--reject);color:#fff}
        .summary-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px;margin-top:14px}
        .summary-item{border:1px solid #eef2f7;background:#fbfcff;border-radius:11px;padding:12px}
        .summary-label{font-size:.79rem;color:var(--muted);margin-bottom:5px;font-weight:600;text-transform:uppercase;letter-spacing:.03em}
        .summary-value{font-size:1.04rem;font-weight:700;color:#111827}
        h3{margin:0 0 12px 0;font-size:1.15rem}
        .table-wrap{overflow:auto;border:1px solid #edf1f5;border-radius:12px}
        table{width:100%;border-collapse:separate;border-spacing:0;min-width:760px;background:#fff}
        th,td{padding:12px 14px;text-align:left;border-bottom:1px solid #eef2f7;font-size:.94rem}
        th{background:#f8fafc;font-size:.82rem;text-transform:uppercase;letter-spacing:.03em;color:#4b5563}
        tbody tr:last-child td{border-bottom:0}
        .money{font-variant-numeric:tabular-nums}
        .form-grid{display:grid;gap:10px}
        label{font-size:.86rem;font-weight:600;color:#374151}
        textarea,input[type=number]{padding:10px 11px;border:1px solid #d1d5db;border-radius:9px;width:100%;font:inherit;color:inherit;background:#fff}
        textarea:focus,input[type=number]:focus{border-color:#93c5fd;outline:0;box-shadow:0 0 0 3px var(--primary-soft)}
        .actions{display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin-top:4px}
        .bottom-nav{display:flex;justify-content:flex-end}
        @media (max-width:720px){
            body{padding:14px}
            .card{padding:14px}
            .summary-grid{grid-template-columns:1fr}
            .page-title{font-size:1.3rem}
        }
    </style>
</head>
<body>
<div class="container">
    <div class="card">
        <div class="header-bar">
            <div>
                <h1 class="page-title">Refund #<?php echo bvsrv_h($refundId); ?> (Seller Slice)</h1>
                <div class="meta">Review refund details and your seller decision for this request.</div>
            </div>
            <a class="btn btn-primary" href="<?php echo bvsrv_h($dashboardUrl); ?>">Back to Refund Dashboard</a>
        </div>

        <div class="summary-grid">
            <div class="summary-item">
                <div class="summary-label">Header status</div>
                <div class="summary-value"><span class="pill"><?php echo bvsrv_h((string)($refund['status'] ?? '')); ?></span></div>
            </div>
            <div class="summary-item">
                <div class="summary-label">Seller decision status</div>
                <div class="summary-value"><span class="pill"><?php echo bvsrv_h($status); ?></span></div>
            </div>
            <div class="summary-item">
                <div class="summary-label">Requested Amount</div>
                <div class="summary-value money"><?php echo bvsrv_h(number_format($headerRequestedAmount, 2) . ' ' . $currency); ?></div>
            </div>
            <div class="summary-item">
                <div class="summary-label">Approved Amount</div>
                <div class="summary-value money"><?php echo bvsrv_h(number_format($headerApprovedAmount, 2) . ' ' . $currency); ?></div>
            </div>
            <div class="summary-item">
                <div class="summary-label">Fee Deducted / Non-refundable Fee</div>
                <div class="summary-value money"><?php echo bvsrv_h(number_format($headerFeeLossAmount, 2) . ' ' . $currency); ?></div>
            </div>
            <div class="summary-item">
                <div class="summary-label">Actual Refund Amount</div>
                <div class="summary-value money"><?php echo bvsrv_h(number_format($headerActualRefundAmount, 2) . ' ' . $currency); ?></div>
            </div>
        </div>
    </div>

    <div class="card">
        <h3>Seller-owned refund items</h3>
        <div class="table-wrap">
            <table>
                <thead>
                <tr>
                    <th>Item ID</th>
                    <th>Listing</th>
                    <th>Requested</th>
                    <th>Approved</th>
                    <th>Fee Loss</th>
                    <th>Actual / Net Refund</th>
                </tr>
                </thead>
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
                        <td class="money"><?php echo bvsrv_h(number_format($itemRequestedAmount, 2)); ?></td>
                        <td class="money"><?php echo bvsrv_h(number_format($itemApprovedAmount, 2)); ?></td>
                        <td class="money"><?php echo bvsrv_h(number_format($itemFeeLossAmount, 2)); ?></td>
                        <td class="money"><?php echo bvsrv_h(number_format($itemActualRefundAmount, 2)); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <?php if ($status === 'pending_approval'): ?>
    <div class="card">
        <h3>Seller Decision</h3>
        <form method="post" action="/seller/refund_action.php" class="form-grid">
            <input type="hidden" name="csrf_token" value="<?php echo bvsrv_h($csrf); ?>">
            <input type="hidden" name="refund_id" value="<?php echo bvsrv_h($refundId); ?>">
            <input type="hidden" name="return_url" value="<?php echo bvsrv_h('/seller/refund_view.php?id=' . $refundId); ?>">

            <label for="approved_refund_amount">Approved Amount (your seller slice)</label>
            <input id="approved_refund_amount" type="number" step="0.01" min="0" name="approved_refund_amount" value="<?php echo bvsrv_h(number_format($requested, 2, '.', '')); ?>">

            <label for="note">Note</label>
            <textarea id="note" name="note" rows="3" placeholder="Optional decision note"></textarea>

            <div class="actions">
                <button class="btn btn-approve" type="submit" name="action" value="approve">Approve Seller Slice</button>
                <button class="btn btn-reject" type="submit" name="action" value="reject">Reject Seller Slice</button>
            </div>
        </form>
    </div>
    <?php endif; ?>

    <div class="bottom-nav">
        <a class="btn btn-secondary" href="<?php echo bvsrv_h($dashboardUrl); ?>">Back to Refund Dashboard</a>
    </div>
</div>
</body>
</html>
