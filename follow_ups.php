
<?php
session_start();
require_once "../config/database.php";

// Allow Admin and Staff
if (
    !isset($_SESSION["user_id"]) ||
    !isset($_SESSION["user_role"]) ||
    !in_array($_SESSION["user_role"], ["Admin", "Staff"], true)
) {
    header("Location: ../login.php");
    exit;
}

if (empty($_SESSION["csrf_token"])) {
    $_SESSION["csrf_token"] = bin2hex(random_bytes(32));
}

$message = "";
$error = "";

// Add follow-up
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["add_followup"])) {

    if (
        !isset($_POST["csrf_token"]) ||
        !hash_equals($_SESSION["csrf_token"], $_POST["csrf_token"])
    ) {
        $error = "Invalid request. Please refresh the page and try again.";
    } else {
        $customer_id = filter_input(INPUT_POST, "customer_id", FILTER_VALIDATE_INT);
        $follow_up_date = trim($_POST["follow_up_date"] ?? "");
        $notes = trim($_POST["notes"] ?? "");

        if (!$customer_id || !$follow_up_date || !$notes) {
            $error = "Please complete all required fields.";
        } elseif (strlen($notes) > 2000) {
            $error = "Notes must not exceed 2,000 characters.";
        } else {
            $date_check = DateTime::createFromFormat("Y-m-d", $follow_up_date);

            if (!$date_check || $date_check->format("Y-m-d") !== $follow_up_date) {
                $error = "Please enter a valid follow-up date.";
            } else {
                // Confirm that the customer exists
                $check = $conn->prepare(
                    "SELECT customer_id FROM customers WHERE customer_id = ?"
                );
                $check->bind_param("i", $customer_id);
                $check->execute();
                $customer_exists = $check->get_result()->num_rows > 0;
                $check->close();

                if (!$customer_exists) {
                    $error = "The selected customer does not exist.";
                } else {
                    $status = "Pending";

                    $stmt = $conn->prepare(
                        "INSERT INTO follow_ups
                         (customer_id, follow_up_date, notes, status)
                         VALUES (?, ?, ?, ?)"
                    );

                    $stmt->bind_param(
                        "isss",
                        $customer_id,
                        $follow_up_date,
                        $notes,
                        $status
                    );

                    if ($stmt->execute()) {
                        $message = "Customer follow-up scheduled successfully.";
                    } else {
                        $error = "Unable to schedule the follow-up.";
                    }

                    $stmt->close();
                }
            }
        }
    }
}

// Update follow-up status
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["update_status"])) {

    if (
        !isset($_POST["csrf_token"]) ||
        !hash_equals($_SESSION["csrf_token"], $_POST["csrf_token"])
    ) {
        $error = "Invalid request. Please refresh the page and try again.";
    } else {
        $followup_id = filter_input(INPUT_POST, "followup_id", FILTER_VALIDATE_INT);
        $new_status = $_POST["new_status"] ?? "";

        if (!$followup_id || !in_array($new_status, ["Pending", "Done"], true)) {
            $error = "Invalid follow-up information.";
        } else {
            $stmt = $conn->prepare(
                "UPDATE follow_ups
                 SET status = ?
                 WHERE followup_id = ?"
            );

            $stmt->bind_param("si", $new_status, $followup_id);

            if ($stmt->execute()) {
                $message = "Follow-up status updated successfully.";
            } else {
                $error = "Unable to update the follow-up status.";
            }

            $stmt->close();
        }
    }
}

// Get customers for dropdown
$customers = $conn->query(
    "SELECT customer_id, full_name, contact_no
     FROM customers
     ORDER BY full_name ASC"
);

// Get follow-up records
$followups = $conn->query(
    "SELECT
        f.followup_id,
        f.customer_id,
        f.follow_up_date,
        f.notes,
        f.status,
        c.full_name,
        c.contact_no
     FROM follow_ups f
     INNER JOIN customers c ON f.customer_id = c.customer_id
     ORDER BY
        CASE WHEN f.status = 'Pending' THEN 0 ELSE 1 END,
        f.follow_up_date ASC,
        f.followup_id DESC"
);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>CRM Follow-ups | PrintFlow Pro</title>

    <script src="https://cdn.tailwindcss.com"></script>

    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">

    <style>
        body {
            font-family: 'Inter', sans-serif;
        }

        input:focus, select:focus, textarea:focus, button:focus, a:focus {
            outline: 3px solid #818cf8;
            outline-offset: 3px;
        }
    </style>
</head>

<body class="bg-slate-100 text-slate-800 min-h-screen">

<header class="bg-white border-b border-slate-200">
    <div class="max-w-7xl mx-auto px-4 py-4 flex flex-wrap justify-between items-center gap-4">

        <div>
            <h1 class="text-xl font-bold text-indigo-700">PrintFlow Pro</h1>
            <p class="text-sm text-slate-500">CRM Follow-up Management</p>
        </div>

        <nav class="flex flex-wrap gap-4 text-sm font-medium" aria-label="Main navigation">
            <?php if ($_SESSION["user_role"] === "Admin"): ?>
                <a href="../admin/dashboard.php" class="text-slate-600 hover:text-indigo-700">Dashboard</a>
                <a href="../admin/orders.php" class="text-slate-600 hover:text-indigo-700">Orders</a>
                <a href="../admin/users.php" class="text-slate-600 hover:text-indigo-700">Users</a>
            <?php else: ?>
                <a href="dashboard.php" class="text-slate-600 hover:text-indigo-700">Dashboard</a>
                <a href="orders.php" class="text-slate-600 hover:text-indigo-700">Orders</a>
                <a href="production.php" class="text-slate-600 hover:text-indigo-700">Production</a>
            <?php endif; ?>

            <a href="quotations.php" class="text-slate-600 hover:text-indigo-700">Quotations</a>
            <a href="design_proofs.php" class="text-slate-600 hover:text-indigo-700">Design Proofs</a>
            <a href="payments.php" class="text-slate-600 hover:text-indigo-700">Payments</a>
            <a href="follow_ups.php" class="text-indigo-700 font-bold" aria-current="page">Follow-ups</a>
            <a href="../login.php?logout=1" class="text-red-600 hover:text-red-700">Logout</a>
        </nav>

    </div>
</header>

<main class="max-w-7xl mx-auto px-4 py-8">

    <div class="mb-8">
        <h2 class="text-2xl font-bold">Customer Follow-ups</h2>
        <p class="text-slate-600 mt-1">
            Schedule and manage customer communication and follow-up activities.
        </p>
    </div>

    <?php if ($message): ?>
        <div class="bg-green-100 border border-green-300 text-green-800 rounded-lg p-4 mb-5" role="status">
            <?= htmlspecialchars($message) ?>
        </div>
    <?php endif; ?>

    <?php if ($error): ?>
        <div class="bg-red-100 border border-red-300 text-red-800 rounded-lg p-4 mb-5" role="alert">
            <?= htmlspecialchars($error) ?>
        </div>
    <?php endif; ?>

    <section class="bg-white border border-slate-200 rounded-xl shadow-sm p-6 mb-8">

        <h3 class="text-lg font-bold mb-5">Schedule a Follow-up</h3>

        <form method="POST" class="space-y-5">

            <input type="hidden" name="csrf_token"
                   value="<?= htmlspecialchars($_SESSION["csrf_token"]) ?>">

            <div>
                <label for="customer_id" class="block text-sm font-semibold mb-2">
                    Select Customer
                </label>

                <select
                    id="customer_id"
                    name="customer_id"
                    required
                    class="w-full border border-slate-300 rounded-lg p-3 bg-white">

                    <option value="">Choose a customer</option>

                    <?php while ($customer = $customers->fetch_assoc()): ?>
                        <option value="<?= (int)$customer["customer_id"] ?>">
                            <?= htmlspecialchars($customer["full_name"]) ?>
                            - <?= htmlspecialchars($customer["contact_no"]) ?>
                        </option>
                    <?php endwhile; ?>

                </select>
            </div>

            <div>
                <label for="follow_up_date" class="block text-sm font-semibold mb-2">
                    Follow-up Date
                </label>

                <input
                    type="date"
                    id="follow_up_date"
                    name="follow_up_date"
                    required
                    class="w-full border border-slate-300 rounded-lg p-3">
            </div>

            <div>
                <label for="notes" class="block text-sm font-semibold mb-2">
                    Follow-up Notes
                </label>

                <textarea
                    id="notes"
                    name="notes"
                    rows="4"
                    maxlength="2000"
                    required
                    placeholder="Example: Contact the customer regarding their order or design revision."
                    class="w-full border border-slate-300 rounded-lg p-3"></textarea>
            </div>

            <button
                type="submit"
                name="add_followup"
                class="bg-indigo-600 hover:bg-indigo-700 text-white font-semibold px-6 py-3 rounded-lg">
                Schedule Follow-up
            </button>

        </form>

    </section>

    <section class="bg-white border border-slate-200 rounded-xl shadow-sm p-6">

        <h3 class="text-lg font-bold mb-5">Follow-up Records</h3>

        <?php if ($followups && $followups->num_rows > 0): ?>

            <div class="overflow-x-auto">

                <table class="w-full text-sm text-left">

                    <thead class="bg-slate-50 text-slate-600">
                        <tr>
                            <th class="p-3">Customer</th>
                            <th class="p-3">Contact</th>
                            <th class="p-3">Follow-up Date</th>
                            <th class="p-3">Notes</th>
                            <th class="p-3">Status</th>
                            <th class="p-3">Action</th>
                        </tr>
                    </thead>

                    <tbody class="divide-y divide-slate-100">

                        <?php while ($followup = $followups->fetch_assoc()): ?>

                            <tr>
                                <td class="p-3 font-semibold">
                                    <?= htmlspecialchars($followup["full_name"]) ?>
                                </td>

                                <td class="p-3">
                                    <?= htmlspecialchars($followup["contact_no"]) ?>
                                </td>

                                <td class="p-3">
                                    <?= htmlspecialchars(date(
                                        "M d, Y",
                                        strtotime($followup["follow_up_date"])
                                    )) ?>
                                </td>

                                <td class="p-3 min-w-64 whitespace-pre-wrap">
                                    <?= htmlspecialchars($followup["notes"]) ?>
                                </td>

                                <td class="p-3">
                                    <?php if ($followup["status"] === "Done"): ?>
                                        <span class="bg-green-100 text-green-800 px-3 py-1 rounded-full font-semibold">
                                            Done
                                        </span>
                                    <?php else: ?>
                                        <span class="bg-amber-100 text-amber-800 px-3 py-1 rounded-full font-semibold">
                                            Pending
                                        </span>
                                    <?php endif; ?>
                                </td>

                                <td class="p-3">

                                    <form method="POST" class="flex flex-wrap gap-2">

                                        <input type="hidden" name="csrf_token"
                                               value="<?= htmlspecialchars($_SESSION["csrf_token"]) ?>">

                                        <input type="hidden" name="followup_id"
                                               value="<?= (int)$followup["followup_id"] ?>">

                                        <select name="new_status"
                                                aria-label="Status for follow-up <?= (int)$followup["followup_id"] ?>"
                                                class="border border-slate-300 rounded-lg p-2">

                                            <option value="Pending"
                                                <?= $followup["status"] === "Pending" ? "selected" : "" ?>>
                                                Pending
                                            </option>

                                            <option value="Done"
                                                <?= $followup["status"] === "Done" ? "selected" : "" ?>>
                                                Done
                                            </option>

                                        </select>

                                        <button
                                            type="submit"
                                            name="update_status"
                                            class="bg-indigo-600 hover:bg-indigo-700 text-white px-3 py-2 rounded-lg">
                                            Update
                                        </button>

                                    </form>

                                </td>
                            </tr>

                        <?php endwhile; ?>

                    </tbody>

                </table>

            </div>

        <?php else: ?>

            <div class="text-center py-10">
                <h4 class="font-semibold text-slate-700">No follow-ups yet</h4>
                <p class="text-slate-500 text-sm mt-2">
                    Scheduled customer follow-ups will appear here.
                </p>
            </div>

        <?php endif; ?>

    </section>

</main>

</body>
</html>

<?php
$conn->close();
?>
