
<?php
session_start();

require_once "../config/database.php";

// Check login
if (!isset($_SESSION["user_id"]) || !isset($_SESSION["user_role"])) {
    header("Location: ../login.php");
    exit;
}

// Admin access only
if (strtolower($_SESSION["user_role"]) !== "admin") {
    header("Location: ../login.php");
    exit;
}

// CSRF protection
if (!isset($_SESSION["csrf_token"])) {
    $_SESSION["csrf_token"] = bin2hex(random_bytes(32));
}

$csrf_token = $_SESSION["csrf_token"];

// Handle new user registration
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["add_user"])) {

    $submitted_token = $_POST["csrf_token"] ?? "";

    if (!hash_equals($csrf_token, $submitted_token)) {
        header("Location: users.php?error=security");
        exit;
    }

    $full_name = trim($_POST["full_name"] ?? "");
    $username = trim($_POST["username"] ?? "");
    $password = $_POST["password"] ?? "";
    $role = $_POST["role"] ?? "";

    $allowed_roles = ["Admin", "Staff"];

    if (
        $full_name === "" ||
        $username === "" ||
        $password === "" ||
        !in_array($role, $allowed_roles, true)
    ) {
        header("Location: users.php?error=required");
        exit;
    }

    if (strlen($password) < 8) {
        header("Location: users.php?error=password");
        exit;
    }

    // Hash the password before saving it
    $password_hash = password_hash($password, PASSWORD_DEFAULT);

    $stmt = $conn->prepare("
        INSERT INTO users (full_name, username, password_hash, role)
        VALUES (?, ?, ?, ?)
    ");

    $stmt->bind_param(
        "ssss",
        $full_name,
        $username,
        $password_hash,
        $role
    );

    if ($stmt->execute()) {
        $stmt->close();

        header("Location: users.php?added=1");
        exit;
    } else {

        // Username is already taken
        if ($conn->errno === 1062) {
            $stmt->close();

            header("Location: users.php?error=duplicate");
            exit;
        }

        $stmt->close();

        header("Location: users.php?error=database");
        exit;
    }
}

// Search users
$search = trim($_GET["search"] ?? "");
$search_value = "%" . $search . "%";

$stmt = $conn->prepare("
    SELECT user_id, full_name, username, role
    FROM users
    WHERE full_name LIKE ?
       OR username LIKE ?
       OR role LIKE ?
    ORDER BY user_id DESC
");

$stmt->bind_param(
    "sss",
    $search_value,
    $search_value,
    $search_value
);

$stmt->execute();

$users = $stmt->get_result();
$total_users = $users->num_rows;

$admin_name = $_SESSION["user_name"] ?? "Administrator";

?>

<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>User Management | PrintFlow</title>

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
        select:focus,
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
                    class="flex items-center gap-2 bg-slate-100 hover:bg-indigo-50 hover:text-indigo-700 px-4 py-3 rounded-xl text-sm font-semibold transition"
                >
                    <i data-lucide="contact" class="w-5 h-5"></i>
                    Customers
                </a>

                <a
                    href="users.php"
                    aria-current="page"
                    class="flex items-center gap-2 bg-indigo-600 text-white px-4 py-3 rounded-xl text-sm font-semibold"
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
                User Management
            </h2>

            <p class="text-base text-slate-600 mt-2">
                Manage administrator and staff accounts in PrintFlow.
            </p>

        </section>


        <!-- Notifications -->
        <?php if (isset($_GET["added"])): ?>

            <div class="bg-emerald-50 border border-emerald-200 text-emerald-800 p-4 rounded-xl mb-6" role="status">
                User account created successfully.
            </div>

        <?php elseif (isset($_GET["error"])): ?>

            <div class="bg-red-50 border border-red-200 text-red-800 p-4 rounded-xl mb-6" role="alert">

                <?php
                $error = $_GET["error"];

                if ($error === "security") {
                    echo "Security verification failed. Please try again.";
                } elseif ($error === "required") {
                    echo "Please complete all required fields.";
                } elseif ($error === "password") {
                    echo "Password must contain at least 8 characters.";
                } elseif ($error === "duplicate") {
                    echo "That username is already being used. Please choose another.";
                } else {
                    echo "Unable to create the account. Please try again.";
                }
                ?>

            </div>

        <?php endif; ?>


        <!-- User Count -->
        <section class="bg-white border border-slate-200 rounded-2xl p-5 shadow-sm mb-6">

            <div class="flex items-center gap-4">

                <div class="w-12 h-12 bg-indigo-50 text-indigo-600 rounded-xl flex items-center justify-center">
                    <i data-lucide="users" class="w-6 h-6"></i>
                </div>

                <div>
                    <p class="text-sm text-slate-600">
                        Registered System Users
                    </p>

                    <p class="text-2xl font-bold text-slate-900">
                        <?= number_format($total_users) ?>
                    </p>
                </div>

            </div>

        </section>


        <!-- Add User Form -->
        <section class="bg-white border border-slate-200 rounded-2xl shadow-sm mb-6">

            <div class="p-5 border-b border-slate-200">

                <div class="flex items-center gap-3">

                    <div class="w-10 h-10 bg-indigo-50 text-indigo-600 rounded-xl flex items-center justify-center">
                        <i data-lucide="user-plus" class="w-5 h-5"></i>
                    </div>

                    <div>
                        <h3 class="text-lg font-bold text-slate-900">
                            Add New User
                        </h3>

                        <p class="text-sm text-slate-600">
                            Create an account for an administrator or staff member.
                        </p>
                    </div>

                </div>

            </div>


            <form method="POST" action="users.php" class="p-5">

                <input
                    type="hidden"
                    name="csrf_token"
                    value="<?= htmlspecialchars($csrf_token) ?>"
                >

                <div class="grid grid-cols-1 md:grid-cols-2 gap-5">

                    <!-- Full Name -->
                    <div>

                        <label for="full_name" class="block text-sm font-semibold text-slate-700 mb-2">
                            Full Name
                        </label>

                        <input
                            type="text"
                            id="full_name"
                            name="full_name"
                            placeholder="Enter full name"
                            maxlength="150"
                            required
                            class="w-full border border-slate-300 rounded-xl px-4 py-3 text-base focus:outline-none focus:ring-2 focus:ring-indigo-500"
                        >

                    </div>


                    <!-- Username -->
                    <div>

                        <label for="username" class="block text-sm font-semibold text-slate-700 mb-2">
                            Username
                        </label>

                        <input
                            type="text"
                            id="username"
                            name="username"
                            placeholder="Create a username"
                            maxlength="100"
                            required
                            class="w-full border border-slate-300 rounded-xl px-4 py-3 text-base focus:outline-none focus:ring-2 focus:ring-indigo-500"
                        >

                    </div>


                    <!-- Password -->
                    <div>

                        <label for="password" class="block text-sm font-semibold text-slate-700 mb-2">
                            Password
                        </label>

                        <input
                            type="password"
                            id="password"
                            name="password"
                            placeholder="At least 8 characters"
                            minlength="8"
                            required
                            class="w-full border border-slate-300 rounded-xl px-4 py-3 text-base focus:outline-none focus:ring-2 focus:ring-indigo-500"
                        >

                        <p class="text-xs text-slate-500 mt-2">
                            Passwords are securely hashed before being stored.
                        </p>

                    </div>


                    <!-- Role -->
                    <div>

                        <label for="role" class="block text-sm font-semibold text-slate-700 mb-2">
                            User Role
                        </label>

                        <select
                            id="role"
                            name="role"
                            required
                            class="w-full border border-slate-300 rounded-xl px-4 py-3 text-base bg-white focus:outline-none focus:ring-2 focus:ring-indigo-500"
                        >

                            <option value="">Select user role</option>
                            <option value="Admin">Admin</option>
                            <option value="Staff">Staff</option>

                        </select>

                    </div>

                </div>


                <div class="mt-6">

                    <button
                        type="submit"
                        name="add_user"
                        value="1"
                        class="flex items-center justify-center gap-2 bg-indigo-600 hover:bg-indigo-700 text-white px-6 py-3 rounded-xl font-semibold transition"
                    >
                        <i data-lucide="user-plus" class="w-5 h-5"></i>
                        Create User Account
                    </button>

                </div>

            </form>

        </section>


        <!-- Search Users -->
        <section class="bg-white border border-slate-200 rounded-2xl p-5 shadow-sm mb-6">

            <form method="GET" action="users.php" class="flex flex-col sm:flex-row gap-3">

                <div class="flex-1">

                    <label for="search" class="block text-sm font-semibold text-slate-700 mb-2">
                        Search Users
                    </label>

                    <input
                        type="search"
                        id="search"
                        name="search"
                        value="<?= htmlspecialchars($search) ?>"
                        placeholder="Search by name, username, or role"
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
                        href="users.php"
                        class="flex items-center justify-center bg-slate-100 hover:bg-slate-200 px-5 py-3 rounded-xl font-semibold text-sm transition"
                    >
                        Clear
                    </a>

                </div>

            </form>

        </section>


        <!-- User Records -->
        <section class="bg-white border border-slate-200 rounded-2xl shadow-sm overflow-hidden">

            <div class="p-5 border-b border-slate-200">

                <h3 class="text-lg font-bold text-slate-900">
                    System User Records
                </h3>

                <p class="text-sm text-slate-600 mt-1">
                    View the administrator and staff accounts registered in the system.
                </p>

            </div>


            <?php if ($users && $users->num_rows > 0): ?>

                <div class="overflow-x-auto">

                    <table class="w-full text-left">

                        <thead class="bg-slate-50 border-b border-slate-200">

                            <tr class="text-sm text-slate-600">

                                <th scope="col" class="px-5 py-4">User ID</th>
                                <th scope="col" class="px-5 py-4">Full Name</th>
                                <th scope="col" class="px-5 py-4">Username</th>
                                <th scope="col" class="px-5 py-4">Role</th>
                                <th scope="col" class="px-5 py-4">Account</th>

                            </tr>

                        </thead>


                        <tbody class="divide-y divide-slate-100">

                            <?php while ($user = $users->fetch_assoc()): ?>

                                <tr class="hover:bg-slate-50">

                                    <td class="px-5 py-4 font-bold text-indigo-700">
                                        #<?= (int)$user["user_id"] ?>
                                    </td>

                                    <td class="px-5 py-4 font-medium text-slate-800">
                                        <?= htmlspecialchars($user["full_name"]) ?>
                                    </td>

                                    <td class="px-5 py-4 text-slate-600">
                                        <?= htmlspecialchars($user["username"]) ?>
                                    </td>

                                    <td class="px-5 py-4">

                                        <?php if ($user["role"] === "Admin"): ?>

                                            <span class="inline-block px-3 py-2 rounded-lg text-sm font-semibold bg-violet-100 text-violet-800">
                                                Admin
                                            </span>

                                        <?php else: ?>

                                            <span class="inline-block px-3 py-2 rounded-lg text-sm font-semibold bg-blue-100 text-blue-800">
                                                Staff
                                            </span>

                                        <?php endif; ?>

                                    </td>

                                    <td class="px-5 py-4">

                                        <?php if ((int)$user["user_id"] === (int)$_SESSION["user_id"]): ?>

                                            <span class="text-sm font-semibold text-emerald-700">
                                                Current Account
                                            </span>

                                        <?php else: ?>

                                            <span class="text-sm text-slate-500">
                                                Registered
                                            </span>

                                        <?php endif; ?>

                                    </td>

                                </tr>

                            <?php endwhile; ?>

                        </tbody>

                    </table>

                </div>

            <?php else: ?>

                <div class="text-center px-6 py-12">

                    <i data-lucide="users" class="w-12 h-12 text-slate-400 mx-auto"></i>

                    <h4 class="font-semibold text-lg text-slate-800 mt-4">
                        No users found
                    </h4>

                    <p class="text-sm text-slate-600 mt-2">
                        Create a user account or try another search.
                    </p>

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
