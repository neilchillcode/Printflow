<?php
session_start();
require_once "../config/database.php";

// Admin access only
if (
    !isset($_SESSION["user_id"]) ||
    !isset($_SESSION["user_role"]) ||
    strtolower($_SESSION["user_role"]) !== "admin"
) {
    header("Location: ../login.php");
    exit;
}

if (empty($_SESSION["csrf_token"])) {
    $_SESSION["csrf_token"] = bin2hex(random_bytes(32));
}

$csrf_token = $_SESSION["csrf_token"];
$message = "";
$message_type = "success";

// Handle product actions
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $submitted_token = $_POST["csrf_token"] ?? "";

    if (!hash_equals($csrf_token, $submitted_token)) {
        $message = "Your session token is invalid. Please refresh the page and try again.";
        $message_type = "error";
    } else {
        $action = $_POST["action"] ?? "";

        if ($action === "save") {
            $product_id = (int)($_POST["product_id"] ?? 0);
            $product_name = trim($_POST["product_name"] ?? "");
            $description = trim($_POST["description"] ?? "");
            $base_price_input = trim($_POST["base_price"] ?? "");

            if ($product_name === "") {
                $message = "Please enter a product name.";
                $message_type = "error";
            } elseif (strlen($product_name) > 100) {
                $message = "Product name must not exceed 100 characters.";
                $message_type = "error";
            } elseif ($base_price_input === "" || !is_numeric($base_price_input) || (float)$base_price_input < 0) {
                $message = "Please enter a valid price of zero or higher.";
                $message_type = "error";
            } else {
                $base_price = (float)$base_price_input;

                if ($product_id > 0) {
                    $stmt = $conn->prepare(
                        "UPDATE products
                         SET product_name = ?, description = ?, base_price = ?
                         WHERE product_id = ?"
                    );

                    if ($stmt) {
                        $stmt->bind_param("ssdi", $product_name, $description, $base_price, $product_id);

                        if ($stmt->execute() && $stmt->affected_rows >= 0) {
                            $message = "Product details updated successfully.";
                        } else {
                            $message = "Unable to update the product. Please try again.";
                            $message_type = "error";
                        }

                        $stmt->close();
                    } else {
                        $message = "Unable to prepare the product update.";
                        $message_type = "error";
                    }
                } else {
                    $stmt = $conn->prepare(
                        "INSERT INTO products (product_name, description, base_price, is_available)
                         VALUES (?, ?, ?, 1)"
                    );

                    if ($stmt) {
                        $stmt->bind_param("ssd", $product_name, $description, $base_price);

                        if ($stmt->execute()) {
                            $message = "Product added successfully.";
                        } else {
                            $message = "Unable to add the product. Please try again.";
                            $message_type = "error";
                        }

                        $stmt->close();
                    } else {
                        $message = "Unable to prepare the product record.";
                        $message_type = "error";
                    }
                }
            }
        } elseif ($action === "toggle") {
            $product_id = (int)($_POST["product_id"] ?? 0);

            if ($product_id <= 0) {
                $message = "Invalid product selected.";
                $message_type = "error";
            } else {
                $stmt = $conn->prepare(
                    "UPDATE products
                     SET is_available = CASE
                        WHEN COALESCE(is_available, 1) = 1 THEN 0
                        ELSE 1
                     END
                     WHERE product_id = ?"
                );

                if ($stmt) {
                    $stmt->bind_param("i", $product_id);

                    if ($stmt->execute() && $stmt->affected_rows > 0) {
                        $message = "Product availability updated successfully.";
                    } else {
                        $message = "Product was not found or its availability could not be changed.";
                        $message_type = "error";
                    }

                    $stmt->close();
                } else {
                    $message = "Unable to prepare the availability update.";
                    $message_type = "error";
                }
            }
        } else {
            $message = "Invalid action.";
            $message_type = "error";
        }
    }
}

// Load product to edit, if requested
$edit_product = null;
$edit_id = isset($_GET["edit"]) ? (int)$_GET["edit"] : 0;

if ($edit_id > 0) {
    $stmt = $conn->prepare(
        "SELECT product_id, product_name, description, base_price, is_available
         FROM products
         WHERE product_id = ?"
    );

    if ($stmt) {
        $stmt->bind_param("i", $edit_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $edit_product = $result->fetch_assoc();
        $stmt->close();
    }
}

// Search and filter
$search = trim($_GET["search"] ?? "");
$filter = $_GET["filter"] ?? "all";

$sql = "SELECT product_id, product_name, description, base_price, is_available
        FROM products
        WHERE 1 = 1";

$params = [];
$types = "";

if ($search !== "") {
    $sql .= " AND (product_name LIKE ? OR description LIKE ?)";
    $search_term = "%" . $search . "%";
    $params[] = $search_term;
    $params[] = $search_term;
    $types .= "ss";
}

if ($filter === "available") {
    $sql .= " AND COALESCE(is_available, 1) = 1";
} elseif ($filter === "unavailable") {
    $sql .= " AND COALESCE(is_available, 1) = 0";
}

$sql .= " ORDER BY product_name ASC";

$product_result = false;
$stmt = $conn->prepare($sql);

if ($stmt) {
    if (!empty($params)) {
        $stmt->bind_param($types, ...$params);
    }

    if ($stmt->execute()) {
        $product_result = $stmt->get_result();
    }

    // Keep statement open while result rows are rendered.
}

$admin_name = $_SESSION["user_name"] ?? "Administrator";
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Product Management | PrintFlow Pro</title>

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
        button:focus,
        input:focus,
        textarea:focus,
        select:focus {
            outline: 3px solid #818cf8;
            outline-offset: 2px;
        }
    </style>
</head>

<body class="bg-slate-100 text-slate-800 min-h-screen">

<header class="bg-white border-b border-slate-200 shadow-sm">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 py-4">

        <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">

            <div class="flex items-center gap-3">
                <div class="w-11 h-11 bg-indigo-600 rounded-xl flex items-center justify-center text-white">
                    <i data-lucide="printer" class="w-6 h-6"></i>
                </div>

                <div>
                    <h1 class="font-bold text-lg text-slate-900">PrintFlow Pro</h1>
                    <p class="text-sm text-slate-500">Order Management & CRM</p>
                </div>
            </div>

            <div class="flex items-center justify-between sm:justify-end gap-4">
                <div class="text-left sm:text-right">
                    <p class="font-semibold text-sm"><?= htmlspecialchars($admin_name) ?></p>
                    <p class="text-sm text-emerald-600">Administrator</p>
                </div>

                <a
                    href="dashboard.php?logout=1"
                    class="flex items-center gap-2 bg-slate-100 hover:bg-red-50 hover:text-red-600 px-4 py-3 rounded-xl text-sm font-semibold transition"
                    aria-label="Log out of PrintFlow"
                >
                    <i data-lucide="log-out" class="w-5 h-5"></i>
                    Logout
                </a>
            </div>

        </div>

        <nav class="flex flex-wrap gap-2 mt-5" aria-label="Admin main navigation">

            <a href="dashboard.php"
               class="flex items-center gap-2 bg-slate-100 hover:bg-indigo-50 hover:text-indigo-700 px-4 py-3 rounded-xl text-sm font-semibold transition">
                <i data-lucide="layout-dashboard" class="w-5 h-5"></i>
                Dashboard
            </a>

            <a href="orders.php"
               class="flex items-center gap-2 bg-slate-100 hover:bg-indigo-50 hover:text-indigo-700 px-4 py-3 rounded-xl text-sm font-semibold transition">
                <i data-lucide="clipboard-list" class="w-5 h-5"></i>
                Orders
            </a>

            <a href="customers.php"
               class="flex items-center gap-2 bg-slate-100 hover:bg-indigo-50 hover:text-indigo-700 px-4 py-3 rounded-xl text-sm font-semibold transition">
                <i data-lucide="contact" class="w-5 h-5"></i>
                Customers
            </a>

            <a href="products.php" aria-current="page"
               class="flex items-center gap-2 bg-indigo-600 text-white px-4 py-3 rounded-xl text-sm font-semibold">
                <i data-lucide="package" class="w-5 h-5"></i>
                Products
            </a>

            <a href="users.php"
               class="flex items-center gap-2 bg-slate-100 hover:bg-indigo-50 hover:text-indigo-700 px-4 py-3 rounded-xl text-sm font-semibold transition">
                <i data-lucide="users" class="w-5 h-5"></i>
                Users
            </a>

            <a href="reports.php"
               class="flex items-center gap-2 bg-slate-100 hover:bg-indigo-50 hover:text-indigo-700 px-4 py-3 rounded-xl text-sm font-semibold transition">
                <i data-lucide="chart-no-axes-combined" class="w-5 h-5"></i>
                Reports
            </a>

        </nav>
    </div>
</header>


<main class="max-w-7xl mx-auto px-4 sm:px-6 py-8">

    <section class="mb-8">
        <h2 class="text-2xl font-bold text-slate-900">Product Management</h2>
        <p class="text-base text-slate-600 mt-2">
            Add, update, search, and manage the availability of printing and merchandise products.
        </p>
    </section>

    <?php if ($message !== ""): ?>
        <div class="mb-6 rounded-xl border px-4 py-3 <?= $message_type === "error"
            ? "bg-red-50 border-red-200 text-red-700"
            : "bg-emerald-50 border-emerald-200 text-emerald-700" ?>"
            role="alert">
            <?= htmlspecialchars($message) ?>
        </div>
    <?php endif; ?>


    <!-- Add / Edit Product Form -->
    <section class="bg-white border border-slate-200 rounded-2xl shadow-sm p-5 sm:p-6 mb-8">

        <div class="flex items-center gap-3 mb-5">
            <div class="w-10 h-10 bg-indigo-50 text-indigo-600 rounded-xl flex items-center justify-center">
                <i data-lucide="<?= $edit_product ? "edit" : "plus" ?>" class="w-5 h-5"></i>
            </div>

            <div>
                <h3 class="text-lg font-bold text-slate-900">
                    <?= $edit_product ? "Edit Product" : "Add New Product" ?>
                </h3>
                <p class="text-sm text-slate-500">
                    Enter the product information below.
                </p>
            </div>
        </div>

        <form method="POST" action="products.php<?= $edit_product ? "?edit=" . (int)$edit_product["product_id"] : "" ?>"
              class="grid grid-cols-1 md:grid-cols-2 gap-5">

            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
            <input type="hidden" name="action" value="save">
            <input type="hidden" name="product_id" value="<?= $edit_product ? (int)$edit_product["product_id"] : 0 ?>">

            <div class="md:col-span-2">
                <label for="product_name" class="block text-sm font-semibold text-slate-700 mb-2">
                    Product Name <span class="text-red-600">*</span>
                </label>
                <input
                    type="text"
                    id="product_name"
                    name="product_name"
                    maxlength="100"
                    required
                    value="<?= htmlspecialchars($edit_product["product_name"] ?? "") ?>"
                    placeholder="Example: T-Shirt Printing"
                    class="w-full border border-slate-300 rounded-xl px-4 py-3 focus:border-indigo-500"
                >
            </div>

            <div>
                <label for="base_price" class="block text-sm font-semibold text-slate-700 mb-2">
                    Base Price (₱) <span class="text-red-600">*</span>
                </label>
                <input
                    type="number"
                    id="base_price"
                    name="base_price"
                    min="0"
                    step="0.01"
                    required
                    value="<?= $edit_product ? htmlspecialchars((string)$edit_product["base_price"]) : "" ?>"
                    placeholder="0.00"
                    class="w-full border border-slate-300 rounded-xl px-4 py-3 focus:border-indigo-500"
                >
                <p class="text-xs text-slate-500 mt-1">
                    Enter the starting price for this product.
                </p>
            </div>

            <div class="md:col-span-2">
                <label for="description" class="block text-sm font-semibold text-slate-700 mb-2">
                    Description
                </label>
                <textarea
                    id="description"
                    name="description"
                    rows="3"
                    placeholder="Describe the product or available customization options."
                    class="w-full border border-slate-300 rounded-xl px-4 py-3 focus:border-indigo-500"
                ><?= htmlspecialchars($edit_product["description"] ?? "") ?></textarea>
            </div>

            <div class="md:col-span-2 flex flex-wrap gap-3">
                <button
                    type="submit"
                    class="inline-flex items-center gap-2 bg-indigo-600 hover:bg-indigo-700 text-white px-5 py-3 rounded-xl text-sm font-semibold transition"
                >
                    <i data-lucide="save" class="w-5 h-5"></i>
                    <?= $edit_product ? "Save Changes" : "Add Product" ?>
                </button>

                <?php if ($edit_product): ?>
                    <a
                        href="products.php"
                        class="inline-flex items-center gap-2 bg-slate-100 hover:bg-slate-200 text-slate-700 px-5 py-3 rounded-xl text-sm font-semibold transition"
                    >
                        Cancel
                    </a>
                <?php endif; ?>
            </div>

        </form>
    </section>


    <!-- Product List -->
    <section class="bg-white border border-slate-200 rounded-2xl shadow-sm overflow-hidden">

        <div class="p-5 border-b border-slate-200">
            <h3 class="text-lg font-bold text-slate-900">Products</h3>
            <p class="text-sm text-slate-600 mt-1">
                View and manage the products available in the system.
            </p>
        </div>

        <form method="GET" action="products.php"
              class="p-5 bg-slate-50 border-b border-slate-200 flex flex-col sm:flex-row gap-3">

            <div class="flex-1">
                <label for="search" class="sr-only">Search products</label>
                <input
                    type="search"
                    id="search"
                    name="search"
                    value="<?= htmlspecialchars($search) ?>"
                    placeholder="Search by product name or description..."
                    class="w-full border border-slate-300 rounded-xl px-4 py-3 bg-white"
                >
            </div>

            <div>
                <label for="filter" class="sr-only">Filter availability</label>
                <select id="filter" name="filter"
                        class="w-full sm:w-48 border border-slate-300 rounded-xl px-4 py-3 bg-white">
                    <option value="all" <?= $filter === "all" ? "selected" : "" ?>>All Products</option>
                    <option value="available" <?= $filter === "available" ? "selected" : "" ?>>Available</option>
                    <option value="unavailable" <?= $filter === "unavailable" ? "selected" : "" ?>>Unavailable</option>
                </select>
            </div>

            <button type="submit"
                    class="inline-flex items-center justify-center gap-2 bg-slate-800 hover:bg-slate-900 text-white px-5 py-3 rounded-xl text-sm font-semibold">
                <i data-lucide="search" class="w-4 h-4"></i>
                Search
            </button>

            <a href="products.php"
               class="inline-flex items-center justify-center bg-white hover:bg-slate-100 border border-slate-300 text-slate-700 px-5 py-3 rounded-xl text-sm font-semibold">
                Reset
            </a>

        </form>

        <?php if ($product_result && $product_result->num_rows > 0): ?>

            <div class="overflow-x-auto">
                <table class="w-full text-left">

                    <thead class="bg-slate-50 border-b border-slate-200">
                        <tr class="text-sm text-slate-600">
                            <th scope="col" class="px-5 py-4">Product</th>
                            <th scope="col" class="px-5 py-4">Description</th>
                            <th scope="col" class="px-5 py-4">Base Price</th>
                            <th scope="col" class="px-5 py-4">Availability</th>
                            <th scope="col" class="px-5 py-4">Actions</th>
                        </tr>
                    </thead>

                    <tbody class="divide-y divide-slate-100">

                        <?php while ($product = $product_result->fetch_assoc()): ?>

                            <?php $is_available = (int)($product["is_available"] ?? 1) === 1; ?>

                            <tr class="hover:bg-slate-50">

                                <td class="px-5 py-4">
                                    <p class="font-semibold text-slate-900">
                                        <?= htmlspecialchars($product["product_name"]) ?>
                                    </p>
                                    <p class="text-xs text-slate-500 mt-1">
                                        Product #<?= (int)$product["product_id"] ?>
                                    </p>
                                </td>

                                <td class="px-5 py-4 text-sm text-slate-600 min-w-56">
                                    <?= !empty($product["description"])
                                        ? nl2br(htmlspecialchars($product["description"]))
                                        : "No description provided." ?>
                                </td>

                                <td class="px-5 py-4 font-semibold text-slate-800 whitespace-nowrap">
                                    ₱<?= number_format((float)$product["base_price"], 2) ?>
                                </td>

                                <td class="px-5 py-4 whitespace-nowrap">
                                    <?php if ($is_available): ?>
                                        <span class="inline-flex items-center rounded-full bg-emerald-50 text-emerald-700 px-3 py-1 text-xs font-semibold">
                                            Available
                                        </span>
                                    <?php else: ?>
                                        <span class="inline-flex items-center rounded-full bg-slate-100 text-slate-600 px-3 py-1 text-xs font-semibold">
                                            Unavailable
                                        </span>
                                    <?php endif; ?>
                                </td>

                                <td class="px-5 py-4">
                                    <div class="flex flex-wrap gap-2">

                                        <a
                                            href="products.php?edit=<?= (int)$product["product_id"] ?>"
                                            class="inline-flex items-center gap-1 bg-indigo-50 hover:bg-indigo-100 text-indigo-700 px-3 py-2 rounded-lg text-xs font-semibold"
                                        >
                                            <i data-lucide="edit" class="w-4 h-4"></i>
                                            Edit
                                        </a>

                                        <form method="POST" action="products.php"
                                              onsubmit="return confirm('Are you sure you want to change this product availability?');">

                                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                                            <input type="hidden" name="action" value="toggle">
                                            <input type="hidden" name="product_id" value="<?= (int)$product["product_id"] ?>">

                                            <button
                                                type="submit"
                                                class="inline-flex items-center gap-1 <?= $is_available
                                                    ? "bg-amber-50 hover:bg-amber-100 text-amber-700"
                                                    : "bg-emerald-50 hover:bg-emerald-100 text-emerald-700" ?> px-3 py-2 rounded-lg text-xs font-semibold"
                                            >
                                                <i data-lucide="<?= $is_available ? "eye-off" : "eye" ?>" class="w-4 h-4"></i>
                                                <?= $is_available ? "Deactivate" : "Activate" ?>
                                            </button>

                                        </form>

                                    </div>
                                </td>

                            </tr>

                        <?php endwhile; ?>

                    </tbody>
                </table>
            </div>

        <?php else: ?>

            <div class="text-center px-6 py-12">
                <i data-lucide="package" class="w-12 h-12 text-slate-400 mx-auto"></i>

                <h4 class="font-semibold text-lg text-slate-800 mt-4">
                    No products found
                </h4>

                <p class="text-sm text-slate-600 mt-2">
                    Add a product or adjust your search and filter.
                </p>
            </div>

        <?php endif; ?>

    </section>

    <p class="text-xs text-slate-500 mt-6">
        Deactivating a product hides it from product selection where availability is checked.
        Existing order records are retained.
    </p>

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
if (isset($stmt) && $stmt instanceof mysqli_stmt) {
    $stmt->close();
}
$conn->close();
?>
