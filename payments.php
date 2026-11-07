
<?php
session_start();

require_once "../config/database.php";

// =====================================================
// AUTHENTICATION
// =====================================================

if (
    !isset($_SESSION["user_id"]) ||
    !isset($_SESSION["user_role"]) ||
    !in_array($_SESSION["user_role"], ["Admin", "Staff"], true)
) {
    header("Location: ../login.php");
    exit;
}

// Logout
if (isset($_GET["logout"])) {
    $_SESSION = [];
    session_destroy();

    header("Location: ../login.php");
    exit;
}

$staff_id = (int)$_SESSION["user_id"];
$staff_name = $_SESSION["user_name"] ?? "Staff";
$user_role = $_SESSION["user_role"];

$dashboard_link = ($user_role === "Admin")
    ? "../admin/dashboard.php"
    : "dashboard.php";

// =====================================================
// CSRF PROTECTION
// =====================================================

if (empty($_SESSION["csrf_token"])) {
    $_SESSION["csrf_token"] = bin2hex(random_bytes(32));
}

$message = "";
$message_type = "";

function peso($amount) {
    return "₱" . number_format((float)$amount, 2);
}

// =====================================================
// HANDLE MANUAL PAYMENT RECORDING
// =====================================================

if (
    $_SERVER["REQUEST_METHOD"] === "POST" &&
    ($_POST["action"] ?? "") === "record_manual_payment"
) {

    if (
        !isset($_POST["csrf_token"]) ||
        !hash_equals($_SESSION["csrf_token"], $_POST["csrf_token"])
    ) {
        $message = "Invalid request. Please refresh the page.";
        $message_type = "error";

    } else {

        $order_id = filter_input(
            INPUT_POST,
            "order_id",
            FILTER_VALIDATE_INT
        );

        $amount = filter_input(
            INPUT_POST,
            "amount",
            FILTER_VALIDATE_FLOAT
        );

        $payment_method = trim($_POST["payment_method"] ?? "");
        $payment_type = trim($_POST["payment_type"] ?? "");

        if (
            !$order_id ||
            $amount === false ||
            $amount === null ||
            $amount <= 0 ||
            !in_array(
                $payment_method,
                ["Cash", "GCash", "Bank Transfer"],
                true
            ) ||
            !in_array(
                $payment_type,
                ["Down payment", "Final payment"],
                true
            )
        ) {
            $message = "Please provide valid payment information.";
            $message_type = "error";

        } else {

            try {

                $conn->begin_transaction();

                // Lock order
                $order_check = $conn->prepare("
                    SELECT order_id
                    FROM orders
                    WHERE order_id = ?
                    FOR UPDATE
                ");

                $order_check->bind_param("i", $order_id);
                $order_check->execute();

                $order = $order_check->get_result()->fetch_assoc();
                $order_check->close();

                if (!$order) {
                    throw new Exception("Selected order could not be found.");
                }

                // Get latest accepted quotation
                $quotation_check = $conn->prepare("
                    SELECT amount
                    FROM quotations
                    WHERE order_id = ?
                    AND status = 'Accepted'
                    ORDER BY quotation_id DESC
                    LIMIT 1
                ");

                $quotation_check->bind_param("i", $order_id);
                $quotation_check->execute();

                $quotation = $quotation_check->get_result()->fetch_assoc();
                $quotation_check->close();

                if (!$quotation) {
                    throw new Exception(
                        "This order does not have an accepted quotation."
                    );
                }

                // Calculate official payments
                $paid_check = $conn->prepare("
                    SELECT COALESCE(SUM(amount_paid), 0) AS total_paid
                    FROM payments
                    WHERE order_id = ?
                ");

                $paid_check->bind_param("i", $order_id);
                $paid_check->execute();

                $paid_data = $paid_check->get_result()->fetch_assoc();
                $paid_check->close();

                // Reserve amounts from other pending submissions
                $pending_check = $conn->prepare("
                    SELECT COALESCE(SUM(amount), 0) AS pending_amount
                    FROM payment_submissions
                    WHERE order_id = ?
                    AND status = 'Pending Verification'
                ");

                $pending_check->bind_param("i", $order_id);
                $pending_check->execute();

                $pending_data = $pending_check->get_result()->fetch_assoc();
                $pending_check->close();

                $quotation_amount = (float)$quotation["amount"];
                $total_paid = (float)$paid_data["total_paid"];
                $pending_amount = (float)$pending_data["pending_amount"];

                $available_balance =
                    $quotation_amount -
                    $total_paid -
                    $pending_amount;

                if ($amount > $available_balance + 0.001) {
                    throw new Exception(
                        "Payment exceeds the available balance of " .
                        peso(max(0, $available_balance)) .
                        ". Please review pending submissions."
                    );
                }

                // Final payment requires a recorded down payment
                if ($payment_type === "Final payment") {

                    $down_check = $conn->prepare("
                        SELECT COALESCE(SUM(amount_paid), 0) AS down_total
                        FROM payments
                        WHERE order_id = ?
                        AND payment_type = 'Down payment'
                    ");

                    $down_check->bind_param("i", $order_id);
                    $down_check->execute();

                    $down_data = $down_check->get_result()->fetch_assoc();
                    $down_check->close();

                    if ((float)$down_data["down_total"] <= 0) {
                        throw new Exception(
                            "A down payment must be recorded before recording the final payment."
                        );
                    }
                }

                // Insert official payment
                $insert_payment = $conn->prepare("
                    INSERT INTO payments (
                        order_id,
                        amount_paid,
                        payment_method,
                        payment_type,
                        payment_date
                    )
                    VALUES (?, ?, ?, ?, NOW())
                ");

                $insert_payment->bind_param(
                    "idss",
                    $order_id,
                    $amount,
                    $payment_method,
                    $payment_type
                );

                if (!$insert_payment->execute()) {
                    throw new Exception("Unable to record the payment.");
                }

                $insert_payment->close();

                $conn->commit();

                $message = "Payment recorded successfully! It has been added to the official payment records.";
                $message_type = "success";

            } catch (Throwable $e) {

                $conn->rollback();

                $message = $e->getMessage();
                $message_type = "error";
            }
        }
    }
}

// =====================================================
// HANDLE CUSTOMER PAYMENT APPROVAL OR REJECTION
// =====================================================

if (
    $_SERVER["REQUEST_METHOD"] === "POST" &&
    ($_POST["action"] ?? "") !== "record_manual_payment"
) {

    if (
        !isset($_POST["csrf_token"]) ||
        !hash_equals($_SESSION["csrf_token"], $_POST["csrf_token"])
    ) {
        $message = "Invalid request. Please refresh the page.";
        $message_type = "error";

    } else {

        $submission_id = filter_input(
            INPUT_POST,
            "submission_id",
            FILTER_VALIDATE_INT
        );

        $decision = trim($_POST["decision"] ?? "");
        $staff_note = trim($_POST["staff_note"] ?? "");

        if (
            !$submission_id ||
            !in_array($decision, ["approve", "reject"], true) ||
            $staff_note === "" ||
            mb_strlen($staff_note) > 1000
        ) {
            $message = "Please provide valid payment verification information and a Staff note.";
            $message_type = "error";

        } else {

            try {

                $conn->begin_transaction();

                // Retrieve and lock payment submission
                $submission_check = $conn->prepare("
                    SELECT
                        submission_id,
                        order_id,
                        customer_id,
                        amount,
                        payment_method,
                        payment_type,
                        status
                    FROM payment_submissions
                    WHERE submission_id = ?
                    FOR UPDATE
                ");

                $submission_check->bind_param("i", $submission_id);
                $submission_check->execute();

                $submission = $submission_check
                    ->get_result()
                    ->fetch_assoc();

                $submission_check->close();

                if (!$submission) {
                    throw new Exception("Payment submission not found.");
                }

                if ($submission["status"] !== "Pending Verification") {
                    throw new Exception(
                        "This payment submission has already been reviewed."
                    );
                }

                // Lock associated order
                $order_lock = $conn->prepare("
                    SELECT order_id
                    FROM orders
                    WHERE order_id = ?
                    FOR UPDATE
                ");

                $order_lock->bind_param("i", $submission["order_id"]);
                $order_lock->execute();

                $order_exists = $order_lock
                    ->get_result()
                    ->fetch_assoc();

                $order_lock->close();

                if (!$order_exists) {
                    throw new Exception(
                        "The associated order could not be found."
                    );
                }

                // =====================================================
                // APPROVE PAYMENT
                // =====================================================

                if ($decision === "approve") {

                    // Latest accepted quotation
                    $quotation_check = $conn->prepare("
                        SELECT amount
                        FROM quotations
                        WHERE order_id = ?
                        AND status = 'Accepted'
                        ORDER BY quotation_id DESC
                        LIMIT 1
                    ");

                    $quotation_check->bind_param(
                        "i",
                        $submission["order_id"]
                    );

                    $quotation_check->execute();

                    $quotation = $quotation_check
                        ->get_result()
                        ->fetch_assoc();

                    $quotation_check->close();

                    if (!$quotation) {
                        throw new Exception(
                            "This order does not have an accepted quotation."
                        );
                    }

                    // Officially recorded payments
                    $paid_check = $conn->prepare("
                        SELECT COALESCE(SUM(amount_paid), 0) AS total_paid
                        FROM payments
                        WHERE order_id = ?
                    ");

                    $paid_check->bind_param(
                        "i",
                        $submission["order_id"]
                    );

                    $paid_check->execute();

                    $paid_data = $paid_check
                        ->get_result()
                        ->fetch_assoc();

                    $paid_check->close();

                    // Other pending submissions
                    $pending_check = $conn->prepare("
                        SELECT COALESCE(SUM(amount), 0) AS pending_amount
                        FROM payment_submissions
                        WHERE order_id = ?
                        AND status = 'Pending Verification'
                        AND submission_id != ?
                    ");

                    $pending_check->bind_param(
                        "ii",
                        $submission["order_id"],
                        $submission_id
                    );

                    $pending_check->execute();

                    $pending_data = $pending_check
                        ->get_result()
                        ->fetch_assoc();

                    $pending_check->close();

                    $quotation_amount = (float)$quotation["amount"];
                    $total_paid = (float)$paid_data["total_paid"];
                    $other_pending = (float)$pending_data["pending_amount"];
                    $submission_amount = (float)$submission["amount"];

                    $available_balance =
                        $quotation_amount -
                        $total_paid -
                        $other_pending;

                    if ($submission_amount > $available_balance + 0.001) {
                        throw new Exception(
                            "Cannot approve this payment. The submission exceeds the available balance of " .
                            peso(max(0, $available_balance)) .
                            ". Please review the other pending submissions."
                        );
                    }

                    // Final payment requires a down payment
                    if ($submission["payment_type"] === "Final payment") {

                        $down_check = $conn->prepare("
                            SELECT COALESCE(SUM(amount_paid), 0) AS down_payment_total
                            FROM payments
                            WHERE order_id = ?
                            AND payment_type = 'Down payment'
                        ");

                        $down_check->bind_param(
                            "i",
                            $submission["order_id"]
                        );

                        $down_check->execute();

                        $down_data = $down_check
                            ->get_result()
                            ->fetch_assoc();

                        $down_payment_total =
                            (float)$down_data["down_payment_total"];

                        $down_check->close();

                        if ($down_payment_total <= 0) {
                            throw new Exception(
                                "A down payment must be recorded before approving the final payment."
                            );
                        }
                    }

                    // Insert officially approved payment
                    $insert_payment = $conn->prepare("
                        INSERT INTO payments (
                            order_id,
                            amount_paid,
                            payment_method,
                            payment_type,
                            payment_date
                        )
                        VALUES (?, ?, ?, ?, NOW())
                    ");

                    $insert_payment->bind_param(
                        "idss",
                        $submission["order_id"],
                        $submission_amount,
                        $submission["payment_method"],
                        $submission["payment_type"]
                    );

                    if (!$insert_payment->execute()) {
                        throw new Exception(
                            "Unable to record the approved payment."
                        );
                    }

                    $insert_payment->close();

                    // Update submission status
                    $update_submission = $conn->prepare("
                        UPDATE payment_submissions
                        SET
                            status = 'Approved',
                            staff_note = ?,
                            reviewed_by = ?,
                            reviewed_at = NOW()
                        WHERE submission_id = ?
                        AND status = 'Pending Verification'
                    ");

                    $update_submission->bind_param(
                        "sii",
                        $staff_note,
                        $staff_id,
                        $submission_id
                    );

                    if (
                        !$update_submission->execute() ||
                        $update_submission->affected_rows !== 1
                    ) {
                        throw new Exception(
                            "Unable to update the payment submission."
                        );
                    }

                    $update_submission->close();

                    // =====================================================
                    // CHECK WHETHER ORDER CAN MOVE TO PRODUCTION
                    // =====================================================

                    $production_started = false;

                    if ($submission["payment_type"] === "Down payment") {

                        $proof_check = $conn->prepare("
                            SELECT
                                COUNT(oi.item_id) AS total_items,
                                COALESCE(
                                    SUM(
                                        CASE
                                            WHEN latest.approval_status = 'Approved'
                                            THEN 1
                                            ELSE 0
                                        END
                                    ),
                                    0
                                ) AS approved_items
                            FROM order_items oi

                            LEFT JOIN (
                                SELECT
                                    dp1.item_id,
                                    dp1.approval_status
                                FROM design_proofs dp1

                                INNER JOIN (
                                    SELECT
                                        item_id,
                                        MAX(version_no) AS latest_version
                                    FROM design_proofs
                                    GROUP BY item_id
                                ) versions
                                    ON dp1.item_id = versions.item_id
                                    AND dp1.version_no = versions.latest_version

                            ) latest
                                ON oi.item_id = latest.item_id

                            WHERE oi.order_id = ?
                        ");

                        $proof_check->bind_param(
                            "i",
                            $submission["order_id"]
                        );

                        $proof_check->execute();

                        $proof_data = $proof_check
                            ->get_result()
                            ->fetch_assoc();

                        $proof_check->close();

                        // Every order item must have an approved latest proof
                        if (
                            (int)$proof_data["total_items"] > 0 &&
                            (int)$proof_data["total_items"] ===
                            (int)$proof_data["approved_items"]
                        ) {

                            $status_update = $conn->prepare("
                                UPDATE orders
                                SET order_status = 'In Production'
                                WHERE order_id = ?
                                AND order_status = 'Design Approval'
                            ");

                            $status_update->bind_param(
                                "i",
                                $submission["order_id"]
                            );

                            $status_update->execute();

                            if ($status_update->affected_rows > 0) {
                                $production_started = true;
                            }

                            $status_update->close();
                        }
                    }

                    $conn->commit();

                    if ($production_started) {
                        $message = "Payment approved successfully! The down payment has been recorded and the order has moved to In Production.";
                    } else {
                        $message = "Payment approved successfully! The payment has been added to the official payment records.";
                    }

                    $message_type = "success";
                }

                // =====================================================
                // REJECT PAYMENT
                // =====================================================

                elseif ($decision === "reject") {

                    $reject_submission = $conn->prepare("
                        UPDATE payment_submissions
                        SET
                            status = 'Rejected',
                            staff_note = ?,
                            reviewed_by = ?,
                            reviewed_at = NOW()
                        WHERE submission_id = ?
                        AND status = 'Pending Verification'
                    ");

                    $reject_submission->bind_param(
                        "sii",
                        $staff_note,
                        $staff_id,
                        $submission_id
                    );

                    if (
                        !$reject_submission->execute() ||
                        $reject_submission->affected_rows !== 1
                    ) {
                        throw new Exception(
                            "Unable to reject the payment submission."
                        );
                    }

                    $reject_submission->close();

                    $conn->commit();

                    $message = "Payment submission rejected successfully. The customer can review the Staff note.";
                    $message_type = "success";
                }

            } catch (Throwable $e) {

                $conn->rollback();

                $message = $e->getMessage();
                $message_type = "error";
            }
        }
    }
}

// =====================================================
// RETRIEVE PENDING PAYMENT SUBMISSIONS
// =====================================================

$pending_submissions = $conn->query("
    SELECT
        ps.submission_id,
        ps.order_id,
        ps.customer_id,
        ps.amount,
        ps.payment_method,
        ps.payment_type,
        ps.proof_file,
        ps.status,
        ps.submitted_at,
        c.full_name
    FROM payment_submissions ps
    INNER JOIN customers c
        ON ps.customer_id = c.customer_id
    WHERE ps.status = 'Pending Verification'
    ORDER BY ps.submitted_at ASC, ps.submission_id ASC
");

// =====================================================
// RETRIEVE PAYMENT SUBMISSION HISTORY
// =====================================================

$submission_history = $conn->query("
    SELECT
        ps.submission_id,
        ps.order_id,
        c.full_name,
        ps.amount,
        ps.payment_method,
        ps.payment_type,
        ps.proof_file,
        ps.status,
        ps.submitted_at,
        ps.reviewed_at,
        ps.staff_note
    FROM payment_submissions ps
    INNER JOIN customers c
        ON ps.customer_id = c.customer_id
    ORDER BY ps.submitted_at DESC, ps.submission_id DESC
");

// =====================================================
// RETRIEVE OFFICIAL PAYMENT HISTORY
// =====================================================

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

// =====================================================
// RETRIEVE ORDERS AVAILABLE FOR MANUAL PAYMENT
// =====================================================

$manual_orders = $conn->query("
    SELECT
        o.order_id,
        c.full_name,
        q.amount AS quotation_amount,
        COALESCE(p.total_paid, 0) AS total_paid,
        COALESCE(ps.pending_amount, 0) AS pending_amount,
        GREATEST(
            q.amount
            - COALESCE(p.total_paid, 0)
            - COALESCE(ps.pending_amount, 0),
            0
        ) AS available_balance

    FROM orders o

    INNER JOIN customers c
        ON o.customer_id = c.customer_id

    INNER JOIN quotations q
        ON q.quotation_id = (
            SELECT quotation_id
            FROM quotations
            WHERE order_id = o.order_id
            AND status = 'Accepted'
            ORDER BY quotation_id DESC
            LIMIT 1
        )

    LEFT JOIN (
        SELECT
            order_id,
            SUM(amount_paid) AS total_paid
        FROM payments
        GROUP BY order_id
    ) p
        ON o.order_id = p.order_id

    LEFT JOIN (
        SELECT
            order_id,
            SUM(amount) AS pending_amount
        FROM payment_submissions
        WHERE status = 'Pending Verification'
        GROUP BY order_id
    ) ps
        ON o.order_id = ps.order_id

    ORDER BY o.order_id DESC
");

?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Payment Tracking | PrintFlow Pro</title>

    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://unpkg.com/lucide@latest"></script>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>

    <link
        href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap"
        rel="stylesheet"
    >

    <style>
        body {
            font-family: 'Inter', sans-serif;
        }

        input:focus,
        select:focus,
        textarea:focus,
        button:focus,
        a:focus {
            outline: 3px solid #818cf8;
            outline-offset: 3px;
        }
    </style>
</head>

<body class="bg-slate-100 min-h-screen text-slate-800">

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
                        <?= htmlspecialchars(
                            $user_role === "Admin"
                                ? "Administrator"
                                : "Staff Member"
                        ) ?>
                    </p>
                </div>

                <a
                    href="payments.php?logout=1"
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
                href="<?= htmlspecialchars($dashboard_link) ?>"
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
                aria-current="page"
                class="flex items-center gap-2 bg-indigo-600 text-white px-4 py-3 rounded-xl text-sm font-semibold"
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


<main class="max-w-7xl mx-auto px-4 sm:px-6 py-8">

    <!-- Page Heading -->

    <div class="mb-8">

        <h1 class="text-2xl font-bold text-slate-900">
            Payment Tracking
        </h1>

        <p class="text-base text-slate-600 mt-2">
            Review customer payment submissions, record direct payments, and manage official payment records.
        </p>

    </div>


    <!-- Feedback Message -->

    <?php if ($message !== ""): ?>

        <div
            class="mb-6 p-4 rounded-xl border
            <?= $message_type === "success"
                ? "bg-emerald-50 border-emerald-200 text-emerald-800"
                : "bg-red-50 border-red-200 text-red-800"; ?>"
            role="status"
        >
            <?= htmlspecialchars($message) ?>
        </div>

    <?php endif; ?>


    <!-- Pending Payment Submissions -->

    <section class="bg-white rounded-2xl border border-slate-200 shadow-sm mb-8 overflow-hidden">

        <div class="p-6 border-b border-slate-200">

            <div class="flex flex-wrap items-center justify-between gap-3">

                <div>
                    <h2 class="text-xl font-bold">
                        Pending Payment Submissions
                    </h2>

                    <p class="text-sm text-slate-500 mt-1">
                        Review customer receipts before accepting their payments.
                    </p>
                </div>

                <span class="bg-amber-100 text-amber-800 px-3 py-2 rounded-lg text-sm font-semibold">
                    <?= $pending_submissions
                        ? (int)$pending_submissions->num_rows
                        : 0 ?> Pending
                </span>

            </div>

        </div>

        <div class="overflow-x-auto">

            <table class="w-full text-sm text-left">

                <thead class="bg-slate-50 text-slate-600">

                    <tr>
                        <th class="p-4">Submission ID</th>
                        <th class="p-4">Customer</th>
                        <th class="p-4">Order ID</th>
                        <th class="p-4">Amount</th>
                        <th class="p-4">Method</th>
                        <th class="p-4">Type</th>
                        <th class="p-4">Proof</th>
                        <th class="p-4">Submitted</th>
                        <th class="p-4 min-w-[280px]">Verification</th>
                    </tr>

                </thead>

                <tbody class="divide-y divide-slate-100">

                    <?php if ($pending_submissions && $pending_submissions->num_rows > 0): ?>

                        <?php while ($submission = $pending_submissions->fetch_assoc()): ?>

                            <tr class="align-top">

                                <td class="p-4 font-semibold">
                                    #<?= (int)$submission["submission_id"] ?>
                                </td>

                                <td class="p-4 font-semibold">
                                    <?= htmlspecialchars($submission["full_name"]) ?>
                                </td>

                                <td class="p-4">
                                    #<?= (int)$submission["order_id"] ?>
                                </td>

                                <td class="p-4 font-bold text-indigo-700">
                                    <?= peso($submission["amount"]) ?>
                                </td>

                                <td class="p-4">
                                    <?= htmlspecialchars($submission["payment_method"]) ?>
                                </td>

                                <td class="p-4">
                                    <?= htmlspecialchars($submission["payment_type"]) ?>
                                </td>

                                <td class="p-4">

                                    <a
                                        href="../<?= htmlspecialchars($submission["proof_file"]) ?>"
                                        target="_blank"
                                        rel="noopener noreferrer"
                                        class="inline-flex items-center gap-1 text-indigo-600 hover:text-indigo-800 font-semibold"
                                    >
                                        <i data-lucide="image" class="w-4 h-4"></i>
                                        View Proof
                                    </a>

                                </td>

                                <td class="p-4 whitespace-nowrap">
                                    <?= htmlspecialchars(
                                        date(
                                            "M d, Y h:i A",
                                            strtotime($submission["submitted_at"])
                                        )
                                    ) ?>
                                </td>

                                <td class="p-4 min-w-[280px]">

                                    <form method="POST" action="payments.php" class="space-y-3">

                                        <input
                                            type="hidden"
                                            name="csrf_token"
                                            value="<?= htmlspecialchars($_SESSION["csrf_token"]) ?>"
                                        >

                                        <input
                                            type="hidden"
                                            name="submission_id"
                                            value="<?= (int)$submission["submission_id"] ?>"
                                        >

                                        <label
                                            for="note_<?= (int)$submission["submission_id"] ?>"
                                            class="block text-xs font-semibold text-slate-600"
                                        >
                                            Staff Note / Verification Remarks
                                        </label>

                                        <textarea
                                            name="staff_note"
                                            id="note_<?= (int)$submission["submission_id"] ?>"
                                            rows="2"
                                            maxlength="1000"
                                            required
                                            placeholder="Enter approval remarks or reason for rejection..."
                                            class="w-full border border-slate-300 rounded-xl p-3 resize-y"
                                        ></textarea>

                                        <div class="flex flex-wrap gap-2">

                                            <button
                                                type="submit"
                                                name="decision"
                                                value="approve"
                                                onclick="return confirm('Are you sure you want to approve this payment?');"
                                                class="flex items-center gap-2 bg-emerald-600 hover:bg-emerald-700 text-white px-3 py-2 rounded-lg text-xs font-semibold transition"
                                            >
                                                <i data-lucide="check-circle" class="w-4 h-4"></i>
                                                Approve
                                            </button>

                                            <button
                                                type="submit"
                                                name="decision"
                                                value="reject"
                                                onclick="return confirm('Are you sure you want to reject this payment?');"
                                                class="flex items-center gap-2 bg-red-600 hover:bg-red-700 text-white px-3 py-2 rounded-lg text-xs font-semibold transition"
                                            >
                                                <i data-lucide="x-circle" class="w-4 h-4"></i>
                                                Reject
                                            </button>

                                        </div>

                                    </form>

                                </td>

                            </tr>

                        <?php endwhile; ?>

                    <?php else: ?>

                        <tr>
                            <td colspan="9" class="p-10 text-center">

                                <div class="flex flex-col items-center gap-2">

                                    <div class="bg-emerald-50 text-emerald-600 p-3 rounded-full">
                                        <i data-lucide="check-circle" class="w-7 h-7"></i>
                                    </div>

                                    <h3 class="font-semibold text-slate-700">
                                        No pending payment submissions
                                    </h3>

                                    <p class="text-sm text-slate-500">
                                        Customer payment submissions will appear here when they upload their proof.
                                    </p>

                                </div>

                            </td>
                        </tr>

                    <?php endif; ?>

                </tbody>

            </table>

        </div>

    </section>


    <!-- MANUAL PAYMENT RECORDING -->

    <section class="bg-white rounded-2xl border border-slate-200 shadow-sm mb-8 overflow-hidden">

        <div class="p-6 border-b border-slate-200">

            <div class="flex items-center gap-3">

                <div class="w-11 h-11 bg-indigo-100 text-indigo-600 rounded-xl flex items-center justify-center">
                    <i data-lucide="wallet" class="w-6 h-6"></i>
                </div>

                <div>
                    <h2 class="text-xl font-bold">
                        Record Customer Payment
                    </h2>

                    <p class="text-sm text-slate-500 mt-1">
                        Manually record payments received directly by the printing business.
                    </p>
                </div>

            </div>

        </div>

        <div class="p-6">

            <form method="POST" action="payments.php" class="space-y-5">

                <input
                    type="hidden"
                    name="csrf_token"
                    value="<?= htmlspecialchars($_SESSION["csrf_token"]) ?>"
                >

                <input
                    type="hidden"
                    name="action"
                    value="record_manual_payment"
                >

                <div>

                    <label for="manual_order_id" class="block text-sm font-semibold mb-2">
                        Select Customer Order
                    </label>

                    <select
                        name="order_id"
                        id="manual_order_id"
                        required
                        class="w-full border border-slate-300 rounded-xl p-3 bg-white"
                    >

                        <option value="">Choose an order</option>

                        <?php if ($manual_orders): ?>

                            <?php while ($order = $manual_orders->fetch_assoc()): ?>

                                <?php if ((float)$order["available_balance"] > 0.001): ?>

                                    <option value="<?= (int)$order["order_id"] ?>">
                                        Order #<?= (int)$order["order_id"] ?>
                                        - <?= htmlspecialchars($order["full_name"]) ?>
                                        - Available:
                                        <?= peso($order["available_balance"]) ?>
                                    </option>

                                <?php endif; ?>

                            <?php endwhile; ?>

                        <?php endif; ?>

                    </select>

                    <p class="text-xs text-slate-500 mt-2">
                        Only orders with accepted quotations and an available balance are shown.
                    </p>

                </div>

                <div class="grid grid-cols-1 md:grid-cols-3 gap-5">

                    <div>

                        <label for="amount" class="block text-sm font-semibold mb-2">
                            Payment Amount (₱)
                        </label>

                        <input
                            type="number"
                            name="amount"
                            id="amount"
                            min="0.01"
                            step="0.01"
                            required
                            placeholder="Enter amount"
                            class="w-full border border-slate-300 rounded-xl p-3"
                        >

                    </div>

                    <div>

                        <label for="payment_method" class="block text-sm font-semibold mb-2">
                            Payment Method
                        </label>

                        <select
                            name="payment_method"
                            id="payment_method"
                            required
                            class="w-full border border-slate-300 rounded-xl p-3 bg-white"
                        >
                            <option value="">Select method</option>
                            <option value="Cash">Cash</option>
                            <option value="GCash">GCash</option>
                            <option value="Bank Transfer">Bank Transfer</option>
                        </select>

                    </div>

                    <div>

                        <label for="payment_type" class="block text-sm font-semibold mb-2">
                            Payment Type
                        </label>

                        <select
                            name="payment_type"
                            id="payment_type"
                            required
                            class="w-full border border-slate-300 rounded-xl p-3 bg-white"
                        >
                            <option value="">Select payment type</option>
                            <option value="Down payment">Down payment</option>
                            <option value="Final payment">Final payment</option>
                        </select>

                    </div>

                </div>

                <div class="flex flex-wrap justify-end gap-3">

                    <button
                        type="reset"
                        class="bg-slate-100 hover:bg-slate-200 text-slate-700 px-5 py-3 rounded-xl font-semibold transition"
                    >
                        Clear
                    </button>

                    <button
                        type="submit"
                        onclick="return confirm('Are you sure you want to record this customer payment?');"
                        class="flex items-center gap-2 bg-indigo-600 hover:bg-indigo-700 text-white px-5 py-3 rounded-xl font-semibold transition"
                    >
                        <i data-lucide="plus-circle" class="w-5 h-5"></i>
                        Record Payment
                    </button>

                </div>

            </form>

        </div>

    </section>


    <!-- PAYMENT SUBMISSION HISTORY -->

    <section class="bg-white rounded-2xl border border-slate-200 shadow-sm mb-8 overflow-hidden">

        <div class="p-6 border-b border-slate-200">

            <h2 class="text-xl font-bold">
                Payment Submission History
            </h2>

            <p class="text-sm text-slate-500 mt-1">
                View previously submitted receipts and their verification results.
            </p>

        </div>

        <div class="overflow-x-auto">

            <table class="w-full text-sm text-left">

                <thead class="bg-slate-50 text-slate-600">

                    <tr>
                        <th class="p-4">Submission ID</th>
                        <th class="p-4">Customer</th>
                        <th class="p-4">Order ID</th>
                        <th class="p-4">Amount</th>
                        <th class="p-4">Method</th>
                        <th class="p-4">Type</th>
                        <th class="p-4">Proof</th>
                        <th class="p-4">Status</th>
                        <th class="p-4">Staff Note</th>
                    </tr>

                </thead>

                <tbody class="divide-y divide-slate-100">

                    <?php if ($submission_history && $submission_history->num_rows > 0): ?>

                        <?php while ($submission = $submission_history->fetch_assoc()): ?>

                            <tr>

                                <td class="p-4">
                                    #<?= (int)$submission["submission_id"] ?>
                                </td>

                                <td class="p-4 font-semibold">
                                    <?= htmlspecialchars($submission["full_name"]) ?>
                                </td>

                                <td class="p-4">
                                    #<?= (int)$submission["order_id"] ?>
                                </td>

                                <td class="p-4 font-bold">
                                    <?= peso($submission["amount"]) ?>
                                </td>

                                <td class="p-4">
                                    <?= htmlspecialchars($submission["payment_method"]) ?>
                                </td>

                                <td class="p-4">
                                    <?= htmlspecialchars($submission["payment_type"]) ?>
                                </td>

                                <td class="p-4">

                                    <a
                                        href="../<?= htmlspecialchars($submission["proof_file"]) ?>"
                                        target="_blank"
                                        rel="noopener noreferrer"
                                        class="text-indigo-600 hover:underline font-semibold"
                                    >
                                        View Proof
                                    </a>

                                </td>

                                <td class="p-4">

                                    <?php if ($submission["status"] === "Approved"): ?>

                                        <span class="inline-flex items-center gap-1 text-emerald-700 bg-emerald-50 px-3 py-1 rounded-lg font-semibold">
                                            <i data-lucide="check-circle" class="w-4 h-4"></i>
                                            Approved
                                        </span>

                                    <?php elseif ($submission["status"] === "Rejected"): ?>

                                        <span class="inline-flex items-center gap-1 text-red-700 bg-red-50 px-3 py-1 rounded-lg font-semibold">
                                            <i data-lucide="x-circle" class="w-4 h-4"></i>
                                            Rejected
                                        </span>

                                    <?php else: ?>

                                        <span class="text-amber-700 bg-amber-50 px-3 py-1 rounded-lg font-semibold">
                                            Pending Verification
                                        </span>

                                    <?php endif; ?>

                                </td>

                                <td class="p-4 min-w-[200px]">
                                    <?= htmlspecialchars($submission["staff_note"] ?? "") ?: "—" ?>
                                </td>

                            </tr>

                        <?php endwhile; ?>

                    <?php else: ?>

                        <tr>
                            <td colspan="9" class="p-8 text-center text-slate-500">
                                No payment submissions have been received yet.
                            </td>
                        </tr>

                    <?php endif; ?>

                </tbody>

            </table>

        </div>

    </section>


    <!-- OFFICIAL PAYMENT HISTORY -->

    <section class="bg-white rounded-2xl border border-slate-200 shadow-sm overflow-hidden">

        <div class="p-6 border-b border-slate-200">

            <h2 class="text-xl font-bold">
                Official Payment History
            </h2>

            <p class="text-sm text-slate-500 mt-1">
                Payments officially recorded through customer submissions or manual staff entry.
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
                        <th class="p-4">Payment Date</th>
                    </tr>

                </thead>

                <tbody class="divide-y divide-slate-100">

                    <?php if ($payments && $payments->num_rows > 0): ?>

                        <?php while ($payment = $payments->fetch_assoc()): ?>

                            <tr>

                                <td class="p-4">
                                    #<?= (int)$payment["payment_id"] ?>
                                </td>

                                <td class="p-4">
                                    #<?= (int)$payment["order_id"] ?>
                                </td>

                                <td class="p-4 font-semibold">
                                    <?= htmlspecialchars($payment["full_name"]) ?>
                                </td>

                                <td class="p-4 font-bold text-emerald-700">
                                    <?= peso($payment["amount_paid"]) ?>
                                </td>

                                <td class="p-4">
                                    <?= htmlspecialchars($payment["payment_method"]) ?>
                                </td>

                                <td class="p-4">
                                    <?= htmlspecialchars($payment["payment_type"]) ?>
                                </td>

                                <td class="p-4 whitespace-nowrap">
                                    <?= htmlspecialchars(
                                        date(
                                            "M d, Y h:i A",
                                            strtotime($payment["payment_date"])
                                        )
                                    ) ?>
                                </td>

                            </tr>

                        <?php endwhile; ?>

                    <?php else: ?>

                        <tr>
                            <td colspan="7" class="p-8 text-center text-slate-500">
                                No official payments have been recorded yet.
                            </td>
                        </tr>

                    <?php endif; ?>

                </tbody>

            </table>

        </div>

    </section>

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
