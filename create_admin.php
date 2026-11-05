
<?php

require_once "config/database.php";

// Admin account details
$full_name = "PrintFlow Administrator";
$username = "admin";
$password = "ChangeThisPassword123!";

// Check if the username already exists
$check = $conn->prepare(
    "SELECT user_id FROM users WHERE username = ?"
);

$check->bind_param("s", $username);
$check->execute();
$check->store_result();

if ($check->num_rows > 0) {

    echo "An account with this username already exists.";

} else {

    // Securely hash the password
    $password_hash = password_hash(
        $password,
        PASSWORD_DEFAULT
    );

    $role = "Admin";

    // Insert the Admin account
    $stmt = $conn->prepare(
        "INSERT INTO users
        (full_name, username, password_hash, role)
        VALUES (?, ?, ?, ?)"
    );

    $stmt->bind_param(
        "ssss",
        $full_name,
        $username,
        $password_hash,
        $role
    );

    if ($stmt->execute()) {

        echo "Admin account created successfully!";
        echo "<br>Username: " . htmlspecialchars($username);

    } else {

        echo "Error creating account: " . htmlspecialchars($stmt->error);

    }

    $stmt->close();
}

$check->close();
$conn->close();

?>
