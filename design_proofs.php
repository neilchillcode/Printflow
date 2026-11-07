
<?php
session_start();

require_once "../config/database.php";

// Allow Admin and Staff only
if (
    !isset($_SESSION["user_id"]) ||
    !in_array($_SESSION["user_role"], ["Admin", "Staff"], true)
) {
    header("Location: ../login.php");
    exit;
}

if (empty($_SESSION["csrf_token"])) {
    $_SESSION["csrf_token"] = bin2hex(random_bytes(32));
}

$message = "";
$message_type = "";

// Process design proof upload
if ($_SERVER["REQUEST_METHOD"] === "POST") {

    if (
        !isset($_POST["csrf_token"]) ||
        !hash_equals($_SESSION["csrf_token"], $_POST["csrf_token"])
    ) {
        $message = "Invalid request. Please refresh the page.";
        $message_type = "error";

    } else {

        $item_id = filter_input(INPUT_POST, "item_id", FILTER_VALIDATE_INT);

        if (!$item_id || !isset($_FILES["proof_file"])) {
            $message = "Please select an order item and a design proof file.";
            $message_type = "error";

        } elseif ($_FILES["proof_file"]["error"] !== UPLOAD_ERR_OK) {
            $message = "The file could not be uploaded. Please try again.";
            $message_type = "error";

        } elseif ($_FILES["proof_file"]["size"] > 5 * 1024 * 1024) {
            $message = "The file must not exceed 5 MB.";
            $message_type = "error";

        } else {

            // Confirm that the order item belongs to an order
            // that has an accepted quotation
            $item_check = $conn->prepare("
                SELECT oi.item_id
                FROM order_items oi
                INNER JOIN orders o ON oi.order_id = o.order_id
                INNER JOIN quotations q ON o.order_id = q.order_id
                WHERE oi.item_id = ?
                AND q.status = 'Accepted'
                AND o.order_status = 'Design Approval'
            ");

            $item_check->bind_param("i", $item_id);
            $item_check->execute();

            $item_result = $item_check->get_result();

            if ($item_result->num_rows === 0) {
                $message = "This order item is not eligible for design proof upload.";
                $message_type = "error";

            } else {

                $file_tmp = $_FILES["proof_file"]["tmp_name"];

                // Check actual file type
                $finfo = new finfo(FILEINFO_MIME_TYPE);
                $mime_type = $finfo->file($file_tmp);

                $allowed_types = [
                    "image/jpeg" => "jpg",
                    "image/png" => "png",
                    "application/pdf" => "pdf"
                ];

                if (!isset($allowed_types[$mime_type])) {
                    $message = "Only JPG, PNG, and PDF files are allowed.";
                    $message_type = "error";

                } else {

                    $upload_directory = "../uploads/proofs/";

                    if (!is_dir($upload_directory) || !is_writable($upload_directory)) {
                        $message = "The uploads/proofs folder is missing or is not writable.";
                        $message_type = "error";

                    } else {

                        // Generate the next version number
                        $version_stmt = $conn->prepare("
                            SELECT COALESCE(MAX(version_no), 0) + 1 AS next_version
                            FROM design_proofs
                            WHERE item_id = ?
                        ");

                        $version_stmt->bind_param("i", $item_id);
                        $version_stmt->execute();

                        $version_result = $version_stmt->get_result();
                        $version_row = $version_result->fetch_assoc();

                        $version_no = (int)$version_row["next_version"];

                        $extension = $allowed_types[$mime_type];

                        $file_name = "proof_" . $item_id . "_v" .
                            $version_no . "_" . bin2hex(random_bytes(8)) .
                            "." . $extension;

                        $file_path = "uploads/proofs/" . $file_name;
                        $destination = $upload_directory . $file_name;

                        if (move_uploaded_file($file_tmp, $destination)) {

                            $upload_stmt = $conn->prepare("
                                INSERT INTO design_proofs
                                (item_id, file_path, version_no, approval_status, date_submitted)
                                VALUES (?, ?, ?, 'Pending', NOW())
                            ");

                            $upload_stmt->bind_param(
                                "isi",
                                $item_id,
                                $file_path,
                                $version_no
                            );

                            if ($upload_stmt->execute()) {
                                $message = "Design proof uploaded successfully as Version " . $version_no . ".";
                                $message_type = "success";
                            } else {

                                // Remove uploaded file if database insert fails
                                if (file_exists($destination)) {
                                    unlink($destination);
                                }

                                $message = "The file was uploaded, but its record could not be saved.";
                                $message_type = "error";
                            }

                            $upload_stmt->close();

                        } else {
                            $message = "Unable to save the uploaded file.";
                            $message_type = "error";
                        }

                        $version_stmt->close();
                    }
                }
            }

            $item_check->close();
        }
    }
}

// Get order items eligible for design proof upload
$items = $conn->query("
    SELECT
        oi.item_id,
        oi.order_id,
        p.product_name,
        c.full_name
    FROM order_items oi
    INNER JOIN orders o ON oi.order_id = o.order_id
    INNER JOIN customers c ON o.customer_id = c.customer_id
    INNER JOIN products p ON oi.product_id = p.product_id
    INNER JOIN quotations q ON o.order_id = q.order_id
    WHERE q.status = 'Accepted'
    AND o.order_status = 'Design Approval'
    GROUP BY oi.item_id, oi.order_id, p.product_name, c.full_name
    ORDER BY oi.order_id DESC
");

// Get uploaded design proofs
$proofs = $conn->query("
    SELECT
        dp.proof_id,
        dp.item_id,
        dp.file_path,
        dp.version_no,
        dp.approval_status,
        dp.date_submitted,
        dp.customer_remarks,
        oi.order_id,
        p.product_name,
        c.full_name
    FROM design_proofs dp
    INNER JOIN order_items oi ON dp.item_id = oi.item_id
    INNER JOIN products p ON oi.product_id = p.product_id
    INNER JOIN orders o ON oi.order_id = o.order_id
    INNER JOIN customers c ON o.customer_id = c.customer_id
    ORDER BY dp.proof_id DESC
");
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Design Proofs | PrintFlow Pro</title>

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
        select:focus,
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
                        href="../login.php?logout=1"
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
                    class="flex items-center gap-2 bg-slate-100 hover:bg-indigo-50 hover:text-indigo-700 px-4 py-3 rounded-xl text-sm font-semibold transition"
                >
                    <i data-lucide="clipboard-list" class="w-5 h-5"></i>
                    Orders
                </a>

                <a
                    href="design_proofs.php"
                    aria-current="page"
                    class="flex items-center gap-2 bg-indigo-600 text-white px-4 py-3 rounded-xl text-sm font-semibold"
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

        <!-- Page Heading -->
        <section class="mb-8">

            <h2 class="text-2xl sm:text-3xl font-bold text-slate-900">
                Design Proof Management
            </h2>

            <p class="text-base text-slate-600 mt-2">
                Upload design proofs for customers to review and approve.
            </p>

        </section>

        <!-- Feedback Message -->
        <?php if ($message !== ""): ?>

            <div
                class="mb-6 p-4 rounded-xl border
                <?= $message_type === "success"
                    ? "bg-green-50 border-green-200 text-green-800"
                    : "bg-red-50 border-red-200 text-red-800" ?>"
                role="status"
            >
                <?= htmlspecialchars($message); ?>
            </div>

        <?php endif; ?>

        <!-- Upload Form -->
        <section class="bg-white border border-slate-200 rounded-2xl shadow-sm p-6 md:p-8 mb-8">

            <div class="flex items-center gap-3 mb-2">

                <div class="w-11 h-11 bg-indigo-50 text-indigo-600 rounded-xl flex items-center justify-center">
                    <i data-lucide="upload" class="w-6 h-6"></i>
                </div>

                <h3 class="text-xl font-bold text-slate-900">
                    Upload a Design Proof
                </h3>

            </div>

            <p class="text-sm text-slate-500 mb-6">
                Select an accepted order and upload its design file.
                Allowed formats: JPG, PNG, and PDF. Maximum file size: 5 MB.
            </p>

            <form method="POST" enctype="multipart/form-data">

                <input
                    type="hidden"
                    name="csrf_token"
                    value="<?= htmlspecialchars($_SESSION["csrf_token"]); ?>"
                >

                <div class="mb-5">

                    <label for="item_id" class="block text-sm font-bold text-slate-800 mb-2">
                        Select Order Item *
                    </label>

                    <select
                        name="item_id"
                        id="item_id"
                        required
                        class="w-full border border-slate-300 rounded-xl p-3 bg-white text-slate-800"
                    >
                        <option value="">Choose an order item</option>

                        <?php if ($items && $items->num_rows > 0): ?>

                            <?php while ($item = $items->fetch_assoc()): ?>

                                <option value="<?= (int)$item["item_id"]; ?>">
                                    Order #<?= (int)$item["order_id"]; ?>
                                    - <?= htmlspecialchars($item["full_name"]); ?>
                                    - <?= htmlspecialchars($item["product_name"]); ?>
                                </option>

                            <?php endwhile; ?>

                        <?php endif; ?>

                    </select>

                    <?php if (!$items || $items->num_rows === 0): ?>

                        <p class="text-sm text-slate-500 mt-2">
                            No accepted orders are currently waiting for design proofs.
                        </p>

                    <?php endif; ?>

                </div>

                <div class="mb-6">

                    <label for="proof_file" class="block text-sm font-bold text-slate-800 mb-2">
                        Design Proof File *
                    </label>

                    <input
                        type="file"
                        name="proof_file"
                        id="proof_file"
                        accept=".jpg,.jpeg,.png,.pdf"
                        required
                        class="w-full border border-slate-300 rounded-xl p-3 bg-white"
                    >

                </div>

                <button
                    type="submit"
                    class="inline-flex items-center gap-2 bg-indigo-600 hover:bg-indigo-700 text-white font-semibold px-6 py-3 rounded-xl transition"
                >
                    <i data-lucide="upload" class="w-5 h-5"></i>
                    Upload Design Proof
                </button>

            </form>

        </section>

        <!-- Uploaded Proofs -->
        <section class="bg-white border border-slate-200 rounded-2xl shadow-sm overflow-hidden">

            <div class="p-5 border-b border-slate-200">

                <div class="flex items-center gap-3">

                    <div class="w-11 h-11 bg-indigo-50 text-indigo-600 rounded-xl flex items-center justify-center">
                        <i data-lucide="files" class="w-6 h-6"></i>
                    </div>

                    <div>
                        <h3 class="text-lg font-bold text-slate-900">
                            Uploaded Design Proofs
                        </h3>

                        <p class="text-sm text-slate-600 mt-1">
                            View the uploaded versions and their customer approval status.
                        </p>
                    </div>

                </div>

            </div>

            <div class="overflow-x-auto">

                <table class="w-full text-left">

                    <thead class="bg-slate-50 border-b border-slate-200">

                        <tr class="text-sm text-slate-600">

                            <th scope="col" class="px-5 py-4">Proof ID</th>
                            <th scope="col" class="px-5 py-4">Order</th>
                            <th scope="col" class="px-5 py-4">Customer</th>
                            <th scope="col" class="px-5 py-4">Product</th>
                            <th scope="col" class="px-5 py-4">Version</th>
                            <th scope="col" class="px-5 py-4">Submitted</th>
                            <th scope="col" class="px-5 py-4">Approval Status</th>
                            <th scope="col" class="px-5 py-4">File</th>

                        </tr>

                    </thead>

                    <tbody class="divide-y divide-slate-100">

                        <?php if ($proofs && $proofs->num_rows > 0): ?>

                            <?php while ($proof = $proofs->fetch_assoc()): ?>

                                <tr class="hover:bg-slate-50">

                                    <td class="px-5 py-4 font-bold text-indigo-700 whitespace-nowrap">
                                        #<?= (int)$proof["proof_id"]; ?>
                                    </td>

                                    <td class="px-5 py-4 whitespace-nowrap">
                                        #<?= (int)$proof["order_id"]; ?>
                                    </td>

                                    <td class="px-5 py-4">
                                        <?= htmlspecialchars($proof["full_name"]); ?>
                                    </td>

                                    <td class="px-5 py-4">
                                        <?= htmlspecialchars($proof["product_name"]); ?>
                                    </td>

                                    <td class="px-5 py-4 whitespace-nowrap">
                                        Version <?= (int)$proof["version_no"]; ?>
                                    </td>

                                    <td class="px-5 py-4 whitespace-nowrap text-slate-600">
                                        <?= date("M d, Y", strtotime($proof["date_submitted"])); ?>
                                    </td>

                                    <td class="px-5 py-4">

                                        <?php
                                        $status_class = "bg-amber-100 text-amber-800";

                                        if ($proof["approval_status"] === "Approved") {
                                            $status_class = "bg-emerald-100 text-emerald-800";
                                        } elseif ($proof["approval_status"] === "Revision Requested") {
                                            $status_class = "bg-blue-100 text-blue-800";
                                        }
                                        ?>

                                        <span class="inline-block px-3 py-2 rounded-lg text-sm font-semibold whitespace-nowrap <?= $status_class; ?>">
                                            <?= htmlspecialchars($proof["approval_status"]); ?>
                                        </span>

                                    </td>

                                    <td class="px-5 py-4 whitespace-nowrap">

                                        <a
                                            href="../<?= htmlspecialchars($proof["file_path"]); ?>"
                                            target="_blank"
                                            rel="noopener noreferrer"
                                            class="inline-flex items-center gap-2 text-indigo-700 hover:text-indigo-900 font-semibold"
                                        >
                                            <i data-lucide="external-link" class="w-4 h-4"></i>
                                            View File
                                        </a>

                                    </td>

                                </tr>

                                <?php if (!empty($proof["customer_remarks"])): ?>

                                    <tr>
                                        <td colspan="8" class="px-5 pb-4 text-slate-600 bg-slate-50">
                                            <strong>Customer remarks:</strong>
                                            <?= htmlspecialchars($proof["customer_remarks"]); ?>
                                        </td>
                                    </tr>

                                <?php endif; ?>

                            <?php endwhile; ?>

                        <?php else: ?>

                            <tr>
                                <td colspan="8" class="px-5 py-12 text-center">

                                    <div class="w-14 h-14 bg-indigo-50 text-indigo-600 rounded-xl flex items-center justify-center mx-auto mb-4">
                                        <i data-lucide="files" class="w-7 h-7"></i>
                                    </div>

                                    <h4 class="font-semibold text-lg text-slate-800">
                                        No Design Proofs Yet
                                    </h4>

                                    <p class="text-sm text-slate-500 mt-2">
                                        Uploaded design proofs will appear here.
                                    </p>

                                </td>
                            </tr>

                        <?php endif; ?>

                    </tbody>

                </table>

            </div>

        </section>

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
