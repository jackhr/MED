INSERT INTO medicine_inventory_consumptions (
    workspace_id,
    intake_log_id,
    inventory_id,
    medicine_id,
    dosage_value,
    dosage_unit,
    created_at,
    updated_at
)
SELECT
    l.workspace_id,
    l.id,
    i.id,
    l.medicine_id,
    l.dosage_value,
    l.dosage_unit,
    l.created_at,
    l.created_at
FROM medicine_intake_logs l
INNER JOIN medicine_inventory i
    ON i.workspace_id = l.workspace_id
   AND i.medicine_id = l.medicine_id
   AND i.unit = l.dosage_unit
   AND l.created_at >= i.created_at
LEFT JOIN medicine_inventory_consumptions c
    ON c.workspace_id = l.workspace_id
   AND c.intake_log_id = l.id
WHERE c.id IS NULL;

UPDATE medicine_inventory i
SET i.stock_on_hand = GREATEST(
    0.00,
    i.initial_stock_on_hand - COALESCE((
        SELECT SUM(l.dosage_value)
        FROM medicine_intake_logs l
        WHERE l.workspace_id = i.workspace_id
          AND l.medicine_id = i.medicine_id
          AND l.dosage_unit = i.unit
          AND l.created_at >= i.created_at
    ), 0.00)
);
