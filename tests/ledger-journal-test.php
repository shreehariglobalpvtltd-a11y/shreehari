<?php
declare(strict_types=1);
require dirname(__DIR__).'/includes/bootstrap.php';
if (PHP_SAPI !== 'cli' || !str_ends_with((string)Database::scalar('SELECT DATABASE()'), '_test')) {
    exit('TEST DATABASE ONLY');
}
$id = Database::insert('team_ledger_journal', ['ledger_id'=>0, 'event_type'=>'test', 'new_row'=>'{"synthetic":true}']);
echo "PASS journal accepts appended events\n";
foreach (['UPDATE team_ledger_journal SET event_type=\'changed\' WHERE id=:id', 'DELETE FROM team_ledger_journal WHERE id=:id'] as $sql) {
    try {
        Database::query($sql, ['id'=>$id]);
    } catch (Throwable $e) {
        if (!str_contains($e->getMessage(), 'Ledger audit history is immutable')) {
            throw $e;
        }
        echo "PASS database rejects history mutation\n";
        continue;
    }
    throw new RuntimeException('Audit history mutation was allowed');
}
echo "SUCCESS 3 immutable journal checks\n";
