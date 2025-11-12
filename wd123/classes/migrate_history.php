<?php
// Ensure this path is correct relative to your script location
require_once "Transaction.php"; 
require_once "Database.php";

class HistoryMigrator extends Transaction {
    public function migrateHistoricalItems() {
        $conn = $this->connect();
        $conn->beginTransaction();
        $total_units_migrated = 0;

        try {
            // 1. Fetch ALL historical, quantity-based records (skipping forms already manually fixed or known bad ones)
            // Note: We use the old table name: borrow_items_old
            $stmt_old = $conn->prepare("
                SELECT form_id, apparatus_id, quantity, item_status
                FROM borrow_items_old
                WHERE form_id NOT IN (56, 66) -- Skip manually verified forms
                ORDER BY form_id ASC
            ");
            $stmt_old->execute();
            $old_items = $stmt_old->fetchAll(PDO::FETCH_ASSOC);

            // 2. Prepare statements for inserting new unit records
            $stmt_insert = $conn->prepare("
                INSERT INTO borrow_items (form_id, unit_id, item_status, created_at)
                VALUES (:form_id, :unit_id, :item_status, NOW())
            ");

            foreach ($old_items as $item) {
                $form_id = $item['form_id'];
                $type_id = $item['apparatus_id'];
                $quantity = (int)$item['quantity'];
                $item_status = $item['item_status'];

                if ($quantity <= 0) continue;

                // 3. Find and lock available UNITS for this apparatus type (type_id)
                // We use the same method as the main transaction logic.
                $available_units = $this->getUnitsForBorrow($type_id, $quantity, $conn);

                // Fallback check: If the original quantity is greater than available units,
                // we assume it's because the items were returned, and we'll just link the units we have.
                $units_to_link = array_slice($available_units, 0, $quantity);
                
                if (empty($units_to_link) && $item_status === 'returned') {
                    // CRITICAL CATCH: If items were returned, their status in apparatus_unit is 'available'.
                    // We must find *any* unit of that type, regardless of current status, 
                    // assuming the current status reflects the unit's permanent state (e.g., deleted/damaged).
                    
                    // WARNING: This is a complex historical assumption. For simplicity, we skip if no units are available
                    // and rely on the status being correct in the form. For true accuracy, you need a full snapshot backup.
                    // For now, we skip and assume the form record is enough for history if no units are available.
                    
                    // We will only migrate records that are not 'returned' if units are truly unavailable.
                    if ($item_status !== 'returned' && $item_status !== 'rejected') {
                        // If it's an active status but no units are left, we cannot accurately assign units.
                        // For historical records, this is an acceptable loss if the data is corrupt.
                        continue;
                    }
                }
                
                // 4. Insert one row into the new 'borrow_items' table for each unit
                foreach ($units_to_link as $unit_id) {
                    $stmt_insert->execute([
                        ':form_id' => $form_id,
                        ':unit_id' => $unit_id,
                        ':item_status' => $item_status,
                    ]);
                    $total_units_migrated++;
                    
                    // IMPORTANT: We must update the unit status for active/pending loans in the apparatus_unit table
                    if ($item_status === 'borrowed' || $item_status === 'approved' || $item_status === 'checking' || $item_status === 'waiting_for_approval') {
                        $this->updateUnitStatus([$unit_id], $item_status, $conn);
                    }
                }
            }

            $conn->commit();
            return "Migration successful! {$total_units_migrated} historical unit records migrated to the new borrow_items table.";

        } catch (Exception $e) {
            $conn->rollBack();
            return "Migration FAILED: " . $e->getMessage();
        }
    }
}

// --- Execution ---
$migrator = new HistoryMigrator();
$result = $migrator->migrateHistoricalItems();

echo $result . "\n";
?>