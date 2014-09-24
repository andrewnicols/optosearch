<?php

$fh = fopen("iPhn6", "r");
if ($fh) {
    while (($line = fgets($fh)) !== false) {
        $line = trim($line);
        if (empty($line)) {
            continue;
        }

        try {
            $order = json_decode($line);
        } catch (Exception $e) {
            echo "Could not process {$line}\n";
            continue;
        }

        $orderno = $order->order_number;

        if (empty($order->select_list)) {
            continue;
        }

        foreach ($order->select_list as $item) {
            if ($item->value === 'DEFAULT') {
                continue;
            } else if (strpos($item->text, 'iPhn6') === false) {
                continue;
            }
            //echo "Found {$item->text} with value {$item->value}\n";
            echo "Found {$item->text}\n";
        }
    }
}
