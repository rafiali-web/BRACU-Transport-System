<?php
/**
 * Payment page for University Bus Booking System
 * Handles payment processing for adding funds to wallet
 */

require_once '../includes/config.php';

// Check if user is logged in
if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    header("Location: login.php");
    exit;
}

$user_id = $_SESSION['id'];

// This would typically integrate with a payment gateway like Stripe or PayPal
// For this example, we'll simulate a payment processing

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    error_log("=== PAYMENT PROCESSING STARTED ===");
    $amount = (float)$_POST['amount'];
    $payment_method = $_POST['payment_method'];
    error_log("Amount: " . $amount . ", Method: " . $payment_method);
    if ($amount <= 0) {
        $_SESSION['error'] = "Invalid amount";
        header("Location: wallet.php");
        exit();
    }
    
    // Debug information
    error_log("Payment processing started. Amount: $amount, User ID: $user_id");
    
    // Get current balance for debugging
    $current_balance = 0;
    $sql = "SELECT balance FROM wallet WHERE user_id = :user_id";
    if ($stmt = $pdo->prepare($sql)) {
        $stmt->bindParam(":user_id", $user_id, PDO::PARAM_INT);
        if ($stmt->execute() && $stmt->rowCount() == 1) {
            $wallet = $stmt->fetch(PDO::FETCH_ASSOC);
            $current_balance = $wallet['balance'];
        }
        unset($stmt);
    }
    
    error_log("Current balance before payment: $current_balance");
    
    // Simulate payment processing
    sleep(2); // Simulate processing time
    
    // Start transaction
    $pdo->beginTransaction();
    
    try {
        // Update wallet balance
        $sql = "UPDATE wallet SET balance = balance + :amount WHERE user_id = :user_id";
        
        if ($stmt = $pdo->prepare($sql)) {
            $stmt->bindParam(":amount", $amount, PDO::PARAM_STR);
            $stmt->bindParam(":user_id", $user_id, PDO::PARAM_INT);
            
            if ($stmt->execute()) {
                error_log("Wallet updated successfully");
                // DEBUG CODE: Check if wallet was actually updated
                $updated_balance = 0;
                $sql_check = "SELECT balance FROM wallet WHERE user_id = :user_id";
                if ($stmt_check = $pdo->prepare($sql_check)) {
                    $stmt_check->bindParam(":user_id", $user_id, PDO::PARAM_INT);
                    if ($stmt_check->execute() && $stmt_check->rowCount() == 1) {
                        $updated_wallet = $stmt_check->fetch(PDO::FETCH_ASSOC);
                        $updated_balance = $updated_wallet['balance'];
                        error_log("Balance after update: " . $updated_balance);
                    }
                    unset($stmt_check);
                }
                // END DEBUG CODE
                
                // Record transaction
                $sql = "INSERT INTO transactions (user_id, amount, type, description) 
                        VALUES (:user_id, :amount, 'credit', 'Added funds via $payment_method')";
                
                if ($stmt2 = $pdo->prepare($sql)) {
                    $stmt2->bindParam(":user_id", $user_id, PDO::PARAM_INT);
                    $stmt2->bindParam(":amount", $amount, PDO::PARAM_STR);
                    
                    if ($stmt2->execute()) {
                        $pdo->commit();
                        
                        // Update session balance
                        $_SESSION['balance'] = $current_balance + $amount;
                        
                        error_log("Payment processed successfully. New balance: " . $_SESSION['balance']);
                        
                        $_SESSION['success'] = "Payment processed successfully!";
                        header("Location: wallet.php");
                        exit();
                    } else {
                        error_log("Failed to record transaction");
                    }
                    unset($stmt2);
                }
            } else {
                error_log("Failed to update wallet");
            }
            unset($stmt);
        }
    } catch (Exception $e) {
        $pdo->rollBack();
        error_log("Payment failed: " . $e->getMessage());
        $_SESSION['error'] = "Payment failed: " . $e->getMessage();
        header("Location: payment.php");
        exit();
    }
}

require_once '../includes/header.php';
?>

<h1 class="page-title">Add Funds to Wallet</h1>

<div class="card">
    <form method="post">
        <div class="form-group">
            <label for="amount">Amount</label>
            <input type="number" id="amount" name="amount" class="form-control" 
                   min="5" step="5" placeholder="Enter amount" required>
        </div>
        
        <div class="form-group">
            <label for="payment_method">Payment Method</label>
            <select id="payment_method" name="payment_method" class="form-control" required>
                <option value="">Select payment method</option>
                <option value="credit_card">Credit Card</option>
                <option value="debit_card">Debit Card</option>
                <option value="bank_transfer">Bank Transfer</option>
            </select>
        </div>
        
        <!-- Credit card fields (would be shown/hidden based on selection) -->
        <div id="credit_card_fields" style="display: none;">
            <div class="form-group">
                <label for="card_number">Card Number</label>
                <input type="text" id="card_number" name="card_number" class="form-control" 
                       placeholder="Enter card number">
            </div>
            
            <div class="form-group">
                <label for="expiry_date">Expiry Date</label>
                <input type="text" id="expiry_date" name="expiry_date" class="form-control" 
                       placeholder="MM/YY">
            </div>
            
            <div class="form-group">
                <label for="cvv">CVV</label>
                <input type="text" id="cvv" name="cvv" class="form-control" 
                       placeholder="CVV">
            </div>
        </div>
        
        <button type="submit" class="btn btn-primary">Process Payment</button>
    </form>
</div>

<script>
// Show/hide credit card fields based on payment method selection
document.getElementById('payment_method').addEventListener('change', function() {
    const creditCardFields = document.getElementById('credit_card_fields');
    if (this.value === 'credit_card' || this.value === 'debit_card') {
        creditCardFields.style.display = 'block';
    } else {
        creditCardFields.style.display = 'none';
    }
});
</script>

<?php
require_once '../includes/footer.php';
?>


<!-- REFUND SYSTEM NEEDS TO BE ADDED -->



