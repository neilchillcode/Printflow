
<?php
session_start();

require_once "../config/database.php";

// Allow Admin and Staff accounts
if (
    !isset($_SESSION["user_id"]) ||
    !in_array($_SESSION["user_role"], ["Admin", "Staff"], true)
) {
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

// CSRF protection
if (empty($_SESSION["csrf_token"])) {
    $_SESSION["csrf_token"] = bin2hex(random_bytes(32));
}

$message = "";
$message_type = "";

// Process quotation submission or revision
if ($_SERVER["REQUEST_METHOD"] === "POST") {

    if (
        !isset($_POST["csrf_token"]) ||
        !hash_equals($_SESSION["csrf_token"], $_POST["csrf_token"])
    ) {
        $message = "Invalid request. Please refresh the page.";
        $message_type = "error";

    } else {

        $order_id = filter_input(INPUT_POST, "order_id", FILTER_VALIDATE_INT);
        $amount = filter_input(INPUT_POST, "amount", FILTER_VALIDATE_FLOAT);

        if (!$order_id || $amount === false || $amount === null || $amount <= 0) {
            $message = "Please enter a valid order ID and quotation amount.";
            $message_type = "error";

        } else {

            // Confirm that the order exists
            $order_check = $conn->prepare("
                SELECT order_id
                FROM orders
                WHERE order_id = ?
            ");

            $order_check->bind_param("i", $order_id);
            $order_check->execute();
            $order_result = $order_check->get_result();

            if ($order_result->num_rows === 0) {
                $message = "The selected order does not exist.";
                $message_type = "error";

            } else {

                // Check whether a quotation already exists
                $quote_check = $conn->prepare("
                    SELECT quotation_id, status
                    FROM quotations
                    WHERE order_id = ?
                    LIMIT 1
                ");

                $quote_check->bind_param("i", $order_id);
                $quote_check->execute();
                $quote_result = $quote_check->get_result();

                $existing_quote = $quote_result->fetch_assoc();

                if (
                    $existing_quote &&
                    in_array($existing_quote["status"], ["Accepted", "Rejected"], true)
                ) {
                    $message = "This quotation has already been accepted or rejected and cannot be changed.";
                    $message_type = "error";

                } else {

                    if ($existing_quote) {

                        // Update an existing quotation
                        $stmt = $conn->prepare("
                            UPDATE quotations
                            SET amount = ?, date_issued = CURDATE(), status = 'Pending'
                            WHERE quotation_id = ?
                        ");

                        $stmt->bind_param(
                            "di",
                            $amount,
                            $existing_quote["quotation_id"]
                        );

                    } else {

                        // Create a new quotation
                        $stmt = $conn->prepare("
                            INSERT INTO quotations
                            (order_id, amount, date_issued, status)
                            VALUES (?, ?, CURDATE(), 'Pending')
                        ");

                        $stmt->bind_param("id", $order_id, $amount);
                    }

                    if ($stmt->execute()) {
                        $message = "Quotation successfully saved for Order #" . $order_id . ".";
                        $message_type = "success";
                    } else {
                        $message = "Unable to save the quotation. Please try again.";
                        $message_type = "error";
                    }

                    $stmt->close();
                }

                $quote_check->close();
            }

            $order_check->close();
        }
    }
}

// Retrieve orders and their quotations
$sql = "
    SELECT
        o.order_id,
        o.order_date,
        o.due_date,
        o.order_status,
        o.total_amount,
        c.full_name,
        COUNT(DISTINCT oi.item_id) AS item_count,
        q.quotation_id,
        q.amount AS quotation_amount,
        q.date_issued,
        q.status AS quotation_status
    FROM orders o
    INNER JOIN customers c ON o.customer_id = c.customer_id
    LEFT JOIN order_items oi ON o.order_id = oi.order_id
    LEFT JOIN quotations q ON o.order_id = q.order_id
    GROUP BY
        o.order_id,
        o.order_date,
        o.due_date,
        o.order_status,
        o.total_amount,
        c.full_name,
        q.quotation_id,
        q.amount,
        q.date_issued,
        q.status
    ORDER BY o.order_id DESC
";

$orders = $conn->query($sql);

?>

<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Quotation Management | PrintFlow Pro</title>

    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://unpkg.com/lucide@latest"></script>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>

    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">

    <style>
        body {
            font-family: 'Inter', sans-serif;
        }

        a:focus,
        input:focus,
        button:focus {
            outline: 3px solid #818cf8;
            outline-offset: 2px;
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
                            <?= htmlspecialchars($_SESSION["user_name"] ?? "Staff") ?>
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
                    aria-current="page"
                    class="flex items-center gap-2 bg-indigo-600 text-white px-4 py-3 rounded-xl text-sm font-semibold"
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

        <!-- Heading -->
        <section class="mb-8">

            <h2 class="text-2xl font-bold text-slate-900">
                Quotation Management
            </h2>

            <p class="text-base text-slate-600 mt-2">
                Review customer orders and prepare their proposed quotation amounts.
            </p>

        </section>


        <!-- Feedback Message -->
        <?php if ($message !== ""): ?>

            <div
                class="mb-6 p-4 rounded-xl border
                <?= $message_type === "success"
                    ? "bg-green-50 border-green-200 text-green-800"
                    : "bg-red-50 border-red-200 text-red-800"; ?>"
                role="status"
            >
                <?= htmlspecialchars($message); ?>
            </div>

        <?php endif; ?>


        <!-- Orders Table -->
        <section class="bg-white rounded-2xl border border-slate-200 shadow-sm overflow-hidden">

            <div class="p-5 border-b border-slate-200">

                <h3 class="text-lg font-bold text-slate-900">
                    Customer Order Quotations
                </h3>

                <p class="text-sm text-slate-600 mt-1">
                    Create quotations for new requests or update quotations marked for revision.
                </p>

            </div>


            <div class="overflow-x-auto">

                <table class="w-full text-left">

                    <thead class="bg-slate-50 border-b border-slate-200">

                        <tr class="text-sm text-slate-600">

                            <th scope="col" class="px-5 py-4">Order ID</th>
                            <th scope="col" class="px-5 py-4">Customer</th>
                            <th scope="col" class="px-5 py-4">Order Date</th>
                            <th scope="col" class="px-5 py-4">Items</th>
                            <th scope="col" class="px-5 py-4">Order Status</th>
                            <th scope="col" class="px-5 py-4">Quotation</th>
                            <th scope="col" class="px-5 py-4">Proposed Amount</th>
                            <th scope="col" class="px-5 py-4">Action</th>

                        </tr>

                    </thead>


                    <tbody class="divide-y divide-slate-100">

                        <?php if ($orders && $orders->num_rows > 0): ?>

                            <?php while ($order = $orders->fetch_assoc()): ?>

                                <tr class="hover:bg-slate-50">

                                    <td class="px-5 py-4 font-bold text-indigo-700 whitespace-nowrap">
                                        #<?= (int)$order["order_id"]; ?>
                                    </td>

                                    <td class="px-5 py-4 font-medium">
                                        <?= htmlspecialchars($order["full_name"]); ?>
                                    </td>

                                    <td class="px-5 py-4 text-slate-600 whitespace-nowrap">
                                        <?= date("M d, Y", strtotime($order["order_date"])); ?>
                                    </td>

                                    <td class="px-5 py-4">
                                        <?= (int)$order["item_count"]; ?>
                                    </td>

                                    <td class="px-5 py-4 whitespace-nowrap">
                                        <?= htmlspecialchars($order["order_status"]); ?>
                                    </td>

                                    <td class="px-5 py-4 whitespace-nowrap">

                                        <?php if ($order["quotation_id"]): ?>

                                            <?php
                                            $status = $order["quotation_status"];

                                            $status_class = "bg-yellow-100 text-yellow-800";

                                            if ($status === "Accepted") {
                                                $status_class = "bg-green-100 text-green-800";
                                            } elseif ($status === "Rejected") {
                                                $status_class = "bg-red-100 text-red-800";
                                            } elseif ($status === "Revised") {
                                                $status_class = "bg-blue-100 text-blue-800";
                                            }
                                            ?>

                                            <span class="inline-block px-3 py-2 rounded-lg text-xs font-semibold <?= $status_class; ?>">
                                                <?= htmlspecialchars($status); ?>
                                            </span>

                                        <?php else: ?>

                                            <span class="text-slate-400">
                                                Not issued
                                            </span>

                                        <?php endif; ?>

                                    </td>

                                    <td class="px-5 py-4 font-semibold whitespace-nowrap">

                                        <?php if ($order["quotation_id"]): ?>

                                            ₱<?= number_format((float)$order["quotation_amount"], 2); ?>

                                        <?php else: ?>

                                            Not set

                                        <?php endif; ?>

                                    </td>

                                    <td class="px-5 py-4 min-w-64">

                                        <?php
                                        $can_edit = !$order["quotation_id"] ||
                                            in_array($order["quotation_status"], ["Pending", "Revised"], true);
                                        ?>

                                        <?php if ($can_edit): ?>

                                            <form method="POST" action="" class="space-y-2">

                                                <input
                                                    type="hidden"
                                                    name="csrf_token"
                                                    value="<?= htmlspecialchars($_SESSION["csrf_token"]); ?>"
                                                >

                                                <input
                                                    type="hidden"
                                                    name="order_id"
                                                    value="<?= (int)$order["order_id"]; ?>"
                                                >

                                                <label
                                                    for="amount_<?= (int)$order["order_id"]; ?>"
                                                    class="sr-only"
                                                >
                                                    Quotation amount for Order <?= (int)$order["order_id"]; ?>
                                                </label>

                                                <input
                                                    type="number"
                                                    id="amount_<?= (int)$order["order_id"]; ?>"
                                                    name="amount"
                                                    min="0.01"
                                                    step="0.01"
                                                    required
                                                    placeholder="Enter amount"
                                                    value="<?= $order["quotation_id"]
                                                        ? htmlspecialchars($order["quotation_amount"])
                                                        : ""; ?>"
                                                    class="w-full border border-slate-300 rounded-xl px-4 py-3 text-base focus:outline-none focus:ring-2 focus:ring-indigo-500"
                                                >

                                                <button
                                                    type="submit"
                                                    class="w-full bg-indigo-600 hover:bg-indigo-700 text-white font-semibold py-3 rounded-xl transition"
                                                >
                                                    <?= $order["quotation_id"]
                                                        ? "Update Quotation"
                                                        : "Create Quotation"; ?>
                                                </button>

                                            </form>

                                        <?php else: ?>

                                            <span class="text-slate-500 text-sm">
                                                No changes allowed
                                            </span>

                                        <?php endif; ?>

                                    </td>

                                </tr>

                            <?php endwhile; ?>

                        <?php else: ?>

                            <tr>
                                <td colspan="8" class="px-5 py-12 text-center text-slate-500">
                                    No customer orders have been submitted yet.
                                </td>
                            </tr>

                        <?php endif; ?>

                    </tbody>

                </table>

            </div>

        </section>


        <!-- Information Note -->
        <p class="text-sm text-slate-500 mt-5">
            Note: A quotation is initially marked Pending so the customer can review it.
            Accepted and Rejected quotations cannot be edited through this page.
        </p>

    </main>


    <!-- Footer -->
    <footer class="text-center text-sm text-slate-500 py-6">
        PrintFlow Pro &copy; <?= date("Y"); ?>. All rights reserved.
    </footer>


    <script>
        lucide.createIcons();
    </script>

</body>

</html>

<?php
$conn->close();
?>
