
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

// CSRF protection
if (empty($_SESSION["csrf_token"])) {
    $_SESSION["csrf_token"] = bin2hex(random_bytes(32));
}

$message = "";
$error = "";

// Update feedback status
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["update_status"])) {

    if (
        !isset($_POST["csrf_token"]) ||
        !hash_equals($_SESSION["csrf_token"], $_POST["csrf_token"])
    ) {
        $error = "Invalid request. Please refresh the page and try again.";
    } else {
        $feedback_id = filter_input(INPUT_POST, "feedback_id", FILTER_VALIDATE_INT);
        $new_status = $_POST["new_status"] ?? "";

        if (
            !$feedback_id ||
            !in_array($new_status, ["Open", "Resolved"], true)
        ) {
            $error = "Invalid feedback information.";
        } else {
            $stmt = $conn->prepare(
                "UPDATE feedback SET status = ? WHERE feedback_id = ?"
            );

            $stmt->bind_param("si", $new_status, $feedback_id);

            if ($stmt->execute() && $stmt->affected_rows >= 0) {
                $message = "Feedback status updated successfully.";
            } else {
                $error = "Unable to update the feedback status.";
            }

            $stmt->close();
        }
    }
}

// Get feedback records
$sql = "SELECT
            f.feedback_id,
            f.customer_id,
            f.type,
            f.message,
            f.status,
            f.date_submitted,
            c.full_name,
            c.contact_no,
            c.email
        FROM feedback f
        INNER JOIN customers c
            ON f.customer_id = c.customer_id
        ORDER BY
            CASE WHEN f.status = 'Open' THEN 0 ELSE 1 END,
            f.date_submitted DESC,
            f.feedback_id DESC";

$feedbacks = $conn->query($sql);

// Get summary counts
$summary = $conn->query(
    "SELECT
        COUNT(*) AS total,
        SUM(CASE WHEN status = 'Open' THEN 1 ELSE 0 END) AS open_count,
        SUM(CASE WHEN status = 'Resolved' THEN 1 ELSE 0 END) AS resolved_count
     FROM feedback"
)->fetch_assoc();

?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Feedback Management | PrintFlow Pro</title>

    <script src="https://cdn.tailwindcss.com"></script>

    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">

    <style>
        body {
            font-family: 'Inter', sans-serif;
        }

        button:focus,
        a:focus,
        select:focus {
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
            <p class="text-sm text-slate-500">Feedback and Complaint Management</p>
        </div>

        <nav class="flex flex-wrap gap-4 text-sm font-medium" aria-label="Main navigation">

            <?php if ($_SESSION["user_role"] === "Admin"): ?>

                <a href="../admin/dashboard.php" class="text-slate-600 hover:text-indigo-700">Dashboard</a>
                <a href="../admin/orders.php" class="text-slate-600 hover:text-indigo-700">Orders</a>
                <a href="../admin/users.php" class="text-slate-600 hover:text-indigo-700">Users</a>
                <a href="../admin/customers.php" class="text-slate-600 hover:text-indigo-700">Customers</a>

            <?php else: ?>

                <a href="dashboard.php" class="text-slate-600 hover:text-indigo-700">Dashboard</a>
                <a href="orders.php" class="text-slate-600 hover:text-indigo-700">Orders</a>
                <a href="production.php" class="text-slate-600 hover:text-indigo-700">Production</a>

            <?php endif; ?>

            <a href="quotations.php" class="text-slate-600 hover:text-indigo-700">Quotations</a>
            <a href="design_proofs.php" class="text-slate-600 hover:text-indigo-700">Design Proofs</a>
            <a href="payments.php" class="text-slate-600 hover:text-indigo-700">Payments</a>
            <a href="follow_ups.php" class="text-slate-600 hover:text-indigo-700">Follow-ups</a>
            <a href="feedback.php" class="text-indigo-700 font-bold" aria-current="page">Feedback</a>
            <a href="../login.php?logout=1" class="text-red-600 hover:text-red-700">Logout</a>

        </nav>

    </div>
</header>

<main class="max-w-7xl mx-auto px-4 py-8">

    <div class="mb-8">
        <h2 class="text-2xl font-bold">Customer Feedback</h2>
        <p class="text-slate-600 mt-1">
            Review customer feedback and complaints and manage their resolution status.
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

    <!-- Summary Cards -->
    <section class="grid grid-cols-1 sm:grid-cols-3 gap-5 mb-8">

        <div class="bg-white border border-slate-200 rounded-xl p-5 shadow-sm">
            <p class="text-sm text-slate-500 font-medium">Total Submissions</p>
            <p class="text-3xl font-bold text-indigo-700 mt-2">
                <?= (int)($summary["total"] ?? 0) ?>
            </p>
        </div>

        <div class="bg-white border border-slate-200 rounded-xl p-5 shadow-sm">
            <p class="text-sm text-slate-500 font-medium">Open</p>
            <p class="text-3xl font-bold text-amber-600 mt-2">
                <?= (int)($summary["open_count"] ?? 0) ?>
            </p>
        </div>

        <div class="bg-white border border-slate-200 rounded-xl p-5 shadow-sm">
            <p class="text-sm text-slate-500 font-medium">Resolved</p>
            <p class="text-3xl font-bold text-green-700 mt-2">
                <?= (int)($summary["resolved_count"] ?? 0) ?>
            </p>
        </div>

    </section>

    <!-- Feedback Records -->
    <section class="bg-white border border-slate-200 rounded-xl shadow-sm p-6">

        <h3 class="text-lg font-bold mb-5">Feedback and Complaints</h3>

        <?php if ($feedbacks && $feedbacks->num_rows > 0): ?>

            <div class="overflow-x-auto">

                <table class="w-full text-sm text-left">

                    <thead class="bg-slate-50 text-slate-600">
                        <tr>
                            <th class="p-3">Customer</th>
                            <th class="p-3">Type</th>
                            <th class="p-3">Message</th>
                            <th class="p-3">Date Submitted</th>
                            <th class="p-3">Status</th>
                            <th class="p-3">Action</th>
                        </tr>
                    </thead>

                    <tbody class="divide-y divide-slate-100">

                        <?php while ($feedback = $feedbacks->fetch_assoc()): ?>

                            <tr class="hover:bg-slate-50">

                                <td class="p-3 min-w-48">
                                    <p class="font-semibold">
                                        <?= htmlspecialchars($feedback["full_name"]) ?>
                                    </p>

                                    <p class="text-slate-500 text-xs mt-1">
                                        <?= htmlspecialchars($feedback["contact_no"] ?? "") ?>
                                    </p>

                                    <p class="text-slate-500 text-xs">
                                        <?= htmlspecialchars($feedback["email"] ?? "") ?>
                                    </p>
                                </td>

                                <td class="p-3">
                                    <span class="font-semibold">
                                        <?= htmlspecialchars($feedback["type"]) ?>
                                    </span>
                                </td>

                                <td class="p-3 min-w-64 max-w-md whitespace-pre-wrap break-words">
                                    <?= htmlspecialchars($feedback["message"]) ?>
                                </td>

                                <td class="p-3 whitespace-nowrap">
                                    <?= htmlspecialchars(
                                        date("M d, Y h:i A", strtotime($feedback["date_submitted"]))
                                    ) ?>
                                </td>

                                <td class="p-3">

                                    <?php if ($feedback["status"] === "Resolved"): ?>

                                        <span class="bg-green-100 text-green-800 px-3 py-1 rounded-full font-semibold">
                                            Resolved
                                        </span>

                                    <?php else: ?>

                                        <span class="bg-amber-100 text-amber-800 px-3 py-1 rounded-full font-semibold">
                                            Open
                                        </span>

                                    <?php endif; ?>

                                </td>

                                <td class="p-3">

                                    <form method="POST" class="flex flex-wrap gap-2">

                                        <input
                                            type="hidden"
                                            name="csrf_token"
                                            value="<?= htmlspecialchars($_SESSION["csrf_token"]) ?>">

                                        <input
                                            type="hidden"
                                            name="feedback_id"
                                            value="<?= (int)$feedback["feedback_id"] ?>">

                                        <select
                                            name="new_status"
                                            aria-label="Status for feedback <?= (int)$feedback["feedback_id"] ?>"
                                            class="border border-slate-300 rounded-lg p-2">

                                            <option value="Open"
                                                <?= $feedback["status"] === "Open" ? "selected" : "" ?>>
                                                Open
                                            </option>

                                            <option value="Resolved"
                                                <?= $feedback["status"] === "Resolved" ? "selected" : "" ?>>
                                                Resolved
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
                <h4 class="font-semibold text-slate-700">
                    No feedback or complaints submitted yet
                </h4>

                <p class="text-slate-500 text-sm mt-2">
                    Customer submissions will appear here.
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
