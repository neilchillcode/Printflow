<?php
session_start();

require_once "../config/database.php";

// Allow Admin and Staff accounts only
if (
    !isset($_SESSION["user_id"]) ||
    !isset($_SESSION["user_role"]) ||
    !in_array($_SESSION["user_role"], ["Admin", "Staff"], true)
) {
    header("Location: ../login.php");
    exit;
}

// CSRF protection
if (empty($_SESSION["csrf_token"])) {
    $_SESSION["csrf_token"] = bin2hex(random_bytes(32));
}

$message = "";
$message_type = "";

// Record a payment
if ($_SERVER["REQUEST_METHOD"] === "POST") {

    if (
        !isset($_POST["csrf_token"]) ||
        !hash_equals($_SESSION["csrf_token"], $_POST["csrf_token"])
    ) {
        $message = "Invalid request. Please refresh the page.";
        $message_type = "error";

    } else {

        $order_id = filter_input(INPUT_POST, "order_id", FILTER_VALIDATE_INT);
        $amount_paid = filter_input(INPUT_POST, "amount_paid", FILTER_VALIDATE_FLOAT);
        $payment_method = trim($_POST["payment_method"] ?? "");
        $payment_type = $_POST["payment_type"] ?? "";

        $allowed_methods = ["Cash", "GCash", "Bank Transfer", "Other"];
        $allowed_types = ["Down payment", "Final payment"];

        if (
            !$order_id ||
            $amount_paid === false ||
            $amount_paid === null ||
            $amount_paid <= 0 ||
            !in_array($payment_method, $allowed_methods, true) ||
            !in_array($payment_type, $allowed_types, true)
        ) {
            $message = "Please enter valid payment information.";
            $message_type = "error";

        } else {

            // Get the accepted quotation amount for the selected order
            $order_check = $conn->prepare("
                SELECT
                    o.order_id,
                    o.order_status,
                    q.amount AS quotation_amount,
                    COALESCE((
                        SELECT SUM(p.amount_paid)
                        FROM payments p
                        WHERE p.order_id = o.order_id
                    ), 0) AS total_paid
                FROM orders o
                INNER JOIN quotations q
                    ON o.order_id = q.order_id
                WHERE o.order_id = ?
                AND q.status = 'Accepted'
                ORDER BY q.quotation_id DESC
                LIMIT 1
            ");

            $order_check->bind_param("i", $order_id);
            $order_check->execute();

            $order_result = $order_check->get_result();
            $order = $order_result->fetch_assoc();
            $order_check->close();

            if (!$order) {

                $message = "Order not found or its quotation has not been accepted.";
                $message_type = "error";

            } else {

                $quotation_amount = (float)$order["quotation_amount"];
                $total_paid = (float)$order["total_paid"];
                $remaining_balance = $quotation_amount - $total_paid;

                if ($amount_paid > $remaining_balance + 0.001) {

                    $message = "Payment exceeds the remaining balance of ₱" .
                        number_format(max(0, $remaining_balance), 2) . ".";
                    $message_type = "error";

                } else {

                    try {
                        $conn->begin_transaction();

                        // Check whether a down payment has already been recorded
                        $down_payment_check = $conn->prepare("
                            SELECT COALESCE(SUM(amount_paid), 0) AS down_payment_total
                            FROM payments
                            WHERE order_id = ?
                            AND payment_type = 'Down payment'
                        ");

                        $down_payment_check->bind_param("i", $order_id);
                        $down_payment_check->execute();

                        $down_payment_result = $down_payment_check->get_result();
                        $down_payment_row = $down_payment_result->fetch_assoc();

                        $existing_down_payment = (float)$down_payment_row["down_payment_total"];
                        $down_payment_check->close();

                        // Final payment cannot be recorded before a down payment
                        if ($payment_type === "Final payment" && $existing_down_payment <= 0) {
                            throw new Exception("Please record the down payment first.");
                        }

                        // Insert payment
                        $insert = $conn->prepare("
                            INSERT INTO payments (
                                order_id,
                                amount_paid,
                                payment_method,
                                payment_type,
                                payment_date
                            )
                            VALUES (?, ?, ?, ?, NOW())
                        ");

                        $insert->bind_param(
                            "idss",
                            $order_id,
                            $amount_paid,
                            $payment_method,
                            $payment_type
                        );

                        if (!$insert->execute()) {
                            throw new Exception("Unable to record payment. Please try again.");
                        }

                        $insert->close();

                        $production_started = false;

                        // If a down payment was recorded, check whether all latest proofs are approved
                        if ($payment_type === "Down payment") {

                            $proof_check = $conn->prepare("
                                SELECT
                                    COUNT(oi.item_id) AS total_items,
                                    SUM(
                                        CASE
                                            WHEN latest.approval_status = 'Approved'
                                            THEN 1
                                            ELSE 0
                                        END
                                    ) AS approved_items
                                FROM order_items oi
                                LEFT JOIN (
                                    SELECT dp1.item_id, dp1.approval_status
                                    FROM design_proofs dp1
                                    INNER JOIN (
                                        SELECT item_id, MAX(version_no) AS latest_version
                                        FROM design_proofs
                                        GROUP BY item_id
                                    ) versions
                                        ON dp1.item_id = versions.item_id
                                        AND dp1.version_no = versions.latest_version
                                ) latest
                                    ON oi.item_id = latest.item_id
                                WHERE oi.order_id = ?
                            ");

                            $proof_check->bind_param("i", $order_id);
                            $proof_check->execute();

                            $proof_result = $proof_check->get_result();
                            $proof_data = $proof_result->fetch_assoc();
                            $proof_check->close();

                            // Move to production only if every item has an approved latest proof
                            if (
                                (int)$proof_data["total_items"] > 0 &&
                                (int)$proof_data["total_items"] === (int)$proof_data["approved_items"]
                            ) {
                                $status_update = $conn->prepare("
                                    UPDATE orders
                                    SET order_status = 'In Production'
                                    WHERE order_id = ?
                                    AND order_status = 'Design Approval'
                                ");

                                $status_update->bind_param("i", $order_id);
                                $status_update->execute();

                                if ($status_update->affected_rows > 0) {
                                    $production_started = true;
                                }

                                $status_update->close();
                            }
                        }

                        $conn->commit();

                        if ($production_started) {
                            $message = "Down payment recorded successfully. All design proofs are approved, so the order has moved to In Production.";
                        } else {
                            $message = "Payment recorded successfully.";
                        }

                        $message_type = "success";

                    } catch (Exception $e) {

                        $conn->rollback();

                        $message = $e->getMessage();
                        $message_type = "error";
                    }
                }
            }
        }
    }
}

// Retrieve orders with accepted quotations
$orders = $conn->query("
    SELECT
        o.order_id,
        c.full_name,
        q.amount AS quotation_amount,
        COALESCE((
            SELECT SUM(p.amount_paid)
            FROM payments p
            WHERE p.order_id = o.order_id
        ), 0) AS total_paid
    FROM orders o
    INNER JOIN customers c
        ON o.customer_id = c.customer_id
    INNER JOIN quotations q
        ON o.order_id = q.order_id
    WHERE q.status = 'Accepted'
    ORDER BY o.order_id DESC, q.quotation_id DESC
");

// Retrieve payment history
$payments = $conn->query("
    SELECT
        p.payment_id,
        p.order_id,
        c.full_name,
        p.amount_paid,
        p.payment_method,
        p.payment_type,
        p.payment_date
    FROM payments p
    INNER JOIN orders o
        ON p.order_id = o.order_id
    INNER JOIN customers c
        ON o.customer_id = c.customer_id
    ORDER BY p.payment_date DESC, p.payment_id DESC
");
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Payment Tracking | PrintFlow</title>

    <script src="https://cdn.tailwindcss.com"></script>

    <link
        href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap"
        rel="stylesheet"
    >

    <style>
        body {
            font-family: 'Inter', sans-serif;
        }

        input:focus, select:focus, button:focus, a:focus {
            outline: 3px solid #818cf8;
            outline-offset: 2px;
        }
    </style>
</head>

<body class="bg-slate-100 min-h-screen text-slate-800">

    <!-- Navigation -->
    <nav class="bg-white border-b border-slate-200">
        <div class="max-w-7xl mx-auto px-5 py-4 flex flex-wrap justify-between items-center gap-4">

            <a href="../<?php echo strtolower($_SESSION["user_role"]); ?>/dashboard.php"
               class="text-xl font-extrabold text-indigo-700">
                PrintFlow
            </a>

            <div class="flex flex-wrap gap-5 text-sm font-semibold">
                <a href="../<?php echo strtolower($_SESSION["user_role"]); ?>/dashboard.php"
                   class="hover:text-indigo-700">
                    Dashboard
                </a>

                <a href="orders.php" class="hover:text-indigo-700">
                    Orders
                </a>

                <a href="quotations.php" class="hover:text-indigo-700">
                    Quotations
                </a>

                <a href="design_proofs.php" class="hover:text-indigo-700">
                    Design Proofs
                </a>

                <a href="payments.php" class="text-indigo-700">
                    Payments
                </a>
            </div>

        </div>
    </nav>

    <main class="max-w-7xl mx-auto px-5 py-10">

        <div class="mb-8">
            <h1 class="text-3xl font-extrabold">Payment Tracking</h1>

            <p class="text-slate-600 mt-2">
                Record customer payments and monitor the remaining balances of orders.
            </p>
        </div>

        <!-- Feedback message -->
        <?php if ($message !== ""): ?>
            <div
                class="mb-6 p-4 rounded-xl border
                <?php echo $message_type === "success"
                    ? "bg-green-50 border-green-200 text-green-800"
                    : "bg-red-50 border-red-200 text-red-800"; ?>"
                role="status"
            >
                <?php echo htmlspecialchars($message); ?>
            </div>
        <?php endif; ?>

        <!-- Record payment form -->
        <section class="bg-white rounded-2xl border border-slate-200 shadow-sm p-6 mb-8">

            <h2 class="text-xl font-bold mb-2">Record a Payment</h2>

            <p class="text-sm text-slate-500 mb-6">
                Only orders with accepted quotations can receive payments.
            </p>

            <?php if ($orders && $orders->num_rows > 0): ?>

                <form method="POST" action="" class="grid grid-cols-1 md:grid-cols-2 gap-5">

                    <input
                        type="hidden"
                        name="csrf_token"
                        value="<?php echo htmlspecialchars($_SESSION["csrf_token"]); ?>"
                    >

                    <div>
                        <label for="order_id" class="block font-semibold mb-2">
                            Select Order
                        </label>

                        <select
                            name="order_id"
                            id="order_id"
                            required
                            class="w-full border border-slate-300 rounded-lg p-3 bg-white"
                        >
                            <option value="">Choose an order</option>

                            <?php
                            $displayed_orders = [];

                            while ($order = $orders->fetch_assoc()):
                                $order_key = (int)$order["order_id"];

                                // Avoid duplicate order options if an order has multiple accepted quotations
                                if (isset($displayed_orders[$order_key])) {
                                    continue;
                                }

                                $displayed_orders[$order_key] = true;

                                $balance = (float)$order["quotation_amount"] -
                                    (float)$order["total_paid"];
                            ?>

                                <?php if ($balance > 0.001): ?>

                                    <option value="<?php echo $order["order_id"]; ?>">
                                        Order #<?php echo $order["order_id"]; ?>
                                        - <?php echo htmlspecialchars($order["full_name"]); ?>
                                        - Balance: ₱<?php echo number_format($balance, 2); ?>
                                    </option>

                                <?php endif; ?>

                            <?php endwhile; ?>

                        </select>
                    </div>

                    <div>
                        <label for="amount_paid" class="block font-semibold mb-2">
                            Amount Paid (₱)
                        </label>

                        <input
                            type="number"
                            name="amount_paid"
                            id="amount_paid"
                            min="0.01"
                            step="0.01"
                            required
                            placeholder="Enter payment amount"
                            class="w-full border border-slate-300 rounded-lg p-3"
                        >
                    </div>

                    <div>
                        <label for="payment_method" class="block font-semibold mb-2">
                            Payment Method
                        </label>

                        <select
                            name="payment_method"
                            id="payment_method"
                            required
                            class="w-full border border-slate-300 rounded-lg p-3 bg-white"
                        >
                            <option value="">Choose payment method</option>
                            <option value="Cash">Cash</option>
                            <option value="GCash">GCash</option>
                            <option value="Bank Transfer">Bank Transfer</option>
                            <option value="Other">Other</option>
                        </select>
                    </div>

                    <div>
                        <label for="payment_type" class="block font-semibold mb-2">
                            Payment Type
                        </label>

                        <select
                            name="payment_type"
                            id="payment_type"
                            required
                            class="w-full border border-slate-300 rounded-lg p-3 bg-white"
                        >
                            <option value="">Choose payment type</option>
                            <option value="Down payment">Down payment</option>
                            <option value="Final payment">Final payment</option>
                        </select>
                    </div>

                    <div class="md:col-span-2">
                        <button
                            type="submit"
                            class="bg-indigo-600 hover:bg-indigo-700 text-white font-bold px-6 py-3 rounded-lg"
                        >
                            Record Payment
                        </button>
                    </div>

                </form>

            <?php else: ?>

                <div class="bg-slate-50 border border-slate-200 rounded-xl p-5">
                    <p class="font-semibold">No orders with an outstanding balance.</p>

                    <p class="text-sm text-slate-500 mt-1">
                        An order will appear here once its quotation has been accepted and it has an unpaid balance.
                    </p>
                </div>

            <?php endif; ?>

        </section>

        <!-- Payment history -->
        <section class="bg-white rounded-2xl border border-slate-200 shadow-sm overflow-hidden">

            <div class="p-6 border-b border-slate-200">
                <h2 class="text-xl font-bold">Payment History</h2>

                <p class="text-sm text-slate-500 mt-1">
                    All recorded customer payments.
                </p>
            </div>

            <div class="overflow-x-auto">

                <table class="w-full text-sm text-left">

                    <thead class="bg-slate-50 text-slate-600">
                        <tr>
                            <th class="p-4">Payment ID</th>
                            <th class="p-4">Order ID</th>
                            <th class="p-4">Customer</th>
                            <th class="p-4">Amount</th>
                            <th class="p-4">Method</th>
                            <th class="p-4">Type</th>
                            <th class="p-4">Date</th>
                        </tr>
                    </thead>

                    <tbody class="divide-y divide-slate-100">

                        <?php if ($payments && $payments->num_rows > 0): ?>

                            <?php while ($payment = $payments->fetch_assoc()): ?>

                                <tr>
                                    <td class="p-4">
                                        #<?php echo $payment["payment_id"]; ?>
                                    </td>

                                    <td class="p-4">
                                        #<?php echo $payment["order_id"]; ?>
                                    </td>

                                    <td class="p-4 font-semibold">
                                        <?php echo htmlspecialchars($payment["full_name"]); ?>
                                    </td>

                                    <td class="p-4 font-bold text-green-700">
                                        ₱<?php echo number_format($payment["amount_paid"], 2); ?>
                                    </td>

                                    <td class="p-4">
                                        <?php echo htmlspecialchars($payment["payment_method"]); ?>
                                    </td>

                                    <td class="p-4">
                                        <?php echo htmlspecialchars($payment["payment_type"]); ?>
                                    </td>

                                    <td class="p-4">
                                        <?php echo date(
                                            "M d, Y h:i A",
                                            strtotime($payment["payment_date"])
                                        ); ?>
                                    </td>
                                </tr>

                            <?php endwhile; ?>

                        <?php else: ?>

                            <tr>
                                <td colspan="7" class="p-8 text-center text-slate-500">
                                    No payments have been recorded yet.
                                </td>
                            </tr>

                        <?php endif; ?>

                    </tbody>

                </table>

            </div>

        </section>

    </main>

</body>
</html>
