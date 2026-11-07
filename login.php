
<?php
session_start();

// LOGOUT: Completely clear the current session
if (isset($_GET["logout"])) {

    $_SESSION = [];

    if (ini_get("session.use_cookies")) {
        $params = session_get_cookie_params();

        setcookie(
            session_name(),
            "",
            time() - 42000,
            $params["path"],
            $params["domain"],
            $params["secure"],
            $params["httponly"]
        );
    }

    session_destroy();

    header("Location: login.php");
    exit;
}

require_once "config/database.php";

$error = "";
$success = "";

$mode = isset($_GET["mode"]) ? $_GET["mode"] : "login";

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $action = $_POST["action"] ?? "login";

    // CUSTOMER REGISTRATION
    if ($action === "signup") {

        $full_name = trim($_POST["full_name"] ?? "");
        $contact_no = trim($_POST["contact_no"] ?? "");
        $email = trim($_POST["email"] ?? "");
        $address = trim($_POST["address"] ?? "");
        $password = $_POST["password"] ?? "";

        if (
            empty($full_name) ||
            empty($contact_no) ||
            empty($email) ||
            empty($address) ||
            empty($password)
        ) {
            $error = "Please complete all registration fields.";

        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = "Please enter a valid email address.";

        } elseif (strlen($password) < 8) {
            $error = "Password must contain at least 8 characters.";

        } else {

            $check = $conn->prepare(
                "SELECT customer_id FROM customers WHERE email = ?"
            );

            $check->bind_param("s", $email);
            $check->execute();
            $check->store_result();

            if ($check->num_rows > 0) {

                $error = "This email address is already registered.";

            } else {

                $password_hash = password_hash(
                    $password,
                    PASSWORD_DEFAULT
                );

                $stmt = $conn->prepare(
                    "INSERT INTO customers
                    (full_name, contact_no, email, password_hash, address, date_registered)
                    VALUES (?, ?, ?, ?, ?, CURDATE())"
                );

                $stmt->bind_param(
                    "sssss",
                    $full_name,
                    $contact_no,
                    $email,
                    $password_hash,
                    $address
                );

                if ($stmt->execute()) {
                    header("Location: login.php?registered=1");
                    exit;
                } else {
                    $error = "Registration failed. Please try again.";
                }

                $stmt->close();
            }

            $check->close();
        }

        $mode = "signup";
    }

    // LOGIN
    else {

        $identifier = trim($_POST["identifier"] ?? "");
        $password = $_POST["password"] ?? "";
        $role = $_POST["role"] ?? "";

        if (empty($identifier) || empty($password)) {

            $error = "Please enter your login details.";

        } else {

            // ADMIN / STAFF LOGIN
            if ($role === "staff") {

                $stmt = $conn->prepare(
                    "SELECT user_id, full_name, username, password_hash, role
                     FROM users
                     WHERE username = ?"
                );

                $stmt->bind_param("s", $identifier);
                $stmt->execute();

                $result = $stmt->get_result();
                $user = $result->fetch_assoc();

                if ($user && password_verify($password, $user["password_hash"])) {

                    // Clear any previous account session
                    $_SESSION = [];

                    session_regenerate_id(true);

                    $_SESSION["user_id"] = $user["user_id"];
                    $_SESSION["user_name"] = $user["full_name"];
                    $_SESSION["user_role"] = $user["role"];

                    if (strtolower($user["role"]) === "admin") {
                        header("Location: admin/dashboard.php");
                    } else {
                        header("Location: staff/dashboard.php");
                    }

                    exit;

                } else {
                    $error = "Invalid username or password.";
                }

                $stmt->close();
            }

            // CUSTOMER LOGIN
            elseif ($role === "customer") {

                $stmt = $conn->prepare(
                    "SELECT customer_id, full_name, email, password_hash
                     FROM customers
                     WHERE email = ?"
                );

                $stmt->bind_param("s", $identifier);
                $stmt->execute();

                $result = $stmt->get_result();
                $customer = $result->fetch_assoc();

                if (
                    $customer &&
                    !empty($customer["password_hash"]) &&
                    password_verify($password, $customer["password_hash"])
                ) {

                    // Clear any previous account session
                    $_SESSION = [];

                    session_regenerate_id(true);

                    $_SESSION["customer_id"] = $customer["customer_id"];
                    $_SESSION["customer_name"] = $customer["full_name"];
                    $_SESSION["user_role"] = "customer";

                    header("Location: customer/dashboard.php");
                    exit;

                } else {
                    $error = "Invalid email or password.";
                }

                $stmt->close();
            }
        }
    }
}

// Registration success message
if (isset($_GET["registered"])) {
    $success = "Account created successfully! You can now sign in.";
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>PrintFlow Pro - Login</title>

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

<body>

<div class="min-h-screen bg-gradient-to-br from-indigo-950 via-slate-900 to-indigo-900 flex items-center justify-center p-4 relative overflow-hidden">

    <!-- Background decorations -->
    <div class="absolute -top-40 -left-40 w-96 h-96 bg-indigo-500/20 rounded-full blur-3xl pointer-events-none"></div>

    <div class="absolute -bottom-40 -right-40 w-96 h-96 bg-purple-500/20 rounded-full blur-3xl pointer-events-none"></div>

    <!-- Login Card -->
    <div class="max-w-md w-full bg-slate-900/80 backdrop-blur-xl border border-slate-800 rounded-3xl shadow-2xl p-8 relative z-10 text-white">

        <!-- Logo and Title -->
        <div class="text-center mb-8">

            <div class="w-16 h-16 bg-gradient-to-tr from-indigo-600 to-violet-500 rounded-2xl mx-auto flex items-center justify-center shadow-lg shadow-indigo-500/30 mb-4">

                <i data-lucide="printer" class="w-8 h-8 text-white"></i>

            </div>

            <h1 class="text-2xl font-bold tracking-tight">
                PrintFlow Pro
            </h1>

            <p class="text-slate-400 text-sm mt-1">
                Printing & Custom Merchandise OMS & CRM
            </p>

        </div>

        <!-- Messages -->
        <?php if (!empty($error)): ?>

            <div class="bg-red-500/10 border border-red-500/30 text-red-300 text-sm rounded-xl p-3 mb-5">
                <?= htmlspecialchars($error) ?>
            </div>

        <?php endif; ?>

        <?php if (!empty($success)): ?>

            <div class="bg-emerald-500/10 border border-emerald-500/30 text-emerald-300 text-sm rounded-xl p-3 mb-5">
                <?= htmlspecialchars($success) ?>
            </div>

        <?php endif; ?>

        <?php if ($mode === "signup"): ?>

            <!-- CUSTOMER SIGNUP -->

            <h2 class="text-lg font-semibold text-center mb-5">
                Create Customer Account
            </h2>

            <form method="POST" action="login.php?mode=signup" class="space-y-4">

                <input type="hidden" name="action" value="signup">

                <div>
                    <label class="block text-xs font-medium text-slate-300 mb-1">
                        Full Name
                    </label>

                    <input
                        type="text"
                        name="full_name"
                        required
                        placeholder="Enter your full name"
                        class="w-full bg-slate-800 border border-slate-700 rounded-xl px-4 py-2.5 text-sm text-white placeholder-slate-500 focus:outline-none focus:ring-2 focus:ring-indigo-500"
                    >
                </div>

                <div>
                    <label class="block text-xs font-medium text-slate-300 mb-1">
                        Contact Number
                    </label>

                    <input
                        type="tel"
                        name="contact_no"
                        required
                        placeholder="09XXXXXXXXX"
                        class="w-full bg-slate-800 border border-slate-700 rounded-xl px-4 py-2.5 text-sm text-white placeholder-slate-500 focus:outline-none focus:ring-2 focus:ring-indigo-500"
                    >
                </div>

                <div>
                    <label class="block text-xs font-medium text-slate-300 mb-1">
                        Email Address
                    </label>

                    <input
                        type="email"
                        name="email"
                        required
                        placeholder="name@example.com"
                        class="w-full bg-slate-800 border border-slate-700 rounded-xl px-4 py-2.5 text-sm text-white placeholder-slate-500 focus:outline-none focus:ring-2 focus:ring-indigo-500"
                    >
                </div>

                <div>
                    <label class="block text-xs font-medium text-slate-300 mb-1">
                        Address
                    </label>

                    <input
                        type="text"
                        name="address"
                        required
                        placeholder="Enter your address"
                        class="w-full bg-slate-800 border border-slate-700 rounded-xl px-4 py-2.5 text-sm text-white placeholder-slate-500 focus:outline-none focus:ring-2 focus:ring-indigo-500"
                    >
                </div>

                <div>
                    <label class="block text-xs font-medium text-slate-300 mb-1">
                        Password
                    </label>

                    <input
                        type="password"
                        name="password"
                        required
                        minlength="8"
                        placeholder="At least 8 characters"
                        class="w-full bg-slate-800 border border-slate-700 rounded-xl px-4 py-2.5 text-sm text-white placeholder-slate-500 focus:outline-none focus:ring-2 focus:ring-indigo-500"
                    >
                </div>

                <button
                    type="submit"
                    class="w-full bg-gradient-to-r from-indigo-600 to-violet-600 hover:from-indigo-500 hover:to-violet-500 text-white font-medium py-3 rounded-xl shadow-lg transition"
                >
                    Create Account
                </button>

            </form>

            <div class="text-center mt-6">

                <a href="login.php" class="text-xs text-indigo-400 hover:text-indigo-300">
                    Already have an account? Sign in
                </a>

            </div>

        <?php else: ?>

            <!-- LOGIN -->

            <div class="mb-6 bg-slate-800/60 p-3 rounded-2xl border border-slate-700/50">

                <p class="text-xs font-semibold text-indigo-400 uppercase tracking-wider mb-2 text-center">
                    Welcome to PrintFlow
                </p>

                <p class="text-xs text-slate-400 text-center">
                    Sign in to access your account and manage your printing orders.
                </p>

            </div>

            <div class="relative flex py-2 items-center mb-6">

                <div class="flex-grow border-t border-slate-700"></div>

                <span class="flex-shrink mx-4 text-slate-500 text-xs uppercase tracking-wider">
                    Sign in to continue
                </span>

                <div class="flex-grow border-t border-slate-700"></div>

            </div>

            <form method="POST" action="login.php" class="space-y-4">

                <div>
                    <label class="block text-xs font-medium text-slate-300 mb-1">
                        Username or Email Address
                    </label>

                    <input
                        type="text"
                        name="identifier"
                        required
                        placeholder="Enter your username or email"
                        class="w-full bg-slate-800 border border-slate-700 rounded-xl px-4 py-2.5 text-sm text-white placeholder-slate-500 focus:outline-none focus:ring-2 focus:ring-indigo-500"
                    >
                </div>

                <div>
                    <label class="block text-xs font-medium text-slate-300 mb-1">
                        Password
                    </label>

                    <input
                        type="password"
                        name="password"
                        required
                        placeholder="Enter your password"
                        class="w-full bg-slate-800 border border-slate-700 rounded-xl px-4 py-2.5 text-sm text-white placeholder-slate-500 focus:outline-none focus:ring-2 focus:ring-indigo-500"
                    >
                </div>

                <div>
                    <label class="block text-xs font-medium text-slate-300 mb-1">
                        Access Role
                    </label>

                    <select
                        name="role"
                        required
                        class="w-full bg-slate-800 border border-slate-700 rounded-xl px-4 py-2.5 text-sm text-white focus:outline-none focus:ring-2 focus:ring-indigo-500"
                    >

                        <option value="staff">Admin / Print Shop Staff</option>

                        <option value="customer">Customer / Client</option>

                    </select>
                </div>

                <button
                    type="submit"
                    class="w-full bg-gradient-to-r from-indigo-600 to-violet-600 hover:from-indigo-500 hover:to-violet-500 text-white font-medium py-3 rounded-xl shadow-lg shadow-indigo-600/30 transition"
                >
                    Sign In to Dashboard
                </button>

            </form>

            <div class="text-center mt-6">

                <a href="login.php?mode=signup" class="text-xs text-indigo-400 hover:text-indigo-300 transition">
                    Don't have a customer account? Sign up here
                </a>

            </div>

        <?php endif; ?>

        <div class="text-center mt-8">

            <p class="text-xs text-slate-500">
                PrintFlow Pro &copy; <?= date("Y") ?>
            </p>

        </div>

    </div>

</div>

<script>
    lucide.createIcons();
</script>

</body>
</html>
