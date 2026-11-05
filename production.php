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

$staff_id = (int)$_SESSION["user_id"];
$staff_name = $_SESSION["user_name"] ?? "Staff";

// Production stages
$stages = [
    "Queued",
    "Printing",
    "Quality Check",
    "Done"
];

// CSRF protection
if (!isset($_SESSION["csrf_token"])) {
    $_SESSION["csrf_token"] = bin2hex(random_bytes(32));
}

$csrf_token = $_SESSION["csrf_token"];

// Handle production updates
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["update_production"])) {

    $submitted_token = $_POST["csrf_token"] ?? "";
    $item_id = (int)($_POST["item_id"] ?? 0);
    $stage = $_POST["stage"] ?? "";
    $quality_result = $_POST["quality_result"] ?? "";
    $remarks = trim($_POST["remarks"] ?? "");

    if (!hash_equals($csrf_token, $submitted_token)) {
        header("Location: production.php?error=security");
        exit;
    }

    if ($item_id <= 0 || !in_array($stage, $stages, true)) {
        header("Location: production.php?error=invalid");
        exit;
    }

    if (strlen($remarks) > 1000) {
        header("Location: production.php?error=remarks");
        exit;
    }

    // Quality result is required only when recording a Quality Check.
    if ($stage === "Quality Check" && !in_array($quality_result, ["Pass", "Fail"], true)) {
        header("Location: production.php?error=qualityresult");
        exit;
    }

    // Confirm that the order item exists and check its recorded down payment.
    $check_stmt = $conn->prepare("
        SELECT
            oi.item_id,
            oi.order_id,
            COALESCE(
                SUM(
                    CASE
                        WHEN p.payment_type = 'Down payment'
                        THEN p.amount_paid
                        ELSE 0
                    END
                ),
                0
            ) AS down_payment_total
        FROM order_items oi
        LEFT JOIN payments p
            ON p.order_id = oi.order_id
        WHERE oi.item_id = ?
        GROUP BY oi.item_id, oi.order_id
    ");

    $check_stmt->bind_param("i", $item_id);
    $check_stmt->execute();
    $item = $check_stmt->get_result()->fetch_assoc();
    $check_stmt->close();

    if (!$item) {
        header("Location: production.php?error=invalid");
        exit;
    }

    // A positive down payment must be recorded before production starts.
    // Queued is allowed because it only means the item is waiting for production.
    if ($stage !== "Queued" && (float)$item["down_payment_total"] <= 0) {
        header("Location: production.php?error=downpayment");
        exit;
    }

    // Get the latest production record to enforce the quality-check workflow.
    $latest_stmt = $conn->prepare("
        SELECT stage, quality_result
        FROM production_logs
        WHERE item_id = ?
        ORDER BY log_id DESC
        LIMIT 1
    ");

    $latest_stmt->bind_param("i", $item_id);
    $latest_stmt->execute();
    $latest_log = $latest_stmt->get_result()->fetch_assoc();
    $latest_stmt->close();

    $previous_stage = $latest_log["stage"] ?? "Queued";
    $previous_quality_result = $latest_log["quality_result"] ?? null;

    // A failed quality check must go back to Printing before another check.
    if (
        $previous_stage === "Quality Check" &&
        $previous_quality_result === "Fail" &&
        $stage !== "Printing"
    ) {
        header("Location: production.php?error=rework");
        exit;
    }

    // Quality Check can only be recorded after Printing.
    if ($stage === "Quality Check" && $previous_stage !== "Printing") {
        header("Location: production.php?error=printingfirst");
        exit;
    }

    // Done is allowed only after a passing quality check.
    if (
        $stage === "Done" &&
        !(
            $previous_stage === "Quality Check" &&
            $previous_quality_result === "Pass"
        )
    ) {
        header("Location: production.php?error=qualityfirst");
        exit;
    }

    // Only save a quality result for Quality Check records.
    $saved_quality_result = ($stage === "Quality Check") ? $quality_result : null;

    // Record a new production log.
    $insert_stmt = $conn->prepare("
        INSERT INTO production_logs
            (item_id, updated_by, stage, quality_result, remarks)
        VALUES (?, ?, ?, ?, ?)
    ");

    $insert_stmt->bind_param(
        "iisss",
        $item_id,
        $staff_id,
        $stage,
        $saved_quality_result,
        $remarks
    );

    if ($insert_stmt->execute()) {
        $insert_stmt->close();

        header("Location: production.php?updated=1");
        exit;
    } else {
        $insert_stmt->close();

        header("Location: production.php?error=database");
        exit;
    }
}

// Search production records
$search = trim($_GET["search"] ?? "");
$search_value = "%" . $search . "%";

// Retrieve order items and their latest production stage and quality result.
$stmt = $conn->prepare("
    SELECT
        oi.item_id,
        oi.order_id,
        p.product_name,
        oi.size,
        oi.color,
        oi.material,
        oi.quantity,
        oi.design_notes,
        c.full_name,
        o.due_date,
        o.order_status,
        pl.stage AS production_stage,
        pl.quality_result,
        pl.remarks AS production_remarks,
        pl.log_date AS last_updated,
        u.full_name AS updated_by_name

    FROM order_items oi

    INNER JOIN products p
        ON oi.product_id = p.product_id

    INNER JOIN orders o
        ON oi.order_id = o.order_id

    INNER JOIN customers c
        ON o.customer_id = c.customer_id

    LEFT JOIN production_logs pl
        ON pl.log_id = (
            SELECT MAX(pl2.log_id)
            FROM production_logs pl2
            WHERE pl2.item_id = oi.item_id
        )

    LEFT JOIN users u
        ON pl.updated_by = u.user_id

    WHERE
        CAST(o.order_id AS CHAR) LIKE ?
        OR CAST(oi.item_id AS CHAR) LIKE ?
        OR c.full_name LIKE ?
        OR p.product_name LIKE ?

    ORDER BY oi.item_id DESC
");

$stmt->bind_param(
    "ssss",
    $search_value,
    $search_value,
    $search_value,
    $search_value
);

$stmt->execute();

$production_items = $stmt->get_result();
$total_items = $production_items->num_rows;

?>

<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Production Monitoring | PrintFlow</title>

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

            <!-- Navigation -->
            <nav class="flex flex-wrap gap-2 mt-5" aria-label="Staff navigation">

                <a
                    href="dashboard.php"
                    class="flex items-center gap-2 bg-slate-100 hover:bg-indigo-50 hover:text-indigo-700 px-4 py-3 rounded-xl text-sm font-semibold transition"
                >
                    <i data-lucide="layout-dashboard" class="w-5 h-5"></i>
                    Dashboard
                </a>

                <a
                    href="orders.php"
                    class="flex items-center gap-2 bg-slate-100 hover:bg-indigo-50 hover:text-indigo-700 px-4 py-3 rounded-xl text-sm font-semibold transition"
                >
                    <i data-lucide="clipboard-list" class="w-5 h-5"></i>
                    Orders
                </a>

                <a
                    href="production.php"
                    aria-current="page"
                    class="flex items-center gap-2 bg-indigo-600 text-white px-4 py-3 rounded-xl text-sm font-semibold"
                >
                    <i data-lucide="printer" class="w-5 h-5"></i>
                    Production
                </a>

            </nav>

        </div>

    </header>


    <!-- Main Content -->
    <main class="max-w-7xl mx-auto px-4 sm:px-6 py-8">

        <!-- Heading -->
        <section class="mb-8">

            <h2 class="text-2xl font-bold text-slate-900">
                Production Monitoring
            </h2>

            <p class="text-base text-slate-600 mt-2">
                Monitor individual order items and record their production progress, including quality-check results.
            </p>

        </section>


        <!-- Notifications -->
        <?php if (isset($_GET["updated"])): ?>

            <div class="bg-emerald-50 border border-emerald-200 text-emerald-800 p-4 rounded-xl mb-6" role="status">
                Production progress updated successfully.
            </div>

        <?php elseif (isset($_GET["error"])): ?>

            <div class="bg-red-50 border border-red-200 text-red-800 p-4 rounded-xl mb-6" role="alert">

                <?php
                $error = $_GET["error"];

                if ($error === "security") {
                    echo "Security verification failed. Please try again.";
                } elseif ($error === "invalid") {
                    echo "Invalid production information.";
                } elseif ($error === "remarks") {
                    echo "Remarks cannot exceed 1,000 characters.";
                } elseif ($error === "downpayment") {
                    echo "A down payment must be recorded before production can begin. Please record the down payment first.";
                } elseif ($error === "qualityresult") {
                    echo "Please select Pass or Fail when recording a Quality Check.";
                } elseif ($error === "rework") {
                    echo "This item failed its quality check. Return it to Printing for rework before recording another quality check.";
                } elseif ($error === "printingfirst") {
                    echo "The item must be in Printing before it can undergo a Quality Check.";
                } elseif ($error === "qualityfirst") {
                    echo "The item must pass Quality Check before it can be marked Done.";
                } else {
                    echo "Unable to save the production update. Please try again.";
                }
                ?>

            </div>

        <?php endif; ?>


        <!-- Production Stages -->
        <section class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 mb-6">

            <div class="bg-white border border-slate-200 rounded-xl p-4">
                <p class="text-sm text-slate-500">Stage 1</p>
                <p class="font-bold text-slate-800 mt-1">Queued</p>
                <p class="text-sm text-slate-600 mt-1">Waiting for production.</p>
            </div>

            <div class="bg-white border border-slate-200 rounded-xl p-4">
                <p class="text-sm text-slate-500">Stage 2</p>
                <p class="font-bold text-blue-700 mt-1">Printing</p>
                <p class="text-sm text-slate-600 mt-1">Product is being printed or reworked.</p>
            </div>

            <div class="bg-white border border-slate-200 rounded-xl p-4">
                <p class="text-sm text-slate-500">Stage 3</p>
                <p class="font-bold text-violet-700 mt-1">Quality Check</p>
                <p class="text-sm text-slate-600 mt-1">Staff records Pass or Fail.</p>
            </div>

            <div class="bg-white border border-slate-200 rounded-xl p-4">
                <p class="text-sm text-slate-500">Stage 4</p>
                <p class="font-bold text-emerald-700 mt-1">Done</p>
                <p class="text-sm text-slate-600 mt-1">Production passed inspection.</p>
            </div>

        </section>


        <!-- Item Count -->
        <section class="bg-white border border-slate-200 rounded-2xl p-5 shadow-sm mb-6">

            <div class="flex items-center gap-4">

                <div class="w-12 h-12 bg-indigo-50 text-indigo-600 rounded-xl flex items-center justify-center">
                    <i data-lucide="layers" class="w-6 h-6"></i>
                </div>

                <div>
                    <p class="text-sm text-slate-600">
                        Order Items Found
                    </p>

                    <p class="text-2xl font-bold text-slate-900">
                        <?= number_format($total_items) ?>
                    </p>
                </div>

            </div>

        </section>


        <!-- Search -->
        <section class="bg-white border border-slate-200 rounded-2xl p-5 shadow-sm mb-6">

            <form method="GET" action="production.php" class="flex flex-col sm:flex-row gap-3">

                <div class="flex-1">

                    <label for="search" class="block text-sm font-semibold text-slate-700 mb-2">
                        Search Production Items
                    </label>

                    <input
                        type="search"
                        id="search"
                        name="search"
                        value="<?= htmlspecialchars($search) ?>"
                        placeholder="Search by order ID, item ID, customer, or product"
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
                        href="production.php"
                        class="flex items-center justify-center bg-slate-100 hover:bg-slate-200 px-5 py-3 rounded-xl font-semibold text-sm transition"
                    >
                        Clear
                    </a>

                </div>

            </form>

        </section>

        <!-- Production Records -->
        <section class="bg-white border border-slate-200 rounded-2xl shadow-sm overflow-hidden">

            <div class="p-5 border-b border-slate-200">

                <h3 class="text-lg font-bold text-slate-900">
                    Production Records
                </h3>

                <p class="text-sm text-slate-600 mt-1">
                    Update each ordered product and record its quality-check result.
                </p>

            </div>


            <?php if ($production_items && $production_items->num_rows > 0): ?>

                <div class="divide-y divide-slate-200">

                    <?php while ($item = $production_items->fetch_assoc()): ?>

                        <?php
                        $current_stage = $item["production_stage"] ?? "Queued";
                        $current_quality_result = $item["quality_result"] ?? "";

                        $stage_class = "bg-slate-100 text-slate-700";

                        if ($current_stage === "Printing") {
                            $stage_class = "bg-blue-100 text-blue-800";
                        } elseif ($current_stage === "Quality Check") {
                            $stage_class = "bg-violet-100 text-violet-800";
                        } elseif ($current_stage === "Done") {
                            $stage_class = "bg-emerald-100 text-emerald-800";
                        }

                        $quality_class = "";
                        if ($current_quality_result === "Pass") {
                            $quality_class = "bg-emerald-100 text-emerald-800";
                        } elseif ($current_quality_result === "Fail") {
                            $quality_class = "bg-red-100 text-red-800";
                        }
                        ?>

                        <article class="p-5">

                            <!-- Item Information -->
                            <div class="flex flex-col lg:flex-row lg:items-start justify-between gap-4">

                                <div class="flex-1">

                                    <div class="flex flex-wrap items-center gap-2 mb-2">

                                        <span class="font-bold text-indigo-700">
                                            Order #<?= (int)$item["order_id"] ?>
                                        </span>

                                        <span class="text-slate-400">•</span>

                                        <span class="text-sm text-slate-500">
                                            Item #<?= (int)$item["item_id"] ?>
                                        </span>

                                    </div>

                                    <h4 class="text-lg font-bold text-slate-900">
                                        <?= htmlspecialchars($item["product_name"]) ?>
                                    </h4>

                                    <p class="text-sm text-slate-600 mt-1">
                                        Customer: <?= htmlspecialchars($item["full_name"]) ?>
                                    </p>

                                    <div class="grid grid-cols-2 sm:grid-cols-4 gap-3 mt-4">

                                        <div>
                                            <p class="text-xs text-slate-500">Quantity</p>
                                            <p class="font-semibold mt-1">
                                                <?= (int)$item["quantity"] ?>
                                            </p>
                                        </div>

                                        <div>
                                            <p class="text-xs text-slate-500">Size</p>
                                            <p class="font-semibold mt-1">
                                                <?= htmlspecialchars($item["size"] ?? "Not specified") ?>
                                            </p>
                                        </div>

                                        <div>
                                            <p class="text-xs text-slate-500">Color</p>
                                            <p class="font-semibold mt-1">
                                                <?= htmlspecialchars($item["color"] ?? "Not specified") ?>
                                            </p>
                                        </div>

                                        <div>
                                            <p class="text-xs text-slate-500">Material</p>
                                            <p class="font-semibold mt-1">
                                                <?= htmlspecialchars($item["material"] ?? "Not specified") ?>
                                            </p>
                                        </div>

                                    </div>

                                    <div class="mt-4">

                                        <p class="text-xs text-slate-500">
                                            Design Notes
                                        </p>

                                        <p class="text-sm text-slate-700 mt-1">
                                            <?= htmlspecialchars($item["design_notes"] ?? "No design notes provided.") ?>
                                        </p>

                                    </div>

                                    <div class="mt-3 text-sm text-slate-500">
                                        Due Date:
                                        <span class="font-medium text-slate-700">
                                            <?= htmlspecialchars($item["due_date"] ?? "Not set") ?>
                                        </span>
                                    </div>

                                </div>


                                <!-- Current Stage -->
                                <div class="lg:min-w-48">

                                    <p class="text-sm text-slate-500 mb-2">
                                        Current Production Stage
                                    </p>

                                    <span class="inline-block px-3 py-2 rounded-lg text-sm font-semibold <?= $stage_class ?>">
                                        <?= htmlspecialchars($current_stage) ?>
                                    </span>

                                    <?php if ($current_stage === "Quality Check" && $current_quality_result !== ""): ?>

                                        <p class="mt-3 text-sm text-slate-600">
                                            Quality Result:
                                            <span class="inline-block px-2 py-1 rounded-md text-xs font-semibold <?= $quality_class ?>">
                                                <?= htmlspecialchars($current_quality_result) ?>
                                            </span>
                                        </p>

                                    <?php elseif ($current_stage === "Quality Check"): ?>

                                        <p class="text-xs text-slate-500 mt-2">
                                            Quality result not recorded.
                                        </p>

                                    <?php endif; ?>

                                    <?php if (!empty($item["last_updated"])): ?>

                                        <p class="text-xs text-slate-500 mt-3">
                                            Last updated:
                                            <?= htmlspecialchars($item["last_updated"]) ?>
                                        </p>

                                        <p class="text-xs text-slate-500 mt-1">
                                            Updated by:
                                            <?= htmlspecialchars($item["updated_by_name"] ?? "Unknown") ?>
                                        </p>

                                    <?php else: ?>

                                        <p class="text-xs text-slate-500 mt-3">
                                            No production update recorded yet.
                                        </p>

                                    <?php endif; ?>

                                </div>

                            </div>


                            <!-- Production Update Form -->
                            <div class="border-t border-slate-100 mt-5 pt-5">

                                <form method="POST" action="production.php" class="grid grid-cols-1 md:grid-cols-4 gap-4">

                                    <input
                                        type="hidden"
                                        name="csrf_token"
                                        value="<?= htmlspecialchars($csrf_token) ?>"
                                    >

                                    <input
                                        type="hidden"
                                        name="item_id"
                                        value="<?= (int)$item["item_id"] ?>"
                                    >

                                    <div>

                                        <label
                                            for="stage-<?= (int)$item["item_id"] ?>"
                                            class="block text-sm font-semibold text-slate-700 mb-2"
                                        >
                                            Update Stage
                                        </label>

                                        <select
                                            id="stage-<?= (int)$item["item_id"] ?>"
                                            name="stage"
                                            required
                                            class="w-full border border-slate-300 rounded-xl px-4 py-3 text-base bg-white focus:outline-none focus:ring-2 focus:ring-indigo-500"
                                        >

                                            <?php foreach ($stages as $stage): ?>

                                                <option
                                                    value="<?= htmlspecialchars($stage) ?>"
                                                    <?= $current_stage === $stage ? "selected" : "" ?>
                                                >
                                                    <?= htmlspecialchars($stage) ?>
                                                </option>

                                            <?php endforeach; ?>

                                        </select>

                                    </div>


                                    <div>

                                        <label
                                            for="quality-<?= (int)$item["item_id"] ?>"
                                            class="block text-sm font-semibold text-slate-700 mb-2"
                                        >
                                            Quality Result
                                        </label>

                                        <select
                                            id="quality-<?= (int)$item["item_id"] ?>"
                                            name="quality_result"
                                            class="w-full border border-slate-300 rounded-xl px-4 py-3 text-base bg-white focus:outline-none focus:ring-2 focus:ring-indigo-500"
                                        >
                                            <option value="">Not applicable</option>
                                            <option value="Pass">Pass</option>
                                            <option value="Fail">Fail</option>
                                        </select>

                                        <p class="text-xs text-slate-500 mt-1">
                                            Required when stage is Quality Check.
                                        </p>

                                    </div>


                                    <div>

                                        <label
                                            for="remarks-<?= (int)$item["item_id"] ?>"
                                            class="block text-sm font-semibold text-slate-700 mb-2"
                                        >
                                            Production Remarks
                                        </label>

                                        <input
                                            type="text"
                                            id="remarks-<?= (int)$item["item_id"] ?>"
                                            name="remarks"
                                            maxlength="1000"
                                            value="<?= htmlspecialchars($item["production_remarks"] ?? "") ?>"
                                            placeholder="Add a production update"
                                            class="w-full border border-slate-300 rounded-xl px-4 py-3 text-base focus:outline-none focus:ring-2 focus:ring-indigo-500"
                                        >

                                    </div>


                                    <div class="flex items-end">

                                        <button
                                            type="submit"
                                            name="update_production"
                                            value="1"
                                            class="w-full flex items-center justify-center gap-2 bg-indigo-600 hover:bg-indigo-700 text-white px-5 py-3 rounded-xl font-semibold transition"
                                        >
                                            <i data-lucide="save" class="w-5 h-5"></i>
                                            Save Update
                                        </button>

                                    </div>

                                </form>

                            </div>

                        </article>

                    <?php endwhile; ?>

                </div>

            <?php else: ?>

                <div class="text-center px-6 py-12">

                    <i data-lucide="printer" class="w-12 h-12 text-slate-400 mx-auto"></i>

                    <h4 class="font-semibold text-lg text-slate-800 mt-4">
                        No production items found
                    </h4>

                    <p class="text-sm text-slate-600 mt-2">
                        Production records will appear here when customer orders contain products.
                    </p>

                    <?php if (!empty($search)): ?>

                        <a
                            href="production.php"
                            class="inline-block mt-4 text-indigo-700 hover:underline font-semibold"
                        >
                            Show all production items
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