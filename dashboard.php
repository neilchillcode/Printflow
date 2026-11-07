
<?php
session_start();

require_once "../config/database.php";

// Check if user is logged in
if (!isset($_SESSION["user_id"]) || !isset($_SESSION["user_role"])) {
    header("Location: ../login.php");
    exit;
}

// Only Staff accounts can access this dashboard
if (strtolower($_SESSION["user_role"]) !== "staff") {
    header("Location: ../login.php");
    exit;
}

// Handle logout
if (isset($_GET["logout"])) {

    $_SESSION = [];

    session_destroy();

    header("Location: ../login.php");
    exit;
}

$staff_name = $_SESSION["user_name"] ?? "Staff";

// Total Orders
$result = $conn->query("
    SELECT COUNT(*) AS total_orders
    FROM orders
");

$total_orders = $result->fetch_assoc()["total_orders"];

// Pending Orders
$result = $conn->query("
    SELECT COUNT(*) AS pending_orders
    FROM orders
    WHERE order_status = 'Pending'
");

$pending_orders = $result->fetch_assoc()["pending_orders"];

// Orders In Production
$result = $conn->query("
    SELECT COUNT(*) AS production_orders
    FROM orders
    WHERE order_status = 'In Production'
");

$production_orders = $result->fetch_assoc()["production_orders"];

// Completed Orders
$result = $conn->query("
    SELECT COUNT(*) AS completed_orders
    FROM orders
    WHERE order_status IN ('Completed', 'Released')
");

$completed_orders = $result->fetch_assoc()["completed_orders"];

// Recent Orders
$recent_orders = $conn->query("
    SELECT
        o.order_id,
        c.full_name,
        o.order_date,
        o.due_date,
        o.total_amount,
        o.order_status
    FROM orders o
    INNER JOIN customers c
        ON o.customer_id = c.customer_id
    ORDER BY o.order_id DESC
    LIMIT 5
");

?>

<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Staff Dashboard | PrintFlow Pro</title>

    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://unpkg.com/lucide@latest"></script>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>

    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">

    <style>
        body {
            font-family: 'Inter', sans-serif;
        }
    </style>

</head>

<body class="bg-slate-100 text-slate-800 min-h-screen">

    <!-- Header -->
    <header class="bg-white border-b border-slate-200 shadow-sm">

        <div class="max-w-7xl mx-auto px-4 sm:px-6 py-4">

            <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">

                <!-- PrintFlow Logo -->
                <div class="flex items-center gap-3">

                    <div class="w-11 h-11 bg-indigo-600 rounded-xl flex items-center justify-center text-white">
                        <i data-lucide="printer" class="w-6 h-6"></i>
                    </div>

                    <div>
                        <h1 class="font-bold text-lg text-slate-900">
                            PrintFlow Pro
                        </h1>

                        <p class="text-sm text-slate-500">
                            Order Management & CRM
                        </p>
                    </div>

                </div>

                <!-- Staff Account -->
                <div class="flex items-center justify-between sm:justify-end gap-4">

                    <div class="text-left sm:text-right">
                        <p class="font-semibold text-sm">
                            <?= htmlspecialchars($staff_name) ?>
                        </p>

                        <p class="text-sm text-blue-600">
                            Staff Member
                        </p>
                    </div>

                    <a
                        href="dashboard.php?logout=1"
                        class="flex items-center gap-2 bg-slate-100 hover:bg-red-50 hover:text-red-600 px-4 py-3 rounded-xl text-sm font-semibold transition"
                    >
                        <i data-lucide="log-out" class="w-5 h-5"></i>
                        Logout
                    </a>

                </div>

            </div>

            <!-- Staff Navigation -->
            <nav class="flex flex-wrap gap-2 mt-5" aria-label="Staff navigation">

                <a
                    href="dashboard.php"
                    aria-current="page"
                    class="flex items-center gap-2 bg-indigo-600 text-white px-4 py-3 rounded-xl text-sm font-semibold"
                >
                    <i data-lucide="layout-dashboard" class="w-5 h-5"></i>
                    Dashboard
                </a>

                 <a
                    href="quotations.php"
                    class="flex items-center gap-2 bg-slate-100 hover:bg-indigo-50 hover:text-indigo-700 px-4 py-3 rounded-xl text-sm font-semibold transition"
                >
                    <i data-lucide="file-text" class="w-5 h-5"></i>
                    Quotations
                </a>

                <a
                    href="orders.php"
                    class="flex items-center gap-2 bg-slate-100 hover:bg-indigo-50 hover:text-indigo-700 px-4 py-3 rounded-xl text-sm font-semibold transition"
                >
                    <i data-lucide="clipboard-list" class="w-5 h-5"></i>
                    Orders
                </a>

                <a
                    href="design_proofs.php"
                    class="flex items-center gap-2 bg-slate-100 hover:bg-indigo-50 hover:text-indigo-700 px-4 py-3 rounded-xl text-sm font-semibold transition"
                >
                    <i data-lucide="palette" class="w-5 h-5"></i>
                    Design Proofs
                </a>

                <a
                    href="production.php"
                    class="flex items-center gap-2 bg-slate-100 hover:bg-indigo-50 hover:text-indigo-700 px-4 py-3 rounded-xl text-sm font-semibold transition"
                >
                    <i data-lucide="printer" class="w-5 h-5"></i>
                    Production
                </a>

                <a
                    href="payments.php"
                    class="flex items-center gap-2 bg-slate-100 hover:bg-indigo-50 hover:text-indigo-700 px-4 py-3 rounded-xl text-sm font-semibold transition"
                >
                    <i data-lucide="credit-card" class="w-5 h-5"></i>
                    Payments
                </a>

                <a
                    href="follow_ups.php"
                    class="flex items-center gap-2 bg-slate-100 hover:bg-indigo-50 hover:text-indigo-700 px-4 py-3 rounded-xl text-sm font-semibold transition"
                >
                    <i data-lucide="bell" class="w-5 h-5"></i>
                    Follow-ups
                </a>

                <a
                    href="feedback.php"
                    class="flex items-center gap-2 bg-slate-100 hover:bg-indigo-50 hover:text-indigo-700 px-4 py-3 rounded-xl text-sm font-semibold transition"
                >
                    <i data-lucide="message-square" class="w-5 h-5"></i>
                    Feedback
                </a>

            </nav>

        </div>

    </header>

    <!-- Main Content -->
    <main class="max-w-7xl mx-auto px-4 sm:px-6 py-8">

        <!-- Welcome -->
        <section class="mb-8">

            <h2 class="text-2xl font-bold text-slate-900">
                Staff Dashboard
            </h2>

            <p class="text-base text-slate-600 mt-2">
                Welcome back, <?= htmlspecialchars($staff_name) ?>.
                Here's an overview of the shop's orders and production.
            </p>

        </section>

        <!-- Dashboard Statistics -->
        <section class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-4 gap-5 mb-8">

            <!-- Total Orders -->
            <div class="bg-white border border-slate-200 rounded-2xl p-5 shadow-sm">

                <div class="flex items-center justify-between">

                    <div>
                        <p class="text-sm text-slate-600">
                            Total Orders
                        </p>

                        <p class="text-3xl font-bold text-slate-900 mt-2">
                            <?= number_format($total_orders) ?>
                        </p>
                    </div>

                    <div class="w-12 h-12 bg-indigo-50 text-indigo-600 rounded-xl flex items-center justify-center">
                        <i data-lucide="clipboard-list" class="w-6 h-6"></i>
                    </div>

                </div>

            </div>

            <!-- Pending Orders -->
            <div class="bg-white border border-slate-200 rounded-2xl p-5 shadow-sm">

                <div class="flex items-center justify-between">

                    <div>
                        <p class="text-sm text-slate-600">
                            Pending Orders
                        </p>

                        <p class="text-3xl font-bold text-amber-600 mt-2">
                            <?= number_format($pending_orders) ?>
                        </p>
                    </div>

                    <div class="w-12 h-12 bg-amber-50 text-amber-600 rounded-xl flex items-center justify-center">
                        <i data-lucide="clock" class="w-6 h-6"></i>
                    </div>

                </div>

            </div>

            <!-- In Production -->
            <div class="bg-white border border-slate-200 rounded-2xl p-5 shadow-sm">

                <div class="flex items-center justify-between">

                    <div>
                        <p class="text-sm text-slate-600">
                            In Production
                        </p>

                        <p class="text-3xl font-bold text-blue-600 mt-2">
                            <?= number_format($production_orders) ?>
                        </p>
                    </div>

                    <div class="w-12 h-12 bg-blue-50 text-blue-600 rounded-xl flex items-center justify-center">
                        <i data-lucide="printer" class="w-6 h-6"></i>
                    </div>

                </div>

            </div>

            <!-- Completed Orders -->
            <div class="bg-white border border-slate-200 rounded-2xl p-5 shadow-sm">

                <div class="flex items-center justify-between">

                    <div>
                        <p class="text-sm text-slate-600">
                            Completed Orders
                        </p>

                        <p class="text-3xl font-bold text-emerald-600 mt-2">
                            <?= number_format($completed_orders) ?>
                        </p>
                    </div>

                    <div class="w-12 h-12 bg-emerald-50 text-emerald-600 rounded-xl flex items-center justify-center">
                        <i data-lucide="check-circle" class="w-6 h-6"></i>
                    </div>

                </div>

            </div>

        </section>

        <!-- Quick Actions -->
        <section class="bg-white border border-slate-200 rounded-2xl p-5 shadow-sm mb-8">

            <h3 class="text-lg font-bold text-slate-900 mb-4">
                Quick Actions
            </h3>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">

                <a
                    href="orders.php"
                    class="flex items-center gap-4 border border-slate-200 hover:border-indigo-300 hover:bg-indigo-50 rounded-xl p-4 transition"
                >

                    <div class="w-11 h-11 bg-indigo-100 text-indigo-700 rounded-xl flex items-center justify-center">
                        <i data-lucide="clipboard-list" class="w-6 h-6"></i>
                    </div>

                    <div>
                        <p class="font-semibold text-slate-900">
                            View Orders
                        </p>

                        <p class="text-sm text-slate-600">
                            Review customer order records.
                        </p>
                    </div>

                    <i data-lucide="chevron-right" class="w-5 h-5 ml-auto text-slate-400"></i>

                </a>

                <a
                    href="production.php"
                    class="flex items-center gap-4 border border-slate-200 hover:border-indigo-300 hover:bg-indigo-50 rounded-xl p-4 transition"
                >

                    <div class="w-11 h-11 bg-blue-100 text-blue-700 rounded-xl flex items-center justify-center">
                        <i data-lucide="printer" class="w-6 h-6"></i>
                    </div>

                    <div>
                        <p class="font-semibold text-slate-900">
                            Production Monitoring
                        </p>

                        <p class="text-sm text-slate-600">
                            Monitor the progress of order items.
                        </p>
                    </div>

                    <i data-lucide="chevron-right" class="w-5 h-5 ml-auto text-slate-400"></i>

                </a>

            </div>

        </section>

        <!-- Recent Orders -->
        <section class="bg-white border border-slate-200 rounded-2xl shadow-sm overflow-hidden">

            <div class="p-5 border-b border-slate-200">

                <h3 class="text-lg font-bold text-slate-900">
                    Recent Orders
                </h3>

                <p class="text-sm text-slate-600 mt-1">
                    The five most recently recorded customer orders.
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
                                <th scope="col" class="px-5 py-4">Due Date</th>
                                <th scope="col" class="px-5 py-4">Amount</th>
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
                                        <?= htmlspecialchars($order["order_date"]) ?>
                                    </td>

                                    <td class="px-5 py-4 text-slate-600 whitespace-nowrap">
                                        <?= htmlspecialchars($order["due_date"] ?? "Not set") ?>
                                    </td>

                                    <td class="px-5 py-4 font-semibold whitespace-nowrap">
                                        ₱<?= number_format((float)$order["total_amount"], 2) ?>
                                    </td>

                                    <td class="px-5 py-4">

                                        <?php
                                        $status_class = "bg-slate-100 text-slate-700";

                                        if ($order["order_status"] === "Pending") {
                                            $status_class = "bg-amber-100 text-amber-800";
                                        } elseif ($order["order_status"] === "Design Approval") {
                                            $status_class = "bg-violet-100 text-violet-800";
                                        } elseif ($order["order_status"] === "In Production") {
                                            $status_class = "bg-blue-100 text-blue-800";
                                        } elseif (
                                            $order["order_status"] === "Completed" ||
                                            $order["order_status"] === "Released"
                                        ) {
                                            $status_class = "bg-emerald-100 text-emerald-800";
                                        }
                                        ?>

                                        <span class="inline-block px-3 py-2 rounded-lg text-sm font-semibold whitespace-nowrap <?= $status_class ?>">
                                            <?= htmlspecialchars($order["order_status"]) ?>
                                        </span>

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
                        No orders available yet
                    </h4>

                    <p class="text-sm text-slate-600 mt-2">
                        Customer orders will appear here once they are recorded.
                    </p>

                </div>

            <?php endif; ?>

        </section>

    </main>

    <!-- Footer -->
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
