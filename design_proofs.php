
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

    <title>Design Proofs | PrintFlow</title>

    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">

    <style>
        body {
            font-family: 'Inter', sans-serif;
        }

        a:focus, input:focus, select:focus, button:focus {
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
                <a href="quotations.php" class="hover:text-indigo-700">Quotations</a>
                <a href="design_proofs.php" class="text-indigo-700">Design Proofs</a>
            </div>

        </div>
    </nav>

    <main class="max-w-7xl mx-auto px-5 py-10">

        <div class="mb-8">
            <a href="dashboard.php" class="text-sm font-semibold text-indigo-700 hover:underline">
                ← Back to Dashboard
            </a>

            <h1 class="text-3xl font-extrabold mt-4">Design Proof Management</h1>

            <p class="text-slate-600 mt-2">
                Upload design proofs for customers to review and approve.
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

        <!-- Upload form -->
        <section class="bg-white rounded-2xl border border-slate-200 shadow-sm p-6 md:p-8 mb-8">

            <h2 class="text-xl font-bold mb-2">Upload a Design Proof</h2>

            <p class="text-sm text-slate-500 mb-6">
                Select an accepted order and upload its design file.
                Allowed formats: JPG, PNG, and PDF. Maximum file size: 5 MB.
            </p>

            <form method="POST" enctype="multipart/form-data">

                <input
                    type="hidden"
                    name="csrf_token"
                    value="<?php echo htmlspecialchars($_SESSION["csrf_token"]); ?>"
                >

                <div class="mb-5">
                    <label for="item_id" class="block font-bold mb-2">
                        Select Order Item *
                    </label>

                    <select
                        name="item_id"
                        id="item_id"
                        required
                        class="w-full border border-slate-300 rounded-lg p-3 bg-white"
                    >
                        <option value="">Choose an order item</option>

                        <?php if ($items && $items->num_rows > 0): ?>
                            <?php while ($item = $items->fetch_assoc()): ?>

                                <option value="<?php echo $item["item_id"]; ?>">
                                    Order #<?php echo $item["order_id"]; ?>
                                    - <?php echo htmlspecialchars($item["full_name"]); ?>
                                    - <?php echo htmlspecialchars($item["product_name"]); ?>
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
                    <label for="proof_file" class="block font-bold mb-2">
                        Design Proof File *
                    </label>

                    <input
                        type="file"
                        name="proof_file"
                        id="proof_file"
                        accept=".jpg,.jpeg,.png,.pdf"
                        required
                        class="w-full border border-slate-300 rounded-lg p-3"
                    >
                </div>

                <button
                    type="submit"
                    class="bg-indigo-600 hover:bg-indigo-700 text-white font-bold px-6 py-3 rounded-lg"
                >
                    Upload Design Proof
                </button>

            </form>

        </section>

        <!-- Uploaded proofs -->
        <section class="bg-white rounded-2xl border border-slate-200 shadow-sm overflow-hidden">

            <div class="p-6 border-b border-slate-200">
                <h2 class="text-xl font-bold">Uploaded Design Proofs</h2>
                <p class="text-sm text-slate-500 mt-1">
                    View the uploaded versions and their customer approval status.
                </p>
            </div>

            <div class="overflow-x-auto">

                <table class="w-full text-sm text-left">

                    <thead class="bg-slate-50 text-slate-600">
                        <tr>
                            <th class="p-4">Proof ID</th>
                            <th class="p-4">Order</th>
                            <th class="p-4">Customer</th>
                            <th class="p-4">Product</th>
                            <th class="p-4">Version</th>
                            <th class="p-4">Submitted</th>
                            <th class="p-4">Approval Status</th>
                            <th class="p-4">File</th>
                        </tr>
                    </thead>

                    <tbody class="divide-y divide-slate-100">

                        <?php if ($proofs && $proofs->num_rows > 0): ?>

                            <?php while ($proof = $proofs->fetch_assoc()): ?>

                                <tr class="hover:bg-slate-50">

                                    <td class="p-4 font-bold">
                                        #<?php echo $proof["proof_id"]; ?>
                                    </td>

                                    <td class="p-4">
                                        #<?php echo $proof["order_id"]; ?>
                                    </td>

                                    <td class="p-4">
                                        <?php echo htmlspecialchars($proof["full_name"]); ?>
                                    </td>

                                    <td class="p-4">
                                        <?php echo htmlspecialchars($proof["product_name"]); ?>
                                    </td>

                                    <td class="p-4">
                                        Version <?php echo $proof["version_no"]; ?>
                                    </td>

                                    <td class="p-4">
                                        <?php echo date("M d, Y", strtotime($proof["date_submitted"])); ?>
                                    </td>

                                    <td class="p-4">
                                        <?php
                                        $status_class = "bg-yellow-100 text-yellow-800";

                                        if ($proof["approval_status"] === "Approved") {
                                            $status_class = "bg-green-100 text-green-800";
                                        } elseif ($proof["approval_status"] === "Revision Requested") {
                                            $status_class = "bg-blue-100 text-blue-800";
                                        }
                                        ?>

                                        <span class="px-3 py-1 rounded-full text-xs font-bold <?php echo $status_class; ?>">
                                            <?php echo htmlspecialchars($proof["approval_status"]); ?>
                                        </span>
                                    </td>

                                    <td class="p-4">
                                        <a
                                            href="../<?php echo htmlspecialchars($proof["file_path"]); ?>"
                                            target="_blank"
                                            rel="noopener noreferrer"
                                            class="text-indigo-700 font-bold underline"
                                        >
                                            View File
                                        </a>
                                    </td>

                                </tr>

                                <?php if (!empty($proof["customer_remarks"])): ?>
                                    <tr>
                                        <td colspan="8" class="px-4 pb-4 text-slate-600">
                                            <strong>Customer remarks:</strong>
                                            <?php echo htmlspecialchars($proof["customer_remarks"]); ?>
                                        </td>
                                    </tr>
                                <?php endif; ?>

                            <?php endwhile; ?>

                        <?php else: ?>

                            <tr>
                                <td colspan="8" class="p-10 text-center text-slate-500">
                                    No design proofs have been uploaded yet.
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
