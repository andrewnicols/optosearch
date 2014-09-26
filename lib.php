<?php

// iPhone DB.
$DB = new PDO(
    'sqlite:iphoneDB.sqlite',
    null,
    null,
    array(PDO::ATTR_PERSISTENT => true)
);
if ($DB) {
    echo "Opened database succesfully\n";
}

function fetch($orderno, $item = null) {
    $baseurl = "https://www.optusbusiness.com.au/";
    if ($item) {
        $url = "{$baseurl}/test_track_and_trace_status_detail.php?sos_ord_no={$orderno}&opom_ord_id={$item}&request_json=true";
    } else {
        $url = "{$baseurl}/test_track_status.php?orderno={$orderno}&request_json=true";
    }
    $ch = curl_init();

    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_HEADER, 1);

    $result = curl_exec($ch);

    $header_size = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $header = substr($result, 0, $header_size);
    $body = substr($result, $header_size);
    curl_close($ch);

    $data = json_decode($body);
    return $data;
}

function fetch_order_from_db($orderno) {
    global $DB;
    $sthorder = $DB->prepare("SELECT * FROM orders WHERE orderno = :orderno",
            array(PDO::ATTR_CURSOR => PDO::CURSOR_FWDONLY));
    $sthorder->execute(array(
        ':orderno' => $orderno,
    ));
    return $sthorder->fetch();
}

function insert_order_into_db($order, $items) {
    global $DB;
    $sthorder = $DB->prepare("INSERT INTO orders (orderno) VALUES (:orderno)",
            array(PDO::ATTR_CURSOR => PDO::CURSOR_FWDONLY));
    $sthitems = $DB->prepare("
        INSERT INTO order_items
        (orderno, itemno, device, complete)
        VALUES
        (:orderno, :itemno, :device, 0)",
            array(PDO::ATTR_CURSOR => PDO::CURSOR_FWDONLY));

    $orderno = $order->order_number;

    $sthorder->execute(array(
        ':orderno' => $orderno,
    ));

    $foundiPhone = false;
    foreach ($items as $item) {
        if ($item->value === 'DEFAULT') {
            continue;
        } else if (strpos($item->text, 'iPhn6') === false) {
            continue;
        }
        $sthitems->execute(array(
            ':orderno'  => $orderno,
            ':itemno'   => $item->value,
            ':device'   => $item->text,
        ));
        $foundiPhone = true;
    }
    return $foundiPhone;
}

function fetch_orderno($orderno) {
    // Check if this item is already in the database.
    if (fetch_order_from_db($orderno)) {
        echo "S";
        set_highest_orderno($orderno);
        return false;
    }

    // Fetch the order.
    if (!$order = fetch($orderno)) {
        echo "E";
        return false;
    }

    if (empty($order->select_list)) {
        // Nothing in the order list.
        echo "N";
        return false;
    }

    echo "\n";

    // Only set the highest order number here because we can't guarantee
    // that a real order exists before this point.
    set_highest_orderno($orderno);

    return insert_order_into_db($order, $order->select_list);
}

function fetch_highest_number() {
    global $DB;
    $sthorder = $DB->prepare("SELECT orderno FROM orders ORDER BY orderno DESC LIMIT 1");
    $sthorder->execute();
    if ($row = $sthorder->fetch()) {
        return $row['orderno'];
    }
}

function insert_status_text($status) {
    global $DB;

    $sthinsert = $DB->prepare("INSERT INTO status_texts (uid, message, complete) VALUES (:uid, :message, 0)",
            array(PDO::ATTR_CURSOR => PDO::CURSOR_FWDONLY));
    $sthinsert->execute(array(
        ':uid'      => $status->uid,
        ':message'  => $status->message,
    ));
}

function fetch_statuses_from_db($itemno) {
    global $DB;

    $lastcheck = $DB->prepare("
            SELECT
                i.orderno,
                si.*,
                st.*
            FROM order_items AS i
            JOIN status_items AS si ON si.itemno = i.itemno
            JOIN status_texts st ON st.uid = si.uid
            WHERE i.itemno = :itemno
            ORDER BY si.time DESC
            ",
            array(PDO::ATTR_CURSOR => PDO::CURSOR_FWDONLY));
    $lastcheck->execute(array(
        ':itemno'   => $itemno,
    ));
    return $lastcheck;
}

function fetch_latest_status_from_db($itemno) {
    $lastcheck = fetch_statuses_from_db($itemno);
    $statuses = $lastcheck->fetchAll();

    if (count($statuses)) {
        $firstrecord = $statuses[0];
        $firstrecord['count'] = count($statuses);
        return $firstrecord;
    }
    return array();
}

function fetch_first_status_from_db($itemno) {
    $lastcheck = fetch_statuses_from_db($itemno);
    $statuses = $lastcheck->fetchAll();

    if (count($statuses)) {
        $firstrecord = $statuses[count($statuses) - 1];
        $firstrecord['count'] = count($statuses);
        return $firstrecord;
    }
    return array();
}

function fetch_incomplete_orders_from_db($filter = null, $ignorecomplete = true, $orderno = null) {
    global $DB;

    $fields = array(
        'i.device',
        'i.orderno',
        'i.itemno',
        'i.firstentry',
        'i.lastupdate',
    );

    $sql = "SELECT " . implode(", ", $fields) . " FROM order_items AS i WHERE 1 = 1";
    $params = array();

    if ($ignorecomplete) {
        $sql .= " AND (i.complete = 0 OR i.complete is null)";
    }
    if ($filter) {
        $sql .= " AND i.device LIKE :filter";
        $params[':filter'] = $filter;
    }

    if ($orderno) {
        $sql .= " AND i.orderno = :orderno";
        $params[':orderno'] = $orderno;
    }

    $sql .= " ORDER BY i.firstentry ASC";

    $sthdevices = $DB->prepare($sql);
    $sthdevices->execute($params);

    return ($sthdevices);
}

function mark_item_as_complete($itemno) {
    global $DB;

    $markcomplete = $DB->prepare("
            UPDATE order_items
            SET complete = 1
            WHERE itemno = :itemno",
            array(PDO::ATTR_CURSOR => PDO::CURSOR_FWDONLY));

    $markcomplete->execute(array(
        ':itemno' => $itemno,
    ));
}

function update_last_updated($itemno) {
    global $DB;

    $sthupdate = $DB->prepare("
            UPDATE order_items
            SET lastupdate = :lastupdate
            WHERE itemno = :itemno",
            array(PDO::ATTR_CURSOR => PDO::CURSOR_FWDONLY));
    $sthupdate->execute(array(
        ':itemno' => $itemno,
        ':lastupdate' => time(),
    ));
}

function fetch_and_update_item_status($orderno, $itemno, $topuid) {
    global $DB;

    $currentstatus = fetch($orderno, $itemno);

    $statusinsert = $DB->prepare("
            INSERT INTO status_items (itemno, time, uid) VALUES (:itemno, :time, :uid)",
            array(PDO::ATTR_CURSOR => PDO::CURSOR_FWDONLY));
    $textinsert = $DB->prepare("
            INSERT INTO status_texts (uid, message, complete) VALUES (:uid, :message, 0)",
            array(PDO::ATTR_CURSOR => PDO::CURSOR_FWDONLY));
    foreach ($currentstatus as $statusitem) {
        if ($statusitem->uid === $topuid) {
            // It looks like this this status item matches the most
            // recent one on record. Skip.
            break;
        }
        $statusinsert->execute(array(
            ':itemno' => $itemno,
            ':time' => $statusitem->time,
            ':uid' => $statusitem->uid,
        ));
        $textinsert->execute(array(
            ':uid' => $statusitem->uid,
            ':message' => $statusitem->message,
        ));
    }

    // Mark the item entry as updated to prevent DoS on Optus.
    update_last_updated($itemno);
    set_first_entry($itemno);

    $status = fetch_latest_status_from_db($itemno);
    if ($status['complete'] || $status['delivering']) {
        // Order Item is now complete.
        mark_item_as_complete($itemno);
    }

    return $status;
}

function full_update($item, &$queuepos = null) {
    $updatefrequency = 60 * 60 * 3;

    $item = (object) $item;
    $itemno = $item->itemno;
    $status = fetch_latest_status_from_db($item->itemno);
    $topuid = null;
    if ($status) {
        if ($status['complete'] || $status['delivering']) {
            // Somehow not marked as complete but the item is marked as incomplete. Update.
            mark_item_as_complete($itemno);
            return;
        }
        if ($status['held']) {
            return;
        }
        $topuid = $status['uid'];
    }

    if (empty($updatefrequency) || ($item->lastupdate < time() - $updatefrequency)) {
        $infotype = "F";
        $status = fetch_and_update_item_status($item->orderno, $itemno, $topuid);
        update_last_updated($itemno);
    } else {
        $infotype = "S";
    }

    if ($status['stockwait']) {
        if (isset($queuepos)) {
            if (!isset($queuepos[$item->device])) {
                $queuepos[$item->device] = 0;
            }
            $queuepos[$item->device]++;
            echo "{$infotype} - {$status['time']} Order {$item->orderno}:{$item->firstentry} is currently number {$queuepos[$item->device]} in the queue for an {$item->device}\n";
        } else {
            echo "{$infotype} - {$status['time']} Order {$item->orderno}:{$item->firstentry} is currently in the queue for an {$item->device}\n";
        }
    } else {
        echo "{$infotype} - {$status['time']} Order {$item->orderno}:{$item->firstentry} for a {$item->device}: {$status['message']}\n";
    }
}

function set_highest_orderno($orderno) {
    global $DB;

    $sthfetch = $DB->prepare("SELECT * FROM config WHERE key = 'highestorderno'");
    $sthfetch->execute();
    if ($row = $sthfetch->fetch()) {
        if ($row['value'] >= $orderno) {
            return;
        }
        $sthset = $DB->prepare("UPDATE config SET value = :value WHERE key = 'highestorderno'");
    } else {
        $sthset = $DB->prepare("INSERT INTO config (key, value) VALUES ('highestorderno', :value)");
    }
    $sthset->execute(array(
        ':value' => $orderno,
    ));
}

function set_first_entry($itemno) {
    global $DB;

    if ($firstentry = fetch_first_status_from_db($itemno)) {

        $sthupdate = $DB->prepare("
                UPDATE order_items
                SET firstentry = :firstentry
                WHERE itemno = :itemno",
                array(PDO::ATTR_CURSOR => PDO::CURSOR_FWDONLY));
        $sthupdate->execute(array(
            ':itemno' => $itemno,
            ':firstentry' => $firstentry['time'],
        ));
    }
}

function fix_missing_first_entries() {
    global $DB;

    $sthupdate = $DB->prepare("
            SELECT itemno FROM order_items
            WHERE firstentry is null",
            array(PDO::ATTR_CURSOR => PDO::CURSOR_FWDONLY));
    $sthupdate->execute();

    while ($row = $sthupdate->fetch()) {
        echo "Updating {$row['itemno']}\n";
        set_first_entry($row['itemno']);
    }
}
