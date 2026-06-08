<?php
require_once 'db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: register.html');
    exit;
}

$username = trim($_POST['username'] ?? '');
$password = $_POST['password'] ?? '';
$confirm_password = $_POST['confirm_password'] ?? '';

if ($username === '' || $password === '' || $confirm_password === '') {
    header('Location: register.html?error=' . rawurlencode('Please complete all fields.'));
    exit;
}

if ($password !== $confirm_password) {
    header('Location: register.html?error=' . rawurlencode('Passwords do not match.'));
    exit;
}

if (strlen($password) < 6) {
    header('Location: register.html?error=' . rawurlencode('Password must be at least 6 characters.'));
    exit;
}

try {
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM users WHERE username = :username');
    $stmt->execute([':username' => $username]);
    $exists = $stmt->fetchColumn();

    if ($exists) {
        header('Location: register.html?error=' . rawurlencode('That username is already taken.'));
        exit;
    }

    $password_hash = password_hash($password, PASSWORD_DEFAULT);
    $insert = $pdo->prepare('INSERT INTO users (username, password_hash, role) VALUES (:username, :password_hash, :role)');
    $insert->execute([
        ':username' => $username,
        ':password_hash' => $password_hash,
        ':role' => 'Staff',
    ]);

    header('Location: register.html?success=' . rawurlencode('Registration successful. You may now log in.'));
    exit;
} catch (PDOException $e) {
    header('Location: register.html?error=' . rawurlencode('Registration failed. Please try again.'));
    exit;
}
?>
