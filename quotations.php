
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

    <title>Quotation Management | PrintFlow</title>

    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">

    <style>
        body {
            font-family: 'Inter', sans-serif;
        }

        a:focus, input:focus, button:focus {
            outline: 3px solid #818cf8;
            outline-offset: 2px;
        }
    </style>
</head>

<body class="bg-slate-100 min-h-screen text-slate-800">

    <!-- Navigation -->
    <nav class="bg-white border-b border-slate-200">
        <div class="max-w-7xl mx-auto px-5 py-4 flex flex-wrap justify-between items-center gap-4">

            <a href="dashboard.php" class="text-xl font-extrabold text-indigo-700">
                PrintFlow
            </a>

            <div class="flex flex-wrap gap-5 text-sm font-semibold">
                <a href="dashboard.php" class="hover:text-indigo-700">Dashboard</a>
                <a href="orders.php" class="hover:text-indigo-700">Orders</a>
                <a href="production.php" class="hover:text-indigo-700">Production</a>
                <a href="quotations.php" class="text-indigo-700">Quotations</a>
            </div>

        </div>
    </nav>

    <main class="max-w-7xl mx-auto px-5 py-10">

        <div class="mb-8">
            <a href="dashboard.php" class="text-sm font-semibold text-indigo-700 hover:underline">
                ← Back to Dashboard
            </a>

            <h1 class="text-3xl font-extrabold mt-4">Quotation Management</h1>

            <p class="text-slate-600 mt-2">
                Review customer orders and prepare their proposed quotation amounts.
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

        <!-- Orders table -->
        <section class="bg-white rounded-2xl border border-slate-200 shadow-sm overflow-hidden">

            <div class="p-6 border-b border-slate-200">
                <h2 class="text-xl font-bold">Customer Order Quotations</h2>
                <p class="text-sm text-slate-500 mt-1">
                    Create quotations for new requests or update quotations marked for revision.
                </p>
            </div>

            <div class="overflow-x-auto">

                <table class="w-full text-sm text-left">

                    <thead class="bg-slate-50 text-slate-600">
                        <tr>
                            <th class="p-4">Order ID</th>
                            <th class="p-4">Customer</th>
                            <th class="p-4">Order Date</th>
                            <th class="p-4">Items</th>
                            <th class="p-4">Order Status</th>
                            <th class="p-4">Quotation</th>
                            <th class="p-4">Proposed Amount</th>
                            <th class="p-4">Action</th>
                        </tr>
                    </thead>

                    <tbody class="divide-y divide-slate-100">

                        <?php if ($orders && $orders->num_rows > 0): ?>

                            <?php while ($order = $orders->fetch_assoc()): ?>

                                <tr class="hover:bg-slate-50">

                                    <td class="p-4 font-bold">
                                        #<?php echo $order["order_id"]; ?>
                                    </td>

                                    <td class="p-4">
                                        <?php echo htmlspecialchars($order["full_name"]); ?>
                                    </td>

                                    <td class="p-4">
                                        <?php echo date("M d, Y", strtotime($order["order_date"])); ?>
                                    </td>

                                    <td class="p-4">
                                        <?php echo $order["item_count"]; ?>
                                    </td>

                                    <td class="p-4">
                                        <?php echo htmlspecialchars($order["order_status"]); ?>
                                    </td>

                                    <td class="p-4">

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

                                            <span class="px-3 py-1 rounded-full text-xs font-bold <?php echo $status_class; ?>">
                                                <?php echo htmlspecialchars($status); ?>
                                            </span>

                                        <?php else: ?>

                                            <span class="text-slate-400">
                                                Not issued
                                            </span>

                                        <?php endif; ?>

                                    </td>

                                    <td class="p-4 font-semibold">
                                        <?php if ($order["quotation_id"]): ?>
                                            ₱<?php echo number_format((float)$order["quotation_amount"], 2); ?>
                                        <?php else: ?>
                                            Not set
                                        <?php endif; ?>
                                    </td>

                                    <td class="p-4 min-w-64">

                                        <?php
                                        $can_edit = !$order["quotation_id"] ||
                                            in_array($order["quotation_status"], ["Pending", "Revised"], true);
                                        ?>

                                        <?php if ($can_edit): ?>

                                            <form method="POST" action="" class="space-y-2">

                                                <input
                                                    type="hidden"
                                                    name="csrf_token"
                                                    value="<?php echo htmlspecialchars($_SESSION["csrf_token"]); ?>"
                                                >

                                                <input
                                                    type="hidden"
                                                    name="order_id"
                                                    value="<?php echo $order["order_id"]; ?>"
                                                >

                                                <label
                                                    for="amount_<?php echo $order["order_id"]; ?>"
                                                    class="sr-only"
                                                >
                                                    Quotation amount for Order <?php echo $order["order_id"]; ?>
                                                </label>

                                                <input
                                                    type="number"
                                                    id="amount_<?php echo $order["order_id"]; ?>"
                                                    name="amount"
                                                    min="0.01"
                                                    step="0.01"
                                                    required
                                                    placeholder="Enter amount"
                                                    value="<?php
                                                        echo $order["quotation_id"]
                                                            ? htmlspecialchars($order["quotation_amount"])
                                                            : "";
                                                    ?>"
                                                    class="w-full border border-slate-300 rounded-lg p-2"
                                                >

                                                <button
                                                    type="submit"
                                                    class="w-full bg-indigo-600 hover:bg-indigo-700 text-white font-bold py-2 rounded-lg"
                                                >
                                                    <?php
                                                    echo $order["quotation_id"]
                                                        ? "Update Quotation"
                                                        : "Create Quotation";
                                                    ?>
                                                </button>

                                            </form>

                                        <?php else: ?>

                                            <span class="text-slate-500 text-xs">
                                                No changes allowed
                                            </span>

                                        <?php endif; ?>

                                    </td>

                                </tr>

                            <?php endwhile; ?>

                        <?php else: ?>

                            <tr>
                                <td colspan="8" class="p-10 text-center text-slate-500">
                                    No customer orders have been submitted yet.
                                </td>
                            </tr>

                        <?php endif; ?>

                    </tbody>

                </table>

            </div>

        </section>

        <p class="text-sm text-slate-500 mt-5">
            Note: A quotation is initially marked Pending so the customer can review it.
            Accepted and Rejected quotations cannot be edited through this page.
        </p>

    </main>

</body>
</html>
