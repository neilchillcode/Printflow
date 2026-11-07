
<?php
session_start();

require_once "../config/database.php";

// Check login
if (!isset($_SESSION["user_id"]) || !isset($_SESSION["user_role"])) {
    header("Location: ../login.php");
    exit;
}

// Staff access only
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

// Search
$search = trim($_GET["search"] ?? "");
$search_value = "%" . $search . "%";

// Retrieve orders
$stmt = $conn->prepare("
    SELECT
        o.order_id,
        c.full_name,
        o.order_date,
        o.due_date,
        o.order_status,
        o.total_amount,
        COUNT(oi.item_id) AS item_count
    FROM orders o
    INNER JOIN customers c
        ON o.customer_id = c.customer_id
    LEFT JOIN order_items oi
        ON o.order_id = oi.order_id
    WHERE
        CAST(o.order_id AS CHAR) LIKE ?
        OR c.full_name LIKE ?
        OR o.order_status LIKE ?
    GROUP BY
        o.order_id,
        c.full_name,
        o.order_date,
        o.due_date,
        o.order_status,
        o.total_amount
    ORDER BY o.order_id DESC
");

$stmt->bind_param(
    "sss",
    $search_value,
    $search_value,
    $search_value
);

$stmt->execute();

$orders = $stmt->get_result();

// Selected order details
$selected_order = null;
$order_items = null;
$view_error = false;

if (isset($_GET["view"])) {

    $view_id = (int)$_GET["view"];

    if ($view_id > 0) {

        $detail_stmt = $conn->prepare("
            SELECT
                o.order_id,
                c.full_name,
                c.contact_no,
                c.email,
                c.address,
                o.order_date,
                o.due_date,
                o.order_status,
                o.total_amount
            FROM orders o
            INNER JOIN customers c
                ON o.customer_id = c.customer_id
            WHERE o.order_id = ?
        ");

        $detail_stmt->bind_param("i", $view_id);
        $detail_stmt->execute();

        $detail_result = $detail_stmt->get_result();

        if ($detail_result->num_rows > 0) {

            $selected_order = $detail_result->fetch_assoc();

            $items_stmt = $conn->prepare("
                SELECT
                    oi.item_id,
                    p.product_name,
                    oi.size,
                    oi.color,
                    oi.material,
                    oi.quantity,
                    oi.design_notes
                FROM order_items oi
                INNER JOIN products p
                    ON oi.product_id = p.product_id
                WHERE oi.order_id = ?
                ORDER BY oi.item_id ASC
            ");

            $items_stmt->bind_param("i", $view_id);
            $items_stmt->execute();

            $order_items = $items_stmt->get_result();

            $items_stmt->close();

        } else {
            $view_error = true;
        }

        $detail_stmt->close();

    } else {
        $view_error = true;
    }
}

?>

<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Staff Orders | PrintFlow</title>

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
                    class="flex items-center gap-2 bg-slate-100 hover:bg-indigo-50 hover:text-indigo-700 px-4 py-3 rounded-xl text-sm font-semibold transition"
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
                    aria-current="page"
                    class="flex items-center gap-2 bg-indigo-600 text-white px-4 py-3 rounded-xl text-sm font-semibold"
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

        <!-- Heading -->
        <section class="mb-8">

            <h2 class="text-2xl font-bold text-slate-900">
                Customer Orders
            </h2>

            <p class="text-base text-slate-600 mt-2">
                Review customer orders and their requested products.
            </p>

        </section>


        <!-- Selected Order Details -->
        <?php if ($selected_order): ?>

            <section class="bg-white border border-slate-200 rounded-2xl shadow-sm mb-6 overflow-hidden">

                <div class="p-5 border-b border-slate-200 flex flex-col sm:flex-row sm:items-center justify-between gap-3">

                    <div>
                        <h3 class="text-lg font-bold text-slate-900">
                            Order Details #<?= (int)$selected_order["order_id"] ?>
                        </h3>

                        <p class="text-sm text-slate-600 mt-1">
                            Complete information about this customer order.
                        </p>
                    </div>

                    <a
                        href="orders.php"
                        class="inline-flex items-center justify-center gap-2 bg-slate-100 hover:bg-slate-200 px-4 py-3 rounded-xl text-sm font-semibold transition"
                    >
                        <i data-lucide="arrow-left" class="w-5 h-5"></i>
                        Back to Orders
                    </a>

                </div>


                <!-- Customer Information -->
                <div class="p-5">

                    <h4 class="font-bold text-slate-900 mb-4">
                        Customer Information
                    </h4>

                    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-5">

                        <div>
                            <p class="text-sm text-slate-500">Customer Name</p>
                            <p class="font-semibold mt-1">
                                <?= htmlspecialchars($selected_order["full_name"]) ?>
                            </p>
                        </div>

                        <div>
                            <p class="text-sm text-slate-500">Contact Number</p>
                            <p class="font-semibold mt-1">
                                <?= htmlspecialchars($selected_order["contact_no"] ?? "Not provided") ?>
                            </p>
                        </div>

                        <div>
                            <p class="text-sm text-slate-500">Email Address</p>
                            <p class="font-semibold mt-1">
                                <?= htmlspecialchars($selected_order["email"] ?? "Not provided") ?>
                            </p>
                        </div>

                        <div class="sm:col-span-2 lg:col-span-3">
                            <p class="text-sm text-slate-500">Address</p>
                            <p class="font-semibold mt-1">
                                <?= htmlspecialchars($selected_order["address"] ?? "Not provided") ?>
                            </p>
                        </div>

                    </div>

                </div>


                <div class="border-t border-slate-200 p-5">

                    <h4 class="font-bold text-slate-900 mb-4">
                        Order Information
                    </h4>

                    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-5">

                        <div>
                            <p class="text-sm text-slate-500">Order Date</p>
                            <p class="font-semibold mt-1">
                                <?= htmlspecialchars($selected_order["order_date"]) ?>
                            </p>
                        </div>

                        <div>
                            <p class="text-sm text-slate-500">Due Date</p>
                            <p class="font-semibold mt-1">
                                <?= htmlspecialchars($selected_order["due_date"] ?? "Not set") ?>
                            </p>
                        </div>

                        <div>
                            <p class="text-sm text-slate-500">Order Status</p>
                            <p class="font-semibold mt-1">
                                <?= htmlspecialchars($selected_order["order_status"]) ?>
                            </p>
                        </div>

                        <div>
                            <p class="text-sm text-slate-500">Total Amount</p>
                            <p class="font-bold text-indigo-700 text-lg mt-1">
                                ₱<?= number_format((float)$selected_order["total_amount"], 2) ?>
                            </p>
                        </div>

                    </div>

                </div>


                <!-- Ordered Products -->
                <div class="border-t border-slate-200 p-5">

                    <h4 class="font-bold text-slate-900 mb-4">
                        Ordered Products
                    </h4>

                    <?php if ($order_items && $order_items->num_rows > 0): ?>

                        <div class="overflow-x-auto">

                            <table class="w-full text-left">

                                <thead class="bg-slate-50 border-b border-slate-200">

                                    <tr class="text-sm text-slate-600">
                                        <th scope="col" class="px-4 py-3">Product</th>
                                        <th scope="col" class="px-4 py-3">Size</th>
                                        <th scope="col" class="px-4 py-3">Color</th>
                                        <th scope="col" class="px-4 py-3">Material</th>
                                        <th scope="col" class="px-4 py-3">Quantity</th>
                                        <th scope="col" class="px-4 py-3">Design Notes</th>
                                    </tr>

                                </thead>

                                <tbody class="divide-y divide-slate-100">

                                    <?php while ($item = $order_items->fetch_assoc()): ?>

                                        <tr>

                                            <td class="px-4 py-4 font-semibold">
                                                <?= htmlspecialchars($item["product_name"]) ?>
                                            </td>

                                            <td class="px-4 py-4">
                                                <?= htmlspecialchars($item["size"] ?? "Not specified") ?>
                                            </td>

                                            <td class="px-4 py-4">
                                                <?= htmlspecialchars($item["color"] ?? "Not specified") ?>
                                            </td>

                                            <td class="px-4 py-4">
                                                <?= htmlspecialchars($item["material"] ?? "Not specified") ?>
                                            </td>

                                            <td class="px-4 py-4">
                                                <?= (int)$item["quantity"] ?>
                                            </td>

                                            <td class="px-4 py-4">
                                                <?= htmlspecialchars($item["design_notes"] ?? "None") ?>
                                            </td>

                                        </tr>

                                    <?php endwhile; ?>

                                </tbody>

                            </table>

                        </div>

                    <?php else: ?>

                        <p class="text-slate-600 text-sm">
                            No products have been recorded for this order.
                        </p>

                    <?php endif; ?>

                </div>

            </section>

        <?php elseif ($view_error): ?>

            <div class="bg-red-50 border border-red-200 text-red-800 p-4 rounded-xl mb-6" role="alert">
                The requested order could not be found.
                <a href="orders.php" class="font-semibold underline ml-1">
                    Return to orders
                </a>
            </div>

        <?php endif; ?>


        <!-- Search -->
        <section class="bg-white border border-slate-200 rounded-2xl p-5 shadow-sm mb-6">

            <form method="GET" action="orders.php" class="flex flex-col sm:flex-row gap-3">

                <div class="flex-1">

                    <label for="search" class="block text-sm font-semibold text-slate-700 mb-2">
                        Search Orders
                    </label>

                    <input
                        type="search"
                        id="search"
                        name="search"
                        value="<?= htmlspecialchars($search) ?>"
                        placeholder="Search by order ID, customer, or status"
                        class="w-full border border-slate-300 rounded-xl px-4 py-3 text-base focus:outline-none focus:ring-2 focus:ring-indigo-500"
                    >

                </div>

                <div class="flex items-end gap-2">

                    <button
                        type="submit"
                        class="flex items-center justify-center gap-2 bg-indigo-600 hover:bg-indigo-700 text-white px-5 py-3 rounded-xl font-semibold text-sm transition"
                    >
                        <i data-lucide="search" class="w-5 h-5"></i>
                        Search
                    </button>

                    <a
                        href="orders.php"
                        class="flex items-center justify-center bg-slate-100 hover:bg-slate-200 px-5 py-3 rounded-xl font-semibold text-sm transition"
                    >
                        Clear
                    </a>

                </div>

            </form>

        </section>


        <!-- Order Records -->
        <section class="bg-white border border-slate-200 rounded-2xl shadow-sm overflow-hidden">

            <div class="p-5 border-b border-slate-200">

                <h3 class="text-lg font-bold text-slate-900">
                    Order Records
                </h3>

                <p class="text-sm text-slate-600 mt-1">
                    Review recorded orders and open their details.
                </p>

            </div>


            <?php if ($orders && $orders->num_rows > 0): ?>

                <div class="overflow-x-auto">

                    <table class="w-full text-left">

                        <thead class="bg-slate-50 border-b border-slate-200">

                            <tr class="text-sm text-slate-600">

                                <th scope="col" class="px-5 py-4">Order ID</th>
                                <th scope="col" class="px-5 py-4">Customer</th>
                                <th scope="col" class="px-5 py-4">Items</th>
                                <th scope="col" class="px-5 py-4">Order Date</th>
                                <th scope="col" class="px-5 py-4">Due Date</th>
                                <th scope="col" class="px-5 py-4">Amount</th>
                                <th scope="col" class="px-5 py-4">Status</th>
                                <th scope="col" class="px-5 py-4">Action</th>

                            </tr>

                        </thead>


                        <tbody class="divide-y divide-slate-100">

                            <?php while ($order = $orders->fetch_assoc()): ?>

                                <tr class="hover:bg-slate-50">

                                    <td class="px-5 py-4 font-bold text-indigo-700 whitespace-nowrap">
                                        #<?= (int)$order["order_id"] ?>
                                    </td>

                                    <td class="px-5 py-4 font-medium">
                                        <?= htmlspecialchars($order["full_name"]) ?>
                                    </td>

                                    <td class="px-5 py-4">
                                        <?= (int)$order["item_count"] ?>
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

                                    <td class="px-5 py-4 whitespace-nowrap">
                                        <?= htmlspecialchars($order["order_status"]) ?>
                                    </td>

                                    <td class="px-5 py-4">

                                        <a
                                            href="orders.php?view=<?= (int)$order["order_id"] ?>"
                                            class="inline-flex items-center justify-center gap-2 bg-indigo-50 hover:bg-indigo-100 text-indigo-700 px-4 py-2 rounded-lg text-sm font-semibold transition"
                                        >
                                            <i data-lucide="eye" class="w-4 h-4"></i>
                                            View Details
                                        </a>

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
                        No orders found
                    </h4>

                    <p class="text-sm text-slate-600 mt-2">
                        Customer orders will appear here once they are recorded.
                    </p>

                    <?php if (!empty($search)): ?>

                        <a
                            href="orders.php"
                            class="inline-block mt-4 text-indigo-700 hover:underline font-semibold"
                        >
                            Show all orders
                        </a>

                    <?php endif; ?>

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
$stmt->close();
$conn->close();
?>
