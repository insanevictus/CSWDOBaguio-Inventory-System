<?php
try {
    // Connect to the SQLite database file
    $pdo = new PDO("sqlite:" . __DIR__ . "/inventory.db");
    // Set error handling mode to exceptions for easy troubleshooting
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    // Enable Foreign Key constraints for user-to-history tracking safety
    $pdo->exec("PRAGMA foreign_keys = ON;");

    // 1. Create the Users Table
    $pdo->exec("CREATE TABLE IF NOT EXISTS users (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        username TEXT UNIQUE NOT NULL,
        password_hash TEXT NOT NULL,
        role TEXT NOT NULL DEFAULT 'Staff'
    );");

    // 2. Create the Products Table (Updated for Categories & Expiry Dates)
    $pdo->exec("CREATE TABLE IF NOT EXISTS products (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        product_name TEXT NOT NULL,
        category TEXT NOT NULL,
        current_stock INTEGER NOT NULL DEFAULT 0,
        expiry_date TEXT NULL
    );");

    // 3. Create the Stock History Ledger Table
    $pdo->exec("CREATE TABLE IF NOT EXISTS stock_history (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        product_id INTEGER NOT NULL,
        user_id INTEGER NOT NULL,
        transaction_type TEXT NOT NULL,
        quantity INTEGER NOT NULL,
        staff_name TEXT NOT NULL,
        remarks TEXT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP, 
        FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
        FOREIGN KEY (user_id) REFERENCES users(id)
    );");

    // 4. Seed default Admin profile if table is completely empty
    $checkUsers = $pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
    if ($checkUsers == 0) {
        $default_hash = password_hash("admin123", PASSWORD_DEFAULT);
        $stmt = $pdo->prepare("INSERT INTO users (username, password_hash, role) VALUES ('admin', :pass, 'Admin')");
        $stmt->execute([':pass' => $default_hash]);
    }

} catch (PDOException $e) {
    die("Database connection failed: " . $e->getMessage());
}
?>