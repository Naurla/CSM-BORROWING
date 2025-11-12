<?php
require_once "Database.php";

class Transaction extends Database
{

    // --- BCNF CORE STOCK/UNIT MANAGEMENT HELPERS (UPDATED FOR STOCK QUEUEING) ---

    // FIX 1: Allow $conn to be passed to use the transactional connection
    protected function countAvailableUnits($type_id, $conn = null) 
    {
        $used_conn = $conn ?? $this->connect();
        $stmt = $used_conn->prepare("
            SELECT COUNT(unit_id) 
            FROM apparatus_unit 
            WHERE type_id = :type_id AND current_status = 'available'
        ");
        $stmt->execute([':type_id' => $type_id]);
        return $stmt->fetchColumn();
    }

    protected function getUnitsForBorrow($type_id, $quantity_needed, $conn)
    {
        $stmt = $conn->prepare("
            SELECT unit_id 
            FROM apparatus_unit 
            WHERE type_id = :type_id AND current_status = 'available'
            LIMIT :quantity
            FOR UPDATE
        ");
        $stmt->bindParam(':type_id', $type_id); 
        $stmt->bindParam(':quantity', $quantity_needed); 
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_COLUMN); 
    }

    protected function updateUnitStatus(array $unit_ids, $new_status, $conn) 
    {
        if (empty($unit_ids)) return true;
        
        $placeholders = implode(',', array_fill(0, count($unit_ids), '?'));
        $sql = "UPDATE apparatus_unit SET current_status = ? WHERE unit_id IN ({$placeholders})";
        
        $stmt = $conn->prepare($sql);
        $params = array_merge([$new_status], $unit_ids);
        
        return $stmt->execute($params);
    }
    
    protected function getFormUnitIds($form_id, $conn) 
    {
        $stmt = $conn->prepare("
            SELECT unit_id 
            FROM borrow_items 
            WHERE form_id = :form_id AND unit_id IS NOT NULL
        ");
        $stmt->execute([':form_id' => $form_id]);
        return $stmt->fetchAll(PDO::FETCH_COLUMN); 
    }
    
    protected function getPendingQuantity($type_id, $conn) {
        $stmt = $conn->prepare("
            SELECT SUM(bi.quantity) 
            FROM borrow_items bi
            JOIN borrow_forms bf ON bi.form_id = bf.id
            WHERE bi.type_id = :type_id AND bf.status = 'waiting_for_approval'
        ");
        $stmt->execute([':type_id' => $type_id]);
        return (int)$stmt->fetchColumn() ?? 0;
    }

    protected function getCurrentlyOutCount($type_id, $conn = null) {
        $used_conn = $conn ?? $this->connect(); 
        $stmt = $used_conn->prepare("
            SELECT COUNT(unit_id) FROM apparatus_unit 
            WHERE type_id = :type_id AND current_status IN ('borrowed', 'checking') 
        ");
        $stmt->execute([':type_id' => $type_id]);
        return $stmt->fetchColumn(); 
    }
    
    protected function refreshAvailableStockColumn($type_id, $conn)
    {
        $stmt = $conn->prepare("SELECT total_stock, damaged_stock, lost_stock FROM apparatus_type WHERE id = :id");
        $stmt->execute([':id' => $type_id]);
        $data = $stmt->fetch(PDO::FETCH_ASSOC);

        $currently_out = $this->getCurrentlyOutCount($type_id, $conn); 
        $pending_quantity = $this->getPendingQuantity($type_id, $conn);

        $available_physical_stock = $data['total_stock'] - $data['damaged_stock'] - $data['lost_stock'];
        $new_available_stock = $available_physical_stock - $currently_out - $pending_quantity; 
        $new_available_stock = max(0, $new_available_stock); 

        $status = ($new_available_stock > 0) ? 'available' : 'unavailable';
        
        $update_stmt = $conn->prepare("
            UPDATE apparatus_type 
            SET available_stock = :available_stock, status = :status 
            WHERE id = :id
        ");
        return $update_stmt->execute([
            ':available_stock' => $new_available_stock,
            ':status' => $status,
            ':id' => $type_id
        ]);
    }


    protected function addLog($form_id, $staff_id, $action, $remarks, $conn)
    {
        $stmt = $conn->prepare("
            INSERT INTO logs (form_id, user_id, action, message)
            VALUES (:form_id, :user_id, :action, :remarks)
        ");
        
        $log_user_id = $staff_id ?? $_SESSION["user"]["id"]; 
        
        return $stmt->execute([
            ':form_id' => $form_id,
            ':user_id' => $log_user_id, 
            ':action' => $action,
            ':remarks' => $remarks
        ]);
    }

    // --- MODIFIED STOCK METHODS ---

    public function checkApparatusStock($apparatus_id, $quantity_needed)
    {
        return $this->countAvailableUnits($apparatus_id) >= $quantity_needed;
    }

    protected function checkIfDuplicateExists($name, $type, $size, $material)
    {
        $conn = $this->connect();
        $stmt = $conn->prepare("
            SELECT COUNT(*) 
            FROM apparatus_type 
            WHERE name = :name 
            AND apparatus_type = :type 
            AND size = :size 
            AND material = :material
        ");
        $stmt->execute([
            ':name' => $name,
            ':type' => $type,
            ':size' => $size,
            ':material' => $material
        ]);
        
        return $stmt->fetchColumn() > 0;
    }

    // --- STUDENT SUBMISSION (STOCK QUEUEING LOGIC) ---

    public function createTransaction($user_id, $type, $apparatus_list, $borrow_date, $expected_return_date, $agreed_terms)
    {
        $conn = $this->connect();
        $conn->setAttribute(2, false); 
        $conn->beginTransaction(); 

        try {
            $refreshed_ids = []; 
            $transaction_items = []; 
            $requested_type_ids = array_column($apparatus_list, 'id');

            // === FIX STEP 0: PRE-CHECK ACTIVE DUPLICATE REQUESTS (Single item rule) ===
            $placeholders = implode(',', array_fill(0, count($requested_type_ids), '?'));
            $active_status_list = "'waiting_for_approval', 'approved', 'borrowed', 'checking'"; 

            $params_dupes = array_merge([$user_id], $requested_type_ids);
            
            $stmt_get_dupe = $conn->prepare("
                SELECT at.name 
                FROM borrow_forms bf
                JOIN borrow_items bi ON bf.id = bi.form_id
                JOIN apparatus_type at ON bi.type_id = at.id
                WHERE bf.user_id = ? 
                  AND bf.status IN ({$active_status_list})
                  AND bi.type_id IN ({$placeholders})
                LIMIT 1
            ");
            $stmt_get_dupe->execute($params_dupes);
            $conflicting_item_name = $stmt_get_dupe->fetchColumn();

            if ($conflicting_item_name) {
                $conn->rollBack();
                $conn->setAttribute(2, true);
                return ['error_type' => 'duplicate_item_request', 'item_name' => $conflicting_item_name];
            }
            // ========================================================

            // 1. Initial Stock Check (Aggregated Stock - Pending Requests)
            foreach ($apparatus_list as $app) {
                $type_id = $app['id'];
                $quantity = $app['quantity'];

                // Lock apparatus_type row for atomicity
                $stmt_type = $conn->prepare("SELECT total_stock, damaged_stock, lost_stock FROM apparatus_type WHERE id = :id FOR UPDATE");
                $stmt_type->execute([':id' => $type_id]);
                $data = $stmt_type->fetch(PDO::FETCH_ASSOC);

                $available_physical_stock = $data['total_stock'] - $data['damaged_stock'] - $data['lost_stock'];
                $currently_out = $this->getCurrentlyOutCount($type_id, $conn);
                $pending_quantity = $this->getPendingQuantity($type_id, $conn);
                
                // Core Stock Queueing Check
                $available_for_new_request = $available_physical_stock - $currently_out - $pending_quantity;

                if ($available_for_new_request < $quantity) {
                    $conn->rollBack(); 
                    $conn->setAttribute(2, true);
                    return 'stock_error'; 
                }

                $transaction_items[] = ['type_id' => $type_id, 'quantity' => $quantity];
                if (!in_array($type_id, $refreshed_ids)) $refreshed_ids[] = $type_id;
            }
            
            // 2. Insert borrow form
            $formType = ($type === 'borrow') ? 'borrow' : 'reservation';
            $status = 'waiting_for_approval';
            
            $stmt = $conn->prepare("
                    INSERT INTO borrow_forms (user_id, form_type, status, request_date, borrow_date, expected_return_date)
                    VALUES (:user_id, :form_type, :status, CURDATE(), :borrow_date, :expected_return_date)
            ");
            $stmt->bindParam(":user_id", $user_id);
            $stmt->bindParam(":form_type", $formType);
            $stmt->bindParam(":status", $status);
            $stmt->bindParam(":borrow_date", $borrow_date);
            $stmt->bindParam(":expected_return_date", $expected_return_date);
            $stmt->execute();

            $form_id = $conn->lastInsertId();

            // 3. Insert borrow items (Inserting TYPE_ID and Quantity, UNIT_ID is NULL)
            $stmt2 = $conn->prepare("
                    INSERT INTO borrow_items (form_id, type_id, quantity, item_status, unit_id) 
                    VALUES (:form_id, :type_id, :quantity, 'pending', NULL)
            ");
            
            // Insert one row for each unit requested (quantity = 1 per row)
            foreach ($transaction_items as $item) {
                for ($i = 0; $i < $item['quantity']; $i++) {
                    $stmt2->execute([
                        ':form_id' => $form_id,
                        ':type_id' => $item['type_id'],
                        ':quantity' => 1, 
                    ]);
                }
            }

            // 4. Update the available_stock column (Reduces stock visibility for the next student)
            foreach ($refreshed_ids as $type_id) {
                $this->refreshAvailableStockColumn($type_id, $conn);
            }
            
            $conn->commit();
            $conn->setAttribute(2, true); 
            return true;
            
        } catch (Exception $e) {
            $conn->rollBack();
            $conn->setAttribute(2, true);
            error_log("Transaction Creation Failed: " . $e->getMessage());
            return 'db_error'; 
        }
    }
    
    public function getTransactionItems($form_id)
    {
        return $this->getFormItems($form_id); 
    }
    

    public function rejectForm($form_id, $staff_id, $remarks = null) {
        $conn = $this->connect();
        $conn->beginTransaction();
        $final_remarks = empty($remarks) ? null : $remarks;

        try {
            // 1. Get the type_ids involved for stock refresh (to restore the stock from the queue)
            $stmt_get_types = $conn->prepare("SELECT DISTINCT type_id FROM borrow_items WHERE form_id = ?");
            $stmt_get_types->execute([$form_id]);
            $type_ids = $stmt_get_types->fetchAll(PDO::FETCH_COLUMN); 
            
            // 2. Update form and items status
            $stmt_form = $conn->prepare("
                    UPDATE borrow_forms 
                    SET status='rejected', staff_id=:staff_id, staff_remarks=:remarks 
                    WHERE id=:form_id
            ");
            $stmt_form->bindParam(':staff_id', $staff_id);
            $stmt_form->bindParam(':remarks', $final_remarks, $final_remarks === null ? 0 : 2);
            $stmt_form->bindParam(':form_id', $form_id);
            $stmt_form->execute();
            
            $conn->prepare("UPDATE borrow_items SET item_status='rejected' WHERE form_id=:form_id")
                    ->execute([':form_id' => $form_id]);
            
            // 3. Log and Commit
            foreach ($type_ids as $type_id) {
                         $this->refreshAvailableStockColumn($type_id, $conn); // Stock refresh restores the available count
            }
            $this->addLog($form_id, $staff_id, 'rejected', $final_remarks, $conn);
            $conn->commit();
            return true;
        } catch (Exception $e) {
            $conn->rollBack();
            error_log("Rejection failed: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Approve a borrow or reservation request (UNIT ASSIGNMENT LOGIC).
     */
    public function approveForm($form_id, $staff_id, $remarks = null) {
        $conn = $this->connect();
        $conn->beginTransaction(); 

        try {
            // 0. Fetch the apparatus types involved in this form for explicit locking
            $stmt_types = $conn->prepare("SELECT DISTINCT type_id FROM borrow_items WHERE form_id = :id");
            $stmt_types->execute([':id' => $form_id]);
            $type_ids_to_lock = $stmt_types->fetchAll(PDO::FETCH_COLUMN);

            // CRITICAL STEP: Lock the involved apparatus_type rows *now* to serialize concurrent access.
            foreach ($type_ids_to_lock as $type_id) {
                $conn->prepare("SELECT id FROM apparatus_type WHERE id = ? FOR UPDATE")
                       ->execute([$type_id]);
            }

            // Lock the borrow form data itself
            $stmt = $conn->prepare("SELECT * FROM borrow_forms WHERE id = :id FOR UPDATE");
            $stmt->bindParam(":id", $form_id);
            $stmt->execute();
            $form = $stmt->fetch();

            if (!$form || $form['status'] !== 'waiting_for_approval') { 
                $conn->rollBack(); 
                return false; 
            }

            $status = ($form['form_type'] === 'borrow') ? 'borrowed' : 'approved';
            $type_ids_to_refresh = [];

            // 1. Get and group pending item rows (unit_id IS NULL)
            $stmt_items = $conn->prepare("SELECT id, type_id, quantity FROM borrow_items WHERE form_id = :form_id AND item_status = 'pending' AND unit_id IS NULL FOR UPDATE");
            $stmt_items->execute([':form_id' => $form_id]);
            $pending_item_rows = $stmt_items->fetchAll(PDO::FETCH_ASSOC);

            $items_by_type = [];
            foreach ($pending_item_rows as $row) {
                $items_by_type[$row['type_id']][] = $row['id'];
                if (!in_array($row['type_id'], $type_ids_to_refresh)) $type_ids_to_refresh[] = $row['type_id'];
            }

            // 2. Assign specific units and update their status
            $update_item_stmt = $conn->prepare("UPDATE borrow_items SET unit_id = :unit_id, item_status = 'borrowed' WHERE id = :item_id AND unit_id IS NULL");
            
            foreach ($items_by_type as $type_id => $item_ids) {
                $quantity_needed = count($item_ids);
                
                // CRITICAL CHECK: Re-confirm available unit count while under lock
                $available_units_count = $this->countAvailableUnits($type_id, $conn); // USE TRANSACTIONAL CONNECTION
                if ($available_units_count < $quantity_needed) {
                    $conn->rollBack();
                    // Fail safely if stock was taken by a concurrent process
                    return 'stock_mismatch_on_approval'; 
                }


                // 🟢 CRITICAL CONCURRENCY FIX: Use explicit PDO::PARAM_INT for LIMIT.
                $stmt_find_units = $conn->prepare("
                    SELECT unit_id 
                    FROM apparatus_unit 
                    WHERE type_id = :type_id AND current_status = 'available'
                    ORDER BY unit_id ASC -- Added order by for deterministic unit selection
                    LIMIT :quantity
                    FOR UPDATE
                ");
                
                $stmt_find_units->bindValue(':type_id', $type_id);
                $stmt_find_units->bindValue(':quantity', $quantity_needed, PDO::PARAM_INT);

                $stmt_find_units->execute();
                $units = $stmt_find_units->fetchAll(PDO::FETCH_COLUMN);

                // FINAL CHECK: This should now rarely fail if the explicit count check passed above.
                if (count($units) !== $quantity_needed) {
                        $conn->rollBack();
                        return 'stock_mismatch_on_approval'; // Trigger stock failure message
                }

                // Assign units to borrow_items and update unit status
                foreach ($units as $index => $unit_id) {
                    $item_id = $item_ids[$index]; 

                    // Claim the unit by setting status to 'borrowed'
                    $this->updateUnitStatus([$unit_id], 'borrowed', $conn); 
                    
                    $update_item_stmt->execute([
                        ':unit_id' => $unit_id,
                        ':item_id' => $item_id
                    ]);
                }
            }

            // 3. Update form status
            $updateForm = $conn->prepare("
                    UPDATE borrow_forms 
                    SET status = :status, staff_id = :staff_id, staff_remarks = :remarks 
                    WHERE id = :id
            ");
            $updateForm->execute([":status" => $status, ":staff_id" => $staff_id, ":remarks" => $remarks, ":id" => $form_id]);

            // 4. Final Stock Refresh (Moves items from 'pending' count to 'out' count)
            // This is safe because the apparatus_type row is already locked from step 0.
            foreach ($type_ids_to_refresh as $type_id) {
                $this->refreshAvailableStockColumn($type_id, $conn);
            }
            
            $this->addLog($form_id, $staff_id, 'approved', $remarks, $conn);
            $conn->commit();
            return true;

        } catch (Exception $e) {
            $conn->rollBack();
            error_log("Approval failed: " . $e->getMessage());
            return false;
        }
    }
    
    // Mark a borrow form as returned by the borrower (DEPRECATED: Use confirmReturn)
    public function markReturned($form_id, $staff_id, $remarks = null) {
        return $this->confirmReturn($form_id, $staff_id, $remarks);
    }
    
    // Approve a student's return request (DEPRECATED: Use confirmReturn)
    public function approveReturn($form_id, $staff_id, $remarks = null) {
        return $this->confirmReturn($form_id, $staff_id, $remarks);
    }
    
    /**
     * Mark a form as overdue, set units to 'unavailable', but DONT deduct from total stock.
     */
    public function markAsOverdue($form_id, $staff_id, $remarks = "") {
        $conn = $this->connect();
        $conn->beginTransaction();

        try {
            $stmt_status = $conn->prepare("SELECT status, user_id, expected_return_date FROM borrow_forms WHERE id = :id FOR UPDATE");
            $stmt_status->execute([':id' => $form_id]);
            $form_data = $stmt_status->fetch(PDO::FETCH_ASSOC);

            if (!$form_data) { $conn->rollBack(); return false; }

            $student_id = $form_data['user_id'];
            $old_status = $form_data['status'];
            $expected_return_date = new DateTime($form_data['expected_return_date']);
            $today = new DateTime();
            $today->setTime(0, 0, 0); 

            $days_overdue = 0;
            if ($today > $expected_return_date) {
                $interval = $today->diff($expected_return_date);
                $days_overdue = $interval->days;
            }

            // 1. Identify units involved, and move their status to 'unavailable'/'lost'
            $type_ids = [];
            $units_declared_out = 0;

            if ($old_status === 'borrowed' || $old_status === 'approved' || $old_status === 'checking') { 
                $unit_ids = $this->getFormUnitIds($form_id, $conn);
                $units_declared_out = count($unit_ids);
                
                foreach($unit_ids as $unit_id) {
                    $stmt_get_type = $conn->prepare("SELECT type_id FROM apparatus_unit WHERE unit_id = ? FOR UPDATE");
                    $stmt_get_type->execute([$unit_id]);
                    $type_id = $stmt_get_type->fetchColumn();
                    if (!in_array($type_id, $type_ids)) $type_ids[] = $type_id;

                    // Set unit condition to 'lost' and status to 'unavailable' 
                    // This removes it from the currently_out count.
                    $conn->prepare("UPDATE apparatus_unit SET current_condition = 'lost', current_status = 'unavailable' WHERE unit_id = ?")
                         ->execute([$unit_id]);
                }
            }
            
            if (empty($type_ids)) {
                        $stmt_get_types = $conn->prepare("SELECT DISTINCT type_id FROM borrow_items WHERE form_id = ?");
                        $stmt_get_types->execute([$form_id]);
                        $type_ids = $stmt_get_types->fetchAll(PDO::FETCH_COLUMN); 
            }
            
            // === STEP 2: Stock Adjustment (Removed permanent deduction) ===
            // When marking overdue, we only remove the units from the "currently_out" count (which the unit status update above handles).
            // We do NOT modify total_stock or lost_stock here.
            
            // 3. Update the form status 
            $stmt = $conn->prepare("
                    UPDATE borrow_forms
                    SET status = 'overdue', staff_id = :staff_id, staff_remarks = :remarks
                    WHERE id = :form_id
            ");
            $stmt->execute([
                ':staff_id' => $staff_id, 
                ':remarks' => $remarks, 
                ':form_id' => $form_id
            ]);
            
            // 4. Update item status
            $conn->prepare("UPDATE borrow_items SET item_status='overdue' WHERE form_id=:form_id")->execute([':form_id' => $form_id]);

            // 5. APPLY BAN ONLY IF DAYS OVERDUE IS 2 OR MORE (1-day grace period)
            $log_message = "Staff marked as overdue (Units status set to unavailable). "; 
            $ban_duration_days = 1;

            if ($days_overdue >= 2) {
                $ban_until = new DateTime("+{$ban_duration_days} day");
                $ban_date_str = $ban_until->format('Y-m-d H:i:s');
                
                $stmt_ban = $conn->prepare("UPDATE users SET ban_until_date = :ban_date WHERE id = :student_id");
                $stmt_ban->execute([
                    ':ban_date' => $ban_date_str,
                    ':student_id' => $student_id
                ]);
                $log_message .= "{$ban_duration_days}-day ban applied until " . $ban_until->format('Y-m-d H:i:s') . ".";
            } else {
                $log_message .= "Grace period observed (Days Overdue: {$days_overdue}). No ban applied.";
            }

            // 6. Final Refresh stock display
            foreach ($type_ids as $type_id) {
                $this->refreshAvailableStockColumn($type_id, $conn);
            }

            $this->addLog($form_id, $staff_id, 'marked_overdue', $log_message, $conn);
            $conn->commit();
            
            return true;
        } catch (Exception $e) {
            $conn->rollBack();
            error_log("Error marking as overdue: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Confirm item return by staff (ON-TIME RETURN, or handles returns after being marked overdue).
     */
    public function confirmReturn($form_id, $staff_id, $remarks = "") {
        $conn = $this->connect();
        $conn->beginTransaction();

        try {
            $stmt_status = $conn->prepare("SELECT status, user_id FROM borrow_forms WHERE id = ? FOR UPDATE");
            $stmt_status->execute([$form_id]);
            $form_data = $stmt_status->fetch();
            
            if (!$form_data) { $conn->rollBack(); return false; }
            
            $old_status = $form_data['status'];
            $student_id = $form_data['user_id'];
            
            $type_ids = [];
            $unit_ids = $this->getFormUnitIds($form_id, $conn);
            
            // Gather type IDs for stock refresh
            foreach($unit_ids as $unit_id) {
                $stmt_get_type = $conn->prepare("SELECT type_id FROM apparatus_unit WHERE unit_id = ?");
                $stmt_get_type->execute([$unit_id]);
                $type_id = $stmt_get_type->fetchColumn();
                if (!in_array($type_id, $type_ids)) $type_ids[] = $type_id;
            }

            
            // 1. STOCK REVERSAL AND UNIT RESTORATION LOGIC (FIX for overdue returns)
            $is_late = FALSE;

            if ($old_status === 'overdue') { 
                
                // Mark unit as good/available (reversing markAsOverdue permanent change)
                $unit_placeholders = implode(',', $unit_ids);
                $conn->prepare("UPDATE apparatus_unit SET current_condition = 'good', current_status = 'available' WHERE unit_id IN ({$unit_placeholders})")
                    ->execute();
                
                // Reverse the lost_stock deduction from markAsOverdue, which now should be ZERO if the logic above was applied.
                // Since the corrected markAsOverdue no longer modifies total_stock/lost_stock, this reversal is no longer needed here.
                // If you had previous data where markAsOverdue DID deduct stock, you would need to run the reversal below:
                /*
                $unit_count = count($unit_ids);
                foreach ($type_ids as $type_id) {
                    $conn->prepare("SELECT id FROM apparatus_type WHERE id = ? FOR UPDATE")->execute([$type_id]);

                    $stmt_update_stock = $conn->prepare("
                        UPDATE apparatus_type
                        SET 
                            total_stock = total_stock + :unit_count,
                            lost_stock = GREATEST(0, lost_stock - :unit_count)
                        WHERE id = :type_id
                    ");
                    $stmt_update_stock->execute([
                        ':unit_count' => $unit_count,
                        ':type_id' => $type_id
                    ]);
                }
                */

                // Treat the return as late return since it was marked overdue
                $is_late = TRUE; 
            
            } else if ($old_status !== 'returned') { 
                // Standard return logic (from 'borrowed', 'approved', 'checking')
                if (!$this->updateUnitStatus($unit_ids, 'available', $conn)) {
                    $conn->rollBack(); return false;
                }
                // is_late remains FALSE unless determined otherwise (not covered by this simple function)
            } else {
                // If status is already 'returned', just maintain the existing late flag
                $is_late = (bool)$form_data['is_late_return'];
            }
            
            if (empty($type_ids)) {
                        $stmt_get_types = $conn->prepare("SELECT DISTINCT type_id FROM borrow_items WHERE form_id = ?");
                        $stmt_get_types->execute([$form_id]);
                        $type_ids = $stmt_get_types->fetchAll(PDO::FETCH_COLUMN); 
            }
            
            // 2. Update form status
            $stmt = $conn->prepare("
                    UPDATE borrow_forms
                    SET status = 'returned', actual_return_date = CURDATE(), staff_id = :staff_id, staff_remarks = :remarks, is_late_return = :is_late
                    WHERE id = :form_id
            ");
            $stmt->execute([
                ':staff_id' => $staff_id, 
                ':remarks' => $remarks, 
                ':form_id' => $form_id, 
                ':is_late' => $is_late
            ]);

            // 3. Update item status in borrow_items
            $conn->prepare("UPDATE borrow_items SET item_status='returned' WHERE form_id=:form_id")
                    ->execute([':form_id' => $form_id]);

            // 4. Clear any existing ban for this student (return successful)
            $conn->prepare("UPDATE users SET ban_until_date = NULL WHERE id = :student_id")
                    ->execute([':student_id' => $student_id]);
            
            // 5. Refresh stock display
            foreach ($type_ids as $type_id) {
                $this->refreshAvailableStockColumn($type_id, $conn);
            }
            
            $this->addLog($form_id, $staff_id, 'confirmed_return', 'Staff verified return', $conn);
            $conn->commit();
            return true;
        } catch (Exception $e) {
            $conn->rollBack();
            error_log("Error in confirmReturn: " . $e->getMessage());
            return false;
        }
    }
    
  /**
     * CONFIRM LATE RETURN: Marks the form as 'returned' but sets the 'is_late_return' flag to TRUE.
     */
    public function confirmLateReturn($form_id, $staff_id, $remarks = "") {
        $conn = $this->connect();
        $conn->beginTransaction();

        try {
            $stmt_status = $conn->prepare("SELECT status, user_id, expected_return_date FROM borrow_forms WHERE id = ? FOR UPDATE");
            $stmt_status->execute([$form_id]);
            $form_data = $stmt_status->fetch();

            if (!$form_data) { $conn->rollBack(); return false; }
            
            $student_id = $form_data['user_id'];
            $old_status = $form_data['status'];
            $type_ids = [];
            
            // 1. Restore unit status to 'available'
            $unit_ids = $this->getFormUnitIds($form_id, $conn);
            $unit_count = count($unit_ids); // Count units for stock reversal if needed

            foreach($unit_ids as $unit_id) {
                $stmt_get_type = $conn->prepare("SELECT type_id FROM apparatus_unit WHERE unit_id = ?");
                $stmt_get_type->execute([$unit_id]);
                $type_id = $stmt_get_type->fetchColumn();
                if (!in_array($type_id, $type_ids)) $type_ids[] = $type_id;
            }

            // Units go to 'available' and stock is refreshed
            if (!$this->updateUnitStatus($unit_ids, 'available', $conn)) {
                $conn->rollBack(); return false;
            }
            
            // 🟢 FIX: Handle Stock Reversal if item was previously marked OVERDUE (under the old logic)
            if ($old_status === 'overdue') {
                $unit_placeholders = implode(',', $unit_ids);
                
                // 1. Set unit condition back to 'good' (was 'lost')
                $conn->prepare("UPDATE apparatus_unit SET current_condition = 'good' WHERE unit_id IN ({$unit_placeholders})")
                     ->execute();
                
                // 2. Reverse permanent stock changes (revert lost_stock deduction)
                foreach ($type_ids as $type_id) {
                    $conn->prepare("SELECT id FROM apparatus_type WHERE id = ? FOR UPDATE")->execute([$type_id]);

                    $stmt_update_stock = $conn->prepare("
                        UPDATE apparatus_type
                        SET 
                            total_stock = total_stock + :unit_count,
                            lost_stock = GREATEST(0, lost_stock - :unit_count)
                        WHERE id = :type_id
                    ");
                    $stmt_update_stock->execute([
                        ':unit_count' => $unit_count,
                        ':type_id' => $type_id
                    ]);
                }
            }


            // 2. Update the form: Set status to 'returned', actual return date to NOW, AND set LATE flag to TRUE
            $stmt = $conn->prepare("
                    UPDATE borrow_forms
                    SET 
                        status = 'returned', 
                        actual_return_date = CURDATE(), 
                        is_late_return = TRUE, 
                        staff_id = :staff_id, 
                        staff_remarks = :remarks
                    WHERE id = :form_id
            ");
            $stmt->execute([
                ':staff_id' => $staff_id, 
                ':remarks' => $remarks, 
                ':form_id' => $form_id
            ]);
            
            // 3. Update item status to 'returned' in borrow_items
            $conn->prepare("UPDATE borrow_items SET item_status='returned' WHERE form_id=:form_id")
                    ->execute([':form_id' => $form_id]);

            // 4. Clear any existing ban for this student 
            $conn->prepare("UPDATE users SET ban_until_date = NULL WHERE id = :student_id")
                    ->execute([':student_id' => $student_id]);
            
            // 5. Refresh stock display
            foreach ($type_ids as $type_id) {
                $this->refreshAvailableStockColumn($type_id, $conn);
            }

            $this->addLog($form_id, $staff_id, 'confirmed_late_return', 'Staff confirmed late return.', $conn);
            $conn->commit();
            
            return true;
        } catch (Exception $e) {
            $conn->rollBack();
            error_log("Error confirming late return: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Mark an item as damaged (MODIFIED BCNF logic - expects $unit_id).
     */
    public function markAsDamaged($form_id, $staff_id, $remarks = "", $unit_id = null) {
        $conn = $this->connect();
        $conn->beginTransaction();

        try {
            $stmt_status = $conn->prepare("SELECT status, user_id FROM borrow_forms WHERE id = ? FOR UPDATE");
            $stmt_status->execute([$form_id]);
            $form_data = $stmt_status->fetch(PDO::FETCH_ASSOC);
            
            if (!$form_data) { $conn->rollBack(); return false; }
            
            $old_status = $form_data['status'];
            $student_id = $form_data['user_id'];
            
            // Get all unit IDs for this form
            $all_unit_ids = $this->getFormUnitIds($form_id, $conn);
            $type_ids_to_refresh = [];
            
            // 1. Update the borrow form status to 'damaged'
            $stmt = $conn->prepare("
                    UPDATE borrow_forms
                    SET status = 'damaged', actual_return_date = CURDATE(), staff_id = :staff_id, staff_remarks = :remarks
                    WHERE id = :form_id
            ");
            $stmt->execute([':staff_id' => $staff_id, ':remarks' => $remarks, ':form_id' => $form_id]);

            
            foreach ($all_unit_ids as $current_unit_id) {
                // Get Type ID (Needed for stock refresh)
                $stmt_get_type = $conn->prepare("SELECT type_id FROM apparatus_unit WHERE unit_id = ? FOR UPDATE");
                $stmt_get_type->execute([$current_unit_id]);
                $type_id = $stmt_get_type->fetchColumn();
                if (!in_array($type_id, $type_ids_to_refresh)) $type_ids_to_refresh[] = $type_id;

                if ($current_unit_id == $unit_id) {
                    // Damaged item: update unit status and condition
                    $conn->prepare("
                            UPDATE apparatus_unit
                            SET current_condition = 'damaged', current_status = 'unavailable'
                            WHERE unit_id = :unit_id
                    ")->execute([':unit_id' => $unit_id]);
                    
                    // --- FIX: AUTOMATICALLY INCREMENT APPARATUS_TYPE DAMAGED_STOCK ---
                    $conn->prepare("SELECT id FROM apparatus_type WHERE id = ? FOR UPDATE")->execute([$type_id]);

                    $conn->prepare("
                            UPDATE apparatus_type
                            SET damaged_stock = damaged_stock + 1
                            WHERE id = :type_id
                    ")->execute([':type_id' => $type_id]);
                    // ------------------------------------------------------------------

                    // Update item status in borrow_items
                    $conn->prepare("UPDATE borrow_items SET item_status='damaged' WHERE form_id = :form_id AND unit_id = :unit_id")
                        ->execute([':form_id' => $form_id, ':unit_id' => $unit_id]);

                } else {
                    // Other items are returned: restore unit status to 'available'
                    
                    if ($old_status === 'overdue') { 
                        // If previous form status was overdue, and this unit is NOT damaged, 
                        // the reversal logic should have been handled in confirmReturn or needs to be replicated here for this unit.
                        // Given the form status will be updated to 'damaged', we assume this flow is for staff marking a return as damaged. 
                        // We MUST restore the unit condition for UNDAMAGED units if old_status was 'overdue'.
                        
                        // Restore the lost unit's condition (if it was marked lost) and set status to available.
                        $conn->prepare("UPDATE apparatus_unit SET current_condition = 'good', current_status = 'available' WHERE unit_id = ?")
                          ->execute([$current_unit_id]);
                        
                        // NOTE: Since markAsOverdue no longer affects total_stock/lost_stock, 
                        // no stock reversal is needed here either (assuming the corrected markAsOverdue is used).

                    } else {
                        // Standard unit status update (from borrowed/checking to available)
                        $this->updateUnitStatus([$current_unit_id], 'available', $conn);
                    }
                    
                    $conn->prepare("UPDATE borrow_items SET item_status='returned' WHERE form_id = :form_id AND unit_id = :unit_id")
                        ->execute([':form_id' => $form_id, ':unit_id' => $current_unit_id]);
                }
            }
            
            // 3. Clear any existing ban for this student 
            $conn->prepare("UPDATE users SET ban_until_date = NULL WHERE id = :student_id")
                    ->execute([':student_id' => $student_id]);
            
            // 4. Refresh stock display
            foreach ($type_ids_to_refresh as $type_id) {
                // This call updates available_stock based on the newly incremented damaged/unit status changes
                $this->refreshAvailableStockColumn($type_id, $conn);
            }
            
            $this->addLog($form_id, $staff_id, 'returned_with_issue', $remarks, $conn);
            $conn->commit();
            return true;
        } catch (Exception $e) {
            $conn->rollBack();
            error_log("Mark Damaged failed: " . $e->getMessage());
            return false;
        }
    }
    
    // --- NEW UNIT MANAGEMENT METHODS ---
    
    /**
     * Retrieves all individual units for a given apparatus type ID.
     */
    public function getUnitsByType($type_id) 
    {
        $conn = $this->connect();
        $stmt = $conn->prepare("
            SELECT unit_id, serial_number, current_condition, current_status, created_at 
            FROM apparatus_unit 
            WHERE type_id = ? 
            ORDER BY unit_id ASC
        ");
        $stmt->execute([$type_id]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * Restores a damaged unit back to good condition and updates stock counts.
     */
    public function restoreDamagedUnit($unit_id, $staff_id) 
    {
        $conn = $this->connect();
        $conn->beginTransaction();

        try {
            // 1. Get the unit data and lock apparatus_unit row
            $stmt_get_unit = $conn->prepare("
                SELECT type_id, current_condition, current_status 
                FROM apparatus_unit 
                WHERE unit_id = ? FOR UPDATE
            ");
            $stmt_get_unit->execute([$unit_id]);
            $unit_data = $stmt_get_unit->fetch(PDO::FETCH_ASSOC);

            if (!$unit_data || $unit_data['current_condition'] !== 'damaged') {
                $conn->rollBack();
                return 'not_damaged'; 
            }
            
            $type_id = $unit_data['type_id'];

            // CRITICAL: Lock apparatus_type row to prevent concurrency issues during stock update
            $conn->prepare("SELECT id FROM apparatus_type WHERE id = ? FOR UPDATE")->execute([$type_id]);


            // 2. Update apparatus_unit: Restore to good/available
            $stmt_unit_update = $conn->prepare("
                UPDATE apparatus_unit 
                SET current_condition = 'good', current_status = 'available' 
                WHERE unit_id = ?
            ");
            $stmt_unit_update->execute([$unit_id]);
            
            // 3. Update apparatus_type: Decrement damaged_stock by 1
            // FIX APPLIED HERE: Use GREATEST(0, ...) to prevent negative damaged_stock counts
            $stmt_type_decrement = $conn->prepare("
                UPDATE apparatus_type 
                SET damaged_stock = GREATEST(0, damaged_stock - 1)
                WHERE id = ?
            ");
            $stmt_type_decrement->execute([$type_id]);

            // 4. Recalculate available_stock column
            $this->refreshAvailableStockColumn($type_id, $conn);
            
            // 5. Log the action
            $this->addLog(null, $staff_id, 'unit_restored', "Unit ID {$unit_id} (Type ID {$type_id}) restored from damaged to good condition.", $conn);

            $conn->commit();
            return true;

        } catch (Exception $e) {
            $conn->rollBack();
            error_log("Unit Restoration failed: " . $e->getMessage());
            return false;
        }
    }
    
    // --- MODIFIED APPARATUS CRUD METHODS (BCNF) ---

    public function addApparatus($name, $type, $size, $material, $description, $total_stock, $damaged_stock, $lost_stock, $image = 'default.jpg') {
        $conn = $this->connect();
        $conn->beginTransaction();

        if ($this->checkIfDuplicateExists($name, $type, $size, $material)) {
            if ($conn->inTransaction()) { $conn->rollBack(); }
            return false;
        }
        
        try {
            $initial_available_stock = $total_stock - $damaged_stock - $lost_stock; 
            $initial_available_stock = max(0, $initial_available_stock);

            $available_units = $initial_available_stock; 

            $initial_condition = ($damaged_stock > 0 || $lost_stock > 0) ? 'mixed' : 'good';
            $initial_status = ($initial_available_stock > 0) ? 'available' : 'unavailable';
            
            // --- STEP 1: Insert Type Data into apparatus_type ---
            $stmt = $conn->prepare(" 
                    INSERT INTO apparatus_type (name, apparatus_type, size, material, description, total_stock, available_stock, damaged_stock, lost_stock, item_condition, status, image)
                    VALUES (:name, :type, :size, :material, :description, :total_stock, :available_stock, :damaged_stock, :lost_stock, :condition, :status, :image)
            ");

            $stmt->execute([
                ":name" => $name, ":type" => $type, ":size" => $size, ":material" => $material, 
                ":description" => $description, ":total_stock" => $total_stock, 
                ":available_stock" => $initial_available_stock, 
                ":damaged_stock" => $damaged_stock, ":lost_stock" => $lost_stock, 
                ":condition" => $initial_condition, ":status" => $initial_status, 
                ":image" => $image
            ]);
            
            $type_id = $conn->lastInsertId();

            // --- STEP 2: Insert Unit Data into apparatus_unit ---
            
            // Insert Available Units
            $stmt_unit_available = $conn->prepare("INSERT INTO apparatus_unit (type_id, current_condition, current_status) VALUES (:type_id, 'good', 'available')");
            for ($i = 0; $i < $available_units; $i++) {
                $stmt_unit_available->execute([':type_id' => $type_id]);
            }

            // Insert Damaged/Lost Units (as unavailable)
            $stmt_unit_unavailable = $conn->prepare("INSERT INTO apparatus_unit (type_id, current_condition, current_status) VALUES (:type_id, :condition, 'unavailable')");
            for ($i = 0; $i < $damaged_stock; $i++) {
                $stmt_unit_unavailable->execute([':type_id' => $type_id, ':condition' => 'damaged']);
            }
            for ($i = 0; $i < $lost_stock; $i++) {
                $stmt_unit_unavailable->execute([':type_id' => $type_id, ':condition' => 'lost']);
            }
            
            $conn->commit();
            return true;

        } catch (Exception $e) {
            if ($conn->inTransaction()) { 
                $conn->rollBack();
            }
            error_log("Add Apparatus Error: " . $e->getMessage());
            return false;
        }
    }

    public function updateApparatus($id, $name, $type, $size, $material, $description, $total_stock, $condition, $status, $image) {
        return $this->updateApparatusDetailsAndStock($id, $name, $type, $size, $material, $description, $total_stock, 0, 0, $image);
    } 

    public function getAllApparatus() {
        $conn = $this->connect();
        $stmt = $conn->prepare("
            SELECT 
                at.*, 
                (SELECT COUNT(unit_id) FROM apparatus_unit WHERE type_id = at.id AND current_status IN ('borrowed', 'checking')) AS currently_out 
            FROM apparatus_type at
            ORDER BY at.id DESC
        ");
        $stmt->execute();
        return $stmt->fetchAll();
    }

    public function deleteApparatus($id) {
        $conn = $this->connect();
        $conn->beginTransaction();

        try {
            // 1. Check for active loans (unchanged)
            $check_active_loans = $conn->prepare("
                SELECT 1 
                FROM apparatus_unit au
                WHERE au.type_id = :id 
                  AND au.current_status IN ('borrowed', 'checking') 
                LIMIT 1
            ");
            $check_active_loans->execute([':id' => $id]);

            if ($check_active_loans->rowCount() > 0) {
                $conn->rollBack();
                return 'in_use'; 
            }
            
            // 2. Execute the DELETE command 
            $sql_delete = "DELETE FROM apparatus_type WHERE id = :id";
            $stmt_delete = $conn->prepare($sql_delete);
            $stmt_delete->bindParam(":id", $id);
            
            if (!$stmt_delete->execute()) {
                $conn->rollBack();
                return false;
            }

            // 3. COMMIT the transaction 
            $conn->commit();
            
            // 4. Reset AUTO_INCREMENT (Non-critical operation, now outside the transaction)
            $sql_max_id = "SELECT MAX(id) FROM apparatus_type";
            $max_id = $conn->query($sql_max_id)->fetchColumn();
            $next_auto_increment = ($max_id === false || $max_id === null) ? 1 : $max_id + 1;
            
            $conn->exec("ALTER TABLE apparatus_type AUTO_INCREMENT = {$next_auto_increment}");

            return true;
            
        } catch (Exception $e) {
            if ($conn->inTransaction()) {
                $conn->rollBack();
            }
            error_log("Delete Apparatus Error (ID {$id}): " . $e->getMessage());
            return false;
        }
    }

    // Transaction.php
    public function getAvailableApparatus() {
        $conn = $this->connect();
        $sql = "
            SELECT 
                at.id, 
                at.name, 
                at.apparatus_type, 
                at.size, 
                at.material, 
                at.description, 
                at.image, 
                (at.total_stock - at.damaged_stock - at.lost_stock) AS physical_stock,
                (SELECT COUNT(unit_id) FROM apparatus_unit WHERE type_id = at.id AND current_status IN ('borrowed', 'checking')) AS currently_out 
            FROM apparatus_type at 
            ORDER BY at.name ASC
        ";
        $stmt = $conn->prepare($sql);
        $stmt->execute();
        
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $final_results = [];
        foreach ($results as $row) {
            $pending = $this->getPendingQuantity($row['id'], $conn);
            $row['available_stock'] = $row['physical_stock'] - $row['currently_out'] - $pending;
            
            if ($row['available_stock'] > 0) {
                unset($row['physical_stock']);
                unset($row['currently_out']);
                $final_results[] = $row; 
            }
        }
        
        return $final_results;
    }
    
    // --- ADDED/MODIFIED METHODS FOR SEARCH & FILTER (BCNF) ---

    public function getUniqueApparatusTypes() {
        $conn = $this->connect();
        $sql = "SELECT DISTINCT apparatus_type FROM apparatus_type ORDER BY apparatus_type ASC";
        $stmt = $conn->prepare($sql);
        $stmt->execute();
        
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    public function getAllApparatusIncludingZeroStock($search_term = '', $filter_type = '') {
        $conn = $this->connect();
        
        $sql = "SELECT id, name, apparatus_type, size, material, description, image, available_stock 
                  FROM apparatus_type";
        
        $params = [];
        $conditions = [];

        if (!empty($search_term)) {
            $conditions[] = "(name LIKE :search_term)"; 
            $params[':search_term'] = '%' . $search_term . '%';
        }

        if (!empty($filter_type)) {
            $conditions[] = "apparatus_type = :filter_type";
            $params[':filter_type'] = $filter_type;
        }

        if (!empty($conditions)) {
            $sql .= " WHERE " . implode(' AND ', $conditions);
        }

        $sql .= " ORDER BY available_stock DESC, name ASC"; 
        
        $stmt = $conn->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }
    
    public function getApparatusById($id) {
        $conn = $this->connect();
        $stmt = $conn->prepare("
            SELECT 
                at.*, 
                (SELECT COUNT(unit_id) FROM apparatus_unit WHERE type_id = at.id AND current_status IN ('borrowed', 'checking')) AS currently_out 
            FROM apparatus_type at 
            WHERE id = :id
        "); 
        $stmt->execute([':id' => $id]);
        return $stmt->fetch();
    }

    public function getFormItems($form_id)
    {
        $sql = "SELECT 
                        bi.id, 
                        bi.type_id AS apparatus_id, 
                        bi.quantity, 
                        bi.item_status,
                        at.name, 
                        at.apparatus_type,
                        at.size, 
                        at.material,
                        bi.unit_id,
                        au.current_status AS unit_current_status
                    FROM borrow_items bi
                    JOIN apparatus_type at ON bi.type_id = at.id
                    LEFT JOIN apparatus_unit au ON bi.unit_id = au.unit_id 
                    WHERE bi.form_id = :form_id
                    ORDER BY at.name, bi.unit_id";

        $stmt = $this->connect()->prepare($sql);
        $stmt->bindParam(':form_id', $form_id);
        $stmt->execute();

        return $stmt->fetchAll();
    }
    
    public function getBorrowFormItems($form_id) {
        $conn = $this->connect();
        $stmt = $conn->prepare("
            SELECT 
                at.id,
                at.name, 
                at.apparatus_type, 
                at.size, 
                at.material, 
                at.image, 
                COUNT(bi.id) AS quantity, 
                MAX(bi.item_status) AS item_status,
                GROUP_CONCAT(bi.unit_id ORDER BY bi.unit_id) AS unit_ids_list
            FROM borrow_items bi
            JOIN apparatus_type at ON bi.type_id = at.id 
            WHERE bi.form_id = :form_id
            GROUP BY at.id, at.name, at.apparatus_type, at.size, at.material, at.image
            ORDER BY at.name
        ");
        $stmt->execute([':form_id' => $form_id]);
        return $stmt->fetchAll();
    }
    
    public function getStudentFormsByStatus($student_id, $status = null) {
        $conn = $this->connect();
        
        $select_list = "GROUP_CONCAT(CONCAT(a.name, ' (x', form_items.count, ')') SEPARATOR ', ') AS apparatus_list";
        
        $base_sql = "
            SELECT bf.*, {$select_list}
            FROM borrow_forms bf
            JOIN users u ON bf.user_id = u.id
            JOIN (
                SELECT 
                    bi.form_id, 
                    bi.type_id, 
                    COUNT(bi.id) as count 
                FROM borrow_items bi
                GROUP BY bi.form_id, bi.type_id
            ) AS form_items ON bf.id = form_items.form_id
            JOIN apparatus_type a ON form_items.type_id = a.id
        ";

        if ($status && strtolower($status) !== 'all') {
            $statusArray = array_map('trim', explode(',', $status));
            $placeholders = implode(',', array_fill(0, count($statusArray), '?'));
            $query = $conn->prepare("
                {$base_sql}
                WHERE bf.user_id = ?
                AND bf.status IN ({$placeholders})
                GROUP BY bf.id
            ");
            $query->execute(array_merge([$student_id], $statusArray));
        } else {
            $query = $conn->prepare("
                {$base_sql}
                WHERE bf.user_id = ?
                GROUP BY bf.id
            ");
            $query->execute([$student_id]);
        }

        return $query->fetchAll(); 
    }

    // --- NEW METHOD TO GET OVERDUE FORMS (Category 3) ---
    private function getOverdueBorrowedForms() {
        $conn = $this->connect();
        
        $select_list = "GROUP_CONCAT(CONCAT(a.name, ' (x', form_items.count, ')') SEPARATOR ', ') AS apparatus_list";
        
        $query = $conn->query("
            SELECT 
                bf.id, bf.user_id AS borrower_id, 
                u.firstname, u.lastname, bf.form_type AS type, 
                bf.status, bf.request_date, bf.borrow_date, bf.expected_return_date, bf.actual_return_date, bf.staff_remarks,
                {$select_list}
            FROM borrow_forms bf
            JOIN users u ON bf.user_id = u.id
            JOIN (
                SELECT 
                    bi.form_id, 
                    bi.type_id, 
                    COUNT(bi.id) as count 
                FROM borrow_items bi
                GROUP BY bi.form_id, bi.type_id
            ) AS form_items ON bf.id = form_items.form_id
            JOIN apparatus_type a ON form_items.type_id = a.id
            WHERE bf.status = 'borrowed' 
            AND bf.expected_return_date < CURDATE()
            GROUP BY bf.id
            ORDER BY bf.id DESC
        ");

        return $query->fetchAll(PDO::FETCH_ASSOC);
    }
    // -----------------------------------------------------

    /**
     * Retrieves forms requiring staff action:
     * 1. Waiting for Approval
     * 2. Checking (Student initiated return)
     * 3. Borrowed & Overdue (Student has NOT initiated return - MISSING/LATE)
     */
    public function getPendingForms() {
        $conn = $this->connect();
        
        // 1. Get Approval/Checking Forms (Category 1 & 2)
        $select_list = "GROUP_CONCAT(CONCAT(a.name, ' (x', form_items.count, ')') SEPARATOR ', ') AS apparatus_list";
        
        $query_active = $conn->query("
            SELECT 
                bf.id, bf.user_id AS borrower_id, 
                u.firstname, u.lastname, bf.form_type AS type, 
                bf.status, bf.request_date, bf.borrow_date, bf.expected_return_date, bf.actual_return_date, bf.staff_remarks,
                {$select_list}
            FROM borrow_forms bf
            JOIN users u ON bf.user_id = u.id
            JOIN (
                SELECT 
                    bi.form_id, 
                    bi.type_id, 
                    COUNT(bi.id) as count 
                FROM borrow_items bi
                GROUP BY bi.form_id, bi.type_id
            ) AS form_items ON bf.id = form_items.form_id
            JOIN apparatus_type a ON form_items.type_id = a.id
            WHERE bf.status IN ('waiting_for_approval', 'checking')
            GROUP BY bf.id
        ");
        $active_forms = $query_active->fetchAll(PDO::FETCH_ASSOC);
        
        // 2. Get Missing/Overdue Forms (Category 3)
        $overdue_forms = $this->getOverdueBorrowedForms();

        // 3. Combine and sort results
        $combined_forms = array_merge($active_forms, $overdue_forms);
        
        // Sort by Form ID (or date, but ID is simple) descending
        usort($combined_forms, function($a, $b) {
            return $b['id'] <=> $a['id'];
        });

        return $combined_forms; 
    }
    
    public function getFormApparatus($form_id)
    {
        $conn = $this->connect();
        $stmt = $conn->prepare("
            SELECT at.id, at.name, at.image, COUNT(bi.id) AS quantity 
            FROM borrow_items bi
            JOIN apparatus_type at ON bi.type_id = at.id 
            WHERE bi.form_id = :form_id
            GROUP BY at.id, at.name, at.image
        ");
        $stmt->bindParam(':form_id', $form_id);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    public function isApparatusDeletable($apparatus_id)
    {
        $conn = $this->connect();
        $sql = "
            SELECT 1 FROM apparatus_unit 
            WHERE type_id = :id 
            AND current_status IN ('borrowed') 
            LIMIT 1
        ";
        $stmt = $conn->prepare($sql);
        $stmt->execute([':id' => $apparatus_id]);
        return $stmt->rowCount() === 0; 
    }

    public function updateApparatusDetailsAndStock($id, $name, $type, $size, $material, $description, $total_stock, $damaged_stock, $lost_stock, $image) {
        $conn = $this->connect();
        $conn->beginTransaction();

        try {
            $currently_out = $this->getCurrentlyOutCount($id, $conn); 
            $pending_quantity = $this->getPendingQuantity($id, $conn); 

            $available_physical_stock = $total_stock - $damaged_stock - $lost_stock;
            
            $new_available_stock = $available_physical_stock - $currently_out - $pending_quantity; 
            
            if ($new_available_stock < 0) {
                $conn->rollBack();
                return 'stock_too_low'; 
            }

            $item_condition = ($damaged_stock > 0 || $lost_stock > 0) ? 'mixed' : 'good';
            $status = ($new_available_stock > 0) ? 'available' : 'unavailable';
            
            $sql = "
                    UPDATE apparatus_type 
                    SET name = :name, apparatus_type = :type, size = :size, material = :material, 
                        description = :description, total_stock = :total_stock, 
                        available_stock = :available_stock, damaged_stock = :damaged_stock,
                        lost_stock = :lost_stock, item_condition = :condition, status = :status,
                        image = :image
                    WHERE id = :id
            ";

            $stmt = $conn->prepare($sql);

            $stmt->execute([
                ":name" => $name, ":type" => $type, ":size" => $size, ":material" => $material, 
                ":description" => $description, ":total_stock" => $total_stock, 
                ":available_stock" => max(0, $new_available_stock), 
                ":damaged_stock" => $damaged_stock, ":lost_stock" => $lost_stock, 
                ":condition" => $item_condition, ":status" => $status, 
                ":image" => $image, ":id" => $id
            ]);
            
            $conn->commit();
            return true;
            
        } catch (Exception $e) {
            $conn->rollBack();
            error_log("Update Apparatus Details Failed: " . $e->getMessage());
            return false;
        }
    }

    public function getAllFormsFiltered($filter = 'all', $search = '') {
        $conn = $this->connect();
        
        $base_sql = "
            SELECT 
                bf.*, 
                u.firstname, 
                u.lastname
            FROM borrow_forms bf
            JOIN users u ON bf.user_id = u.id
        ";
        
        $where_clauses = [];
        $params = [];
        
        if ($filter !== 'all') {
            if ($filter === 'overdue') {
                $where_clauses[] = "(bf.status IN ('approved', 'borrowed') AND bf.expected_return_date < CURDATE())";
            } else {
                $where_clauses[] = "bf.status = :filter_status";
                $params[':filter_status'] = $filter;
            }
        }
        
        if (!empty($search)) {
            $where_clauses[] = "
                (u.firstname LIKE :search_term 
                 OR u.lastname LIKE :search_term 
                 OR bf.id = :search_id)
            ";
            $params[':search_term'] = '%' . $search . '%';
            $params[':search_id'] = is_numeric($search) ? (int)$search : 0;
        }
        
        if (!empty($where_clauses)) {
            $base_sql .= " WHERE " . implode(' AND ', $where_clauses);
        }

        $base_sql .= " ORDER BY bf.id DESC";

        $query = $conn->prepare($base_sql);
        $query->execute($params);
        return $query->fetchAll(PDO::FETCH_ASSOC);
    }


    public function getAllForms() {
        return $this->getAllFormsFiltered('all', ''); 
    }

    public function isStudentBanned($student_id) {
        $conn = $this->connect();
        $stmt = $conn->prepare("SELECT ban_until_date FROM users WHERE id = :id");
        $stmt->execute([':id' => $student_id]);
        $ban_date_string = $stmt->fetchColumn();
        
        if (!$ban_date_string) {
            return false;
        }

        $current_datetime = new DateTime();
        $ban_datetime = new DateTime($ban_date_string);
        
        return $ban_datetime > $current_datetime;
    }

    public function getStudentActiveTransactions($student_id)
{
    $conn = $this->connect();
    $query = $conn->prepare("
        SELECT * FROM borrow_forms 
        WHERE user_id = :student_id 
        AND status IN ('waiting_for_approval', 'approved', 'borrowed', 'checking', 'overdue')
        ORDER BY created_at DESC
    ");
    $query->bindParam(":student_id", $student_id);
    $query->execute();
    return $query->fetchAll();
}
    
    public function getActiveTransactionCount($student_id) {
        $conn = $this->connect();
        $sql = "SELECT COUNT(*) FROM borrow_forms 
                  WHERE user_id = ? 
                  AND status IN ('waiting_for_approval', 'approved', 'borrowed', 'checking')";
        $stmt = $conn->prepare($sql);
        $stmt->execute([$student_id]);
        return $stmt->fetchColumn();
    }
    
    public function hasOverdueLoansPendingReturn($student_id) {
        $conn = $this->connect();
        $sql = "
            SELECT 1 FROM borrow_forms
            WHERE user_id = :student_id
            AND status IN ('approved', 'borrowed') 
            AND expected_return_date < CURDATE()  
            LIMIT 1
        ";
        $stmt = $conn->prepare($sql);
        $stmt->execute([':student_id' => $student_id]); 
        
        return $stmt->rowCount() > 0;
    }
    public function getStudentTransactions($student_id)
    {
        $conn = $this->connect();
        $query = $conn->prepare("
            SELECT 
                bf.*, 
                u.firstname, 
                u.lastname
            FROM borrow_forms bf
            JOIN users u ON bf.user_id = u.id 
            WHERE bf.user_id = :student_id 
            ORDER BY bf.created_at DESC
        ");
        $query->bindParam(":student_id", $student_id);
        $query->execute();
        return $query->fetchAll();
    }

    public function getBorrowFormById($form_id) {
        $conn = $this->connect();
        $query = $conn->prepare("
            SELECT 
                bf.id, 
                bf.form_type, 
                bf.status, 
                bf.borrow_date, 
                bf.expected_return_date, 
                bf.actual_return_date, 
                bf.staff_remarks,
                bf.is_late_return, 
                bf.staff_remarks AS student_remarks 
            FROM borrow_forms bf
            WHERE bf.id = ?
        ");
        $query->execute([$form_id]);
        return $query->fetch(); 
    }

    
    public function markAsChecking($form_id, $student_id, $remarks = null) 
    {
        $conn = $this->connect();
        $conn->beginTransaction();

        $check_stmt = $conn->prepare("
            SELECT status 
            FROM borrow_forms 
            WHERE id = :form_id 
              AND user_id = :student_id 
              AND status IN ('borrowed', 'approved', 'overdue') 
        ");
        $check_stmt->execute([':form_id' => $form_id, ':student_id' => $student_id]);
        
        $old_status = $check_stmt->fetchColumn(); 

        if ($old_status === false) {
            $conn->rollBack();
            return false; 
        }

        try {
            $stmt_form = $conn->prepare("
                UPDATE borrow_forms 
                SET status='checking', staff_remarks=:remarks, actual_return_date=CURDATE() 
                WHERE id=:form_id
            ");
            $stmt_form->bindParam(':remarks', $remarks);
            $stmt_form->bindParam(':form_id', $form_id);
            $stmt_form->execute();

            $conn->prepare("UPDATE borrow_items SET item_status='checking' WHERE form_id=:form_id")
                ->execute([':form_id' => $form_id]);

            $unit_ids = $this->getFormUnitIds($form_id, $conn);
            
            // Crucially, when an item previously marked 'overdue' is returned, we update unit status to 'checking'
            // If the old status was 'overdue', its permanent loss status will be reversed later in confirmReturn/confirmLateReturn.
            if (!$this->updateUnitStatus($unit_ids, 'checking', $conn)) {
                $conn->rollBack(); return false;
            }
            
            $this->addLog($form_id, $student_id, 'initiated_return', 'Student requested return verification. Remarks: ' . $remarks, $conn);
            
            $conn->commit();
            return true;

        } catch (Exception $e) {
            $conn->rollBack();
            error_log("Error marking as checking: " . $e->getMessage());
            return false;
        }
    }
    public function getBanUntilDate($student_id) {
        $conn = $this->connect();
        $stmt = $conn->prepare("SELECT ban_until_date FROM users WHERE id = :id");
        $stmt->execute([':id' => $student_id]);
        return $stmt->fetchColumn();
    }


}