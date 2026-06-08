<?php
session_start();
require_once 'db.php';

$error_message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username']);
    $password = $_POST['password'];

    if (!empty($username) && !empty($password)) {
        $stmt = $pdo->prepare("SELECT * FROM users WHERE username = :username");
        $stmt->execute([':username' => $username]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($user && password_verify($password, $user['password_hash'])) {
            // Store user details in the session variables
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['username'] = $user['username'];
            $_SESSION['role'] = $user['role'];

            // Redirect securely to the dashboard page
            header("Location: dashboard.php");
            exit;
        } else {
            $error_message = "Invalid username or password!";
        }
    } else {
        $error_message = "Please fill in all fields!";
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Inventory System - Login</title>
    <link rel="stylesheet" href="dashboard.css">
    <style>
        body {
            display: flex;
            justify-content: center;
            align-items: center;
            height: 100vh;
            margin: 0;
            position: relative;
        }
        .login-container {
            width: 100%;
            max-width: 400px;
            text-align: center;
        }
        .main-title {
            margin: 0 0 20px 0;
            color: #010e20;
            font-size: 36px;
            font-weight: 800;
            letter-spacing: 1px;
        }
        .login-card {
            background: #c2d6f1;
            padding: 40px;
            border-radius: 12px;
            box-shadow: 0 8px 24px rgba(0, 0, 0, 0.1);
            text-align: left;
        }
        .login-card h2 {
            margin-top: 0;
            margin-bottom: 24px;
            color: #000000;
            text-align: center;
            font-size: 20px;
        }
        .form-group {
            margin-bottom: 20px;
        }
        .form-group label {
            display: block;
            margin-bottom: 8px;
            color: #141414;
            font-weight: 600;
        }
        .form-group input {
            width: 100%;
            padding: 12px;
            border: 1px solid #cbd5e0;
            border-radius: 6px;
            box-sizing: border-box;
            font-size: 16px;
            background-color: #ffffff;
        }
        .form-group input:focus {
            outline: none;
            border-color: #007bff;
            box-shadow: 0 0 0 3px rgba(0, 123, 255, 0.15);
        }
        .btn-login {
            width: 100%;
            padding: 12px;
            background-color: #007bff;
            border: none;
            border-radius: 6px;
            color: white;
            font-size: 16px;
            font-weight: bold;
            cursor: pointer;
            transition: background 0.2s;
            margin-top: 10px;
        }
        .btn-login:hover {
            background-color: #0056b3;
        }
        .error-alert {
            background-color: #f8d7da;
            color: #721c24;
            padding: 12px;
            border-radius: 6px;
            margin-bottom: 20px;
            text-align: center;
            font-size: 14px;
            border: 1px solid #f5c6cb;
        }
    </style>
</head>
<body>

    <div class="login-container">
        <h1 class="main-title">CSWDO</h1>
        
        <div class="login-card">
            <h2>Inventory System Login</h2>
            
            <?php if (!empty($error_message)): ?>
                <div class="error-alert"><?php echo $error_message; ?></div>
            <?php endif; ?>

            <form action="index.php" method="POST">
                <div class="form-group">
                    <label for="username">Username</label>
                    <input type="text" id="username" name="username" required autocomplete="off">
                </div>
                <div class="form-group">
                    <label for="password">Password</label>
                    <input type="password" id="password" name="password" required>
                </div>
                <button type="submit" class="btn-login">Log In</button>
            </form>
        </div>
    </div>

    <div class="developer-credits">
        Developed by: <br> <strong>JOHN MARVIN VICENTE</strong><br>
        VISIT: <a href="https://insanevictus.github.io/PersonalWebsite/SocialMedias.html">Personal Website</a>
    </div>

</body>
</html>