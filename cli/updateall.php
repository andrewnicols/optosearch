<?php

require_once(__DIR__ . '/../lib.php');

$filter = 'iPhn6%';

$order_items = fetch_incomplete_orders_from_db($filter);
$queuepos = array();
foreach ($order_items as $item) {
    full_update($item, $queuepos);
}
