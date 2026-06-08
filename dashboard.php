<?php
// --- 0. SET TIMEZONE FLOWS ---
date_default_timezone_set('Asia/Manila'); 

session_start();
require_once 'db.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit;
}

$message = '';
$error = '';
if (isset($_SESSION['success_flash'])) {
    $message = $_SESSION['success_flash'];
    unset($_SESSION['success_flash']);
}
if (isset($_SESSION['error_flash'])) {
    $error = $_SESSION['error_flash'];
    unset($_SESSION['error_flash']);
}

$active_tab_hint = isset($_SESSION['active_tab_flash']) ? $_SESSION['active_tab_flash'] : 'in';
unset($_SESSION['active_tab_flash']);

// --- 1. OPERATION: REGISTER NEW PRODUCT PROFILE ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_add_product'])) {
    $_SESSION['active_tab_flash'] = 'in'; 
    $product_name = trim($_POST['product_name']);
    $category = $_POST['category'];

    if (!empty($product_name) && !empty($category)) {
        try {
            $check = $pdo->prepare("SELECT id FROM products WHERE LOWER(product_name) = LOWER(:name)");
            $check->execute([':name' => $product_name]);
            $existing = $check->fetch(PDO::FETCH_ASSOC);

            if ($existing) {
                $repair = $pdo->prepare("UPDATE products SET current_stock = 0, category = :category WHERE id = :id");
                $repair->execute([':category' => $category, ':id' => $existing['id']]);
                $_SESSION['success_flash'] = "Item profile safely restored and synchronized.";
            } else {
                $stmt = $pdo->prepare("INSERT INTO products (product_name, category, current_stock) VALUES (:name, :category, 0)");
                $stmt->execute([':name' => $product_name, ':category' => $category]);
                $_SESSION['success_flash'] = "Item '$product_name' [$category] successfully added.";
            }
        } catch (PDOException $e) {
            $_SESSION['error_flash'] = "System profile error: " . $e->getMessage();
        }
    } else {
        $_SESSION['error_flash'] = "Please complete all fields to register an asset profile.";
    }
    header("Location: dashboard.php");
    exit;
}

// --- 2. OPERATION: LOG IN / OUT TRANSACTIONS ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_stock_movement'])) {
    $type = $_POST['transaction_type']; 
    $_SESSION['active_tab_flash'] = strtolower($type); 
    
    $input_quantity = intval($_POST['quantity']);
    $unit = !empty($_POST['unit']) ? trim($_POST['unit']) : 'piece';
    $remarks = isset($_POST['remarks']) ? trim($_POST['remarks']) : '';
    $staff_name = isset($_POST['staff_name']) ? trim($_POST['staff_name']) : 'System';
    
    $unit_multiplier = (strtolower($unit) === 'piece' || strtolower($unit) === 'pcs') ? 1 : intval($_POST['unit_multiplier']);
    if ($unit_multiplier <= 0) { $unit_multiplier = 1; }

    $total_pieces = $input_quantity * $unit_multiplier;

    try {
        if ($type === 'IN') {
            $product_id = intval($_POST['product_id']);
            $expiry_date = !empty($_POST['expiry_date']) ? $_POST['expiry_date'] : null;
        } else {
            if (!empty($_POST['batch_composite'])) {
                $parts = explode('|', $_POST['batch_composite']);
                $product_id = intval($parts[0]);
                // Explicitly check for 'NO_EXPIRY' string or empty bounds
                $expiry_date = ($parts[1] === 'NO_EXPIRY' || empty($parts[1])) ? null : $parts[1];
            } else {
                throw new Exception("Please search and select a valid active batch to issue stock release.");
            }
        }

        if (empty($product_id)) {
            throw new Exception("Invalid item selection. Please type and click a dynamic suggestion from the list.");
        }

        $stmt = $pdo->prepare("SELECT * FROM products WHERE id = :id");
        $stmt->execute([':id' => $product_id]);
        $product = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$product) {
            throw new Exception("The selected item type does not exist in inventory records.");
        }

        // --- FIXED BATCH SUM QUERY ENGINE ---
        $current_batch_balance = 0;
        if ($expiry_date === null) {
            // Safe query tailored explicitly for items with NO expiration date (Office Supplies/Non-Food)
            $batch_stmt = $pdo->prepare("SELECT SUM(CASE WHEN transaction_type = 'IN' THEN quantity ELSE -quantity END) as net_qty 
                                         FROM stock_history 
                                         WHERE product_id = :p_id AND (expiry_date IS NULL OR expiry_date = '')");
            $batch_stmt->execute([':p_id' => $product_id]);
        } else {
            // Query tailored for food items with specific expiration dates
            $batch_stmt = $pdo->prepare("SELECT SUM(CASE WHEN transaction_type = 'IN' THEN quantity ELSE -quantity END) as net_qty 
                                         FROM stock_history 
                                         WHERE product_id = :p_id AND expiry_date = :exp");
            $batch_stmt->execute([':p_id' => $product_id, ':exp' => $expiry_date]);
        }
        
        $batch_row = $batch_stmt->fetch(PDO::FETCH_ASSOC);
        if ($batch_row && $batch_row['net_qty'] !== null) {
            $current_batch_balance = intval($batch_row['net_qty']);
        }

        if ($type === 'OUT' && $current_batch_balance < $total_pieces) {
            throw new Exception("Insufficient batch stock! This batch only has " . $current_batch_balance . " pieces left. (Attempted to pull " . $total_pieces . " pieces via conversion).");
        } else {
            $new_total_stock = ($type === 'IN') ? $product['current_stock'] + $total_pieces : $product['current_stock'] - $total_pieces;

            $pdo->beginTransaction();
            
            $update = $pdo->prepare("UPDATE products SET current_stock = :stock WHERE id = :id");
            $update->execute([':stock' => $new_total_stock, ':id' => $product_id]);

            $current_timestamp = date('Y-m-d H:i:s');
            $final_remarks = "[Logged: $input_quantity $unit (1 $unit = $unit_multiplier pcs)] " . (!empty($remarks) ? $remarks : '');

            $log = $pdo->prepare("INSERT INTO stock_history (product_id, user_id, transaction_type, quantity, staff_name, remarks, expiry_date, created_at, unit, unit_multiplier) VALUES (:p_id, :u_id, :type, :qty, :staff, :remarks, :expiry, :created_at, :unit, :multiplier)");
            $log->execute([
                ':p_id' => $product_id,
                ':u_id' => $_SESSION['user_id'],
                ':type' => $type,
                ':qty' => $total_pieces, 
                ':staff' => $staff_name,
                ':remarks' => $final_remarks,
                ':expiry' => $expiry_date,
                ':created_at' => $current_timestamp,
                ':unit' => $unit,
                ':multiplier' => $unit_multiplier
            ]);

            $pdo->commit();
            $_SESSION['success_flash'] = "Stock successfully adjusted by " . $total_pieces . " individual pieces!";
        }
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $_SESSION['error_flash'] = "Transaction error: " . $e->getMessage();
    }
    
    header("Location: dashboard.php");
    exit;
}
// --- FRESH DATA ENGINE READS ---
$all_products = $pdo->query("SELECT * FROM products ORDER BY product_name ASC")->fetchAll(PDO::FETCH_ASSOC);
$history_logs = $pdo->query("SELECT h.*, p.product_name 
                             FROM stock_history h 
                             JOIN products p ON h.product_id = p.id 
                             ORDER BY h.created_at DESC")->fetchAll(PDO::FETCH_ASSOC);

// --- COMPUTE THE DYNAMIC EXPIRY BATCH MATRICES ---
$batch_stocks = [];
$active_out_dropdown_batches = [];

foreach ($all_products as $prod) {
    $stmt = $pdo->prepare("SELECT expiry_date, SUM(CASE WHEN transaction_type = 'IN' THEN quantity ELSE -quantity END) as net_qty 
                           FROM stock_history 
                           WHERE product_id = :p_id 
                           GROUP BY expiry_date");
    $stmt->execute([':p_id' => $prod['id']]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $green = 0; $yellow = 0; $red = 0; $no_expiry_total = 0;
    $computed_category = $prod['category'];

    foreach ($rows as $row) {
        $net_quantity = intval($row['net_qty']);
        if ($net_quantity <= 0) continue; 

        $active_out_dropdown_batches[] = [
            'product_id' => $prod['id'],
            'product_name' => $prod['product_name'],
            'category' => $computed_category,
            'expiry_date' => $row['expiry_date'],
            'net_qty' => $net_quantity
        ];

        if (!empty($row['expiry_date']) && $computed_category === 'Food') {
            $today_timestamp = time();
            $expiry_timestamp = strtotime($row['expiry_date']);
            $days_left = ($expiry_timestamp - $today_timestamp) / (60 * 60 * 24);

            if ($days_left <= 0) {
                $red += $net_quantity;
            } elseif ($days_left <= 30) {
                $yellow += $net_quantity;
            } else {
                $green += $net_quantity;
            }
        } else {
            $no_expiry_total += $net_quantity;
        }
    }

    $batch_stocks[] = [
        'id' => $prod['id'],
        'product_name' => $prod['product_name'],
        'category' => $computed_category,
        'total_stock' => $prod['current_stock'],
        'green' => $green,
        'yellow' => $yellow,
        'red' => $red,
        'no_expiry' => $no_expiry_total
    ];
}

// --- SORT BATCH STOCKS ALPHABETICALLY BY PRODUCT NAME ---
usort($batch_stocks, function($a, $b) {
    return strcasecmp($a['product_name'], $b['product_name']);
});

// --- SORT ACTIVE OUT DROPDOWN BATCHES ALPHABETICALLY BY PRODUCT NAME ---
usort($active_out_dropdown_batches, function($a, $b) {
    return strcasecmp($a['product_name'], $b['product_name']);
});
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CSWDO - Inventory System</title>
    <link class="styles-hook" rel="stylesheet" href="dashboard.css">
</head>
<body data-active-tab="<?php echo $active_tab_hint; ?>">

    <div class="navbar">
        <a href="dashboard.php" class="brand">CSWDO</a>
        <div><a href="dashboard.php" class="brand">Inventory System</a></div>
        <div><a href="logout.php" class="btn-logout">Log Out</a></div>
    </div>

    <div class="master-wrapper">
        <?php if (!empty($message)): ?><div class="alert-success"><?php echo $message; ?></div><?php endif; ?>
        <?php if (!empty($error)): ?><div class="alert-danger"><?php echo $error; ?></div><?php endif; ?>

        <div class="workspace-card">
            <div class="tab-bar">
                <button id="tab-in" class="tab-btn" onclick="switchWorkspaceTab('in')">IN</button>
                <button id="tab-out" class="tab-btn" onclick="switchWorkspaceTab('out')">OUT</button>
                <button id="tab-history" class="tab-btn" onclick="switchWorkspaceTab('history')">History</button>
                <button id="tab-current_stocks" class="tab-btn" onclick="switchWorkspaceTab('current_stocks')">Current Stocks</button>
            </div>

            <div id="view-in" class="view-panel">
                <h3 style="margin-top: 0; color:#000000;">Receive Incoming Stock</h3>
                <form action="dashboard.php" method="POST" autocomplete="off">
                    <input type="hidden" name="action_stock_movement" value="1">
                    <input type="hidden" name="transaction_type" value="IN">
                    <input type="hidden" name="product_id" id="hidden_product_id_in" required>

                    <div class="form-group">
                        <label>Search Item Type</label>
                        <div class="autocomplete-wrapper">
                            <input type="text" id="search_autocomplete_in" placeholder="Type item title keywords here..." oninput="searchAutocompleteEngine('IN')" onfocus="searchAutocompleteEngine('IN')" required style="padding: 10px; border: 1px solid #ccd0d5; border-radius: 6px; width: 100%; box-sizing: border-box; font-size: 14px;">
                            <div id="suggestions_box_in" class="autocomplete-suggestions-box"></div>
                        </div>
                    </div>

                    <div class="form-group" id="movement_expiry_wrapper" style="display:none;">
                        <label>Batch Expiration Date</label>
                        <input type="date" id="movement_expiry_date" name="expiry_date">
                    </div>

                    <div style="display: flex; gap: 15px;">
                        <div class="form-group" style="flex: 2;">
                            <label>Quantity of Unit Received</label>
                            <input type="number" name="quantity" min="1" required placeholder="Amount to receive">
                        </div>
                        <div class="form-group" style="flex: 1;">
                            <label>Unit</label>
                            <input type="text" id="unit_in" name="unit" placeholder="piece, box, roll, ream etc..." list="unit-suggestions" oninput="toggleInMultiplier(this.value)">
                        </div>
                    </div>

                    <div class="form-group" id="multiplier_in_wrapper" style="display:none; background-color: #dbe7f7; border: 1px solid #000000; padding: 12px; border-radius: 6px; margin-bottom: 15px;">
                        <label style="color:#8a6d3b; font-weight: bold;">Pieces per Unit</label>
                        <input type="number" name="unit_multiplier" id="unit_multiplier_in" value="1" min="1" placeholder="Pcs inside packaging box">
                    </div>

                    <div class="form-group">
                        <label>Staff Accountable</label>
                        <input type="text" name="staff_name" required placeholder="Enter name">
                    </div>
                    <div class="form-group">
                        <label>Remarks</label>
                        <input type="text" name="remarks" placeholder="Optional tracking notes">
                    </div>
                    <button type="submit" class="btn-action">IN STOCKS</button>
                </form>
                
                <br><hr style="border-color: #e4e6eb;"><br>
                
                <div style="background-color: #d8e6fc; padding: 20px; border-radius: 8px; border: 1px solid #e4e6eb;">
                    <h4 style="margin-top: 0; margin-bottom: 14px; color: #050505;">Missing an item? Register it here first.</h4>
                    <form action="dashboard.php" method="POST" autocomplete="off">
                        <input type="hidden" name="action_add_product" value="1">
                        <div class="form-group">
                            <label>Item Name Title</label>
                            <input type="text" name="product_name" required placeholder="e.g., Ballpen, Envelope">
                        </div>
                        <div class="form-group">
                            <label>Item Classification</label>
                            <select name="category" required>
                                <option value="">-- Assign Category --</option>
                                <option value="Non-Food">Non-Food Item</option>
                                <option value="Food">Food Product</option>
                                <option value="Office Supply">Office Supply</option>
                            </select>
                        </div>
                        <button type="submit" class="btn-action" style="background-color: #42b72a;">Add to Stock Lists</button>
                    </form>
                </div>
            </div>

            <div id="view-out" class="view-panel">
                <h3 style="margin-top: 0; color:#000000;">Issue Stock Release</h3>
                <form action="dashboard.php" method="POST" autocomplete="off">
                    <input type="hidden" name="action_stock_movement" value="1">
                    <input type="hidden" name="transaction_type" value="OUT">
                    <input type="hidden" name="batch_composite" id="hidden_batch_composite_out" required>

                    <div class="form-group">
                        <label>Search Expiration Batch to Issue</label>
                        <div class="autocomplete-wrapper">
                            <input type="text" id="search_autocomplete_out" placeholder="Type item title keywords here..." oninput="searchAutocompleteEngine('OUT')" onfocus="searchAutocompleteEngine('OUT')" required style="padding: 10px; border: 1px solid #ccd0d5; border-radius: 6px; width: 100%; box-sizing: border-box; font-size: 14px;">
                            <div id="suggestions_box_out" class="autocomplete-suggestions-box"></div>
                        </div>
                    </div>
                    
                    <div style="display: flex; gap: 15px;">
                        <div class="form-group" style="flex: 2;">
                            <label>Quantity</label>
                            <input type="number" name="quantity" min="1" required placeholder="Amount to release">
                        </div>
                        <div class="form-group" style="flex: 1;">
                            <label>Unit</label>
                            <input type="text" id="unit_out" name="unit" placeholder="piece, box, roll, ream etc..." list="unit-suggestions" oninput="toggleOutMultiplier(this.value)">
                        </div>
                    </div>

                    <div class="form-group" id="multiplier_out_wrapper" style="display:none; background-color: #dbe7f7; border: 1px solid #000000; padding: 12px; border-radius: 6px; margin-bottom: 15px;">
                        <label style="color:#8a6d3b; font-weight: bold;">Pieces per Unit</label>
                        <input type="number" name="unit_multiplier" id="unit_multiplier_out" value="1" min="1" placeholder="Pcs inside packaging box">
                    </div>

                    <div class="form-group">
                        <label>Staff Responsible</label>
                        <input type="text" name="staff_name" required placeholder="Enter name">
                    </div>
                    <div class="form-group">
                        <label>Remarks / Purpose Allocation</label>
                        <input type="text" name="remarks" placeholder="Purpose specifications">
                    </div>
                    <button type="submit" class="btn-action" style="background-color: #f57c00;">OUT STOCKS</button>
                </form>
            </div>

            <div id="view-history" class="view-panel">
                <div style="display: flex; align-items: center; justify-content: space-between; padding-bottom: 10px;">
                    <h2 style="margin-top: 0; color:#000000; margin-bottom: 0;">History IN/OUT</h2>
                    <button type="button" class="btn-export-excel" style="margin-left:auto;" onclick="exportFilteredHistoryOnly()">💾 Export Data</button>
                </div>
                
                <div class="export-control-bar">
                    <div class="export-inputs" style="display: flex; align-items: center; gap: 12px; flex-wrap: wrap; width: 100%;">
                        <label style="font-size: 13px; font-weight: bold; color:#555;">Filter Date:</label>
                        <select id="history_filter_mode" onchange="handleFilterScopeChange('history')">
                            <option value="all">All Time</option>
                            <option value="day">By Day (Specific Date)</option>
                            <option value="month">By Month</option>
                            <option value="year">By Year</option>
                            <option value="range">Custom</option>
                        </select>

                        <div id="history_container_day" style="display:none;">
                            <input type="date" id="history_input_day" value="<?php echo date('Y-m-d'); ?>">
                        </div>
                        <div id="history_container_month" style="display:none;">
                            <input type="month" id="history_input_month" value="<?php echo date('Y-m'); ?>">
                        </div>
                        <div id="history_container_year" style="display:none;">
                            <select id="history_input_year">
                                <?php for($y=date('Y'); $y>=2024; $y--): ?>
                                    <option value="<?php echo $y; ?>"><?php echo $y; ?></option>
                                <?php endfor; ?>
                            </select>
                        </div>
                        <div id="history_container_range" style="display:none;">
                            <span style="font-size:12px; color:#555;">From:</span>
                            <input type="date" id="history_input_start" value="<?php echo date('Y-m-01'); ?>">
                            <span style="font-size:12px; color:#555;">To:</span>
                            <input type="date" id="history_input_end" value="<?php echo date('Y-m-d'); ?>">
                        </div>

                        <div style="flex-grow: 1;"></div>
                        <button type="button" class="btn-action" style="padding: 6px 15px; font-size:13px; width:auto; background-color:#1877f2;" onclick="applyHistoryTableFilter()">🔍 Apply Filter</button>
                    </div>
                </div>

                <table id="history-table">
                    <thead>
                        <tr>
                            <th>Date & Time</th>
                            <th>Item Name</th>
                            <th>Action</th>
                            <th>Total Pieces</th>
                            <th>Staff</th>
                            <th>Details</th>
                            <th>Expiry Attached</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if(count($history_logs) == 0): ?>
                            <tr><td colspan="7" style="text-align:center; color:#65676b;">No records available.</td></tr>
                        <?php else: ?>
                            <?php foreach($history_logs as $log): ?>
                                <tr class="history-row-item">
                                    <td class="history-date-cell"><small><?php echo $log['created_at']; ?></small></td>
                                    <td><strong><?php echo htmlspecialchars($log['product_name']); ?></strong></td>
                                    <td><span class="<?php echo ($log['transaction_type']=='IN')?'badge-movement-in':'badge-movement-out'; ?>"><?php echo $log['transaction_type']; ?></span></td>
                                    <td><strong><?php echo $log['quantity']; ?> pcs</strong></td>
                                    <td><code><?php echo htmlspecialchars($log['staff_name']); ?></code></td>
                                    <td><span style="font-size: 12px; color: #4b4f56;"><?php echo htmlspecialchars($log['remarks']); ?></span></td>
                                    <td><small><?php echo htmlspecialchars($log['expiry_date'] ?: '-'); ?></small></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <div id="view-current_stocks" class="view-panel">
                <div style="display: flex; align-items: center; justify-content: space-between; border-bottom: 1px solid #e4e6eb; padding-bottom: 10px; margin-bottom: 15px; flex-wrap: wrap; gap: 10px;">
                    <h3 style="margin-top: 0; color:#000000; margin-bottom: 0;">Available Stocks</h3>
                    
                    <div style="display: flex; align-items: center; gap: 8px;">
                        <label style="font-size:13px; font-weight:bold; color:#555;">Filter Classification:</label>
                        <select id="stock_category_filter" onchange="filterStocksTableByCategory()" style="padding: 5px; border-radius: 4px; border: 1px solid #ccc;">
                            <option value="ALL">-- Show All Categories --</option>
                            <option value="Office Supply">Office Supply</option>
                            <option value="Non-Food">Non-Food Item</option>
                            <option value="Food">Food Product</option>
                        </select>
                    </div>

                    <button type="button" class="btn-export-excel" onclick="exportCurrentStocksTable()">💾 Export Stocks</button>
                </div>
                
                <table id="stocks-table">
                    <thead>
                        <tr>
                            <th>Item Name</th>
                            <th>Category</th>
                            <th>Available Stocks</th>
                            <th style="text-align: center;">Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if(count($batch_stocks) == 0): ?>
                            <tr><td colspan="4" style="text-align:center; color:#65676b;">No registered assets found.</td></tr>
                        <?php else: ?>
                            <?php foreach($batch_stocks as $b): ?>
                                <tr class="stock-row-item" data-category="<?php echo htmlspecialchars($b['category']); ?>">
                                    <td><strong><?php echo htmlspecialchars($b['product_name']); ?></strong></td>
                                    <td><span class="stock-cat-label" style="background-color:#e4e6eb; padding:4px 8px; border-radius:12px; font-size:12px; font-weight:600;"><?php echo $b['category']; ?></span></td>
                                    <td><strong style="font-size:15px;"><?php echo $b['total_stock']; ?> pcs</strong></td>
                                    <td style="text-align: center;">
                                        <?php if($b['category'] === 'Food'): ?>
                                            <?php if($b['red'] > 0): ?><span class="status-pill pill-red"><?php echo $b['red']; ?> Expired</span><?php endif; ?>
                                            <?php if($b['yellow'] > 0): ?><span class="status-pill pill-yellow"><?php echo $b['yellow']; ?> Near Expiration</span><?php endif; ?>
                                            <?php if($b['green'] > 0): ?><span class="status-pill pill-green"><?php echo $b['green']; ?> Good</span><?php endif; ?>
                                            <?php if($b['red'] == 0 && $b['yellow'] == 0 && $b['green'] == 0): ?>
                                                <span class="status-pill pill-none" style="margin: 0 auto;">0 units</span>
                                            <?php endif; ?>
                                        <?php else: ?>
                                            <span class="status-pill pill-none" style="background-color:#007bff; margin: 0 auto;"><?php echo $b['no_expiry']; ?> pcs Available</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

        </div>
    </div>

    <datalist id="unit-suggestions">
        <option value="piece"></option>
        <option value="pcs"></option>
        <option value="box"></option>
        <option value="roll"></option>
        <option value="ream"></option>
    </datalist>

    <div class="developer-credits">
        Developed by: <br> <strong>JOHN MARVIN VICENTE</strong><br>
        VISIT: <a href="https://insanevictus.github.io/PersonalWebsite/SocialMedias.html">Personal Website</a>
    </div>

    <script>
        const rawProductsIn = [
            <?php foreach($all_products as $p): ?>
            { id: "<?php echo $p['id']; ?>", name: "<?php echo addslashes($p['product_name']); ?>", cat: "<?php echo addslashes($p['category']); ?>" },
            <?php endforeach; ?>
        ];

        const rawBatchesOut = [
            <?php foreach($active_out_dropdown_batches as $batch): ?>
            {
                composite: "<?php echo $batch['product_id'] . '|' . (!empty($batch['expiry_date']) ? $batch['expiry_date'] : 'NO_EXPIRY'); ?>",
                name: "<?php echo addslashes($batch['product_name']); ?>",
                expiry: "<?php echo !empty($batch['expiry_date']) ? 'Expiry: ' . $batch['expiry_date'] : 'Office/Non-Food Item'; ?>",
                qty: <?php echo $batch['net_qty']; ?>
            },
            <?php endforeach; ?>
        ];
    </script>
    <script src="dashboard.js"></script>
</body>
</html>