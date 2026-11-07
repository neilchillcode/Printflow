
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

// Search customers
$search = trim($_GET["search"] ?? "");

if ($search !== "") {
    $sql = "SELECT
                customer_id,
                full_name,
                contact_no,
                email,
                address,
                date_registered
            FROM customers
            WHERE full_name LIKE ?
               OR contact_no LIKE ?
               OR email LIKE ?
            ORDER BY customer_id DESC";

    $stmt = $conn->prepare($sql);
    $search_term = "%" . $search . "%";
    $stmt->bind_param("sss", $search_term, $search_term, $search_term);
    $stmt->execute();
    $customers = $stmt->get_result();

} else {
    $sql = "SELECT
                customer_id,
                full_name,
                contact_no,
                email,
                address,
                date_registered
            FROM customers
            ORDER BY customer_id DESC";

    $customers = $conn->query($sql);
}

// Total registered customers
$count_result = $conn->query("SELECT COUNT(*) AS total FROM customers");
$total_customers = $count_result->fetch_assoc()["total"];

$admin_name = $_SESSION["user_name"] ?? "Administrator";
?>

<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Customer Directory | PrintFlow Pro</title>

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
        input:focus {
            outline: 3px solid #818cf8;
            outline-offset: 3px;
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
                            <?= htmlspecialchars($admin_name) ?>
                        </p>

                        <p class="text-sm text-emerald-600">
                            Administrator
                        </p>
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

            <!-- Consistent Admin Navigation -->
            <nav class="flex flex-wrap gap-2 mt-5" aria-label="Admin main navigation">

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
                    href="customers.php"
                    aria-current="page"
                    class="flex items-center gap-2 bg-indigo-600 text-white px-4 py-3 rounded-xl text-sm font-semibold"
                >
                    <i data-lucide="contact" class="w-5 h-5"></i>
                    Customers
                </a>

                <a
                    href="users.php"
                    class="flex items-center gap-2 bg-slate-100 hover:bg-indigo-50 hover:text-indigo-700 px-4 py-3 rounded-xl text-sm font-semibold transition"
                >
                    <i data-lucide="users" class="w-5 h-5"></i>
                    Users
                </a>

                <a
                    href="reports.php"
                    class="flex items-center gap-2 bg-slate-100 hover:bg-indigo-50 hover:text-indigo-700 px-4 py-3 rounded-xl text-sm font-semibold transition"
                >
                    <i data-lucide="chart-no-axes-combined" class="w-5 h-5"></i>
                    Reports
                </a>

            </nav>

        </div>

    </header>


    <!-- Main Content -->
    <main class="max-w-7xl mx-auto px-4 sm:px-6 py-8">

        <!-- Page Heading -->
        <section class="mb-8">

            <h2 class="text-2xl font-bold text-slate-900">
                Customer Directory
            </h2>

            <p class="text-base text-slate-600 mt-2">
                View and search customer information and registration records.
            </p>

        </section>


        <!-- Customer Count -->
        <section class="bg-white border border-slate-200 rounded-2xl p-5 shadow-sm mb-6">

            <div class="flex items-center gap-4">

                <div class="w-12 h-12 bg-indigo-50 text-indigo-600 rounded-xl flex items-center justify-center">
                    <i data-lucide="contact" class="w-6 h-6"></i>
                </div>

                <div>
                    <p class="text-sm text-slate-600">
                        Total Registered Customers
                    </p>

                    <p class="text-2xl font-bold text-slate-900">
                        <?= number_format((int)$total_customers) ?>
                    </p>
                </div>

            </div>

        </section>


        <!-- Search -->
        <section class="bg-white border border-slate-200 rounded-2xl p-5 shadow-sm mb-6">

            <form method="GET" action="customers.php" class="flex flex-col sm:flex-row gap-3">

                <div class="flex-1">

                    <label for="search" class="block text-sm font-semibold text-slate-700 mb-2">
                        Search Customers
                    </label>

                    <input
                        type="search"
                        id="search"
                        name="search"
                        value="<?= htmlspecialchars($search) ?>"
                        placeholder="Search by name, contact number, or email"
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
                        href="customers.php"
                        class="flex items-center justify-center bg-slate-100 hover:bg-slate-200 px-5 py-3 rounded-xl font-semibold text-sm transition"
                    >
                        Clear
                    </a>

                </div>

            </form>

        </section>


        <!-- Customer Records -->
        <section class="bg-white border border-slate-200 rounded-2xl shadow-sm overflow-hidden">

            <div class="p-5 border-b border-slate-200">

                <div class="flex flex-wrap justify-between items-center gap-3">

                    <div>
                        <h3 class="text-lg font-bold text-slate-900">
                            Customer Records
                        </h3>

                        <p class="text-sm text-slate-600 mt-1">
                            View registered customer information.
                        </p>
                    </div>

                    <p class="text-sm font-medium text-slate-600">
                        <?= (int)$customers->num_rows ?> result(s) found
                    </p>

                </div>

            </div>


            <?php if ($customers && $customers->num_rows > 0): ?>

                <div class="overflow-x-auto">

                    <table class="w-full text-left">

                        <thead class="bg-slate-50 border-b border-slate-200">

                            <tr class="text-sm text-slate-600">
                                <th scope="col" class="px-5 py-4">Customer ID</th>
                                <th scope="col" class="px-5 py-4">Full Name</th>
                                <th scope="col" class="px-5 py-4">Contact Number</th>
                                <th scope="col" class="px-5 py-4">Email</th>
                                <th scope="col" class="px-5 py-4">Address</th>
                                <th scope="col" class="px-5 py-4">Date Registered</th>
                            </tr>

                        </thead>


                        <tbody class="divide-y divide-slate-100">

                            <?php while ($customer = $customers->fetch_assoc()): ?>

                                <tr class="hover:bg-slate-50">

                                    <td class="px-5 py-4 font-bold text-indigo-700 whitespace-nowrap">
                                        #<?= (int)$customer["customer_id"] ?>
                                    </td>

                                    <td class="px-5 py-4 font-medium text-slate-800">
                                        <?= htmlspecialchars($customer["full_name"] ?? "") ?>
                                    </td>

                                    <td class="px-5 py-4 text-slate-700">
                                        <?= htmlspecialchars($customer["contact_no"] ?? "") ?>
                                    </td>

                                    <td class="px-5 py-4 text-slate-700">
                                        <?= htmlspecialchars($customer["email"] ?? "") ?>
                                    </td>

                                    <td class="px-5 py-4 text-slate-700 min-w-48">
                                        <?= htmlspecialchars($customer["address"] ?? "") ?>
                                    </td>

                                    <td class="px-5 py-4 text-slate-600 whitespace-nowrap">

                                        <?php
                                        if (!empty($customer["date_registered"])) {
                                            echo htmlspecialchars(
                                                date("M d, Y", strtotime($customer["date_registered"]))
                                            );
                                        } else {
                                            echo "Not available";
                                        }
                                        ?>

                                    </td>

                                </tr>

                            <?php endwhile; ?>

                        </tbody>

                    </table>

                </div>

            <?php else: ?>

                <div class="text-center px-6 py-12">

                    <i data-lucide="users-round" class="w-12 h-12 text-slate-400 mx-auto"></i>

                    <h4 class="font-semibold text-lg text-slate-800 mt-4">
                        No customers found
                    </h4>

                    <p class="text-sm text-slate-600 mt-2">
                        Try another name, contact number, or email address.
                    </p>

                    <?php if (!empty($search)): ?>

                        <a
                            href="customers.php"
                            class="inline-block mt-4 text-indigo-700 hover:underline font-semibold"
                        >
                            Show all customers
                        </a>

                    <?php endif; ?>

                </div>

            <?php endif; ?>

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
if (isset($stmt)) {
    $stmt->close();
}

$conn->close();
?>
