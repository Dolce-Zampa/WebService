<?php

/** Runs a checkpointed scan; an unsuccessful webhook leaves the window available for retry. */
class ScheduledPromotionCache
{
    public static function run($now, callable $loadCheckpoint, callable $findProducts, callable $notify, callable $saveCheckpoint)
    {
        $previous = $loadCheckpoint();
        if ($previous && $previous > $now) {
            throw new RuntimeException('Promotion cache checkpoint is in the future.');
        }
        $products = $findProducts($previous ?: null, $now);
        if ($products === false) {
            throw new RuntimeException('Unable to read scheduled prices.');
        }
        foreach ($products as $productId) {
            if (!$notify((int) $productId)) {
                throw new RuntimeException('Cache webhook failed for product #' . (int) $productId . '.');
            }
        }
        if (!$saveCheckpoint($now)) {
            throw new RuntimeException('Unable to save promotion cache checkpoint.');
        }
        return count($products);
    }
}
