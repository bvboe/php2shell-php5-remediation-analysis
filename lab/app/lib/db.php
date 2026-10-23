<?php
function db() {
    static $pdo = null;
    if ($pdo !== null) return $pdo;
    $path = DATA_DIR . '/northwind.sqlite';
    $fresh = !file_exists($path);
    $pdo = new PDO('sqlite:' . $path);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    if ($fresh) db_seed($pdo);
    return $pdo;
}

function db_seed($pdo) {
    $pdo->exec("
        CREATE TABLE users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            username TEXT UNIQUE NOT NULL,
            pw_hash  TEXT NOT NULL,
            role     TEXT NOT NULL CHECK(role IN ('customer','advisor')),
            full_name TEXT NOT NULL
        );
        CREATE TABLE applications (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            customer_id INTEGER NOT NULL REFERENCES users(id),
            property   TEXT NOT NULL,
            amount_pence INTEGER NOT NULL,
            term_years INTEGER NOT NULL,
            status TEXT NOT NULL DEFAULT 'review',
            decided_by INTEGER NULL,
            note TEXT NULL
        );
        CREATE TABLE documents (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            application_id INTEGER NOT NULL REFERENCES applications(id),
            orig_name TEXT NOT NULL,
            stored    TEXT NOT NULL,
            bytes     INTEGER NOT NULL,
            uploaded_at TEXT NOT NULL
        );
    ");

    $mk = $pdo->prepare("INSERT INTO users (username,pw_hash,role,full_name) VALUES (?,?,?,?)");
    // Lab credentials. Seeded here rather than printed anywhere at runtime.
    $people = [
        ['a.shah',    'advisor',  'Anita Shah'],
        ['d.okafor',  'advisor',  'Daniel Okafor'],
        ['j.whitlow', 'customer', 'Joan Whitlow'],
        ['m.ferreira','customer', 'Miguel Ferreira'],
        ['p.lindqvist','customer','Pia Lindqvist'],
    ];
    foreach ($people as $p) {
        $mk->execute([$p[0], password_hash('Lab-Passw0rd!', PASSWORD_DEFAULT), $p[1], $p[2]]);
    }

    $app = $pdo->prepare("INSERT INTO applications (customer_id,property,amount_pence,term_years,status) VALUES (?,?,?,?,?)");
    $app->execute([3, '14 Brackley Road, Leeds LS8 2QA',      24500000, 25, 'review']);
    $app->execute([4, 'Flat 6, Dunmore Court, Bristol BS1 5TY', 18750000, 30, 'approved']);
    $app->execute([5, '2 Kirkgate Mews, York YO1 8AN',        31200000, 20, 'review']);
}
