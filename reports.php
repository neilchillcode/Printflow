<?php
session_start();
require_once "../config/database.php";

// Admin access only
if (
    !isset($_SESSION["user_id"]) ||
    !isset($_SESSION["user_role"]) ||
    strtolower($_SESSION["user_role"]) !== "admin"
) {
    header("Location: ../login.php");
    exit;
}

// Total orders
$total_orders = $conn->query(
    "SELECT COUNT(*) AS total FROM orders"
)->fetch_assoc()["total"];

// Completed and released orders
$completed_orders = $conn->query(
    "SELECT COUNT(*) AS total
     FROM orders
     WHERE order_status IN ('Completed', 'Released')"
)->fetch_assoc()["total"];

// Total accepted quotation value.
// Count one accepted quotation amount per order.
$quotation_result = $conn->query(
    "SELECT COALESCE(SUM(amount), 0) AS total
     FROM (
        SELECT order_id, MAX(amount) AS amount
        FROM quotations
        WHERE status = 'Accepted'
        GROUP BY order_id
     ) accepted_quotations"
)->fetch_assoc();

$total_accepted = (float)$quotation_result["total"];

// Total payments received
$payment_result = $conn->query(
    "SELECT COALESCE(SUM(amount_paid), 0) AS total
     FROM payments"
)->fetch_assoc();

$total_payments = (float)$payment_result["total"];

// Outstanding balance from accepted quotations
$balance_result = $conn->query(
    "SELECT COALESCE(SUM(
        GREATEST(
            q.amount - COALESCE(p.total_paid, 0),
            0
        )
    ), 0) AS total_balance
    FROM (
        SELECT order_id, MAX(amount) AS amount
        FROM quotations
        WHERE status = 'Accepted'
        GROUP BY order_id
    ) q
    LEFT JOIN (
        SELECT order_id, SUM(amount_paid) AS total_paid
        FROM payments
        GROUP BY order_id
    ) p ON q.order_id = p.order_id"
)->fetch_assoc();

$total_balance = (float)$balance_result["total_balance"];

// Order status breakdown
$status_result = $conn->query(
    "SELECT order_status, COUNT(*) AS total
     FROM orders
     GROUP BY order_status
     ORDER BY FIELD(
        order_status,
        'Pending',
        'Design Approval',
        'In Production',
        'Completed',
        'Released'
     )"
);

// Recent orders
$recent_orders = $conn->query(
    "SELECT
        o.order_id,
        c.full_name,
        o.order_date,
        o.total_amount,
        o.order_status
     FROM orders o
     INNER JOIN customers c ON o.customer_id = c.customer_id
     ORDER BY o.order_date DESC, o.order_id DESC
     LIMIT 10"
);

// Recent payments
$recent_payments = $conn->query(
    "SELECT
        p.payment_id,
        p.order_id,
        c.full_name,
        p.amount_paid,
        p.payment_method,
        p.payment_type,
        p.payment_date
     FROM payments p
     INNER JOIN orders o ON p.order_id = o.order_id
     INNER JOIN customers c ON o.customer_id = c.customer_id
     ORDER BY p.payment_date DESC, p.payment_id DESC
     LIMIT 10"
);

// Customer Activity Report
// Aggregate each activity table separately to prevent duplicate counts.
$customer_activity = $conn->query(
    "SELECT
        c.customer_id,
        c.full_name,
        c.contact_no,
        c.date_registered,
        COALESCE(order_activity.total_orders, 0) AS total_orders,
        COALESCE(order_activity.total_order_value, 0) AS total_order_value,
        order_activity.latest_order_date,
        COALESCE(feedback_activity.feedback_count, 0) AS feedback_count,
        COALESCE(followup_activity.followup_count, 0) AS followup_count,
        followup_activity.latest_followup_date
     FROM customers c
     LEFT JOIN (
        SELECT
            customer_id,
            COUNT(*) AS total_orders,
            SUM(total_amount) AS total_order_value,
            MAX(order_date) AS latest_order_date
        FROM orders
        GROUP BY customer_id
     ) order_activity
        ON c.customer_id = order_activity.customer_id
     LEFT JOIN (
        SELECT
            customer_id,
            COUNT(*) AS feedback_count
        FROM feedback
        GROUP BY customer_id
     ) feedback_activity
        ON c.customer_id = feedback_activity.customer_id
     LEFT JOIN (
        SELECT
            customer_id,
            COUNT(*) AS followup_count,
            MAX(follow_up_date) AS latest_followup_date
        FROM follow_ups
        GROUP BY customer_id
     ) followup_activity
        ON c.customer_id = followup_activity.customer_id
     ORDER BY
        total_orders DESC,
        latest_order_date DESC,
        c.full_name ASC"
);

function peso($amount) {
    return "₱" . number_format((float)$amount, 2);
}

function displayDate($date, $format = "M d, Y") {
    if (empty($date)) {
        return "—";
    }

    $timestamp = strtotime($date);

    if ($timestamp === false) {
        return "—";
    }

    return date($format, $timestamp);
}

$admin_name = $_SESSION["user_name"] ?? "Administrator";
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reports | PrintFlow Pro</title>

    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://unpkg.com/lucide@latest"></script>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">

    <style>
        body {
            font-family: 'Inter', sans-serif;
        }

        a:focus {
            outline: 3px solid #818cf8;
            outline-offset: 3px;
        }
    </style>
</head>

<body class="bg-slate-100 text-slate-800 min-h-screen">

    <!-- Header -->
    <header class="bg-white border-b border-slate-200 shadow-sm">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 py-4">

            <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">

                <div class="flex items-center gap-3">
                    <div class="w-11 h-11 bg-indigo-600 rounded-xl flex items-center justify-center text-white">
                        <i data-lucide="printer" class="w-6 h-6"></i>
                    </div>

                    <div>
                        <h1 class="font-bold text-lg text-slate-900">PrintFlow Pro</h1>
                        <p class="text-sm text-slate-500">Order Management & CRM</p>
                    </div>
                </div>

                <div class="flex items-center justify-between sm:justify-end gap-4">
                    <div class="text-left sm:text-right">
                        <p class="font-semibold text-sm">
                            <?= htmlspecialchars($admin_name) ?>
                        </p>
                        <p class="text-sm text-emerald-600">Administrator</p>
                    </div>

                    <a
                        href="dashboard.php?logout=1"
                        class="flex items-center gap-2 bg-slate-100 hover:bg-red-50 hover:text-red-600 px-4 py-3 rounded-xl text-sm font-semibold transition"
                        aria-label="Log out of PrintFlow"
                    >
                        <i data-lucide="log-out" class="w-5 h-5"></i>
                        Logout
                    </a>
                </div>
            </div>

            <!-- Admin Navigation -->
            <nav class="flex flex-wrap gap-2 mt-5" aria-label="Admin main navigation">

                <a href="dashboard.php"
                   class="flex items-center gap-2 bg-slate-100 hover:bg-indigo-50 hover:text-indigo-700 px-4 py-3 rounded-xl text-sm font-semibold transition">
                    <i data-lucide="layout-dashboard" class="w-5 h-5"></i>
                    Dashboard
                </a>

                <a href="orders.php"
                   class="flex items-center gap-2 bg-slate-100 hover:bg-indigo-50 hover:text-indigo-700 px-4 py-3 rounded-xl text-sm font-semibold transition">
                    <i data-lucide="clipboard-list" class="w-5 h-5"></i>
                    Orders
                </a>

                <a href="customers.php"
                   class="flex items-center gap-2 bg-slate-100 hover:bg-indigo-50 hover:text-indigo-700 px-4 py-3 rounded-xl text-sm font-semibold transition">
                    <i data-lucide="contact" class="w-5 h-5"></i>
                    Customers
                </a>

                <a href="users.php"
                   class="flex items-center gap-2 bg-slate-100 hover:bg-indigo-50 hover:text-indigo-700 px-4 py-3 rounded-xl text-sm font-semibold transition">
                    <i data-lucide="users" class="w-5 h-5"></i>
                    Users
                </a>

                <a href="reports.php" aria-current="page"
                   class="flex items-center gap-2 bg-indigo-600 text-white px-4 py-3 rounded-xl text-sm font-semibold">
                    <i data-lucide="chart-no-axes-combined" class="w-5 h-5"></i>
                    Reports
                </a>

            </nav>
        </div>
    </header>


    <!-- Main Content -->
    <main class="max-w-7xl mx-auto px-4 sm:px-6 py-8">

        <!-- Page Heading -->
        <section class="mb-8">
            <h2 class="text-2xl font-bold text-slate-900">
                Sales and Operations Reports
            </h2>

            <p class="text-base text-slate-600 mt-2">
                Monitor order activity, accepted quotations, payments, and outstanding balances.
            </p>
        </section>


        <!-- Financial Summary -->
        <section class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-5 mb-8">

            <div class="bg-white border border-slate-200 rounded-2xl p-5 shadow-sm">
                <p class="text-sm text-slate-600 font-medium">Total Accepted Quotations</p>
                <p class="text-2xl font-bold text-indigo-700 mt-2">
                    <?= peso($total_accepted) ?>
                </p>
            </div>

            <div class="bg-white border border-slate-200 rounded-2xl p-5 shadow-sm">
                <p class="text-sm text-slate-600 font-medium">Payments Received</p>
                <p class="text-2xl font-bold text-emerald-700 mt-2">
                    <?= peso($total_payments) ?>
                </p>
            </div>

            <div class="bg-white border border-slate-200 rounded-2xl p-5 shadow-sm">
                <p class="text-sm text-slate-600 font-medium">Outstanding Balance</p>
                <p class="text-2xl font-bold text-amber-600 mt-2">
                    <?= peso($total_balance) ?>
                </p>
            </div>

            <div class="bg-white border border-slate-200 rounded-2xl p-5 shadow-sm">
                <p class="text-sm text-slate-600 font-medium">Total Orders</p>
                <p class="text-2xl font-bold text-slate-900 mt-2">
                    <?= number_format((int)$total_orders) ?>
                </p>
            </div>

        </section>


        <!-- Order Summary -->
        <section class="grid grid-cols-1 sm:grid-cols-2 gap-5 mb-8">

            <div class="bg-white border border-slate-200 rounded-2xl p-5 shadow-sm">
                <div class="flex items-center gap-4">

                    <div class="w-12 h-12 bg-emerald-50 text-emerald-600 rounded-xl flex items-center justify-center">
                        <i data-lucide="check-circle" class="w-6 h-6"></i>
                    </div>

                    <div>
                        <p class="text-sm text-slate-600 font-medium">
                            Completed and Released Orders
                        </p>
                        <p class="text-3xl font-bold text-slate-900 mt-1">
                            <?= number_format((int)$completed_orders) ?>
                        </p>
                    </div>

                </div>
            </div>

            <div class="bg-white border border-slate-200 rounded-2xl p-5 shadow-sm">
                <div class="flex items-center gap-4">

                    <div class="w-12 h-12 bg-indigo-50 text-indigo-600 rounded-xl flex items-center justify-center">
                        <i data-lucide="clock" class="w-6 h-6"></i>
                    </div>

                    <div>
                        <p class="text-sm text-slate-600 font-medium">
                            Other Active Orders
                        </p>
                        <p class="text-3xl font-bold text-slate-900 mt-1">
                            <?= number_format(max(0, (int)$total_orders - (int)$completed_orders)) ?>
                        </p>
                    </div>

                </div>
            </div>

        </section>


        <!-- Order Status Breakdown -->
        <section class="bg-white border border-slate-200 rounded-2xl shadow-sm overflow-hidden mb-8">

            <div class="p-5 border-b border-slate-200">
                <h3 class="text-lg font-bold text-slate-900">
                    Order Status Breakdown
                </h3>
                <p class="text-sm text-slate-600 mt-1">
                    View the number of orders under each status.
                </p>
            </div>

            <?php if ($status_result && $status_result->num_rows > 0): ?>

                <div class="overflow-x-auto">
                    <table class="w-full text-left">

                        <thead class="bg-slate-50 border-b border-slate-200">
                            <tr class="text-sm text-slate-600">
                                <th scope="col" class="px-5 py-4">Order Status</th>
                                <th scope="col" class="px-5 py-4">Number of Orders</th>
                            </tr>
                        </thead>

                        <tbody class="divide-y divide-slate-100">
                            <?php while ($status = $status_result->fetch_assoc()): ?>
                                <tr class="hover:bg-slate-50">
                                    <td class="px-5 py-4 font-semibold text-slate-800">
                                        <?= htmlspecialchars($status["order_status"]) ?>
                                    </td>
                                    <td class="px-5 py-4 text-slate-700">
                                        <?= number_format((int)$status["total"]) ?>
                                    </td>
                                </tr>
                            <?php endwhile; ?>
                        </tbody>

                    </table>
                </div>

            <?php else: ?>

                <p class="text-slate-500 px-5 py-6">
                    No order records available.
                </p>

            <?php endif; ?>

        </section>


        <!-- Customer Activity Report -->
        <section class="bg-white border border-slate-200 rounded-2xl shadow-sm overflow-hidden mb-8">

            <div class="p-5 border-b border-slate-200">
                <h3 class="text-lg font-bold text-slate-900">
                    Customer Activity Report
                </h3>

                <p class="text-sm text-slate-600 mt-1">
                    Review customer orders, order values, feedback, and follow-up activity.
                    All registered customers are included, even if they have no orders yet.
                </p>
            </div>

            <?php if ($customer_activity && $customer_activity->num_rows > 0): ?>

                <div class="overflow-x-auto">
                    <table class="w-full text-left">

                        <thead class="bg-slate-50 border-b border-slate-200">
                            <tr class="text-sm text-slate-600">
                                <th scope="col" class="px-5 py-4">Customer</th>
                                <th scope="col" class="px-5 py-4">Contact Number</th>
                                <th scope="col" class="px-5 py-4">Total Orders</th>
                                <th scope="col" class="px-5 py-4">Total Order Value</th>
                                <th scope="col" class="px-5 py-4">Latest Order</th>
                                <th scope="col" class="px-5 py-4">Feedback</th>
                                <th scope="col" class="px-5 py-4">Follow-ups</th>
                                <th scope="col" class="px-5 py-4">Latest Follow-up</th>
                            </tr>
                        </thead>

                        <tbody class="divide-y divide-slate-100">
                            <?php while ($customer = $customer_activity->fetch_assoc()): ?>

                                <tr class="hover:bg-slate-50">

                                    <td class="px-5 py-4">
                                        <p class="font-semibold text-slate-800">
                                            <?= htmlspecialchars($customer["full_name"]) ?>
                                        </p>
                                        <p class="text-xs text-slate-500 mt-1">
                                            Customer #<?= (int)$customer["customer_id"] ?>
                                        </p>
                                    </td>

                                    <td class="px-5 py-4 text-slate-700 whitespace-nowrap">
                                        <?= !empty($customer["contact_no"])
                                            ? htmlspecialchars($customer["contact_no"])
                                            : "—" ?>
                                    </td>

                                    <td class="px-5 py-4 text-slate-700">
                                        <?= number_format((int)$customer["total_orders"]) ?>
                                    </td>

                                    <td class="px-5 py-4 font-semibold text-slate-800 whitespace-nowrap">
                                        <?= peso($customer["total_order_value"]) ?>
                                    </td>

                                    <td class="px-5 py-4 text-slate-600 whitespace-nowrap">
                                        <?= displayDate($customer["latest_order_date"]) ?>
                                    </td>

                                    <td class="px-5 py-4 text-slate-700">
                                        <?= number_format((int)$customer["feedback_count"]) ?>
                                    </td>

                                    <td class="px-5 py-4 text-slate-700">
                                        <?= number_format((int)$customer["followup_count"]) ?>
                                    </td>

                                    <td class="px-5 py-4 text-slate-600 whitespace-nowrap">
                                        <?= displayDate($customer["latest_followup_date"]) ?>
                                    </td>

                                </tr>

                            <?php endwhile; ?>
                        </tbody>

                    </table>
                </div>

            <?php else: ?>

                <div class="text-center px-6 py-12">
                    <i data-lucide="contact" class="w-12 h-12 text-slate-400 mx-auto"></i>

                    <h4 class="font-semibold text-lg text-slate-800 mt-4">
                        No customers available
                    </h4>

                    <p class="text-sm text-slate-600 mt-2">
                        Registered customer activity will appear here.
                    </p>
                </div>

            <?php endif; ?>

        </section>


        <!-- Recent Orders -->
        <section class="bg-white border border-slate-200 rounded-2xl shadow-sm overflow-hidden mb-8">

            <div class="p-5 border-b border-slate-200">
                <h3 class="text-lg font-bold text-slate-900">
                    Recent Orders
                </h3>
                <p class="text-sm text-slate-600 mt-1">
                    Review the latest customer orders.
                </p>
            </div>

            <?php if ($recent_orders && $recent_orders->num_rows > 0): ?>

                <div class="overflow-x-auto">
                    <table class="w-full text-left">

                        <thead class="bg-slate-50 border-b border-slate-200">
                            <tr class="text-sm text-slate-600">
                                <th scope="col" class="px-5 py-4">Order ID</th>
                                <th scope="col" class="px-5 py-4">Customer</th>
                                <th scope="col" class="px-5 py-4">Order Date</th>
                                <th scope="col" class="px-5 py-4">Order Amount</th>
                                <th scope="col" class="px-5 py-4">Status</th>
                            </tr>
                        </thead>

                        <tbody class="divide-y divide-slate-100">
                            <?php while ($order = $recent_orders->fetch_assoc()): ?>

                                <tr class="hover:bg-slate-50">

                                    <td class="px-5 py-4 font-bold text-indigo-700 whitespace-nowrap">
                                        #<?= (int)$order["order_id"] ?>
                                    </td>

                                    <td class="px-5 py-4 font-medium text-slate-800">
                                        <?= htmlspecialchars($order["full_name"]) ?>
                                    </td>

                                    <td class="px-5 py-4 text-slate-600 whitespace-nowrap">
                                        <?= displayDate($order["order_date"]) ?>
                                    </td>

                                    <td class="px-5 py-4 font-semibold text-slate-800 whitespace-nowrap">
                                        <?= peso($order["total_amount"]) ?>
                                    </td>

                                    <td class="px-5 py-4">
                                        <?= htmlspecialchars($order["order_status"]) ?>
                                    </td>

                                </tr>

                            <?php endwhile; ?>
                        </tbody>

                    </table>
                </div>

            <?php else: ?>

                <div class="text-center px-6 py-12">
                    <i data-lucide="clipboard-list" class="w-12 h-12 text-slate-400 mx-auto"></i>

                    <h4 class="font-semibold text-lg text-slate-800 mt-4">
                        No orders available
                    </h4>

                    <p class="text-sm text-slate-600 mt-2">
                        Recent order records will appear here.
                    </p>
                </div>

            <?php endif; ?>

        </section>


        <!-- Recent Payments -->
        <section class="bg-white border border-slate-200 rounded-2xl shadow-sm overflow-hidden">

            <div class="p-5 border-b border-slate-200">
                <h3 class="text-lg font-bold text-slate-900">
                    Recent Payments
                </h3>
                <p class="text-sm text-slate-600 mt-1">
                    Review the latest recorded customer payments.
                </p>
            </div>

            <?php if ($recent_payments && $recent_payments->num_rows > 0): ?>

                <div class="overflow-x-auto">
                    <table class="w-full text-left">

                        <thead class="bg-slate-50 border-b border-slate-200">
                            <tr class="text-sm text-slate-600">
                                <th scope="col" class="px-5 py-4">Payment ID</th>
                                <th scope="col" class="px-5 py-4">Order ID</th>
                                <th scope="col" class="px-5 py-4">Customer</th>
                                <th scope="col" class="px-5 py-4">Amount Paid</th>
                                <th scope="col" class="px-5 py-4">Payment Type</th>
                                <th scope="col" class="px-5 py-4">Payment Method</th>
                                <th scope="col" class="px-5 py-4">Payment Date</th>
                            </tr>
                        </thead>

                        <tbody class="divide-y divide-slate-100">
                            <?php while ($payment = $recent_payments->fetch_assoc()): ?>

                                <tr class="hover:bg-slate-50">

                                    <td class="px-5 py-4 text-slate-700 whitespace-nowrap">
                                        #<?= (int)$payment["payment_id"] ?>
                                    </td>

                                    <td class="px-5 py-4 font-semibold text-indigo-700 whitespace-nowrap">
                                        #<?= (int)$payment["order_id"] ?>
                                    </td>

                                    <td class="px-5 py-4 font-medium text-slate-800">
                                        <?= htmlspecialchars($payment["full_name"]) ?>
                                    </td>

                                    <td class="px-5 py-4 font-bold text-emerald-700 whitespace-nowrap">
                                        <?= peso($payment["amount_paid"]) ?>
                                    </td>

                                    <td class="px-5 py-4 text-slate-700">
                                        <?= htmlspecialchars($payment["payment_type"]) ?>
                                    </td>

                                    <td class="px-5 py-4 text-slate-700">
                                        <?= htmlspecialchars($payment["payment_method"]) ?>
                                    </td>

                                    <td class="px-5 py-4 text-slate-600 whitespace-nowrap">
                                        <?= displayDate($payment["payment_date"], "M d, Y h:i A") ?>
                                    </td>

                                </tr>

                            <?php endwhile; ?>
                        </tbody>

                    </table>
                </div>

            <?php else: ?>

                <div class="text-center px-6 py-12">
                    <i data-lucide="wallet" class="w-12 h-12 text-slate-400 mx-auto"></i>

                    <h4 class="font-semibold text-lg text-slate-800 mt-4">
                        No payment records available
                    </h4>

                    <p class="text-sm text-slate-600 mt-2">
                        Recorded payments will appear here.
                    </p>
                </div>

            <?php endif; ?>

        </section>


        <p class="text-xs text-slate-500 mt-6">
            Financial summaries are based on accepted quotations and recorded payments.
            They are not limited to completed orders.
        </p>

    </main>


    <footer class="text-center text-sm text-slate-500 py-6">
        PrintFlow Pro &copy; <?= date("Y") ?>. All rights reserved.
    </footer>


    <script>
        lucide.createIcons();
    </script>

</body>
</html>

<?php
$conn->close();
?>
