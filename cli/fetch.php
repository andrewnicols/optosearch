<?php

require_once(__DIR__ . '/../lib.php');

$startno = fetch_highest_number() + 1;
$endno   = $startno + 5000;

for ($i = $startno; $i < $endno; $i++) {
    print_status($startno, $i);
    if (fetch_orderno($i)) {
        $items = fetch_incomplete_orders_from_db(null, false, $i);
        foreach ($items as $item ) {
            full_update($item);
        }
    }
}

function print_status($startno, $i) {
    if (($i - $startno) % 76 === 0) {
        echo "\n$i:\n";
    }
}
