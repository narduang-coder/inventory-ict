ALTER TABLE stock_movements
    ADD COLUMN item_condition VARCHAR(20) NULL AFTER movement_type;

UPDATE stock_movements
SET item_condition = CASE
    WHEN note LIKE '%(ສະພາບ: good)%' THEN 'good'
    WHEN note LIKE '%(ສະພາບ: fair)%' THEN 'fair'
    WHEN note LIKE '%(ສະພາບ: damaged)%' THEN 'damaged'
    WHEN note LIKE '%(ສະພາບ: broken)%' THEN 'broken'
    ELSE NULL
END
WHERE movement_type = 'return' AND item_condition IS NULL;