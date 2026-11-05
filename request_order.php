<?php
session_start();

require_once "../config/database.php";

// Check if the customer is logged in
if (!isset($_SESSION["customer_id"]) || ($_SESSION["user_role"] ?? "") !== "customer") {
    header("Location: ../login.php");
    exit;
}

// Create CSRF token
if (empty($_SESSION["csrf_token"])) {
    $_SESSION["csrf_token"] = bin2hex(random_bytes(32));
}

$message = "";
$message_type = "";

// Get available products.
// COALESCE treats NULL availability as available, matching admin/products.php.
$products = $conn->query("
    SELECT product_id, product_name, description
    FROM products
    WHERE COALESCE(is_available, 1) = 1
    ORDER BY product_name ASC
");

// Process order request
if ($_SERVER["REQUEST_METHOD"] === "POST") {

    if (
        !isset($_POST["csrf_token"]) ||
        !hash_equals($_SESSION["csrf_token"], $_POST["csrf_token"])
    ) {
        $message = "Invalid request. Please refresh the page and try again.";
        $message_type = "error";

    } else {

        $product_id = filter_input(INPUT_POST, "product_id", FILTER_VALIDATE_INT);
        $size = trim($_POST["size"] ?? "");
        $color = trim($_POST["color"] ?? "");
        $material = trim($_POST["material"] ?? "");
        $quantity = filter_input(INPUT_POST, "quantity", FILTER_VALIDATE_INT);
        $design_notes = trim($_POST["design_notes"] ?? "");
        $due_date = trim($_POST["due_date"] ?? "");

        // Validate required fields
        if (!$product_id || !$quantity || $quantity < 1 || $quantity > 999) {
            $message = "Please select a product and enter a valid quantity.";
            $message_type = "error";

        } elseif (
            strlen($size) > 100 ||
            strlen($color) > 100 ||
            strlen($material) > 100
        ) {
            $message = "Size, color, and material details must not exceed 100 characters.";
            $message_type = "error";

        } elseif (strlen($design_notes) > 2000) {
            $message = "Design instructions must not exceed 2,000 characters.";
            $message_type = "error";

        } elseif (
            $due_date !== "" &&
            (
                !DateTime::createFromFormat("Y-m-d", $due_date) ||
                DateTime::createFromFormat("Y-m-d", $due_date)->format("Y-m-d") !== $due_date ||
                $due_date < date("Y-m-d")
            )
        ) {
            $message = "Please enter a valid due date that is not in the past.";
            $message_type = "error";

        } else {

            // Confirm that the selected product is still available.
            // This prevents submission of a product deactivated after the page was opened.
            $product_check = $conn->prepare("
                SELECT product_id
                FROM products
                WHERE product_id = ?
                  AND COALESCE(is_available, 1) = 1
            ");

            if (!$product_check) {
                $message = "Unable to verify product availability. Please try again.";
                $message_type = "error";
            } else {
                $product_check->bind_param("i", $product_id);
                $product_check->execute();
                $product_result = $product_check->get_result();

                if ($product_result->num_rows === 0) {
                    $message = "The selected product is unavailable. Please choose another product.";
                    $message_type = "error";

                } else {

                    try {
                        $conn->begin_transaction();

                        // Create the order
                        $order_stmt = $conn->prepare("
                            INSERT INTO orders
                            (customer_id, order_date, due_date, order_status, total_amount)
                            VALUES (?, NOW(), ?, 'Pending', 0.00)
                        ");

                        if (!$order_stmt) {
                            throw new Exception("Unable to prepare order.");
                        }

                        $customer_id = (int)$_SESSION["customer_id"];
                        $due_date_value = ($due_date === "") ? null : $due_date;

                        $order_stmt->bind_param("is", $customer_id, $due_date_value);
                        $order_stmt->execute();
                        $order_id = $conn->insert_id;
                        $order_stmt->close();

                        // Add the selected product to the order
                        $item_stmt = $conn->prepare("
                            INSERT INTO order_items
                            (order_id, product_id, size, color, material, quantity, design_notes)
                            VALUES (?, ?, ?, ?, ?, ?, ?)
                        ");

                        if (!$item_stmt) {
                            throw new Exception("Unable to prepare order item.");
                        }

                        $item_stmt->bind_param(
                            "iisssis",
                            $order_id,
                            $product_id,
                            $size,
                            $color,
                            $material,
                            $quantity,
                            $design_notes
                        );

                        $item_stmt->execute();
                        $item_stmt->close();

                        $conn->commit();

                        $message = "Your order request was submitted successfully! Your Order ID is #" . $order_id . ".";
                        $message_type = "success";

                        // Refresh product list
                        $products = $conn->query("
                            SELECT product_id, product_name, description
                            FROM products
                            WHERE COALESCE(is_available, 1) = 1
                            ORDER BY product_name ASC
                        ");

                    } catch (Throwable $e) {
                        $conn->rollback();

                        $message = "Something went wrong while submitting your order. Please try again.";
                        $message_type = "error";
                    }
                }

                $product_check->close();
            }
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Request an Order | PrintFlow</title>

    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">

    <style>
        body {
            font-family: 'Inter', sans-serif;
        }

        input:focus, select:focus, textarea:focus, a:focus, button:focus {
            outline: 3px solid #818cf8;
            outline-offset: 2px;
        }
    </style>
</head>

<body class="bg-slate-100 min-h-screen text-slate-800">

    <!-- Navigation -->
    <nav class="bg-white border-b border-slate-200">
        <div class="max-w-6xl mx-auto px-5 py-4 flex flex-wrap justify-between items-center gap-4">

            <a href="dashboard.php" class="text-xl font-extrabold text-indigo-700">
                PrintFlow
            </a>

            <div class="flex flex-wrap gap-5 text-sm font-semibold">
                <a href="dashboard.php" class="hover:text-indigo-700">Dashboard</a>
                <a href="orders.php" class="hover:text-indigo-700">My Orders</a>
                <a href="feedback.php" class="hover:text-indigo-700">Feedback</a>
            </div>

        </div>
    </nav>

    <main class="max-w-3xl mx-auto px-5 py-10">

        <div class="mb-8">
            <a href="dashboard.php" class="text-sm font-semibold text-indigo-700 hover:underline">
                ← Back to Dashboard
            </a>

            <h1 class="text-3xl font-extrabold mt-4">Request a Custom Order</h1>

            <p class="text-slate-600 mt-2">
                Tell us what you need. Our staff will review your request and prepare a quotation.
            </p>
        </div>

        <!-- Feedback message -->
        <?php if ($message !== ""): ?>
            <div class="mb-6 p-4 rounded-xl border
                <?php echo $message_type === "success"
                    ? "bg-green-50 border-green-200 text-green-800"
                    : "bg-red-50 border-red-200 text-red-800"; ?>"
                role="status">

                <?php echo htmlspecialchars($message); ?>

                <?php if ($message_type === "success"): ?>
                    <p class="mt-2">
                        <a href="orders.php" class="font-bold underline">
                            View My Orders
                        </a>
                    </p>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <!-- Order form -->
        <section class="bg-white rounded-2xl shadow-sm border border-slate-200 p-6 md:p-8">

            <form method="POST" action="">

                <input
                    type="hidden"
                    name="csrf_token"
                    value="<?php echo htmlspecialchars($_SESSION["csrf_token"]); ?>"
                >

                <div class="mb-6">
                    <label for="product_id" class="block font-bold mb-2">
                        Select Product *
                    </label>

                    <select
                        id="product_id"
                        name="product_id"
                        required
                        class="w-full border border-slate-300 rounded-lg p-3 bg-white"
                    >
                        <option value="">Choose a product</option>

                        <?php if ($products && $products->num_rows > 0): ?>
                            <?php while ($product = $products->fetch_assoc()): ?>
                                <option value="<?php echo (int)$product["product_id"]; ?>"
                                    <?php echo (isset($_POST["product_id"]) && $_POST["product_id"] == $product["product_id"]) ? "selected" : ""; ?>>

                                    <?php echo htmlspecialchars($product["product_name"]); ?>

                                </option>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <option value="" disabled>No products are currently available</option>
                        <?php endif; ?>
                    </select>

                    <p class="text-sm text-slate-500 mt-2">
                        The final price will be provided through your quotation.
                    </p>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-5 mb-6">

                    <div>
                        <label for="size" class="block font-bold mb-2">
                            Size
                        </label>

                        <input
                            type="text"
                            id="size"
                            name="size"
                            maxlength="100"
                            placeholder="Example: Small, Medium, Large"
                            value="<?php echo htmlspecialchars($_POST["size"] ?? ""); ?>"
                            class="w-full border border-slate-300 rounded-lg p-3"
                        >
                    </div>

                    <div>
                        <label for="color" class="block font-bold mb-2">
                            Color
                        </label>

                        <input
                            type="text"
                            id="color"
                            name="color"
                            maxlength="100"
                            placeholder="Example: Black, White, Blue"
                            value="<?php echo htmlspecialchars($_POST["color"] ?? ""); ?>"
                            class="w-full border border-slate-300 rounded-lg p-3"
                        >
                    </div>

                    <div>
                        <label for="material" class="block font-bold mb-2">
                            Material
                        </label>

                        <input
                            type="text"
                            id="material"
                            name="material"
                            maxlength="100"
                            placeholder="Preferred material"
                            value="<?php echo htmlspecialchars($_POST["material"] ?? ""); ?>"
                            class="w-full border border-slate-300 rounded-lg p-3"
                        >
                    </div>

                    <div>
                        <label for="quantity" class="block font-bold mb-2">
                            Quantity *
                        </label>

                        <input
                            type="number"
                            id="quantity"
                            name="quantity"
                            min="1"
                            max="999"
                            required
                            value="<?php echo htmlspecialchars($_POST["quantity"] ?? "1"); ?>"
                            class="w-full border border-slate-300 rounded-lg p-3"
                        >
                    </div>

                </div>

                <div class="mb-6">
                    <label for="due_date" class="block font-bold mb-2">
                        Preferred Due Date
                    </label>

                    <input
                        type="date"
                        id="due_date"
                        name="due_date"
                        min="<?php echo date("Y-m-d"); ?>"
                        value="<?php echo htmlspecialchars($_POST["due_date"] ?? ""); ?>"
                        class="w-full border border-slate-300 rounded-lg p-3"
                    >

                    <p class="text-sm text-slate-500 mt-2">
                        This is your preferred date. The staff will confirm the schedule.
                    </p>
                </div>

                <div class="mb-8">
                    <label for="design_notes" class="block font-bold mb-2">
                        Design Instructions or Special Requests
                    </label>

                    <textarea
                        id="design_notes"
                        name="design_notes"
                        rows="5"
                        maxlength="2000"
                        placeholder="Describe your preferred design, text, logo, or other requirements."
                        class="w-full border border-slate-300 rounded-lg p-3 resize-y"
                    ><?php echo htmlspecialchars($_POST["design_notes"] ?? ""); ?></textarea>
                </div>

                <button
                    type="submit"
                    <?php echo (!$products || $products->num_rows === 0) ? "disabled" : ""; ?>
                    class="w-full bg-indigo-600 hover:bg-indigo-700 disabled:bg-slate-400 disabled:cursor-not-allowed text-white font-bold py-4 rounded-lg"
                >
                    Submit Order Request
                </button>

            </form>

        </section>

        <p class="text-sm text-slate-500 text-center mt-6">
            Your order request will be reviewed by our staff before the quotation is prepared.
        </p>

    </main>

</body>
</html>
