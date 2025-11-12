<?php 
require_once 'Database.php'; // Ensure the path to your Database.php is correct

class Migrator extends Database {
    /**
     * Executes the one-time data migration from apparatus (type-based) 
     * to apparatus_unit (unit-based).
     */
    public function migrateApparatusToUnits() {
        $conn = $this->connect(); // Get the PDO connection
        $conn->beginTransaction();

        try {
            // 1. Fetch all type data from the current 'apparatus' table
            $stmt = $conn->prepare("SELECT id, total_stock, damaged_stock, lost_stock FROM apparatus");
            $stmt->execute();
            $apparatus_types = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $total_units_created = 0;

            foreach ($apparatus_types as $type) {
                $type_id = $type['id'];
                $total = (int)$type['total_stock'];
                $damaged = (int)$type['damaged_stock'];
                $lost = (int)$type['lost_stock'];
                $available = $total - $damaged - $lost;

                // 2. Insert Available Units ('good' and 'available')
                // We use a prepared statement outside the loops for efficiency
                $stmt_available = $conn->prepare("
                    INSERT INTO apparatus_unit (type_id, current_condition, current_status)
                    VALUES (:type_id, 'good', 'available')
                ");
                for ($i = 0; $i < $available; $i++) {
                    $stmt_available->execute([':type_id' => $type_id]);
                    $total_units_created++;
                }

                // 3. Insert Damaged Units ('damaged' and 'unavailable')
                $stmt_damaged = $conn->prepare("
                    INSERT INTO apparatus_unit (type_id, current_condition, current_status)
                    VALUES (:type_id, 'damaged', 'unavailable')
                ");
                for ($i = 0; $i < $damaged; $i++) {
                    $stmt_damaged->execute([':type_id' => $type_id]);
                    $total_units_created++;
                }
                
                // 4. Insert Lost Units ('lost' and 'unavailable')
                $stmt_lost = $conn->prepare("
                    INSERT INTO apparatus_unit (type_id, current_condition, current_status)
                    VALUES (:type_id, 'lost', 'unavailable')
                ");
                for ($i = 0; $i < $lost; $i++) {
                    $stmt_lost->execute([':type_id' => $type_id]);
                    $total_units_created++;
                }
            }
            
            $conn->commit();
            return "Migration successful! Created {$total_units_created} units in apparatus_unit.";
            
        } catch (Exception $e) {
            $conn->rollBack();
            return "Migration failed: " . $e->getMessage();
        }
    }
}

// --- Execution ---
$migrator = new Migrator();
$result = $migrator->migrateApparatusToUnits();

// Output the result
echo $result . "\n";
?>